<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf/QuotationPdfBuilder.php';

// Auth Guard
requireAuth();

$id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
if ($id === false || $id === null || $id <= 0) {
    sendErrorResponse('Valid quotation ID is required.', 400);
}

try {
    // 1. Fetch Quotation & Customer Details
    $sql = "SELECT 
                q.id,
                q.quotation_number,
                q.customer_id,
                q.quotation_date,
                q.valid_until,
                q.subtotal,
                q.discount_type,
                q.discount_value,
                q.discount_amount,
                q.taxable_amount,
                q.cgst_rate,
                q.cgst_amount,
                q.sgst_rate,
                q.sgst_amount,
                q.igst_rate,
                q.igst_amount,
                q.total_amount,
                q.currency,
                q.status,
                q.notes,
                q.terms_conditions,
                c.company_name,
                c.contact_person,
                c.email AS customer_email,
                c.phone AS customer_phone
            FROM quotations q
            JOIN customers c ON q.customer_id = c.id
            WHERE q.id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $qRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$qRow) {
        sendErrorResponse('Quotation not found.', 404);
    }

    $quotation = [
        'id' => (int) $qRow['id'],
        'quotation_number' => $qRow['quotation_number'],
        'quotation_date' => $qRow['quotation_date'],
        'valid_until' => $qRow['valid_until'],
        'subtotal' => (float) $qRow['subtotal'],
        'discount_type' => $qRow['discount_type'],
        'discount_value' => (float) $qRow['discount_value'],
        'discount_amount' => (float) $qRow['discount_amount'],
        'taxable_amount' => (float) $qRow['taxable_amount'],
        'cgst_rate' => (float) $qRow['cgst_rate'],
        'cgst_amount' => (float) $qRow['cgst_amount'],
        'sgst_rate' => (float) $qRow['sgst_rate'],
        'sgst_amount' => (float) $qRow['sgst_amount'],
        'igst_rate' => (float) $qRow['igst_rate'],
        'igst_amount' => (float) $qRow['igst_amount'],
        'total_amount' => (float) $qRow['total_amount'],
        'currency' => $qRow['currency'],
        'status' => $qRow['status'],
        'notes' => $qRow['notes'],
        'terms_conditions' => $qRow['terms_conditions'],
        'customer' => [
            'company_name' => $qRow['company_name'],
            'contact_person' => $qRow['contact_person'],
            'email' => $qRow['customer_email'],
            'phone' => $qRow['customer_phone'],
        ],
    ];

    // 2. Fetch Quotation Items & Screen Details
    $itemSql = "SELECT 
                    qi.id,
                    qi.quotation_id,
                    qi.screen_id,
                    qi.description,
                    qi.quantity,
                    qi.duration_months,
                    qi.unit_price,
                    qi.discount_amount,
                    qi.line_total,
                    s.name AS screen_name,
                    s.screen_type,
                    s.city AS screen_city
                FROM quotation_items qi
                LEFT JOIN screens s ON qi.screen_id = s.id
                WHERE qi.quotation_id = :id
                ORDER BY qi.id ASC";

    $itemStmt = $pdo->prepare($itemSql);
    $itemStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $itemStmt->execute();
    $itemRows = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $quotation['items'] = array_map(static function (array $item): array {
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

    // 3. Build & Output PDF
    $pdfBuilder = new QuotationPdfBuilder($pdo, $quotation);
    $pdfBuilder->buildPdf();

    $download = isset($_GET['download']) && $_GET['download'] === '1';
    $dest = $download ? 'D' : 'I';
    $fileName = 'Quotation-' . ($quotation['quotation_number'] ?: 'QT-' . $id) . '.pdf';

    $pdfBuilder->Output($dest, $fileName);
    exit;

} catch (Throwable $e) {
    sendErrorResponse('Failed to generate quotation PDF.', 500);
}
