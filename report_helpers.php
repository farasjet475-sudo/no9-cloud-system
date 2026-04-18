<?php

if (!function_exists('table_exists')) {
    function table_exists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $rs = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $rs && $rs->num_rows > 0;
    }
}

if (!function_exists('finance_date_range')) {
    function finance_date_range(): array
    {
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to'] ?? date('Y-m-d');
        return [$from, $to];
    }
}

if (!function_exists('sum_journal_by_account_codes')) {
    function sum_journal_by_account_codes(mysqli $conn, array $codes, string $from, string $to, ?int $companyId = null, ?int $branchId = null): float
    {
        if (!table_exists($conn, 'journal_entries') || !table_exists($conn, 'journal_entry_lines') || !table_exists($conn, 'accounts')) {
            return 0.0;
        }

        $safeCodes = [];
        foreach ($codes as $code) {
            $safeCodes[] = "'" . $conn->real_escape_string($code) . "'";
        }
        $companyFilter = $companyId ? " AND je.company_id=".(int)$companyId : "";
        $branchFilter = $branchId ? " AND je.branch_id=".(int)$branchId : "";
        $sql = "
            SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount),0) total
            FROM journal_entry_lines jel
            INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
            INNER JOIN accounts a ON a.id = jel.account_id
            WHERE a.code IN (".implode(',', $safeCodes).")
              AND je.entry_date >= '".$conn->real_escape_string($from)."'
              AND je.entry_date <= '".$conn->real_escape_string($to)."'
              {$companyFilter}
              {$branchFilter}
        ";
        $row = $conn->query($sql)->fetch_assoc();
        return (float)($row['total'] ?? 0);
    }
}

if (!function_exists('sales_summary_fallback')) {
    function sales_summary_fallback(mysqli $conn, string $from, string $to, int $companyId, int $branchId): array
    {
        $safeFrom = $conn->real_escape_string($from);
        $safeTo = $conn->real_escape_string($to);

        $sales = 0.0;
        if (table_exists($conn, 'sales')) {
            $row = $conn->query("SELECT COALESCE(SUM(total_amount),0) total FROM sales WHERE company_id=$companyId AND branch_id=$branchId AND sale_date>='$safeFrom' AND sale_date<='$safeTo'")->fetch_assoc();
            $sales += (float)($row['total'] ?? 0);
        }
        if (table_exists($conn, 'invoices')) {
            $row = $conn->query("SELECT COALESCE(SUM(total_amount),0) total FROM invoices WHERE company_id=$companyId AND branch_id=$branchId AND invoice_date>='$safeFrom' AND invoice_date<='$safeTo'")->fetch_assoc();
            $sales += (float)($row['total'] ?? 0);
        }

        $expenses = 0.0;
        if (table_exists($conn, 'expenses')) {
            $row = $conn->query("SELECT COALESCE(SUM(amount),0) total FROM expenses WHERE company_id=$companyId AND branch_id=$branchId AND expense_date>='$safeFrom' AND expense_date<='$safeTo'")->fetch_assoc();
            $expenses = (float)($row['total'] ?? 0);
        }

        $cogs = 0.0;
        if (table_exists($conn, 'sale_items') && table_exists($conn, 'products') && table_exists($conn, 'sales')) {
            $sql = "SELECT COALESCE(SUM(si.qty * COALESCE(p.cost_price,0)),0) total
                    FROM sale_items si
                    INNER JOIN sales s ON s.id = si.sale_id
                    INNER JOIN products p ON p.id = si.product_id
                    WHERE s.company_id=$companyId AND s.branch_id=$branchId
                      AND s.sale_date>='$safeFrom' AND s.sale_date<='$safeTo'";
            $row = $conn->query($sql)->fetch_assoc();
            $cogs += (float)($row['total'] ?? 0);
        }
        if (table_exists($conn, 'invoice_items') && table_exists($conn, 'invoices')) {
            $sql = "SELECT COALESCE(SUM(ii.qty * ii.buying_price),0) total
                    FROM invoice_items ii
                    INNER JOIN invoices i ON i.id = ii.invoice_id
                    WHERE i.company_id=$companyId AND i.branch_id=$branchId
                      AND i.invoice_date>='$safeFrom' AND i.invoice_date<='$safeTo'";
            $row = $conn->query($sql)->fetch_assoc();
            $cogs += (float)($row['total'] ?? 0);
        }

        return [
            'sales' => $sales,
            'expenses' => $expenses,
            'cogs' => $cogs,
            'gross_profit' => $sales - $cogs,
            'net_profit' => $sales - $cogs - $expenses,
        ];
    }
}

if (!function_exists('inventory_totals')) {
    function inventory_totals(mysqli $conn, int $companyId): array
    {
        $totals = [
            'product_count' => 0,
            'stock_qty' => 0,
            'inventory_value' => 0,
            'low_stock_count' => 0,
            'out_of_stock_count' => 0,
        ];

        if (!table_exists($conn, 'products')) {
            return $totals;
        }

        $row = $conn->query("SELECT 
                COUNT(*) product_count,
                COALESCE(SUM(stock_qty),0) stock_qty,
                COALESCE(SUM(stock_qty * COALESCE(cost_price,0)),0) inventory_value,
                COALESCE(SUM(CASE WHEN stock_qty <= COALESCE(reorder_level,0) AND stock_qty > 0 THEN 1 ELSE 0 END),0) low_stock_count,
                COALESCE(SUM(CASE WHEN stock_qty <= 0 THEN 1 ELSE 0 END),0) out_of_stock_count
            FROM products
            WHERE company_id=".(int)$companyId)->fetch_assoc();

        foreach ($totals as $k => $v) {
            $totals[$k] = (float)($row[$k] ?? 0);
        }
        $totals['product_count'] = (int)$totals['product_count'];
        $totals['low_stock_count'] = (int)$totals['low_stock_count'];
        $totals['out_of_stock_count'] = (int)$totals['out_of_stock_count'];
        return $totals;
    }
}
