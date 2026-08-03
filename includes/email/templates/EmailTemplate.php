<?php

declare(strict_types=1);

require_once __DIR__ . '/../../pdf/NumberToWordsINR.php';

class EmailTemplate
{
    /**
     * Safely escape HTML output to prevent XSS / HTML Injection.
     */
    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Format numerical amount to Indian Rupees (INR) with Indian comma grouping.
     * Example: 213391.20 -> ₹2,13,391.20 | 90000 -> ₹90,000.00 | 1500000 -> ₹15,00,000.00
     */
    public static function formatCurrency(float|int|string $amount): string
    {
        $num = (float) $amount;
        $isNegative = $num < 0;
        $num = abs($num);

        $formattedNum = number_format($num, 2, '.', '');
        $parts = explode('.', $formattedNum);
        $intPart = $parts[0];
        $decPart = $parts[1];

        // Apply Indian numbering commas (last 3 digits, then groups of 2)
        if (strlen($intPart) > 3) {
            $lastThree = substr($intPart, -3);
            $remaining = substr($intPart, 0, -3);
            $remainingFormatted = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining);
            $intPart = $remainingFormatted . ',' . $lastThree;
        }

        return ($isNegative ? '-' : '') . '₹' . $intPart . '.' . $decPart;
    }

    /**
     * Format date from YYYY-MM-DD to DD-Mon-YYYY (e.g. 2026-08-01 -> 01-Aug-2026)
     */
    public static function formatDate(?string $dateStr): string
    {
        if (empty($dateStr)) {
            return '-';
        }
        $d = DateTime::createFromFormat('Y-m-d', substr(trim($dateStr), 0, 10));
        return $d ? $d->format('d-M-Y') : static::e($dateStr);
    }

    /**
     * Convert amount to INR words reusing includes/pdf/NumberToWordsINR.php
     */
    public static function formatAmountInWords(float|int|string $amount): string
    {
        return convertNumberToWordsINR($amount);
    }

    /**
     * Validate and sanitize URLs. Rejects javascript:, data:, vbscript: protocols.
     */
    public static function safeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }
        $url = trim($url);

        // Check for unsafe pseudo-protocols
        if (preg_match('/^(javascript|data|vbscript):/i', $url)) {
            return null;
        }

        // Must start with http://, https://, or relative /
        if (preg_match('/^(https?:\/\/|\/)/i', $url)) {
            return static::e($url);
        }

        return null;
    }

    /**
     * Render Complete HTML Email Document Envelope
     */
    public static function wrapHtml(string $title, string $contentHtml): string
    {
        $safeTitle = static::e($title);
        $companyName = 'Bhimavaram Digitals';
        $companyAddress = 'Main Road, Near Bus Stand Signal, Bhimavaram - 534201, AP';
        $companyContact = 'Phone: +91 98450 12233 | Email: billing@bhimavaramdigitals.in';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$safeTitle}</title>
<style>
  body { margin: 0; padding: 0; background-color: #f8fafc; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
  table { border-collapse: collapse; }
  .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
  .header { background-color: #2563eb; padding: 24px 32px; text-align: left; }
  .brand-logo { font-size: 20px; font-weight: bold; color: #ffffff; text-decoration: none; display: inline-block; }
  .brand-sub { font-size: 12px; color: #93c5fd; margin-top: 2px; }
  .body { padding: 32px; color: #1e293b; font-size: 14px; line-height: 1.6; }
  .footer { background-color: #f1f5f9; padding: 20px 32px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
  .summary-table { width: 100%; margin: 20px 0; border: 1px solid #e2e8f0; border-radius: 6px; }
  .summary-table th { background-color: #f8fafc; padding: 10px 14px; text-align: left; font-size: 12px; color: #475569; border-bottom: 1px solid #e2e8f0; }
  .summary-table td { padding: 10px 14px; font-size: 13px; color: #0f172a; border-bottom: 1px solid #f1f5f9; }
  .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
  .badge-paid { background-color: #dcfce7; color: #166534; }
  .badge-partial { background-color: #fef9c3; color: #854d0e; }
  .badge-unpaid { background-color: #fee2e2; color: #991b1b; }
  .badge-active { background-color: #dbeafe; color: #1e40af; }
  .btn-primary { display: inline-block; background-color: #2563eb; color: #ffffff !important; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; margin-top: 16px; }
</style>
</head>
<body>
<div style="background-color:#f8fafc; padding: 24px 12px;">
  <div class="container">
    <div class="header">
      <div class="brand-logo">ADSDASH · Bhimavaram Digitals</div>
      <div class="brand-sub">Outdoor & Billboard Media Advertising</div>
    </div>
    <div class="body">
      {$contentHtml}
    </div>
    <div class="footer">
      <p style="margin:0 0 6px 0; font-weight:bold; color:#334155;">{$companyName}</p>
      <p style="margin:0 0 4px 0;">{$companyAddress}</p>
      <p style="margin:0 0 8px 0;">{$companyContact}</p>
      <p style="margin:0; font-size:11px; color:#94a3b8;">This is an automated system notification from AdsDash.</p>
    </div>
  </div>
</div>
</body>
</html>
HTML;
    }
}
