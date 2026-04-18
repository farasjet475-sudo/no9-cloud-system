<?php
$pageTitle = 'Journal Entries';
require_once __DIR__ . '/includes/finance_page_bootstrap.php';

$stmt = $conn->prepare("SELECT je.*, COUNT(jel.id) AS lines,
                               COALESCE(SUM(jel.debit),0) AS total_debit,
                               COALESCE(SUM(jel.credit),0) AS total_credit
                        FROM journal_entries je
                        LEFT JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
                        WHERE je.entry_date BETWEEN ? AND ?
                        GROUP BY je.id
                        ORDER BY je.entry_date DESC, je.id DESC");
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$res = $stmt->get_result();
?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-white"><strong>Journal Entries</strong></div>
    <div class="card-body table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead><tr><th>Date</th><th>Reference</th><th>Memo</th><th>Source</th><th>Lines</th><th>Debit</th><th>Credit</th></tr></thead>
            <tbody>
                <?php while ($row = $res->fetch_assoc()): ?>
                <tr>
                    <td><?php echo finance_escape($row['entry_date']); ?></td>
                    <td><?php echo finance_escape($row['reference_no'] ?? '-'); ?></td>
                    <td><?php echo finance_escape($row['memo'] ?? '-'); ?></td>
                    <td><?php echo finance_escape($row['source_module'] ?? '-'); ?></td>
                    <td><?php echo (int)$row['lines']; ?></td>
                    <td class="text-end"><?php echo number_format((float)$row['total_debit'], 2); ?></td>
                    <td class="text-end"><?php echo number_format((float)$row['total_credit'], 2); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$stmt->close();
include __DIR__ . '/includes/footer.php';
?>
