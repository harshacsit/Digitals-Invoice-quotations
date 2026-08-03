<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf/InvoicePdfBuilder.php';

// Auth Guard
requireAuth();

$id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
if ($id === false || $id === null || $id <= 0) {
    sendErrorResponse('Valid invoice ID is required.', 400);
}

try {
    // 1. Fetch Invoice & Customer Details
    $sql = "SELECT 
                i.id,
                i.invoice_number,
                i.quotation_id,
                i.customer_id,
                i.invoice_date,
                i.due_date,
                i.subtotal,
                i.discount_amount,
                i.taxable_amount,
                i.cgst_rate,
                i.cgst_amount,
                i.sgst_rate,
                i.sgst_amount,
                i.igst_rate,
                i.igst_amount,
                i.total_amount,
                i.paid_amount,
                i.balance_amount,
                i.currency,
                CASE
                    WHEN i.status = 'cancelled' THEN 'cancelled'
                    WHEN i.paid_amount >= i.total_amount THEN 'paid'
                    WHEN i.paid_amount > 0 THEN 'partial'
                    WHEN i.balance_amount > 0 AND CURRENT_DATE() > i.due_date THEN 'overdue'
                    ELSE 'unpaid'
                END AS computed_status,
                i.notes,
                i.terms_conditions,
                c.company_name,
                c.contact_person,
                c.email AS customer_email,
                c.phone AS customer_phone
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $invRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invRow) {
        sendErrorResponse('Invoice not found.', 404);
    }

    $invoice = [
        'id' => (int) $invRow['id'],
        'invoice_number' => $invRow['invoice_number'],
        'quotation_id' => $invRow['quotation_id'] !== null ? (int) $invRow['quotation_id'] : null,
        'invoice_date' => $invRow['invoice_date'],
        'due_date' => $invRow['due_date'],
        'subtotal' => (float) $invRow['subtotal'],
        'discount_amount' => (float) $invRow['discount_amount'],
        'taxable_amount' => (float) $invRow['taxable_amount'],
        'cgst_rate' => (float) $invRow['cgst_rate'],
        'cgst_amount' => (float) $invRow['cgst_amount'],
        'sgst_rate' => (float) $invRow['sgst_rate'],
        'sgst_amount' => (float) $invRow['sgst_amount'],
        'igst_rate' => (float) $invRow['igst_rate'],
        'igst_amount' => (float) $invRow['igst_amount'],
        'total_amount' => (float) $invRow['total_amount'],
        'paid_amount' => (float) $invRow['paid_amount'],
        'balance_amount' => (float) $invRow['balance_amount'],
        'currency' => $invRow['currency'],
        'computed_status' => $invRow['computed_status'],
        'notes' => $invRow['notes'],
        'terms_conditions' => $invRow['terms_conditions'],
        'customer' => [
            'company_name' => $invRow['company_name'],
            'contact_person' => $invRow['contact_person'],
            'email' => $invRow['customer_email'],
            'phone' => $invRow['customer_phone'],
        ],
    ];

    // Linked Quotation Ref
    if ($invoice['quotation_id'] !== null) {
        $qStmt = $pdo->prepare('SELECT id, quotation_number, status FROM quotations WHERE id = :qid');
        $qStmt->bindValue(':qid', $invoice['quotation_id'], PDO::PARAM_INT);
        $qStmt->execute();
        $qRow = $qStmt->fetch(PDO::FETCH_ASSOC);
        if ($qRow) {
            $invoice['quotation'] = [
                'id' => (int) $qRow['id'],
                'quotation_number' => $qRow['quotation_number'],
                'status' => $qRow['status'],
            ];
        } else {
            $invoice['quotation'] = null;
        }
    } else {
        $invoice['quotation'] = null;
    }

    // 2. Fetch Historical Invoice Items
    $itemSql = "SELECT 
                    ii.id,
                    ii.invoice_id,
                    ii.screen_id,
                    ii.description,
                    ii.quantity,
                    ii.duration_months,
                    ii.unit_price,
                    ii.discount_amount,
                    ii.line_total,
                    s.name AS screen_name,
                    s.screen_type,
                    s.city AS screen_city
                FROM invoice_items ii
                LEFT JOIN screens s ON ii.screen_id = s.id
                WHERE ii.invoice_id = :id
                ORDER BY ii.id ASC";

    $itemStmt = $pdo->prepare($itemSql);
    $itemStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $itemStmt->execute();
    $itemRows = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $invoice['items'] = array_map(static function (array $item): array {
        return [
            'id' => (int) $item['id'],
            'description' => $item['description'],
            'screen_name' => $item['screen_name'],
            'screen_type' => $item['screen_type'],
            'screen_city' => $item['screen_city'],
            'quantity' => (float) $item['quantity'],
            'duration_months' => (float) $item['duration_months'],
            'unit_price' => (float) $item['unit_price'],
            'discount_amount' => (float) $item['discount_amount'],
            'line_total' => (float) $item['line_total'],
        ];
    }, $itemRows);

    // 3. Fetch Payment History Ledger
    $paySql = "SELECT id, amount, payment_date, payment_method, payment_reference AS reference_number, notes
               FROM payments
               WHERE invoice_id = :id
               ORDER BY payment_date ASC, id ASC";

    $payStmt = $pdo->prepare($paySql);
    $payStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $payStmt->execute();
    $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Build & Output PDF
    $pdfBuilder = new InvoicePdfBuilder($pdo, $invoice, $payments);
    $pdfBuilder->buildPdf();

    $download = isset($_GET['download']) && $_GET['download'] === '1';
    $dest = $download ? 'D' : 'I';
    $fileName = 'Invoice-' . ($invoice['invoice_number'] ?: 'INV-' . $id) . '.pdf';

    $pdfBuilder->Output($dest, $fileName);
    exit;

} catch (Throwable $e) {
    sendErrorResponse('Failed to generate invoice PDF.', 500);
}
