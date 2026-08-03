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
        $addrParts   = explode(',', $coAddr);
        $coAddrShort = trim(end($addrParts));

        // ── Company Logo HTML ──────────────────────────────────────────────────
        $logoPath = $coSettings['logo_path'] ?? '';
        if ($logoPath && file_exists(rtrim(__DIR__ . '/..', '/') . '/' . ltrim($logoPath, '/'))) {
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            $appRoot   = preg_replace('#/api$#', '', rtrim($scriptDir, '/'));
            $logoUrl   = rtrim($appRoot, '/') . '/' . ltrim($logoPath, '/');
            $coLogoHtml = '<div class="logo-img-wrap"><img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($coName) . ' Logo" style="height:64px;width:auto;"></div>';
        } else {
            $coLogoHtml = '<div class="logo-text-fallback"><div class="logo-text-box"><span class="ltb-b">b</span><span class="ltb-sub">DIGITALS</span></div></div>';
        }

        // ── Financial values ──────────────────────────────────────────────────
        $gstAmount = (float)($quotation['cgst_amount'] ?? 0)
                   + (float)($quotation['sgst_amount'] ?? 0)
                   + (float)($quotation['igst_amount'] ?? 0);

        // ── Derive campaign details from items or notes ────────────────────
        $firstItem      = ($quotation['items'] ?? [])[0] ?? [];
        $campaignDur    = !empty($firstItem['duration_months'])
                            ? $firstItem['duration_months'] . ' Month(s)'
                            : '—';
        $campaignPeriod = '—'; // stored in notes; override if available
        $cyclesPerDay   = '50–100 Cycles';  // default standard

        // Try to extract from notes
        $notesText = $quotation['notes'] ?? '';
        if (preg_match('/cycles[\s:]*([\d\-]+)/i', $notesText, $m)) {
            $cyclesPerDay = $m[1] . ' Cycles/Day';
        }

        $replacements = [
            // Company info
            '{{co_logo_html}}'       => $coLogoHtml,
            '{{co_name}}'            => htmlspecialchars($coName),
            '{{co_address}}'         => htmlspecialchars($coAddr),
            '{{co_address_short}}'   => htmlspecialchars($coAddrShort),
            '{{co_phone}}'           => htmlspecialchars($coPhone),
            '{{co_email}}'           => htmlspecialchars($coEmail),
            '{{co_gstin}}'           => htmlspecialchars($coGstin),
            '{{co_website}}'         => htmlspecialchars($coWeb),
            '{{bank_name}}'          => htmlspecialchars($bankName),
            '{{bank_account}}'       => htmlspecialchars($bankAcct),
            '{{bank_ifsc}}'          => htmlspecialchars($bankIfsc),
            '{{upi_id}}'             => htmlspecialchars($upiId),
            // Customer info
            '{{company_name}}'       => htmlspecialchars($quotation['customer']['company_name'] ?? 'N/A'),
            '{{contact_person}}'     => htmlspecialchars($quotation['customer']['contact_person'] ?? 'N/A'),
            '{{customer_phone}}'     => htmlspecialchars($quotation['customer']['phone'] ?? '-'),
            '{{customer_email}}'     => htmlspecialchars($quotation['customer']['email'] ?? '-'),
            // Quotation metadata
            '{{quotation_number}}'   => htmlspecialchars($quotation['quotation_number'] ?? ''),
            '{{quotation_date}}'     => htmlspecialchars($quotation['quotation_date'] ?? ''),
            '{{valid_until}}'        => htmlspecialchars($quotation['valid_until'] ?? ''),
            '{{status}}'             => htmlspecialchars(strtoupper($quotation['status'] ?? 'DRAFT')),
            // Campaign details
            '{{campaign_duration}}'  => htmlspecialchars($campaignDur),
            '{{campaign_period}}'    => htmlspecialchars($campaignPeriod),
            '{{cycles_per_day}}'     => htmlspecialchars($cyclesPerDay),
            // Tax rates
            '{{cgst_rate}}'          => htmlspecialchars((string)($quotation['cgst_rate'] ?? 9)),
            '{{sgst_rate}}'          => htmlspecialchars((string)($quotation['sgst_rate'] ?? 9)),
            '{{igst_rate}}'          => htmlspecialchars((string)($quotation['igst_rate'] ?? 0)),
            '{{cgst_amount}}'        => 'Rs. ' . number_format((float)($quotation['cgst_amount'] ?? 0), 2),
            '{{sgst_amount}}'        => 'Rs. ' . number_format((float)($quotation['sgst_amount'] ?? 0), 2),
            '{{igst_amount}}'        => 'Rs. ' . number_format((float)($quotation['igst_amount'] ?? 0), 2),
            // Financial
            '{{subtotal}}'           => 'Rs. ' . number_format((float)($quotation['subtotal'] ?? 0), 2),
            '{{discount_amount}}'    => '-Rs. ' . number_format((float)($quotation['discount_amount'] ?? 0), 2),
            '{{taxable_amount}}'     => 'Rs. ' . number_format((float)($quotation['taxable_amount'] ?? 0), 2),
            '{{gst_amount}}'         => 'Rs. ' . number_format($gstAmount, 2),
            '{{total_amount}}'       => 'Rs. ' . number_format((float)($quotation['total_amount'] ?? 0), 2),
            // Notes & Terms
            '{{notes}}'              => htmlspecialchars($quotation['notes'] ?? 'No additional notes.'),
            '{{terms_conditions}}'   => htmlspecialchars($quotation['terms_conditions'] ?? "1. 50% advance payment required to confirm booking.\n2. Balance due before campaign start date.\n3. Rates are subject to change after quotation validity.\n4. Subject to Bhimavaram jurisdiction."),
        ];

        // ── Build items HTML ──────────────────────────────────────────────────
        $itemsHtml = '';
        $idx = 1;
        foreach (($quotation['items'] ?? []) as $item) {
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
        $totalsHtml .= '<tr><td>Subtotal:</td><td>Rs. ' . number_format((float)($quotation['subtotal'] ?? 0), 2) . '</td></tr>';
        if ((float)($quotation['discount_amount'] ?? 0) > 0) {
            $totalsHtml .= '<tr><td>Discount:</td><td>-Rs. ' . number_format((float)($quotation['discount_amount'] ?? 0), 2) . '</td></tr>';
        }
        $totalsHtml .= '<tr><td>Taxable Amount:</td><td>Rs. ' . number_format((float)($quotation['taxable_amount'] ?? 0), 2) . '</td></tr>';
        if ((float)($quotation['cgst_amount'] ?? 0) > 0) {
            $totalsHtml .= '<tr><td>CGST (' . ($quotation['cgst_rate'] ?? 9) . '%):</td><td>Rs. ' . number_format((float)($quotation['cgst_amount'] ?? 0), 2) . '</td></tr>';
            $totalsHtml .= '<tr><td>SGST (' . ($quotation['sgst_rate'] ?? 9) . '%):</td><td>Rs. ' . number_format((float)($quotation['sgst_amount'] ?? 0), 2) . '</td></tr>';
        }
        if ((float)($quotation['igst_amount'] ?? 0) > 0) {
            $totalsHtml .= '<tr><td>IGST (' . ($quotation['igst_rate'] ?? 18) . '%):</td><td>Rs. ' . number_format((float)($quotation['igst_amount'] ?? 0), 2) . '</td></tr>';
        }
        $totalsHtml .= '<tr class="grand-total"><td>TOTAL AMOUNT:</td><td>Rs. ' . number_format((float)($quotation['total_amount'] ?? 0), 2) . '</td></tr>';
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
        $fileName   = 'Quotation-' . ($quotation['quotation_number'] ?: 'QT-' . $id) . '.html';

        if ($print) {
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
