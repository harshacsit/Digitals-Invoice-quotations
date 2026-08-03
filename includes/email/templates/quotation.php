<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailTemplate.php';

/**
 * Generate Quotation Email Template
 *
 * @param array $data Quotation information
 * @return array ['subject' => string, 'html' => string, 'text' => string]
 */
function generateQuotationEmail(array $data): array
{
    $customerName = EmailTemplate::e($data['customer_name'] ?? 'Valued Customer');
    $companyNameStr = !empty($companyName) ? " ({$companyName})" : '';
    $quoteNum = EmailTemplate::e($data['quotation_number'] ?? 'QT-1001');
    $quoteDate = EmailTemplate::formatDate((string) ($data['quotation_date'] ?? ''));
    $validUntil = EmailTemplate::formatDate((string) ($data['valid_until'] ?? ''));

    $subtotal = EmailTemplate::formatCurrency($data['subtotal'] ?? 0);
    $discount = EmailTemplate::formatCurrency($data['discount_amount'] ?? 0);
    $taxable = EmailTemplate::formatCurrency($data['taxable_amount'] ?? 0);
    $cgst = EmailTemplate::formatCurrency($data['cgst_amount'] ?? 0);
    $sgst = EmailTemplate::formatCurrency($data['sgst_amount'] ?? 0);
    $igst = EmailTemplate::formatCurrency($data['igst_amount'] ?? 0);
    $totalAmount = EmailTemplate::formatCurrency($data['total_amount'] ?? 0);
    $amountInWords = EmailTemplate::formatAmountInWords($data['total_amount'] ?? 0);

    $subject = "Quotation {$quoteNum} from Bhimavaram Digitals";

    // Build HTML Content
    $bodyHtml = <<<HTML
<h2 style="color:#0f172a; margin-top:0;">Advertising Space Quotation Proposal</h2>
<p>Dear <strong>{$customerName}</strong>{$companyNameStr},</p>
<p>Thank you for considering <strong>Bhimavaram Digitals</strong> for your advertising campaign. Please find the summary of your quotation <strong>{$quoteNum}</strong> below.</p>

<table class="summary-table">
  <tr><th>Quotation Number</th><td><strong>{$quoteNum}</strong></td></tr>
  <tr><th>Quotation Date</th><td>{$quoteDate}</td></tr>
  <tr><th>Valid Until</th><td>{$validUntil}</td></tr>
  <tr><th>Subtotal</th><td>{$subtotal}</td></tr>
  <tr><th>Discount</th><td>-{$discount}</td></tr>
  <tr><th>Taxable Amount</th><td>{$taxable}</td></tr>
  <tr><th>CGST / SGST</th><td>{$cgst} / {$sgst}</td></tr>
  <tr><th>IGST</th><td>{$igst}</td></tr>
  <tr style="background-color:#eff6ff;">
    <th><strong>Total Amount</strong></th>
    <td><strong style="color:#2563eb; font-size:16px;">{$totalAmount}</strong></td>
  </tr>
</table>

<p style="background-color:#f8fafc; padding:12px; border-left:4px solid #2563eb; font-size:13px; color:#334155;">
  <strong>Amount in Words:</strong> {$amountInWords}
</p>

<p>The complete quotation details and screen specifications are attached to this email as a PDF document.</p>
<p>Please review and let us know if you would like to proceed with booking.</p>

<p style="margin-top:24px;">Best regards,<br><strong>Bhimavaram Digitals Team</strong></p>
HTML;

    $html = EmailTemplate::wrapHtml($subject, $bodyHtml);

    // Build Plain Text Content
    $textCompany = !empty($companyName) ? " ({$companyName})" : "";
    $text = <<<TEXT
Quotation {$data['quotation_number']} from Bhimavaram Digitals

Dear {$customerName}{$textCompany},

Thank you for considering Bhimavaram Digitals for your advertising campaign. Please find the quotation summary below:

Quotation Number: {$data['quotation_number']}
Quotation Date: {$quoteDate}
Valid Until: {$validUntil}

Subtotal: {$subtotal}
Discount: -{$discount}
Taxable Amount: {$taxable}
Total Amount: {$totalAmount}

Amount in Words: {$amountInWords}

The complete quotation details and screen specifications are attached to this email as a PDF.

Regards,
Bhimavaram Digitals Team
TEXT;

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}
