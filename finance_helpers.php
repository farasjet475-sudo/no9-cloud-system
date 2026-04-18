<?php
require_once __DIR__ . '/../config/config.php';

if (!function_exists('finance_escape')) {
    function finance_escape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('finance_fetch_value')) {
    function finance_fetch_value(mysqli $conn, string $sql, string $types = '', array $params = [], $default = 0) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $default;
        }
        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_row() : null;
        $stmt->close();
        return $row && isset($row[0]) && $row[0] !== null ? $row[0] : $default;
    }
}

if (!function_exists('finance_account_id')) {
    function finance_account_id(mysqli $conn, string $code): ?int {
        $stmt = $conn->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('finance_create_journal_entry')) {
    function finance_create_journal_entry(mysqli $conn, array $entry, array $lines): int {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO journal_entries
                (entry_date, reference_no, memo, source_module, source_id, branch_id, company_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $sourceId = $entry['source_id'] ?? null;
            $branchId = $entry['branch_id'] ?? null;
            $companyId = $entry['company_id'] ?? null;
            $createdBy = $entry['created_by'] ?? null;
            $stmt->bind_param(
                'ssssiiii',
                $entry['entry_date'],
                $entry['reference_no'],
                $entry['memo'],
                $entry['source_module'],
                $sourceId,
                $branchId,
                $companyId,
                $createdBy
            );
            $stmt->execute();
            $journalId = (int)$conn->insert_id;
            $stmt->close();

            $lineStmt = $conn->prepare("INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit, credit, line_description)
                VALUES (?, ?, ?, ?, ?)");

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($lines as $line) {
                $debit = (float)($line['debit'] ?? 0);
                $credit = (float)($line['credit'] ?? 0);
                $lineStmt->bind_param('iidds', $journalId, $line['account_id'], $debit, $credit, $line['line_description']);
                $lineStmt->execute();
                $totalDebit += $debit;
                $totalCredit += $credit;
            }
            $lineStmt->close();

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new Exception('Journal entry is not balanced.');
            }

            $conn->commit();
            return $journalId;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('finance_post_sale')) {
    function finance_post_sale(mysqli $conn, array $sale): int {
        $cashAccount = finance_account_id($conn, '1000');
        $salesAccount = finance_account_id($conn, '4000');
        $cogsAccount = finance_account_id($conn, '5000');
        $inventoryAccount = finance_account_id($conn, '1200');

        $gross = (float)$sale['gross_amount'];
        $cost = (float)$sale['cost_amount'];

        return finance_create_journal_entry($conn, [
            'entry_date' => $sale['entry_date'],
            'reference_no' => $sale['reference_no'] ?? null,
            'memo' => $sale['memo'] ?? 'POS sale posted automatically',
            'source_module' => 'sales',
            'source_id' => $sale['source_id'] ?? null,
            'branch_id' => $sale['branch_id'] ?? null,
            'company_id' => $sale['company_id'] ?? null,
            'created_by' => $sale['created_by'] ?? null,
        ], [
            [
                'account_id' => $cashAccount,
                'debit' => $gross,
                'credit' => 0,
                'line_description' => 'Cash received from sale',
            ],
            [
                'account_id' => $salesAccount,
                'debit' => 0,
                'credit' => $gross,
                'line_description' => 'Sales revenue',
            ],
            [
                'account_id' => $cogsAccount,
                'debit' => $cost,
                'credit' => 0,
                'line_description' => 'Cost of goods sold',
            ],
            [
                'account_id' => $inventoryAccount,
                'debit' => 0,
                'credit' => $cost,
                'line_description' => 'Inventory reduced',
            ],
        ]);
    }
}

if (!function_exists('finance_post_expense')) {
    function finance_post_expense(mysqli $conn, array $expense): int {
        $cashAccount = finance_account_id($conn, '1000');
        $expenseAccount = $expense['expense_account_id'] ?? finance_account_id($conn, '6000');

        return finance_create_journal_entry($conn, [
            'entry_date' => $expense['entry_date'],
            'reference_no' => $expense['reference_no'] ?? null,
            'memo' => $expense['memo'] ?? 'Expense posted automatically',
            'source_module' => 'expenses',
            'source_id' => $expense['source_id'] ?? null,
            'branch_id' => $expense['branch_id'] ?? null,
            'company_id' => $expense['company_id'] ?? null,
            'created_by' => $expense['created_by'] ?? null,
        ], [
            [
                'account_id' => $expenseAccount,
                'debit' => (float)$expense['amount'],
                'credit' => 0,
                'line_description' => 'Expense debit',
            ],
            [
                'account_id' => $cashAccount,
                'debit' => 0,
                'credit' => (float)$expense['amount'],
                'line_description' => 'Cash paid',
            ],
        ]);
    }
}

if (!function_exists('finance_trial_balance')) {
    function finance_trial_balance(mysqli $conn, ?string $dateFrom = null, ?string $dateTo = null): array {
        $where = ' WHERE 1=1 ';
        $types = '';
        $params = [];
        if ($dateFrom) {
            $where .= ' AND je.entry_date >= ?';
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $where .= ' AND je.entry_date <= ?';
            $types .= 's';
            $params[] = $dateTo;
        }

        $sql = "SELECT coa.id, coa.code, coa.name, coa.type,
                       SUM(jel.debit) AS total_debit,
                       SUM(jel.credit) AS total_credit
                FROM chart_of_accounts coa
                LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
                LEFT JOIN journal_entries je ON je.id = jel.journal_entry_id
                $where
                GROUP BY coa.id, coa.code, coa.name, coa.type
                ORDER BY coa.code ASC";

        $stmt = $conn->prepare($sql);
        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $row['total_debit'] = (float)($row['total_debit'] ?? 0);
            $row['total_credit'] = (float)($row['total_credit'] ?? 0);
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('finance_net_balance')) {
    function finance_net_balance(array $row): float {
        $normalDebitTypes = ['asset', 'expense', 'cogs'];
        if (in_array($row['type'], $normalDebitTypes, true)) {
            return (float)$row['total_debit'] - (float)$row['total_credit'];
        }
        return (float)$row['total_credit'] - (float)$row['total_debit'];
    }
}
