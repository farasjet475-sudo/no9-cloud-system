<?php

if (!function_exists('fs_table_exists')) {
    function fs_table_exists(mysqli $db, string $table): bool {
        $table = $db->real_escape_string($table);
        $q = $db->query("SHOW TABLES LIKE '$table'");
        return $q && $q->num_rows > 0;
    }
}

if (!function_exists('fs_column_exists')) {
    function fs_column_exists(mysqli $db, string $table, string $column): bool {
        $table = $db->real_escape_string($table);
        $column = $db->real_escape_string($column);
        $q = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $q && $q->num_rows > 0;
    }
}

if (!function_exists('fs_value')) {
    function fs_value(string $sql): float {
        $row = query_one($sql);
        return (float)(array_values($row)[0] ?? 0);
    }
}

if (!function_exists('fs_date_where')) {
    function fs_date_where(string $field, ?string $from = null, ?string $to = null): string {
        $db = db();
        $parts = [];

        if (!empty($from)) {
            $parts[] = "$field >= '" . $db->real_escape_string($from) . "'";
        }
        if (!empty($to)) {
            $parts[] = "$field <= '" . $db->real_escape_string($to) . "'";
        }

        return $parts ? (' AND ' . implode(' AND ', $parts)) : '';
    }
}

if (!function_exists('fs_income_statement')) {
    function fs_income_statement(int $companyId, ?int $branchId = null, ?string $from = null, ?string $to = null): array {
        $branchWhereSales = $branchId ? " AND s.branch_id=".(int)$branchId : "";
        $branchWhereExp   = $branchId ? " AND branch_id=".(int)$branchId : "";

        $sales = fs_value("
            SELECT COALESCE(SUM(s.total_amount),0)
            FROM sales s
            WHERE s.company_id=".(int)$companyId."
            $branchWhereSales
            ".fs_date_where('s.sale_date', $from, $to)."
        ");

        $cogs = fs_value("
            SELECT COALESCE(SUM(si.qty * COALESCE(p.cost_price,0)),0)
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.company_id=".(int)$companyId."
            $branchWhereSales
            ".fs_date_where('s.sale_date', $from, $to)."
        ");

        $grossProfit = $sales - $cogs;

        $expenses = fs_value("
            SELECT COALESCE(SUM(amount),0)
            FROM expenses
            WHERE company_id=".(int)$companyId."
            $branchWhereExp
            ".fs_date_where('expense_date', $from, $to)."
        ");

        $netProfit = $grossProfit - $expenses;

        return [
            'sales_revenue' => $sales,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $expenses,
            'net_profit' => $netProfit,
        ];
    }
}

if (!function_exists('fs_balance_sheet')) {
    function fs_balance_sheet(int $companyId, ?int $branchId = null): array {
        $db = db();

        $branchWhereProducts = $branchId ? " AND branch_id=".(int)$branchId : "";
        $branchWhereInvoices = $branchId ? " AND branch_id=".(int)$branchId : "";
        $branchWhereExp      = $branchId ? " AND branch_id=".(int)$branchId : "";

        $inventory = fs_value("
            SELECT COALESCE(SUM(stock_qty * COALESCE(cost_price,0)),0)
            FROM products
            WHERE company_id=".(int)$companyId."
            $branchWhereProducts
        ");

        $receivables = 0;
        if (fs_table_exists($db, 'invoices') && fs_column_exists($db, 'invoices', 'due_amount')) {
            $receivables = fs_value("
                SELECT COALESCE(SUM(due_amount),0)
                FROM invoices
                WHERE company_id=".(int)$companyId."
                $branchWhereInvoices
            ");
        }

        $cash = 0;
        if (fs_table_exists($db, 'invoice_payments')) {
            $cash += fs_value("
                SELECT COALESCE(SUM(amount),0)
                FROM invoice_payments
                WHERE company_id=".(int)$companyId."
                ".($branchId ? " AND branch_id=".(int)$branchId : "")."
            ");
        }

        $cash += fs_value("
            SELECT COALESCE(SUM(total_amount),0)
            FROM sales
            WHERE company_id=".(int)$companyId."
            ".($branchId ? " AND branch_id=".(int)$branchId : "")."
        ");

        $bank = 0;

        $equipment = 0;

        $assetsTotal = $cash + $bank + $inventory + $equipment + $receivables;

        $unpaidExpenses = 0;
        if (fs_column_exists($db, 'expenses', 'status')) {
            $unpaidExpenses = fs_value("
                SELECT COALESCE(SUM(amount),0)
                FROM expenses
                WHERE company_id=".(int)$companyId."
                $branchWhereExp
                AND status='unpaid'
            ");
        }

        $loans = 0;
        if (fs_table_exists($db, 'owner_transactions') && fs_column_exists($db, 'owner_transactions', 'transaction_type')) {
            $loans = fs_value("
                SELECT COALESCE(SUM(amount),0)
                FROM owner_transactions
                WHERE company_id=".(int)$companyId."
                ".($branchId ? " AND branch_id=".(int)$branchId : "")."
                AND transaction_type='loan'
            ");
        }

        $payables = $unpaidExpenses;

        $liabilitiesTotal = $loans + $payables + $unpaidExpenses;

        $income = fs_income_statement($companyId, $branchId);
        $retainedEarnings = (float)$income['net_profit'];

        $ownerCapital = 0;
        if (fs_table_exists($db, 'owner_transactions') && fs_column_exists($db, 'owner_transactions', 'transaction_type')) {
            $ownerCapital = fs_value("
                SELECT COALESCE(SUM(amount),0)
                FROM owner_transactions
                WHERE company_id=".(int)$companyId."
                ".($branchId ? " AND branch_id=".(int)$branchId : "")."
                AND transaction_type='capital'
            ");
        }

        $equityTotal = $ownerCapital + $retainedEarnings;

        return [
            'assets' => [
                'cash' => $cash,
                'bank' => $bank,
                'inventory' => $inventory,
                'equipment' => $equipment,
                'receivables' => $receivables,
                'total' => $assetsTotal,
            ],
            'liabilities' => [
                'loans' => $loans,
                'payables' => $payables,
                'unpaid_expenses' => $unpaidExpenses,
                'total' => $liabilitiesTotal,
            ],
            'equity' => [
                'owner_capital' => $ownerCapital,
                'retained_earnings' => $retainedEarnings,
                'total' => $equityTotal,
            ],
            'formula_total' => $liabilitiesTotal + $equityTotal,
        ];
    }
}

if (!function_exists('fs_cash_flow_statement')) {
    function fs_cash_flow_statement(int $companyId, ?int $branchId = null, ?string $from = null, ?string $to = null): array {
        $salesInflows = fs_value("
            SELECT COALESCE(SUM(total_amount),0)
            FROM sales
            WHERE company_id=".(int)$companyId."
            ".($branchId ? " AND branch_id=".(int)$branchId : "")."
            ".fs_date_where('sale_date', $from, $to)."
        ");

        $invoicePayments = 0;
        if (fs_table_exists(db(), 'invoice_payments')) {
            $invoicePayments = fs_value("
                SELECT COALESCE(SUM(amount),0)
                FROM invoice_payments
                WHERE company_id=".(int)$companyId."
                ".($branchId ? " AND branch_id=".(int)$branchId : "")."
                ".fs_date_where('payment_date', $from, $to)."
            ");
        }

        $otherInflows = 0;
        if (fs_table_exists(db(), 'owner_transactions') && fs_column_exists(db(), 'owner_transactions', 'transaction_type')) {
            $otherInflows = fs_value("
                SELECT COALESCE(SUM(amount),0)
                FROM owner_transactions
                WHERE company_id=".(int)$companyId."
                ".($branchId ? " AND branch_id=".(int)$branchId : "")."
                AND transaction_type IN ('capital','investment','other_income')
                ".fs_date_where('transaction_date', $from, $to)."
            ");
        }

        $cashInflows = $salesInflows + $invoicePayments + $otherInflows;

        $expensesOut = fs_value("
            SELECT COALESCE(SUM(amount),0)
            FROM expenses
            WHERE company_id=".(int)$companyId."
            ".($branchId ? " AND branch_id=".(int)$branchId : "")."
            ".fs_date_where('expense_date', $from, $to)."
        ");

        $withdrawals = 0;
        if (fs_table_exists(db(), 'owner_transactions') && fs_column_exists(db(), 'owner_transactions', 'transaction_type')) {
            $withdrawals = fs_value("
                SELECT COALESCE(SUM(amount),0)
                FROM owner_transactions
                WHERE company_id=".(int)$companyId."
                ".($branchId ? " AND branch_id=".(int)$branchId : "")."
                AND transaction_type='withdrawal'
                ".fs_date_where('transaction_date', $from, $to)."
            ");
        }

        $cashOutflows = $expensesOut + $withdrawals;

        $openingBalance = 0;
        $netCashFlow = $cashInflows - $cashOutflows;
        $closingBalance = $openingBalance + $netCashFlow;

        return [
            'cash_inflows' => [
                'sales' => $salesInflows,
                'invoice_collections' => $invoicePayments,
                'other_income_investments' => $otherInflows,
                'total' => $cashInflows,
            ],
            'cash_outflows' => [
                'expenses' => $expensesOut,
                'withdrawals' => $withdrawals,
                'total' => $cashOutflows,
            ],
            'opening_balance' => $openingBalance,
            'net_cash_flow' => $netCashFlow,
            'closing_balance' => $closingBalance,
        ];
    }
}

if (!function_exists('fs_owners_equity_statement')) {
    function fs_owners_equity_statement(int $companyId, ?int $branchId = null, ?string $from = null, ?string $to = null): array {
        $openingCapital = 0;

        $additionalInvestment = 0;
        $withdrawals = 0;

        if (fs_table_exists(db(), 'owner_transactions') && fs_column_exists(db(), 'owner_transactions', 'transaction_type')) {
            $additionalInvestment = fs_value("
                SELECT COALESCE(SUM(amount),0)
                FROM owner_transactions
                WHERE company_id=".(int)$companyId."
                ".($branchId ? " AND branch_id=".(int)$branchId : "")."
                AND transaction_type IN ('capital','investment')
                ".fs_date_where('transaction_date', $from, $to)."
            ");

            $withdrawals = fs_value("
                SELECT COALESCE(SUM(amount),0)
                FROM owner_transactions
                WHERE company_id=".(int)$companyId."
                ".($branchId ? " AND branch_id=".(int)$branchId : "")."
                AND transaction_type='withdrawal'
                ".fs_date_where('transaction_date', $from, $to)."
            ");
        }

        $income = fs_income_statement($companyId, $branchId, $from, $to);
        $netProfit = (float)$income['net_profit'];

        $endingEquity = $openingCapital + $additionalInvestment + $netProfit - $withdrawals;

        return [
            'opening_capital' => $openingCapital,
            'additional_investment' => $additionalInvestment,
            'net_profit' => $netProfit,
            'withdrawals' => $withdrawals,
            'ending_equity' => $endingEquity,
        ];
    }
}