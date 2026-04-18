<?php
$pageTitle = 'Activity Logs';
require_once __DIR__ . '/includes/header.php';

$onlineMinutes = 10;
$limitRows = 300;

$selectedCompanyId = (int)($_GET['company_id'] ?? 0);
$selectedUserId    = (int)($_GET['user_id'] ?? 0);

function badge_html($text, $type = 'secondary'){
    $map = [
        'success'   => 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;',
        'danger'    => 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;',
        'primary'   => 'background:#dbeafe;color:#1d4ed8;border:1px solid #bfdbfe;',
        'warning'   => 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;',
        'secondary' => 'background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;',
    ];
    $style = $map[$type] ?? $map['secondary'];
    return '<span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;'.$style.'">'.e($text).'</span>';
}

$companyOptions = [];
$activeUserOptions = [];
$rows = [];

if (is_super_admin()) {
    $companySql = "
        SELECT
            c.id,
            c.name,
            COUNT(DISTINCT CASE
                WHEN a.created_at >= (NOW() - INTERVAL {$onlineMinutes} MINUTE) THEN a.user_id
                ELSE NULL
            END) AS active_users,
            MAX(a.created_at) AS last_activity
        FROM companies c
        LEFT JOIN activity_logs a
            ON a.company_id = c.id
        WHERE c.id <> 1
        GROUP BY c.id, c.name
        ORDER BY c.name ASC
    ";
    $companyOptions = db()->query($companySql)->fetch_all(MYSQLI_ASSOC);

    $userWhere = [];
    if ($selectedCompanyId > 0) {
        $userWhere[] = "a.company_id = " . $selectedCompanyId;
    }

    $userWhereSql = $userWhere ? ('WHERE ' . implode(' AND ', $userWhere)) : '';

    $activeUsersSql = "
        SELECT
            u.id,
            u.full_name,
            u.username,
            c.name AS company_name,
            MAX(a.created_at) AS last_activity
        FROM activity_logs a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN companies c ON c.id = a.company_id
        $userWhereSql
        GROUP BY u.id, u.full_name, u.username, c.name
        HAVING MAX(a.created_at) >= (NOW() - INTERVAL {$onlineMinutes} MINUTE)
        ORDER BY u.full_name ASC
    ";
    $activeUserOptions = db()->query($activeUsersSql)->fetch_all(MYSQLI_ASSOC);

    $where = [];
    if ($selectedCompanyId > 0) {
        $where[] = "a.company_id = " . $selectedCompanyId;
    }
    if ($selectedUserId > 0) {
        $where[] = "a.user_id = " . $selectedUserId;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rowsSql = "
        SELECT
            a.*,
            u.full_name,
            u.username,
            c.name AS company_name,
            CASE
                WHEN recent.last_activity >= (NOW() - INTERVAL {$onlineMinutes} MINUTE) THEN 1
                ELSE 0
            END AS user_online,
            recent.last_activity,
            comp.active_users AS company_active_users
        FROM activity_logs a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN companies c ON c.id = a.company_id
        LEFT JOIN (
            SELECT user_id, MAX(created_at) AS last_activity
            FROM activity_logs
            WHERE user_id IS NOT NULL
            GROUP BY user_id
        ) recent ON recent.user_id = a.user_id
        LEFT JOIN (
            SELECT
                company_id,
                COUNT(DISTINCT CASE
                    WHEN created_at >= (NOW() - INTERVAL {$onlineMinutes} MINUTE) THEN user_id
                    ELSE NULL
                END) AS active_users
            FROM activity_logs
            GROUP BY company_id
        ) comp ON comp.company_id = a.company_id
        $whereSql
        ORDER BY a.id DESC
        LIMIT {$limitRows}
    ";
    $rows = db()->query($rowsSql)->fetch_all(MYSQLI_ASSOC);

} else {
    $cid = current_company_id();

    $activeUsersSql = "
        SELECT
            u.id,
            u.full_name,
            u.username,
            MAX(a.created_at) AS last_activity
        FROM activity_logs a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.company_id = {$cid}
        GROUP BY u.id, u.full_name, u.username
        HAVING MAX(a.created_at) >= (NOW() - INTERVAL {$onlineMinutes} MINUTE)
        ORDER BY u.full_name ASC
    ";
    $activeUserOptions = db()->query($activeUsersSql)->fetch_all(MYSQLI_ASSOC);

    $where = ["a.company_id = {$cid}"];
    if ($selectedUserId > 0) {
        $where[] = "a.user_id = " . $selectedUserId;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $rowsSql = "
        SELECT
            a.*,
            u.full_name,
            u.username,
            CASE
                WHEN recent.last_activity >= (NOW() - INTERVAL {$onlineMinutes} MINUTE) THEN 1
                ELSE 0
            END AS user_online,
            recent.last_activity
        FROM activity_logs a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN (
            SELECT user_id, MAX(created_at) AS last_activity
            FROM activity_logs
            WHERE company_id = {$cid} AND user_id IS NOT NULL
            GROUP BY user_id
        ) recent ON recent.user_id = a.user_id
        $whereSql
        ORDER BY a.id DESC
        LIMIT {$limitRows}
    ";
    $rows = db()->query($rowsSql)->fetch_all(MYSQLI_ASSOC);
}

$totalOnlineCompanies = 0;
$totalOnlineUsers = count($activeUserOptions);

if (is_super_admin()) {
    foreach ($companyOptions as $co) {
        if ((int)$co['active_users'] > 0) {
            $totalOnlineCompanies++;
        }
    }
}
?>

<style>
.activity-shell{
    display:grid;
    gap:18px;
}
.activity-top{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:14px;
}
.activity-stat{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:18px;
    padding:18px;
    box-shadow:0 8px 22px rgba(15,23,42,.05);
}
.activity-stat small{
    color:#64748b;
    display:block;
    margin-bottom:6px;
    font-weight:600;
}
.activity-stat strong{
    font-size:26px;
    color:#0f172a;
}
.activity-card{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:20px;
    padding:20px;
    box-shadow:0 8px 22px rgba(15,23,42,.05);
}
.filters-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}
.filters-grid .form-label{
    font-weight:700;
    color:#334155;
    font-size:13px;
}
.filters-grid .form-select,
.filters-grid .form-control{
    border-radius:12px;
    min-height:44px;
}
.table thead th{
    white-space:nowrap;
}
.online-dot{
    display:inline-block;
    width:10px;
    height:10px;
    border-radius:50%;
    margin-right:6px;
}
.dot-online{ background:#22c55e; }
.dot-offline{ background:#ef4444; }
@media (max-width: 992px){
    .activity-top{grid-template-columns:1fr}
    .filters-grid{grid-template-columns:1fr 1fr}
}
@media (max-width: 576px){
    .filters-grid{grid-template-columns:1fr}
}
</style>

<div class="activity-shell">
    <div class="activity-top">
        <?php if (is_super_admin()): ?>
        <div class="activity-stat">
            <small>Companies Online</small>
            <strong><?php echo (int)$totalOnlineCompanies; ?></strong>
        </div>
        <?php endif; ?>

        <div class="activity-stat">
            <small>Users Active Now</small>
            <strong><?php echo (int)$totalOnlineUsers; ?></strong>
        </div>

        <div class="activity-stat">
            <small>Online Window</small>
            <strong><?php echo (int)$onlineMinutes; ?> min</strong>
        </div>
    </div>

    <div class="activity-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
            <div>
                <h5 class="mb-1">Activity Logs & Online Status</h5>
                <div class="text-muted small">
                    Online waxaa loola jeedaa user/company activity sameeyay <?php echo (int)$onlineMinutes; ?> daqiiqo ee u dambeysay.
                </div>
            </div>
        </div>

        <form method="get" class="filters-grid mb-4">
            <?php if (is_super_admin()): ?>
            <div>
                <label class="form-label">Company</label>
                <select name="company_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">All Companies</option>
                    <?php foreach ($companyOptions as $co): ?>
                        <option value="<?php echo (int)$co['id']; ?>" <?php echo $selectedCompanyId === (int)$co['id'] ? 'selected' : ''; ?>>
                            <?php echo e($co['name']); ?> (<?php echo (int)$co['active_users']; ?> active)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label class="form-label">Active User</label>
                <select name="user_id" class="form-select">
                    <option value="0">All Users</option>
                    <?php foreach ($activeUserOptions as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>" <?php echo $selectedUserId === (int)$u['id'] ? 'selected' : ''; ?>>
                            <?php echo e($u['full_name'] ?: ($u['username'] ?? 'Unknown User')); ?>
                            <?php if (is_super_admin() && !empty($u['company_name'])): ?>
                                - <?php echo e($u['company_name']); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="form-label">Status Info</label>
                <input type="text" class="form-control" value="Showing recent active users" readonly>
            </div>

            <div class="d-flex align-items-end gap-2">
                <button class="btn btn-primary w-100">Filter</button>
                <a href="activity_logs.php" class="btn btn-light w-100">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>User</th>
                        <th>Status</th>
                        <?php if (is_super_admin()): ?>
                            <th>Company</th>
                            <th>Company Online</th>
                        <?php endif; ?>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?php echo is_super_admin() ? 8 : 6; ?>" class="text-center text-muted py-4">
                            No activity logs found.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach($rows as $r): ?>
                    <tr>
                        <td><?php echo e($r['created_at']); ?></td>

                        <td>
                            <div class="fw-semibold"><?php echo e($r['full_name'] ?? 'System'); ?></div>
                            <?php if (!empty($r['username'])): ?>
                                <div class="small text-muted">@<?php echo e($r['username']); ?></div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php
                                echo !empty($r['user_online'])
                                    ? badge_html('Online', 'success')
                                    : badge_html('Offline', 'danger');
                            ?>
                        </td>

                        <?php if(is_super_admin()): ?>
                            <td><?php echo e($r['company_name'] ?? ''); ?></td>
                            <td>
                                <?php
                                    echo ((int)($r['company_active_users'] ?? 0) > 0)
                                        ? badge_html('Company Online', 'primary')
                                        : badge_html('Company Offline', 'secondary');
                                ?>
                            </td>
                        <?php endif; ?>

                        <td><?php echo e($r['action']); ?></td>
                        <td><?php echo e($r['entity']); ?></td>
                        <td><?php echo e($r['notes']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>