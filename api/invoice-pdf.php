<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf/InvoicePdfBuilder.php';

// Disable display of warnings/errors to avoid corrupting PDF binary stream
ini_set('display_errors', '0');

// Auth Guard
requireAuth();

$preview = isset($_GET['preview']) && $_GET['preview'] === '1';
$print   = isset($_GET['print'])   && $_GET['print']   === '1';
$id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;

if ($preview && ($id === null || $id === false || $id <= 0)) {
    $stmt = $pdo->query('SELECT id FROM invoices ORDER BY id DESC LIMIT 1');
    $dbId = $stmt->fetchColumn();
    if ($dbId) {
        $id = (int) $dbId;
    }
}

if (($id === false || $id === null || $id <= 0) && !$preview) {
    sendErrorResponse('Valid invoice ID is required.', 400);
}

try {
    $invRow = null;
    if ($id > 0) {
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
    }

    if (!$invRow) {
        if ($preview) {
            $invoice = [
                'id' => 1,
                'invoice_number' => 'INV-2026-0001',
                'quotation_id' => 1,
                'invoice_date' => date('Y-m-d'),
                'due_date' => date('Y-m-d', strtotime('+30 days')),
                'subtotal' => 150000.00,
                'discount_amount' => 15000.00,
                'taxable_amount' => 135000.00,
                'cgst_rate' => 9.0,
                'cgst_amount' => 12150.00,
                'sgst_rate' => 9.0,
                'sgst_amount' => 12150.00,
                'igst_rate' => 0.0,
                'igst_amount' => 0.0,
                'total_amount' => 159300.00,
                'paid_amount' => 50000.00,
                'balance_amount' => 109300.00,
                'currency' => 'INR',
                'computed_status' => 'partial',
                'notes' => 'This is a sample preview invoice.',
                'terms_conditions' => "1. Balance due within 30 days.\n2. Interest of 12% p.a. charged on late payments.",
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
                ],
                'quotation' => [
                    'id' => 1,
                    'quotation_number' => 'QT-2026-0001',
                    'status' => 'converted'
                ]
            ];
            $payments = [
                [
                    'id' => 1,
                    'amount' => 50000.00,
                    'payment_date' => date('Y-m-d'),
                    'payment_method' => 'bank_transfer',
                    'reference_number' => 'TXN987654321',
                    'notes' => 'Advance payment received.'
                ]
            ];
        } else {
            sendErrorResponse('Invoice not found.', 404);
        }
    } else {
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
    }

    if ($invRow) {
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
    }

    // Check if custom HTML template is active
    $stmtTpl = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'invoice_template_active'");
    $stmtTpl->execute();
    $activeTemplate = $stmtTpl->fetchColumn();

    $tplFile = __DIR__ . '/../storage/templates/invoice_template.html';

    // Auto-detect: use custom template if file exists and setting is not explicitly 'default'
    if (file_exists($tplFile) && $activeTemplate !== 'default') {
        $activeTemplate = 'custom';
    }

    if ($activeTemplate === 'custom' && file_exists($tplFile)) {
        $html = file_get_contents($tplFile);

        // ── Fetch company settings from DB ────────────────────────────────────
        $coSettings = [];
        try {
            $coStmt = $pdo->query("SELECT setting_key, setting_value FROM settings
                WHERE setting_key IN (
                    'company_name','company_tagline','company_address','company_phone',
                    'company_email','company_gstin','company_website',
                    'bank_name','bank_account_name','bank_account_number','bank_ifsc','upi_id'
                )");
            $coSettings = $coStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (\Throwable $e) { /* use defaults */ }

        $coName    = $coSettings['company_name']    ?? 'Bhimavaram Digitals';
        $coAddr    = $coSettings['company_address'] ?? 'Main Road, Near Bus Stand Signal, Bhimavaram - 534201, AP';
        $coPhone   = $coSettings['company_phone']   ?? '+91 999 222 3542';
        $coEmail   = $coSettings['company_email']   ?? 'bhimavaramdigitals@gmail.com';
        $coGstin   = $coSettings['company_gstin']   ?? '37AAACB1234C1Z5';
        $coWeb     = $coSettings['company_website'] ?? 'www.bhimavaramdigitals.in';
        $bankName  = $coSettings['bank_name']           ?? 'State Bank of India';
        $bankAcct  = $coSettings['bank_account_number'] ?? '398811224455';
        $bankIfsc  = $coSettings['bank_ifsc']           ?? 'SBIN0001234';
        $upiId     = $coSettings['upi_id']              ?? 'bhimavaramdigitals@upi';
        // Short address: city part only
        $addrParts = explode(',', $coAddr);
        $coAddrShort = trim(end($addrParts));

        // ── Company Logo HTML ──────────────────────────────────────────────────
        $logoPath = $coSettings['logo_path'] ?? '';
        if ($logoPath && file_exists(rtrim(__DIR__ . '/..', '/') . '/' . ltrim($logoPath, '/'))) {
            // Construct a web-accessible URL
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            $appRoot   = preg_replace('#/api$#', '', rtrim($scriptDir, '/'));
            $logoUrl   = rtrim($appRoot, '/') . '/' . ltrim($logoPath, '/');
            $coLogoHtml = '<div class="logo-img-wrap"><img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($coName) . ' Logo" style="height:64px;width:auto;"></div>';
        } else {
            $coLogoHtml = '<div class="logo-text-fallback"><div class="logo-text-box"><span class="ltb-b">b</span><span class="ltb-sub">DIGITALS</span></div></div>';
        }

        // ── Status CSS class ──────────────────────────────────────────────────
        $rawStatus = $invoice['computed_status'] ?? 'unpaid';
        $statusClassMap = [
            'paid'      => 'paid',
            'partial'   => 'partial',
            'unpaid'    => 'unpaid',
            'overdue'   => 'overdue',
            'cancelled' => 'cancelled',
        ];
        $statusClass = $statusClassMap[$rawStatus] ?? 'unpaid';

        // ── Financial values ──────────────────────────────────────────────────
        $gstAmount = (float)($invoice['cgst_amount'] ?? 0)
                   + (float)($invoice['sgst_amount'] ?? 0)
                   + (float)($invoice['igst_amount'] ?? 0);

        $replacements = [
            // Company info
            '{{co_logo_html}}'    => $coLogoHtml,
            '{{co_name}}'         => htmlspecialchars($coName),
            '{{co_address}}'      => htmlspecialchars($coAddr),
            '{{co_address_short}}'=> htmlspecialchars($coAddrShort),
            '{{co_phone}}'        => htmlspecialchars($coPhone),
            '{{co_email}}'        => htmlspecialchars($coEmail),
            '{{co_gstin}}'        => htmlspecialchars($coGstin),
            '{{co_website}}'      => htmlspecialchars($coWeb),
            '{{bank_name}}'       => htmlspecialchars($bankName),
            '{{bank_account}}'    => htmlspecialchars($bankAcct),
            '{{bank_ifsc}}'       => htmlspecialchars($bankIfsc),
            '{{upi_id}}'          => htmlspecialchars($upiId),
            // Customer info
            '{{company_name}}'    => htmlspecialchars($invoice['customer']['company_name'] ?? 'N/A'),
            '{{contact_person}}'  => htmlspecialchars($invoice['customer']['contact_person'] ?? 'N/A'),
            '{{customer_phone}}'  => htmlspecialchars($invoice['customer']['phone'] ?? '-'),
            '{{customer_email}}'  => htmlspecialchars($invoice['customer']['email'] ?? '-'),
            '{{customer_gstin}}'  => '—',
            // Invoice metadata
            '{{invoice_number}}'  => htmlspecialchars($invoice['invoice_number'] ?? ''),
            '{{invoice_date}}'    => htmlspecialchars($invoice['invoice_date'] ?? ''),
            '{{due_date}}'        => htmlspecialchars($invoice['due_date'] ?? ''),
            '{{status}}'          => htmlspecialchars(strtoupper($rawStatus)),
            '{{status_class}}'    => $statusClass,
            // Tax rates
            '{{cgst_rate}}'       => htmlspecialchars((string)($invoice['cgst_rate'] ?? 9)),
            '{{sgst_rate}}'       => htmlspecialchars((string)($invoice['sgst_rate'] ?? 9)),
            '{{igst_rate}}'       => htmlspecialchars((string)($invoice['igst_rate'] ?? 0)),
            '{{cgst_amount}}'     => 'Rs. ' . number_format((float)($invoice['cgst_amount'] ?? 0), 2),
            '{{sgst_amount}}'     => 'Rs. ' . number_format((float)($invoice['sgst_amount'] ?? 0), 2),
            '{{igst_amount}}'     => 'Rs. ' . number_format((float)($invoice['igst_amount'] ?? 0), 2),
            // Financial
            '{{subtotal}}'        => 'Rs. ' . number_format((float)($invoice['subtotal'] ?? 0), 2),
            '{{discount_amount}}' => '-Rs. ' . number_format((float)($invoice['discount_amount'] ?? 0), 2),
            '{{taxable_amount}}'  => 'Rs. ' . number_format((float)($invoice['taxable_amount'] ?? 0), 2),
            '{{gst_amount}}'      => 'Rs. ' . number_format($gstAmount, 2),
            '{{total_amount}}'    => 'Rs. ' . number_format((float)($invoice['total_amount'] ?? 0), 2),
            '{{paid_amount}}'     => 'Rs. ' . number_format((float)($invoice['paid_amount'] ?? 0), 2),
            '{{balance_amount}}'  => 'Rs. ' . number_format((float)($invoice['balance_amount'] ?? 0), 2),
            // Notes & Terms
            '{{notes}}'             => htmlspecialchars($invoice['notes'] ?? 'No additional notes.'),
            '{{terms_conditions}}'  => htmlspecialchars($invoice['terms_conditions'] ?? "1. Payment due within 30 days of invoice date.\n2. Interest of 12% p.a. charged on late payments.\n3. Subject to Bhimavaram jurisdiction."),
        ];

        // ── Build items HTML ──────────────────────────────────────────────────
        $itemsHtml = '';
        $idx = 1;
        foreach (($invoice['items'] ?? []) as $item) {
            $desc = htmlspecialchars($item['description'] ?? $item['screen_name'] ?? 'Advertising Space');
            $type = htmlspecialchars(($item['screen_type'] ?? '') . (!empty($item['screen_city']) ? ' (' . $item['screen_city'] . ')' : ''));
            $qty  = number_format((float)($item['quantity'] ?? 1), 0);
            $dur  = ($item['duration_months'] ?? 1) . ' mo';
            $rate = 'Rs. ' . number_format((float)($item['unit_price'] ?? 0), 2);
            $tot  = 'Rs. ' . number_format((float)($item['line_total'] ?? 0), 2);

            $itemsHtml .= "<tr>
                <td>{$idx}</td>
                <td>{$desc}</td>
                <td>{$type}</td>
                <td class=\"tr\">{$qty}</td>
                <td class=\"tr\">{$dur}</td>
                <td class=\"tr\">{$rate}</td>
                <td class=\"tr\">{$tot}</td>
            </tr>\n";
            $idx++;
        }
        if ($itemsHtml === '') {
            $itemsHtml = '<tr><td colspan="7" style="text-align:center;color:#94a3b8;font-style:italic;">No items recorded.</td></tr>';
        }

        // ── Build totals HTML ─────────────────────────────────────────────────
        $totalsHtml = '<table class="totals-table">';
        $totalsHtml .= '<tr><td>Subtotal:</td><td>Rs. ' . number_format((float)($invoice['subtotal'] ?? 0), 2) . '</td></tr>';
        if ((float)($invoice['discount_amount'] ?? 0) > 0) {
            $totalsHtml .= '<tr><td>Discount:</td><td>-Rs. ' . number_format((float)($invoice['discount_amount'] ?? 0), 2) . '</td></tr>';
        }
        $totalsHtml .= '<tr><td>Taxable Amount:</td><td>Rs. ' . number_format((float)($invoice['taxable_amount'] ?? 0), 2) . '</td></tr>';
        if ((float)($invoice['cgst_amount'] ?? 0) > 0) {
            $totalsHtml .= '<tr><td>CGST (' . ($invoice['cgst_rate'] ?? 9) . '%):</td><td>Rs. ' . number_format((float)($invoice['cgst_amount'] ?? 0), 2) . '</td></tr>';
            $totalsHtml .= '<tr><td>SGST (' . ($invoice['sgst_rate'] ?? 9) . '%):</td><td>Rs. ' . number_format((float)($invoice['sgst_amount'] ?? 0), 2) . '</td></tr>';
        }
        if ((float)($invoice['igst_amount'] ?? 0) > 0) {
            $totalsHtml .= '<tr><td>IGST (' . ($invoice['igst_rate'] ?? 18) . '%):</td><td>Rs. ' . number_format((float)($invoice['igst_amount'] ?? 0), 2) . '</td></tr>';
        }
        $totalsHtml .= '<tr class="grand-total"><td>TOTAL AMOUNT:</td><td>Rs. ' . number_format((float)($invoice['total_amount'] ?? 0), 2) . '</td></tr>';
        $totalsHtml .= '<tr><td>Paid Amount:</td><td style="color:#16a34a;">Rs. ' . number_format((float)($invoice['paid_amount'] ?? 0), 2) . '</td></tr>';
        $totalsHtml .= '<tr><td><strong>Balance Due:</strong></td><td style="color:#b91c1c;font-weight:700;">Rs. ' . number_format((float)($invoice['balance_amount'] ?? 0), 2) . '</td></tr>';
        $totalsHtml .= '</table>';

        // ── Apply replacements ────────────────────────────────────────────────
        foreach ($replacements as $placeholder => $value) {
            $html = str_replace($placeholder, (string)$value, $html);
        }

        // ── Inject items ──────────────────────────────────────────────────────
        if (str_contains($html, '{{items}}')) {
            $html = str_replace('{{items}}', $itemsHtml, $html);
        } else {
            $html = preg_replace('/<tbody>[\s\S]*?<\/tbody>/i', '<tbody>' . $itemsHtml . '</tbody>', $html, 1);
        }

        // ── Inject totals ─────────────────────────────────────────────────────
        if (str_contains($html, '{{totals}}')) {
            $html = str_replace('{{totals}}', $totalsHtml, $html);
        } else {
            $html = preg_replace('/<table class=["\']totals-table["\'][^>]*>[\s\S]*?<\/table>/i', $totalsHtml, $html, 1);
        }

        // ── Output ───────────────────────────────────────────────────────────
        $isDownload = isset($_GET['download']) && $_GET['download'] === '1';
        $fileName   = 'Invoice-' . ($invoice['invoice_number'] ?: 'INV-' . $id) . '.html';

        if ($print) {
            // Auto-print for print mode
            $printScript = '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>';
            $html = str_ireplace('</body>', $printScript . '</body>', $html);
        }

        if (ob_get_length()) { ob_clean(); }

        if ($isDownload) {
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Cache-Control: no-cache, must-revalidate');
        } else {
            header('Content-Type: text/html; charset=utf-8');
        }

        echo $html;
        exit;
    }

    // 4. Build & Output PDF
    $pdfBuilder = new InvoicePdfBuilder($pdo, $invoice, $payments);
    $pdfBuilder->buildPdf();

    $download = isset($_GET['download']) && $_GET['download'] === '1';
    $dest = $download ? 'D' : 'I';
    $fileName = 'Invoice-' . ($invoice['invoice_number'] ?: 'INV-' . $id) . '.pdf';

    if (ob_get_length()) {
        ob_clean();
    }
    $pdfBuilder->Output($dest, $fileName);
    exit;

} catch (Throwable $e) {
    file_put_contents(__DIR__ . '/../scratch/pdf_errors.log', "[" . date('Y-m-d H:i:s') . "] Invoice PDF Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
    sendErrorResponse('Failed to generate invoice PDF. Error: ' . $e->getMessage(), 500);
}
