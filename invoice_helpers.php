<?php
require_once __DIR__ . '/stock_helpers.php';

if (!function_exists('customer_balance_record')) {
    function customer_balance_record(mysqli $conn, array $data): bool
    {
        if (!function_exists('table_exists') || !table_exists($conn, 'customer_balances')) {
            return false;
        }

        $customerId    = (int)($data['customer_id'] ?? 0);
        $companyId     = (int)($data['company_id'] ?? 0);
        $branchId      = (int)($data['branch_id'] ?? 0);
        $referenceType = trim((string)($data['reference_type'] ?? 'invoice'));
        $referenceId   = (int)($data['reference_id'] ?? 0);
        $debitAmount   = (float)($data['debit_amount'] ?? 0);
        $creditAmount  = (float)($data['credit_amount'] ?? 0);
        $entryDate     = $data['entry_date'] ?? date('Y-m-d');

        $prev = 0.0;
        $q = $conn->query("SELECT balance_after FROM customer_balances WHERE customer_id={$customerId} AND company_id={$companyId} AND branch_id={$branchId} ORDER BY id DESC LIMIT 1");
        if ($q && ($row = $q->fetch_assoc())) {
            $prev = (float)$row['balance_after'];
        }
        $newBalance = $prev + $debitAmount - $creditAmount;

        $stmt = $conn->prepare("INSERT INTO customer_balances (customer_id, company_id, branch_id, reference_type, reference_id, debit_amount, credit_amount, balance_after, entry_date) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iiisiddds', $customerId, $companyId, $branchId, $referenceType, $referenceId, $debitAmount, $creditAmount, $newBalance, $entryDate);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('invoice_create_credit_sale')) {
    function invoice_create_credit_sale(mysqli $conn, array $header, array $items): array
    {
        $companyId  = (int)($header['company_id'] ?? 0);
        $branchId   = (int)($header['branch_id'] ?? 0);
        $customerId = (int)($header['customer_id'] ?? 0);
        $invoiceNo  = trim((string)($header['invoice_no'] ?? ''));
        $invoiceDate = $header['invoice_date'] ?? date('Y-m-d');
        $tax        = (float)($header['tax'] ?? 0);
        $discount   = (float)($header['discount'] ?? 0);
        $notes      = trim((string)($header['notes'] ?? ''));
        $createdBy  = isset($header['created_by']) ? (int)$header['created_by'] : null;

        if ($companyId <= 0 || $branchId <= 0 || $customerId <= 0 || $invoiceNo === '' || empty($items)) {
            return ['ok' => false, 'message' => 'Incomplete invoice header or items.'];
        }

        $subtotal = 0.0;
        $totalCost = 0.0;
        foreach ($items as $item) {
            $qty = (float)($item['qty'] ?? 0);
            $sellingPrice = (float)($item['selling_price'] ?? 0);
            $buyingPrice  = (float)($item['buying_price'] ?? 0);
            $lineDiscount = (float)($item['discount'] ?? 0);
            $lineTotal = max(0, ($qty * $sellingPrice) - $lineDiscount);
            $subtotal += $lineTotal;
            $totalCost += ($qty * $buyingPrice);
        }

        $grandTotal = max(0, $subtotal + $tax - $discount);
        $dueAmount = $grandTotal;

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO invoices (company_id, branch_id, customer_id, invoice_no, invoice_date, subtotal, tax, discount, total_amount, due_amount, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $status = 'unpaid';
            $stmt->bind_param('iiissddddsssi', $companyId, $branchId, $customerId, $invoiceNo, $invoiceDate, $subtotal, $tax, $discount, $grandTotal, $dueAmount, $notes, $status, $createdBy);
            $stmt->execute();
            $invoiceId = $stmt->insert_id;
            $stmt->close();

            foreach ($items as $item) {
                $productId     = (int)($item['product_id'] ?? 0);
                $productName   = trim((string)($item['product_name'] ?? ''));
                $qty           = (float)($item['qty'] ?? 0);
                $buyingPrice   = (float)($item['buying_price'] ?? 0);
                $sellingPrice  = (float)($item['selling_price'] ?? 0);
                $lineDiscount  = (float)($item['discount'] ?? 0);
                $lineTotal     = max(0, ($qty * $sellingPrice) - $lineDiscount);

                if ($productId <= 0 || $qty <= 0) {
                    throw new Exception('Invalid invoice item.');
                }

                $check = $conn->prepare("SELECT stock_qty FROM products WHERE id=? LIMIT 1");
                $check->bind_param('i', $productId);
                $check->execute();
                $res = $check->get_result();
                $product = $res ? $res->fetch_assoc() : null;
                $check->close();

                if (!$product || (float)$product['stock_qty'] < $qty) {
                    throw new Exception('Insufficient stock for invoice item: ' . $productName);
                }

                $stmt = $conn->prepare("INSERT INTO invoice_items (invoice_id, product_id, product_name, qty, buying_price, selling_price, discount, line_total) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iisddddd', $invoiceId, $productId, $productName, $qty, $buyingPrice, $sellingPrice, $lineDiscount, $lineTotal);
                $stmt->execute();
                $stmt->close();

                if (!stock_deduct($conn, $productId, $qty)) {
                    throw new Exception('Failed to deduct stock.');
                }

                stock_record_movement($conn, [
                    'company_id'       => $companyId,
                    'branch_id'        => $branchId,
                    'product_id'       => $productId,
                    'transaction_type' => 'INVOICE_SALE',
                    'reference_no'     => $invoiceNo,
                    'qty_in'           => 0,
                    'qty_out'          => $qty,
                    'unit_cost'        => $buyingPrice,
                    'notes'            => 'Credit invoice sale',
                    'created_by'       => $createdBy,
                ]);
            }

            customer_balance_record($conn, [
                'customer_id'    => $customerId,
                'company_id'     => $companyId,
                'branch_id'      => $branchId,
                'reference_type' => 'invoice',
                'reference_id'   => $invoiceId,
                'debit_amount'   => $grandTotal,
                'credit_amount'  => 0,
                'entry_date'     => $invoiceDate,
            ]);

            if (function_exists('finance_post_invoice_sale')) {
                finance_post_invoice_sale($conn, [
                    'entry_date'   => $invoiceDate,
                    'reference_no' => $invoiceNo,
                    'memo'         => 'Automatic posting from credit invoice',
                    'source_id'    => $invoiceId,
                    'branch_id'    => $branchId,
                    'company_id'   => $companyId,
                    'created_by'   => $createdBy,
                    'gross_amount' => $grandTotal,
                    'cost_amount'  => $totalCost,
                    'customer_id'  => $customerId,
                ]);
            }

            $conn->commit();
            return ['ok' => true, 'invoice_id' => $invoiceId, 'total_amount' => $grandTotal];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
