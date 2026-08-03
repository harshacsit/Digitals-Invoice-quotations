<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailTemplate.php';

/**
 * Generate Campaign Update Email Template
 *
 * @param array $data Campaign information
 * @return array ['subject' => string, 'html' => string, 'text' => string]
 */
function generateCampaignEmail(array $data): array
{
    $customerName = EmailTemplate::e($data['customer_name'] ?? 'Valued Customer');
    $comp = EmailTemplate::e($data['company_name'] ?? '');
    $companyNameStr = !empty($comp) ? " ({$comp})" : '';

    $cmpNum = EmailTemplate::e($data['campaign_number'] ?? 'CMP-1001');
    $cmpName = EmailTemplate::e($data['campaign_name'] ?? 'Advertising Campaign');
    $startDate = EmailTemplate::formatDate((string) ($data['start_date'] ?? ''));
    $endDate = EmailTemplate::formatDate((string) ($data['end_date'] ?? ''));
    $progress = (int) ($data['progress'] ?? 0);

    $rawStatus = strtolower(trim((string) ($data['status'] ?? 'planned')));
    $statusText = strtoupper($rawStatus);

    $subject = "Campaign Update — {$cmpNum}: {$cmpName}";

    // Build HTML Content
    $bodyHtml = <<<HTML
<h2 style="color:#0f172a; margin-top:0;">Advertising Campaign Status Update</h2>
<p>Dear <strong>{$customerName}</strong>{$companyNameStr},</p>
<p>Here is the latest schedule and execution status for your advertising campaign <strong>{$cmpName}</strong> (Ref: <strong>{$cmpNum}</strong>).</p>

<table class="summary-table">
  <tr><th>Campaign Name</th><td><strong>{$cmpName}</strong></td></tr>
  <tr><th>Campaign Number</th><td>{$cmpNum}</td></tr>
  <tr><th>Start Date</th><td>{$startDate}</td></tr>
  <tr><th>End Date</th><td>{$endDate}</td></tr>
  <tr><th>Current Status</th><td><span class="badge badge-active">{$statusText}</span></td></tr>
  <tr><th>Completion Progress</th><td><strong>{$progress}%</strong></td></tr>
</table>

<div style="background-color:#e2e8f0; border-radius:10px; height:12px; overflow:hidden; margin:16px 0;">
  <div style="background-color:#2563eb; height:100%; width:{$progress}%;"></div>
</div>

<p>Our operations team monitors your outdoor screen schedule daily to ensure peak visibility and impressions.</p>

<p style="margin-top:24px;">Best regards,<br><strong>Bhimavaram Digitals Campaign Operations</strong></p>
HTML;

    $html = EmailTemplate::wrapHtml($subject, $bodyHtml);

    // Build Plain Text Content
    $compText = !empty($comp) ? " ({$comp})" : "";
    $text = <<<TEXT
Campaign Update — {$cmpNum}: {$cmpName}

Dear {$data['customer_name']}{$compText},

Here is the latest execution status for your campaign:

Campaign Name: {$cmpName}
Campaign Number: {$cmpNum}
Start Date: {$startDate}
End Date: {$endDate}
Current Status: {$statusText}
Completion Progress: {$progress}%

Regards,
Bhimavaram Digitals Campaign Operations
TEXT;

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}
