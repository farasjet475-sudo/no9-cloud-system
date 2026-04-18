<?php
require_once __DIR__ . '/includes/auth.php';

$db = db();

function backup_table_exists(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$table'");
    return $q && $q->num_rows > 0;
}

function backup_column_exists(mysqli $db, string $table, string $column): bool {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && $q->num_rows > 0;
}

function backup_insert_sql(string $table, array $row, mysqli $db): string {
    $cols = array_map(fn($c) => "`$c`", array_keys($row));
    $vals = array_map(function ($v) use ($db) {
        return is_null($v) ? 'NULL' : "'" . $db->real_escape_string((string)$v) . "'";
    }, array_values($row));

    return "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
}

function can_company_restore(): bool {
    if (function_exists('is_company_admin') && is_company_admin()) return true;
    if (function_exists('is_admin') && is_admin()) return true;

    if (function_exists('current_user')) {
        $u = current_user();
        $role = strtolower((string)($u['role'] ?? ''));
        return in_array($role, ['admin', 'company_admin', 'manager'], true);
    }

    return false;
}

function company_backup_sql(int $cid): string {
    $db = db();

    $sql = "-- NO9 Company Backup\n";
    $sql .= "-- Company ID: {$cid}\n";
    $sql .= "-- Generated At: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tablesWithCompanyId = [
        'branches',
        'users',
        'customers',
        'suppliers',
        'products',
        'expenses',
        'sales',
        'quotations',
        'payment_proofs',
        'notifications',
        'activity_logs',
        'subscriptions',
        'settings'
    ];

    foreach ($tablesWithCompanyId as $table) {
        if (!backup_table_exists($db, $table)) continue;
        if (!backup_column_exists($db, $table, 'company_id')) continue;

        $rows = $db->query("SELECT * FROM `$table` WHERE company_id=" . (int)$cid);
        if (!$rows) continue;

        while ($r = $rows->fetch_assoc()) {
            $sql .= backup_insert_sql($table, $r, $db);
        }
        $sql .= "\n";
    }

    if (backup_table_exists($db, 'sale_items') && backup_table_exists($db, 'sales')) {
        $saleIds = [];
        $salesRes = $db->query("SELECT id FROM `sales` WHERE company_id=" . (int)$cid);
        if ($salesRes) {
            while ($s = $salesRes->fetch_assoc()) {
                $saleIds[] = (int)$s['id'];
            }
        }

        if ($saleIds) {
            $idList = implode(',', $saleIds);
            $itemRes = $db->query("SELECT * FROM `sale_items` WHERE sale_id IN ($idList)");
            if ($itemRes) {
                while ($r = $itemRes->fetch_assoc()) {
                    $sql .= backup_insert_sql('sale_items', $r, $db);
                }
                $sql .= "\n";
            }
        }
    }

    if (backup_table_exists($db, 'invoice_items') && backup_table_exists($db, 'invoices') && backup_column_exists($db, 'invoices', 'company_id')) {
        $invoiceIds = [];
        $invRes = $db->query("SELECT id FROM `invoices` WHERE company_id=" . (int)$cid);
        if ($invRes) {
            while ($s = $invRes->fetch_assoc()) {
                $invoiceIds[] = (int)$s['id'];
            }
        }

        if ($invoiceIds) {
            $idList = implode(',', $invoiceIds);
            $itemRes = $db->query("SELECT * FROM `invoice_items` WHERE invoice_id IN ($idList)");
            if ($itemRes) {
                while ($r = $itemRes->fetch_assoc()) {
                    $sql .= backup_insert_sql('invoice_items', $r, $db);
                }
                $sql .= "\n";
            }
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

function full_platform_backup_sql(): string {
    $db = db();

    $tables = [];
    $res = $db->query("SHOW TABLES");
    while ($row = $res->fetch_array()) {
        $tables[] = $row[0];
    }

    $sql = "-- NO9 Platform Full Backup\n";
    $sql .= "-- Generated At: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $createRow = $db->query("SHOW CREATE TABLE `$table`")->fetch_assoc();
        $create = $createRow['Create Table'] ?? '';

        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $create . ";\n\n";

        $rows = $db->query("SELECT * FROM `$table`");
        if ($rows) {
            while ($r = $rows->fetch_assoc()) {
                $sql .= backup_insert_sql($table, $r, $db);
            }
        }

        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

/* ================= DOWNLOAD BACKUP ================= */
/* IMPORTANT: this must run BEFORE header.php outputs anything */
if (isset($_GET['download'])) {
    if (is_super_admin()) {
        $sql = full_platform_backup_sql();
        $file = 'no9_platform_backup_' . date('Ymd_His') . '.sql';
    } else {
        $cid = (int)current_company_id();
        $sql = company_backup_sql($cid);
        $file = 'company_backup_' . $cid . '_' . date('Ymd_His') . '.sql';
    }

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($sql));

    echo $sql;
    exit;
}

$pageTitle = 'Backup & Restore';
require_once __DIR__ . '/includes/header.php';

/* ================= RESTORE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!empty($_FILES['restore_file']['tmp_name'])) {
        $sql = file_get_contents($_FILES['restore_file']['tmp_name']);

        if (!$sql) {
            flash('error', 'Restore file is empty or invalid.');
            redirect('backup.php');
        }

        if (is_super_admin()) {
            mysqli_report(MYSQLI_REPORT_OFF);
            $db->multi_query($sql);
            while ($db->more_results() && $db->next_result()) {}
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            flash('success', 'Platform restore completed.');
        } elseif (can_company_restore()) {
            mysqli_report(MYSQLI_REPORT_OFF);
            $db->multi_query($sql);
            while ($db->more_results() && $db->next_result()) {}
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            flash('success', 'Company restore completed.');
        } else {
            flash('error', 'Only admins can restore backups.');
        }

        redirect('backup.php');
    }
}
?>

<style>
:root{
    --bk-shadow:0 14px 32px rgba(15,23,42,.07);
    --bk-shadow-lg:0 22px 50px rgba(15,23,42,.12);
    --bk-line:#e2e8f0;
}
.backup-shell{display:grid;gap:22px}
.hero-card{
    border:0;
    border-radius:28px;
    color:#fff;
    padding:30px;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.14), transparent 20%),
        radial-gradient(circle at bottom left, rgba(16,185,129,.15), transparent 25%),
        linear-gradient(135deg,#0f172a 0%, #1d4ed8 48%, #0f766e 100%);
    box-shadow:var(--bk-shadow-lg);
}
.hero-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.18);
    font-size:.83rem;
    font-weight:700;
}
.hero-card h2{
    margin:14px 0 8px;
    font-weight:800;
    letter-spacing:-.02em;
}
.hero-card p{
    margin:0;
    color:rgba(255,255,255,.85);
    max-width:780px;
}
.hero-mini{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    margin-top:18px;
}
.hero-mini .box{
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.16);
    border-radius:18px;
    padding:14px 16px;
    min-width:180px;
}
.hero-mini .box small{
    display:block;
    color:rgba(255,255,255,.78);
}
.hero-mini .box strong{
    font-size:1.05rem;
    font-weight:800;
}
.soft-card{
    border:1px solid var(--bk-line);
    border-radius:24px;
    background:#fff;
    box-shadow:var(--bk-shadow);
    height:100%;
}
.section-title{
    font-weight:800;
    color:#0f172a;
    margin-bottom:4px;
}
.section-sub{
    color:#64748b;
    font-size:.92rem;
    margin-bottom:16px;
}
.info-list{
    display:grid;
    gap:10px;
}
.info-item{
    border:1px solid #e2e8f0;
    border-radius:16px;
    background:#f8fafc;
    padding:12px 14px;
    color:#334155;
}
.btn-modern{
    border-radius:14px;
    padding:12px 16px;
    font-weight:700;
}
</style>

<div class="container-fluid py-4 backup-shell">

    <div class="hero-card">
        <span class="hero-badge"><i class="bi bi-database-check"></i> Backup & Restore Center</span>
        <h2>Modern Backup Dashboard</h2>
        <p>
            Super Admin wuxuu backup iyo restore ka sameyn karaa SaaS-ka oo dhan.
            Company Admin wuxuu backup iyo restore ka sameyn karaa company-giisa oo keliya.
            Users caadi ah restore ma sameyn karaan.
        </p>

        <div class="hero-mini">
            <div class="box">
                <small>Access Level</small>
                <strong>
                    <?php
                    if (is_super_admin()) echo 'SaaS Super Admin';
                    elseif (can_company_restore()) echo 'Company Admin';
                    else echo 'Standard User';
                    ?>
                </strong>
            </div>
            <div class="box">
                <small>Backup Scope</small>
                <strong><?php echo is_super_admin() ? 'Full Platform' : 'Current Company'; ?></strong>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="soft-card p-4">
                <h5 class="section-title"><?php echo is_super_admin() ? 'Backup Full Platform' : 'Backup Company Data'; ?></h5>
                <div class="section-sub">
                    <?php echo is_super_admin()
                        ? 'Create a full SQL backup for the whole SaaS platform.'
                        : 'Create a SQL backup for the current company only.'; ?>
                </div>

                <div class="info-list mb-4">
                    <div class="info-item">Branches, users, customers, products, expenses, sales, quotations, and related records are included.</div>
                    <div class="info-item">Tables without company_id are handled safely.</div>
                    <div class="info-item">Sale items and invoice items are included through their parent documents.</div>
                </div>

                <a href="?download=1" class="btn btn-primary btn-modern">
                    <i class="bi bi-download me-1"></i> Download Backup
                </a>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="soft-card p-4">
                <h5 class="section-title">Restore SQL Backup</h5>
                <div class="section-sub">
                    Upload a valid .sql file to restore data.
                </div>

                <?php if (is_super_admin() || can_company_restore()): ?>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <div class="mb-3">
                            <input type="file" class="form-control" name="restore_file" accept=".sql" required>
                        </div>
                        <button class="btn btn-danger btn-modern">
                            <i class="bi bi-upload me-1"></i> Restore SQL
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info mb-0">Only admins can restore backups.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>