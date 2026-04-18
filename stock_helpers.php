<?php

if (!function_exists('stock_record_movement')) {
    function stock_record_movement(mysqli $conn, array $data): bool
    {
        $productId   = (int)($data['product_id'] ?? 0);
        $branchId    = isset($data['branch_id']) ? (int)$data['branch_id'] : null;
        $companyId   = isset($data['company_id']) ? (int)$data['company_id'] : null;
        $type        = trim((string)($data['transaction_type'] ?? ''));
        $referenceNo = trim((string)($data['reference_no'] ?? ''));
        $qtyIn       = (float)($data['qty_in'] ?? 0);
        $qtyOut      = (float)($data['qty_out'] ?? 0);
        $unitCost    = (float)($data['unit_cost'] ?? 0);
        $notes       = trim((string)($data['notes'] ?? ''));
        $createdBy   = isset($data['created_by']) ? (int)$data['created_by'] : null;
        $createdAt   = $data['created_at'] ?? date('Y-m-d H:i:s');

        if ($productId <= 0 || $type === '') {
            return false;
        }

        $balanceAfter = 0;
        $stmt = $conn->prepare("SELECT stock_qty FROM products WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && ($row = $result->fetch_assoc())) {
            $balanceAfter = (float)$row['stock_qty'];
        }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO stock_movements (company_id, branch_id, product_id, transaction_type, reference_no, qty_in, qty_out, balance_after, unit_cost, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiissdddssis', $companyId, $branchId, $productId, $type, $referenceNo, $qtyIn, $qtyOut, $balanceAfter, $unitCost, $notes, $createdBy, $createdAt);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('stock_deduct')) {
    function stock_deduct(mysqli $conn, int $productId, float $qty): bool
    {
        $stmt = $conn->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?");
        $stmt->bind_param('dii', $qty, $productId, $qty);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0;
    }
}

if (!function_exists('stock_add')) {
    function stock_add(mysqli $conn, int $productId, float $qty): bool
    {
        $stmt = $conn->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
        $stmt->bind_param('di', $qty, $productId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0;
    }
}
