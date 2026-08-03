<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailTemplate.php';

/**
 * Generate Payment Receipt Email Template
 *
 * @param array $data Payment receipt information
 * @return array ['subject' => string, 'html' => string, 'text' => string]
 */
function generatePaymentEmail(array $data): array
{
    $customerName = EmailTemplate::e($data['customer_name'] ?? 'Valued Customer');
    $comp = EmailTemplate::e($data['company_name'] ?? '');
    $companyNameStr = !empty($comp) ? " ({$comp})" : '';

    $invNum = EmailTemplate::e($data['invoice_number'] ?? 'INV-1001');
    $paymentDate = EmailTemplate::formatDate((string) ($data['payment_date'] ?? ''));
    $paymentMethod = EmailTemplate::e(strtoupper((string) ($data['payment_method'] ?? 'ONLINE')));
    $refNum = EmailTemplate::e($data['reference_number'] ?? '-');

    $paymentAmount = EmailTemplate::formatCurrency($data['payment_amount'] ?? 0);
    $totalInvoice = EmailTemplate::formatCurrency($data['total_invoice_amount'] ?? 0);
    $totalPaid = EmailTemplate::formatCurrency($data['total_paid'] ?? 0);
    $balanceAmount = EmailTemplate::formatCurrency($data['balance_amount'] ?? 0);

    $subject = "Payment Receipt for {$invNum} — Bhimavaram Digitals";

    // Build HTML Content
    $bodyHtml = <<<HTML
<h2 style="color:#0f172a; margin-top:0;">Payment Confirmation Receipt</h2>
<p>Dear <strong>{$customerName}</strong>{$companyNameStr},</p>
<p>We have successfully received your payment for Invoice <strong>{$invNum}</strong>. Thank you for your payment!</p>

<table class="summary-table">
  <tr style="background-color:#f0fdf4;">
    <th><strong>Payment Received</strong></th>
    <td><strong style="color:#166534; font-size:16px;">{$paymentAmount}</strong></td>
  </tr>
  <tr><th>Payment Date</th><td>{$paymentDate}</td></tr>
  <tr><th>Payment Method</th><td>{$paymentMethod}</td></tr>
  <tr><th>Reference / Txn ID</th><td>{$refNum}</td></tr>
  <tr><th>Invoice Number</th><td><strong>{$invNum}</strong></td></tr>
  <tr><th>Total Invoice Amount</th><td>{$totalInvoice}</td></tr>
  <tr><th>Total Paid to Date</th><td>{$totalPaid}</td></tr>
  <tr><th>Remaining Balance</th><td><strong>{$balanceAmount}</strong></td></tr>
</table>

<p>Thank you for partnering with <strong>Bhimavaram Digitals</strong>.</p>

<p style="margin-top:24px;">Best regards,<br><strong>Bhimavaram Digitals Billing Team</strong></p>
HTML;

    $html = EmailTemplate::wrapHtml($subject, $bodyHtml);

    // Build Plain Text Content
    $compText = !empty($comp) ? " ({$comp})" : "";
    $text = <<<TEXT
Payment Receipt for {$data['invoice_number']} — Bhimavaram Digitals

Dear {$data['customer_name']}{$compText},

We have received your payment. Thank you!

Payment Received: {$paymentAmount}
Payment Date: {$paymentDate}
Payment Method: {$paymentMethod}
Reference/Txn ID: {$refNum}

Invoice Number: {$data['invoice_number']}
Total Invoice Amount: {$totalInvoice}
Total Paid to Date: {$totalPaid}
Remaining Balance: {$balanceAmount}

Regards,
Bhimavaram Digitals Billing Team
TEXT;

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}
