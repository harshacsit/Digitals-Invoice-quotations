<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/templates/EmailTemplate.php';
require_once __DIR__ . '/templates/quotation.php';
require_once __DIR__ . '/templates/invoice.php';
require_once __DIR__ . '/templates/payment.php';
require_once __DIR__ . '/templates/campaign.php';
require_once __DIR__ . '/templates/system.php';
require_once __DIR__ . '/../pdf/QuotationPdfBuilder.php';
require_once __DIR__ . '/../pdf/InvoicePdfBuilder.php';

class EmailDispatcher
{
    private PDO $pdo;
    private EmailService $emailService;

    public function __construct(?PDO $pdo = null, ?EmailService $emailService = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            global $pdo;
            $this->pdo = $pdo instanceof PDO ? $pdo : null;
        }

        $this->emailService = $emailService ?? new EmailService(null, $this->pdo);
    }

    /**
     * Check if an automated email has already been dispatched for a specific business event.
     */
    public function hasAutomatedEmailBeenSent(string $refType, int $refId, string $emailType, ?string $keyword = null): bool
    {
        if ($refId <= 0) {
            return false;
        }

        try {
            $sql = "SELECT COUNT(*) FROM email_logs 
                    WHERE reference_type = :ref_type 
                      AND reference_id = :ref_id 
                      AND email_type = :email_type";

            $params = [
                ':ref_type' => $refType,
                ':ref_id' => $refId,
                ':email_type' => $emailType,
            ];

            if (!empty($keyword)) {
                $sql .= " AND subject LIKE :keyword";
                $params[':keyword'] = '%' . $keyword . '%';
            }

            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();

            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            error_log('[EmailDispatcher] hasAutomatedEmailBeenSent Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Automated Quotation Email Trigger (Duplicate Protected)
     */
    public function sendAutomatedQuotation(int $quotationId): array
    {
        if ($this->hasAutomatedEmailBeenSent('quotation', $quotationId, 'quotation')) {
            return ['success' => true, 'message' => 'Automated quotation email already sent.', 'skipped' => true];
        }
        return $this->sendQuotation($quotationId);
    }

    /**
     * Automated Invoice Email Trigger (Duplicate Protected)
     */
    public function sendAutomatedInvoice(int $invoiceId): array
    {
        if ($this->hasAutomatedEmailBeenSent('invoice', $invoiceId, 'invoice')) {
            return ['success' => true, 'message' => 'Automated invoice email already sent.', 'skipped' => true];
        }
        return $this->sendInvoice($invoiceId);
    }

    /**
     * Automated Payment Receipt Email Trigger (Duplicate Protected)
     */
    public function sendAutomatedPaymentReceipt(int $paymentId): array
    {
        if ($this->hasAutomatedEmailBeenSent('payment', $paymentId, 'payment')) {
            return ['success' => true, 'message' => 'Automated payment receipt email already sent.', 'skipped' => true];
        }
        return $this->sendPaymentReceipt($paymentId);
    }

    /**
     * Automated Campaign Update Email Trigger (Duplicate Protected per Status Transition)
     */
    public function sendAutomatedCampaignUpdate(int $campaignId, string $status): array
    {
        if ($this->hasAutomatedEmailBeenSent('campaign', $campaignId, 'campaign', $status)) {
            return ['success' => true, 'message' => "Automated campaign update ({$status}) already sent.", 'skipped' => true];
        }
        return $this->sendCampaignUpdate($campaignId);
    }

    /**
     * Dispatch Quotation Email with PDF Attachment
     */
    public function sendQuotation(
        int $quotationId,
        ?string $recipientEmail = null,
        ?string $recipientName = null,
        ?int $sentBy = null
    ): array {
        if ($quotationId <= 0) {
            return ['success' => false, 'message' => 'Valid quotation ID is required.'];
        }

        try {
            // 1. Fetch Quotation & Customer Information
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

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $quotationId, PDO::PARAM_INT);
            $stmt->execute();
            $qRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$qRow) {
                return ['success' => false, 'message' => 'Quotation not found.'];
            }

            // Target Recipient Email and Name
            $targetEmail = !empty($recipientEmail) ? trim($recipientEmail) : trim((string) ($qRow['customer_email'] ?? ''));
            if ($targetEmail === '' || filter_var($targetEmail, FILTER_VALIDATE_EMAIL) === false) {
                return ['success' => false, 'message' => 'Customer email address is missing or invalid.'];
            }

            $targetName = !empty($recipientName) ? trim($recipientName) : trim((string) ($qRow['contact_person'] ?? $qRow['company_name'] ?? ''));
            $sentByUserId = $sentBy ?? getCurrentUserId();

            // 2. Fetch Quotation Items
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

            $itemStmt = $this->pdo->prepare($itemSql);
            $itemStmt->bindValue(':id', $quotationId, PDO::PARAM_INT);
            $itemStmt->execute();
            $itemRows = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $quotationData = [
                'id' => (int) $qRow['id'],
                'quotation_number' => $qRow['quotation_number'],
                'customer_name' => $targetName,
                'company_name' => $qRow['company_name'],
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
                'items' => array_map(static function (array $i): array {
                    return [
                        'id' => (int) $i['id'],
                        'description' => $i['description'],
                        'screen_name' => $i['screen_name'],
                        'screen_type' => $i['screen_type'],
                        'screen_city' => $i['screen_city'],
                        'quantity' => (float) $i['quantity'],
                        'duration_months' => (float) $i['duration_months'],
                        'unit_price' => (float) $i['unit_price'],
                        'discount_amount' => (float) $i['discount_amount'],
                        'line_total' => (float) $i['line_total'],
                    ];
                }, $itemRows),
            ];

            // 3. Generate Email Template Output
            $template = generateQuotationEmail($quotationData);

            // 4. Generate Temporary PDF File
            $tempPdfPath = tempnam(sys_get_temp_dir(), 'adsdash_qpdf_') . '.pdf';
            $pdfFileName = 'Quotation-' . ($qRow['quotation_number'] ?: 'QT-' . $quotationId) . '.pdf';

            try {
                $pdfBuilder = new QuotationPdfBuilder($this->pdo, $quotationData);
                $pdfBuilder->buildPdf();
                $pdfBuilder->Output('F', $tempPdfPath);

                // 5. Send Email via EmailService
                return $this->emailService->send([
                    'to' => $targetEmail,
                    'recipient_name' => $targetName,
                    'subject' => $template['subject'],
                    'html' => $template['html'],
                    'text' => $template['text'],
                    'email_type' => 'quotation',
                    'reference_type' => 'quotation',
                    'reference_id' => $quotationId,
                    'sent_by' => $sentByUserId,
                    'attachments' => [
                        ['path' => $tempPdfPath, 'name' => $pdfFileName]
                    ]
                ]);

            } finally {
                // Ensure temporary PDF file is cleaned up safely
                if (file_exists($tempPdfPath)) {
                    @unlink($tempPdfPath);
                }
            }

        } catch (Throwable $e) {
            error_log('[EmailDispatcher] sendQuotation Exception: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to send email.'];
        }
    }

    /**
     * Dispatch Tax Invoice Email with PDF Attachment
     */
    public function sendInvoice(
        int $invoiceId,
        ?string $recipientEmail = null,
        ?string $recipientName = null,
        ?int $sentBy = null
    ): array {
        if ($invoiceId <= 0) {
            return ['success' => false, 'message' => 'Valid invoice ID is required.'];
        }

        try {
            // 1. Fetch Invoice & Customer Information
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

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $invoiceId, PDO::PARAM_INT);
            $stmt->execute();
            $invRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invRow) {
                return ['success' => false, 'message' => 'Invoice not found.'];
            }

            // Target Recipient Email and Name
            $targetEmail = !empty($recipientEmail) ? trim($recipientEmail) : trim((string) ($invRow['customer_email'] ?? ''));
            if ($targetEmail === '' || filter_var($targetEmail, FILTER_VALIDATE_EMAIL) === false) {
                return ['success' => false, 'message' => 'Customer email address is missing or invalid.'];
            }

            $targetName = !empty($recipientName) ? trim($recipientName) : trim((string) ($invRow['contact_person'] ?? $invRow['company_name'] ?? ''));
            $sentByUserId = $sentBy ?? getCurrentUserId();

            // Fetch Linked Quotation
            $quotationRef = null;
            if ($invRow['quotation_id'] !== null) {
                $qStmt = $this->pdo->prepare('SELECT id, quotation_number, status FROM quotations WHERE id = :qid');
                $qStmt->bindValue(':qid', $invRow['quotation_id'], PDO::PARAM_INT);
                $qStmt->execute();
                $qRow = $qStmt->fetch(PDO::FETCH_ASSOC);
                if ($qRow) {
                    $quotationRef = ['id' => (int) $qRow['id'], 'quotation_number' => $qRow['quotation_number'], 'status' => $qRow['status']];
                }
            }

            // Fetch Items
            $itemSql = "SELECT ii.id, ii.invoice_id, ii.screen_id, ii.description, ii.quantity, ii.duration_months, ii.unit_price, ii.discount_amount, ii.line_total, s.name AS screen_name, s.screen_type, s.city AS screen_city FROM invoice_items ii LEFT JOIN screens s ON ii.screen_id = s.id WHERE ii.invoice_id = :id ORDER BY ii.id ASC";
            $itemStmt = $this->pdo->prepare($itemSql);
            $itemStmt->bindValue(':id', $invoiceId, PDO::PARAM_INT);
            $itemStmt->execute();
            $itemRows = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch Payments
            $paySql = "SELECT id, amount, payment_date, payment_method, payment_reference AS reference_number, notes FROM payments WHERE invoice_id = :id ORDER BY payment_date ASC, id ASC";
            $payStmt = $this->pdo->prepare($paySql);
            $payStmt->bindValue(':id', $invoiceId, PDO::PARAM_INT);
            $payStmt->execute();
            $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

            $invoiceData = [
                'id' => (int) $invRow['id'],
                'invoice_number' => $invRow['invoice_number'],
                'quotation_id' => $invRow['quotation_id'] !== null ? (int) $invRow['quotation_id'] : null,
                'customer_name' => $targetName,
                'company_name' => $invRow['company_name'],
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
                'status' => $invRow['computed_status'],
                'notes' => $invRow['notes'],
                'terms_conditions' => $invRow['terms_conditions'],
                'customer' => [
                    'company_name' => $invRow['company_name'],
                    'contact_person' => $invRow['contact_person'],
                    'email' => $invRow['customer_email'],
                    'phone' => $invRow['customer_phone'],
                ],
                'quotation' => $quotationRef,
                'items' => array_map(static function (array $i): array {
                    return [
                        'id' => (int) $i['id'],
                        'description' => $i['description'],
                        'screen_name' => $i['screen_name'],
                        'screen_type' => $i['screen_type'],
                        'screen_city' => $i['screen_city'],
                        'quantity' => (float) $i['quantity'],
                        'duration_months' => (float) $i['duration_months'],
                        'unit_price' => (float) $i['unit_price'],
                        'discount_amount' => (float) $i['discount_amount'],
                        'line_total' => (float) $i['line_total'],
                    ];
                }, $itemRows),
            ];

            // 2. Generate Email Template Content
            $template = generateInvoiceEmail($invoiceData);

            // 3. Generate Temporary PDF Attachment
            $tempPdfPath = tempnam(sys_get_temp_dir(), 'adsdash_ipdf_') . '.pdf';
            $pdfFileName = 'Invoice-' . ($invRow['invoice_number'] ?: 'INV-' . $invoiceId) . '.pdf';

            try {
                $pdfBuilder = new InvoicePdfBuilder($this->pdo, $invoiceData, $payments);
                $pdfBuilder->buildPdf();
                $pdfBuilder->Output('F', $tempPdfPath);

                // 4. Send Email via EmailService
                return $this->emailService->send([
                    'to' => $targetEmail,
                    'recipient_name' => $targetName,
                    'subject' => $template['subject'],
                    'html' => $template['html'],
                    'text' => $template['text'],
                    'email_type' => 'invoice',
                    'reference_type' => 'invoice',
                    'reference_id' => $invoiceId,
                    'sent_by' => $sentByUserId,
                    'attachments' => [
                        ['path' => $tempPdfPath, 'name' => $pdfFileName]
                    ]
                ]);

            } finally {
                if (file_exists($tempPdfPath)) {
                    @unlink($tempPdfPath);
                }
            }

        } catch (Throwable $e) {
            error_log('[EmailDispatcher] sendInvoice Exception: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to send email.'];
        }
    }

    /**
     * Dispatch Payment Receipt Email
     */
    public function sendPaymentReceipt(
        int $paymentId,
        ?string $recipientEmail = null,
        ?string $recipientName = null,
        ?int $sentBy = null
    ): array {
        if ($paymentId <= 0) {
            return ['success' => false, 'message' => 'Valid payment ID is required.'];
        }

        try {
            // Fetch Payment, Invoice, and Customer Info
            $sql = "SELECT 
                        p.id,
                        p.invoice_id,
                        i.invoice_number,
                        i.total_amount AS total_invoice_amount,
                        i.paid_amount AS total_paid,
                        i.balance_amount,
                        p.amount AS payment_amount,
                        p.payment_date,
                        p.payment_method,
                        p.payment_reference AS reference_number,
                        c.company_name,
                        c.contact_person,
                        c.email AS customer_email
                    FROM payments p
                    JOIN invoices i ON p.invoice_id = i.id
                    JOIN customers c ON p.customer_id = c.id
                    WHERE p.id = :id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $paymentId, PDO::PARAM_INT);
            $stmt->execute();
            $pRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pRow) {
                return ['success' => false, 'message' => 'Payment record not found.'];
            }

            $targetEmail = !empty($recipientEmail) ? trim($recipientEmail) : trim((string) ($pRow['customer_email'] ?? ''));
            if ($targetEmail === '' || filter_var($targetEmail, FILTER_VALIDATE_EMAIL) === false) {
                return ['success' => false, 'message' => 'Customer email address is missing or invalid.'];
            }

            $targetName = !empty($recipientName) ? trim($recipientName) : trim((string) ($pRow['contact_person'] ?? $pRow['company_name'] ?? ''));
            $sentByUserId = $sentBy ?? getCurrentUserId();

            $paymentData = [
                'customer_name' => $targetName,
                'company_name' => $pRow['company_name'],
                'invoice_number' => $pRow['invoice_number'],
                'payment_amount' => (float) $pRow['payment_amount'],
                'payment_date' => $pRow['payment_date'],
                'payment_method' => $pRow['payment_method'],
                'reference_number' => $pRow['reference_number'],
                'total_invoice_amount' => (float) $pRow['total_invoice_amount'],
                'total_paid' => (float) $pRow['total_paid'],
                'balance_amount' => (float) $pRow['balance_amount'],
                'currency' => 'INR',
            ];

            $template = generatePaymentEmail($paymentData);

            return $this->emailService->send([
                'to' => $targetEmail,
                'recipient_name' => $targetName,
                'subject' => $template['subject'],
                'html' => $template['html'],
                'text' => $template['text'],
                'email_type' => 'payment',
                'reference_type' => 'payment',
                'reference_id' => $paymentId,
                'sent_by' => $sentByUserId,
            ]);

        } catch (Throwable $e) {
            error_log('[EmailDispatcher] sendPaymentReceipt Exception: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to send email.'];
        }
    }

    /**
     * Dispatch Campaign Update Email
     */
    public function sendCampaignUpdate(
        int $campaignId,
        ?string $recipientEmail = null,
        ?string $recipientName = null,
        ?int $sentBy = null
    ): array {
        if ($campaignId <= 0) {
            return ['success' => false, 'message' => 'Valid campaign ID is required.'];
        }

        try {
            $sql = "SELECT 
                        cmp.id,
                        cmp.campaign_number,
                        cmp.campaign_name,
                        cmp.start_date,
                        cmp.end_date,
                        cmp.status,
                        cmp.progress,
                        c.company_name,
                        c.contact_person,
                        c.email AS customer_email
                    FROM campaigns cmp
                    JOIN customers c ON cmp.customer_id = c.id
                    WHERE cmp.id = :id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->execute();
            $cmpRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cmpRow) {
                return ['success' => false, 'message' => 'Campaign not found.'];
            }

            $targetEmail = !empty($recipientEmail) ? trim($recipientEmail) : trim((string) ($cmpRow['customer_email'] ?? ''));
            if ($targetEmail === '' || filter_var($targetEmail, FILTER_VALIDATE_EMAIL) === false) {
                return ['success' => false, 'message' => 'Customer email address is missing or invalid.'];
            }

            $targetName = !empty($recipientName) ? trim($recipientName) : trim((string) ($cmpRow['contact_person'] ?? $cmpRow['company_name'] ?? ''));
            $sentByUserId = $sentBy ?? getCurrentUserId();

            $campaignData = [
                'customer_name' => $targetName,
                'company_name' => $cmpRow['company_name'],
                'campaign_number' => $cmpRow['campaign_number'],
                'campaign_name' => $cmpRow['campaign_name'],
                'start_date' => $cmpRow['start_date'],
                'end_date' => $cmpRow['end_date'],
                'status' => $cmpRow['status'],
                'progress' => (int) $cmpRow['progress'],
            ];

            $template = generateCampaignEmail($campaignData);

            return $this->emailService->send([
                'to' => $targetEmail,
                'recipient_name' => $targetName,
                'subject' => $template['subject'],
                'html' => $template['html'],
                'text' => $template['text'],
                'email_type' => 'campaign',
                'reference_type' => 'campaign',
                'reference_id' => $campaignId,
                'sent_by' => $sentByUserId,
            ]);

        } catch (Throwable $e) {
            error_log('[EmailDispatcher] sendCampaignUpdate Exception: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to send email.'];
        }
    }

    /**
     * Dispatch Generic System Notification Email
     */
    public function sendSystemNotification(
        string $recipientEmail,
        ?string $recipientName,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionText = null,
        ?int $sentBy = null
    ): array {
        $targetEmail = trim($recipientEmail);
        if ($targetEmail === '' || filter_var($targetEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['success' => false, 'message' => 'Recipient email address is invalid.'];
        }

        if (trim($title) === '' || trim($message) === '') {
            return ['success' => false, 'message' => 'Title and message content are required.'];
        }

        try {
            $sentByUserId = $sentBy ?? getCurrentUserId();
            $systemData = [
                'recipient_name' => !empty($recipientName) ? trim($recipientName) : 'User',
                'title' => trim($title),
                'message' => trim($message),
                'action_url' => $actionUrl,
                'action_text' => $actionText,
            ];

            $template = generateSystemEmail($systemData);

            return $this->emailService->send([
                'to' => $targetEmail,
                'recipient_name' => $systemData['recipient_name'],
                'subject' => $template['subject'],
                'html' => $template['html'],
                'text' => $template['text'],
                'email_type' => 'system',
                'reference_type' => 'system',
                'sent_by' => $sentByUserId,
            ]);

        } catch (Throwable $e) {
            error_log('[EmailDispatcher] sendSystemNotification Exception: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to send email.'];
        }
    }
}
