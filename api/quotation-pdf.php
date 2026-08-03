<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf/QuotationPdfBuilder.php';

// Disable display of warnings/errors to avoid corrupting PDF binary stream
ini_set('display_errors', '0');

// Auth Guard
requireAuth();

$preview = isset($_GET['preview']) && $_GET['preview'] === '1';
$print   = isset($_GET['print'])   && $_GET['print']   === '1';
$id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;

if ($preview && ($id === null || $id === false || $id <= 0)) {
    $stmt = $pdo->query('SELECT id FROM quotations ORDER BY id DESC LIMIT 1');
    $dbId = $stmt->fetchColumn();
    if ($dbId) {
        $id = (int) $dbId;
    }
}

if (($id === false || $id === null || $id <= 0) && !$preview) {
    sendErrorResponse('Valid quotation ID is required.', 400);
}

try {
    $qRow = null;
    if ($id > 0) {
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
    }

    if (!$qRow) {
        if ($preview) {
            $quotation = [
                'id' => 1,
                'quotation_number' => 'QT-2026-0001',
                'quotation_date' => date('Y-m-d'),
                'valid_until' => date('Y-m-d', strtotime('+15 days')),
                'subtotal' => 150000.00,
                'discount_type' => 'percentage',
                'discount_value' => 10.0,
                'discount_amount' => 15000.00,
                'taxable_amount' => 135000.00,
                'cgst_rate' => 9.0,
                'cgst_amount' => 12150.00,
                'sgst_rate' => 9.0,
                'sgst_amount' => 12150.00,
                'igst_rate' => 0.0,
                'igst_amount' => 0.0,
                'total_amount' => 159300.00,
                'currency' => 'INR',
                'status' => 'DRAFT',
                'notes' => 'This is a sample preview quotation.',
                'terms_conditions' => "1. Payment terms as agreed.\n2. Subject to Bhimavaram jurisdiction.",
                'customer' => [
                    'company_name' => 'Acme Digital Solutions',
                    'contact_person' => 'Ravi Kumar',
                    'email' => 'contact@acmedigital.in',
                    'phone' => '+91 98765 43210',
                ],
                'items' => [
                    [
                        'id' => 1,
                        'description' => 'Phoenix Mall Center LED Video Wall',
                        'screen_name' => 'Phoenix Mall LED',
                        'screen_type' => 'LED Video Wall',
                        'screen_city' => 'Bhimavaram',
                        'quantity' => 1.0,
                        'duration_months' => 3.0,
                        'unit_price' => 50000.00,
                        'discount_amount' => 0.0,
                        'line_total' => 150000.00
                    ]
                ]
            ];
        } else {
            sendErrorResponse('Quotation not found.', 404);
        }
    } else {
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
    }

    if ($qRow) {
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
    }

    // Check if custom HTML template is active
    $stmtTpl = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'quotation_template_active'");
    $stmtTpl->execute();
    $activeTemplate = $stmtTpl->fetchColumn();

    $tplFile = __DIR__ . '/../storage/templates/quotation_template.html';

    if ($activeTemplate === 'custom' && file_exists($tplFile)) {
        $html = file_get_contents($tplFile);

        $replacements = [
            '{{company_name}}' => htmlspecialchars($quotation['customer']['company_name'] ?? 'N/A'),
            '{{contact_person}}' => htmlspecialchars($quotation['customer']['contact_person'] ?? 'N/A'),
            '{{customer_phone}}' => htmlspecialchars($quotation['customer']['phone'] ?? '-'),
            '{{customer_email}}' => htmlspecialchars($quotation['customer']['email'] ?? '-'),
            '{{quotation_number}}' => htmlspecialchars($quotation['quotation_number'] ?? ''),
            '{{quotation_date}}' => htmlspecialchars($quotation['quotation_date'] ?? ''),
            '{{valid_until}}' => htmlspecialchars($quotation['valid_until'] ?? ''),
            '{{status}}' => htmlspecialchars($quotation['status'] ?? ''),
        ];

        $replacements['{{subtotal}}'] = 'Rs. ' . number_format((float)$quotation['subtotal'], 2);
        $replacements['{{discount_amount}}'] = '-Rs. ' . number_format((float)$quotation['discount_amount'], 2);
        
        $gstAmount = (float)$quotation['cgst_amount'] + (float)$quotation['sgst_amount'] + (float)$quotation['igst_amount'];
        $replacements['{{gst_amount}}'] = 'Rs. ' . number_format($gstAmount, 2);
        $replacements['{{taxable_amount}}'] = 'Rs. ' . number_format((float)$quotation['taxable_amount'], 2);
        $replacements['{{total_amount}}'] = 'Rs. ' . number_format((float)$quotation['total_amount'], 2);
        
        $replacements['{{notes}}'] = htmlspecialchars($quotation['notes'] ?? '');
        $replacements['{{terms_conditions}}'] = htmlspecialchars($quotation['terms_conditions'] ?? '1. Payment terms as agreed. 2. Subject to Bhimavaram jurisdiction.');

        $itemsHtml = '';
        $idx = 1;
        foreach (($quotation['items'] ?? []) as $item) {
            $desc = htmlspecialchars($item['description'] ?? $item['screen_name'] ?? 'Advertising Space');
            $type = htmlspecialchars(($item['screen_type'] ?? '') . ($item['screen_city'] ? ' (' . $item['screen_city'] . ')' : ''));
            $qty = number_format((float) ($item['quantity'] ?? 1), 0);
            $dur = ($item['duration_months'] ?? 1) . ' mo';
            $rate = 'Rs. ' . number_format((float)$item['unit_price'], 2);
            $total = 'Rs. ' . number_format((float)$item['line_total'], 2);
            
            $itemsHtml .= "<tr>
                <td>{$idx}</td>
                <td>{$desc}</td>
                <td>{$type}</td>
                <td class=\"text-right\">{$qty}</td>
                <td class=\"text-right\">{$dur}</td>
                <td class=\"text-right\">{$rate}</td>
                <td class=\"text-right\">{$total}</td>
            </tr>";
            $idx++;
        }

        $totalsHtml = '<table class="totals-table">
            <tr>
                <td>Subtotal:</td>
                <td class="text-right">Rs. ' . number_format($quotation['subtotal'], 2) . '</td>
            </tr>
            <tr>
                <td>Discount:</td>
                <td class="text-right">-Rs. ' . number_format($quotation['discount_amount'], 2) . '</td>
            </tr>
            <tr>
                <td>GST Taxes:</td>
                <td class="text-right">Rs. ' . number_format($gstAmount, 2) . '</td>
            </tr>
            <tr class="grand-total">
                <td>Total Amount:</td>
                <td class="text-right">Rs. ' . number_format($quotation['total_amount'], 2) . '</td>
            </tr>
        </table>';

        foreach ($replacements as $placeholder => $value) {
            $html = str_replace($placeholder, (string)$value, $html);
        }

        if (str_contains($html, '{{items}}')) {
            $html = str_replace('{{items}}', $itemsHtml, $html);
        } else {
            $html = preg_replace('/<tbody>[\s\S]*?<\/tbody>/i', '<tbody>' . $itemsHtml . '</tbody>', $html);
        }

        if (str_contains($html, '{{totals}}')) {
            $html = str_replace('{{totals}}', $totalsHtml, $html);
        } else {
            $html = preg_replace('/<table class="totals-table">[\s\S]*?<\/table>/i', $totalsHtml, $html);
        }

        header('Content-Type: text/html; charset=utf-8');
        if ($print) {
            // Inject auto-print script for print mode
            $html = str_ireplace('</body>', '<script>window.onload=function(){window.print();};</script></body>', $html);
            if (!str_contains(strtolower($html), '</body>')) {
                $html .= '<script>window.onload=function(){window.print();};</script>';
            }
        }
        echo $html;
        exit;
    }

    // 3. Build & Output PDF
    $pdfBuilder = new QuotationPdfBuilder($pdo, $quotation);
    $pdfBuilder->buildPdf();

    $download = isset($_GET['download']) && $_GET['download'] === '1';
    $dest = $download ? 'D' : 'I';
    $fileName = 'Quotation-' . ($quotation['quotation_number'] ?: 'QT-' . $id) . '.pdf';

    if (ob_get_length()) {
        ob_clean();
    }
    $pdfBuilder->Output($dest, $fileName);
    exit;

} catch (Throwable $e) {
    file_put_contents(__DIR__ . '/../scratch/pdf_errors.log', "[" . date('Y-m-d H:i:s') . "] Quotation PDF Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
    sendErrorResponse('Failed to generate quotation PDF. Error: ' . $e->getMessage(), 500);
}
