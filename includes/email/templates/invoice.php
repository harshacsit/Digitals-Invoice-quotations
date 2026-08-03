<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailTemplate.php';

/**
 * Generate Tax Invoice Email Template
 *
 * @param array $data Invoice information
 * @return array ['subject' => string, 'html' => string, 'text' => string]
 */
function generateInvoiceEmail(array $data): array
{
    $customerName = EmailTemplate::e($data['customer_name'] ?? 'Valued Customer');
    $comp = EmailTemplate::e($data['company_name'] ?? '');
    $companyNameStr = !empty($comp) ? " ({$comp})" : '';

    $invNum = EmailTemplate::e($data['invoice_number'] ?? 'INV-1001');
    $invDate = EmailTemplate::formatDate((string) ($data['invoice_date'] ?? ''));
    $dueDate = EmailTemplate::formatDate((string) ($data['due_date'] ?? ''));

    $totalAmount = EmailTemplate::formatCurrency($data['total_amount'] ?? 0);
    $paidAmount = EmailTemplate::formatCurrency($data['paid_amount'] ?? 0);
    $balanceAmount = EmailTemplate::formatCurrency($data['balance_amount'] ?? 0);

    $rawStatus = strtolower(trim((string) ($data['status'] ?? 'unpaid')));
    $statusBadgeClass = match ($rawStatus) {
        'paid' => 'badge-paid',
        'partial' => 'badge-partial',
        default => 'badge-unpaid',
    };
    $statusText = strtoupper($rawStatus);

    $subject = "Tax Invoice {$invNum} from Bhimavaram Digitals";

    // Build HTML Content
    $bodyHtml = <<<HTML
<h2 style="color:#0f172a; margin-top:0;">Tax Invoice Statement</h2>
<p>Dear <strong>{$customerName}</strong>{$companyNameStr},</p>
<p>Please find attached your Tax Invoice <strong>{$invNum}</strong> from <strong>Bhimavaram Digitals</strong>.</p>

<table class="summary-table">
  <tr><th>Invoice Number</th><td><strong>{$invNum}</strong></td></tr>
  <tr><th>Invoice Date</th><td>{$invDate}</td></tr>
  <tr><th>Payment Due Date</th><td>{$dueDate}</td></tr>
  <tr><th>Status</th><td><span class="badge {$statusBadgeClass}">{$statusText}</span></td></tr>
  <tr><th>Total Invoice Amount</th><td>{$totalAmount}</td></tr>
  <tr><th>Paid Amount</th><td style="color:#166534; font-weight:bold;">{$paidAmount}</td></tr>
  <tr style="background-color:#fef2f2;">
    <th><strong>Balance Due</strong></th>
    <td><strong style="color:#991b1b; font-size:16px;">{$balanceAmount}</strong></td>
  </tr>
</table>

<p style="background-color:#f8fafc; padding:12px; border-left:4px solid #2563eb; font-size:13px; color:#334155;">
  <strong>Remittance Note:</strong> Payments can be made via Bank Transfer or UPI. Attached PDF contains full bank account details.
</p>

<p>The detailed tax invoice breakdown is attached to this email as a PDF document.</p>

<p style="margin-top:24px;">Best regards,<br><strong>Bhimavaram Digitals Accounts Team</strong></p>
HTML;

    $html = EmailTemplate::wrapHtml($subject, $bodyHtml);

    // Build Plain Text Content
    $compText = !empty($comp) ? " ({$comp})" : "";
    $text = <<<TEXT
Tax Invoice {$data['invoice_number']} from Bhimavaram Digitals

Dear {$customerName}{$compText},

Please find your Tax Invoice summary below:

Invoice Number: {$data['invoice_number']}
Invoice Date: {$invDate}
Due Date: {$dueDate}
Status: {$statusText}

Total Amount: {$totalAmount}
Paid Amount: {$paidAmount}
Balance Due: {$balanceAmount}

Attached PDF contains complete itemized details and bank account information for payment processing.

Regards,
Bhimavaram Digitals Accounts Team
TEXT;

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}
