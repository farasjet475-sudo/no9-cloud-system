<?php
/**
 * Integration examples for existing modules.
 * Copy the matching part into your original files after sale/expense/payment save succeeds.
 */

require_once __DIR__ . '/../includes/finance_helpers.php';

// 1) After a successful POS sale save in sales.php
finance_post_sale($conn, [
    'entry_date'   => date('Y-m-d'),
    'reference_no' => 'SALE-' . $saleId,
    'memo'         => 'Automatic posting from POS sale',
    'source_id'    => $saleId,
    'branch_id'    => $_SESSION['branch_id'] ?? null,
    'company_id'   => $_SESSION['company_id'] ?? null,
    'created_by'   => $_SESSION['user']['id'] ?? null,
    'gross_amount' => $grandTotal,
    'cost_amount'  => $totalCost,
]);

// 2) After a successful expense save in expenses.php
finance_post_expense($conn, [
    'entry_date'         => date('Y-m-d'),
    'reference_no'       => 'EXP-' . $expenseId,
    'memo'               => 'Automatic posting from expense',
    'source_id'          => $expenseId,
    'branch_id'          => $_SESSION['branch_id'] ?? null,
    'company_id'         => $_SESSION['company_id'] ?? null,
    'created_by'         => $_SESSION['user']['id'] ?? null,
    'amount'             => $amount,
    'expense_account_id' => finance_account_id($conn, '6000'),
]);
