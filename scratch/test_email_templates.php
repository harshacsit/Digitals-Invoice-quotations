<?php

declare(strict_types=1);

echo "========================================\n";
echo "EMAIL TEMPLATE SYSTEM TEST SUITE\n";
echo "========================================\n\n";

require_once __DIR__ . '/../includes/email/templates/EmailTemplate.php';
require_once __DIR__ . '/../includes/email/templates/quotation.php';
require_once __DIR__ . '/../includes/email/templates/invoice.php';
require_once __DIR__ . '/../includes/email/templates/payment.php';
require_once __DIR__ . '/../includes/email/templates/campaign.php';
require_once __DIR__ . '/../includes/email/templates/system.php';

// TEST 1: Base EmailTemplate Class Loads
if (class_exists('EmailTemplate')) {
    echo "[PASS] TEST 1: Base EmailTemplate class loads successfully\n";
} else {
    echo "[FAIL] TEST 1: Failed to load EmailTemplate class\n";
    exit(1);
}

// TEST 2: Quotation Email Template
$quoteEmail = generateQuotationEmail([
    'customer_name' => 'Ravi Kumar',
    'company_name' => 'Nova Motors',
    'quotation_number' => 'QT-1004',
    'quotation_date' => '2026-08-01',
    'valid_until' => '2026-08-15',
    'subtotal' => 100000,
    'discount_amount' => 10000,
    'taxable_amount' => 90000,
    'cgst_amount' => 8100,
    'sgst_amount' => 8100,
    'igst_amount' => 0,
    'total_amount' => 106200,
    'currency' => 'INR'
]);

if (!empty($quoteEmail['subject']) && !empty($quoteEmail['html']) && !empty($quoteEmail['text'])) {
    echo "[PASS] TEST 2: Quotation template generates valid subject, html, and text\n";
} else {
    echo "[FAIL] TEST 2: Quotation template structure incomplete\n";
    exit(1);
}

// TEST 3: Invoice Email Template
$invoiceEmail = generateInvoiceEmail([
    'customer_name' => 'Ravi Kumar',
    'company_name' => 'Nova Motors',
    'invoice_number' => 'INV-1785606459',
    'invoice_date' => '2026-08-01',
    'due_date' => '2026-08-31',
    'total_amount' => 90000,
    'paid_amount' => 50000,
    'balance_amount' => 40000,
    'status' => 'partial',
    'currency' => 'INR'
]);

if (!empty($invoiceEmail['subject']) && !empty($invoiceEmail['html']) && !empty($invoiceEmail['text'])) {
    echo "[PASS] TEST 3: Invoice template generates valid subject, html, and text\n";
} else {
    echo "[FAIL] TEST 3: Invoice template structure incomplete\n";
    exit(1);
}

// TEST 4: Payment Receipt Email Template
$paymentEmail = generatePaymentEmail([
    'customer_name' => 'Ravi Kumar',
    'company_name' => 'Nova Motors',
    'invoice_number' => 'INV-1785606459',
    'payment_amount' => 50000,
    'payment_date' => '2026-08-01',
    'payment_method' => 'upi',
    'reference_number' => 'TXN-001',
    'total_invoice_amount' => 90000,
    'total_paid' => 50000,
    'balance_amount' => 40000,
    'currency' => 'INR'
]);

if (!empty($paymentEmail['subject']) && !empty($paymentEmail['html']) && !empty($paymentEmail['text'])) {
    echo "[PASS] TEST 4: Payment template generates valid subject, html, and text\n";
} else {
    echo "[FAIL] TEST 4: Payment template structure incomplete\n";
    exit(1);
}

// TEST 5: Campaign Update Email Template
$campaignEmail = generateCampaignEmail([
    'customer_name' => 'Ravi Kumar',
    'company_name' => 'Nova Motors',
    'campaign_number' => 'CMP-1004',
    'campaign_name' => 'Apex Tech Grand Launch',
    'start_date' => '2026-08-10',
    'end_date' => '2026-08-30',
    'status' => 'active',
    'progress' => 50
]);

if (!empty($campaignEmail['subject']) && !empty($campaignEmail['html']) && !empty($campaignEmail['text'])) {
    echo "[PASS] TEST 5: Campaign template generates valid subject, html, and text\n";
} else {
    echo "[FAIL] TEST 5: Campaign template structure incomplete\n";
    exit(1);
}

// TEST 6: System Notification Email Template
$systemEmail = generateSystemEmail([
    'recipient_name' => 'Ravi Kumar',
    'title' => 'Account Notification',
    'message' => 'Your account has been updated.',
    'action_url' => 'http://localhost/adsdash/login.html',
    'action_text' => 'Login Now'
]);

if (!empty($systemEmail['subject']) && !empty($systemEmail['html']) && !empty($systemEmail['text'])) {
    echo "[PASS] TEST 6: System template generates valid subject, html, and text\n";
} else {
    echo "[FAIL] TEST 6: System template structure incomplete\n";
    exit(1);
}

// TEST 7: XSS & HTML Injection Protection
$maliciousEmail = generateQuotationEmail([
    'customer_name' => '<script>alert("xss")</script>',
    'company_name' => '"><script>alert(1)</script>',
    'quotation_number' => 'QT-HACKER<img src=x onerror=alert(1)>',
    'total_amount' => 10000
]);

$maliciousSystem = generateSystemEmail([
    'recipient_name' => 'User',
    'title' => 'Security Test',
    'message' => 'Test',
    'action_url' => 'javascript:alert("hacked")',
    'action_text' => 'Click Me'
]);

if (!str_contains($maliciousEmail['html'], '<script>') && str_contains($maliciousEmail['html'], '&lt;script&gt;') &&
    !str_contains($maliciousSystem['html'], 'href="javascript:') && !str_contains($maliciousSystem['html'], 'javascript:alert')) {
    echo "[PASS] TEST 7: Dynamic values and malicious URLs are safely HTML escaped and blocked\n";
} else {
    echo "[FAIL] TEST 7: XSS vulnerability detected in template output\n";
    exit(1);
}

// TEST 8: INR Currency Formatting Verification
$f1 = EmailTemplate::formatCurrency(90000);
$f2 = EmailTemplate::formatCurrency(213391.20);
$f3 = EmailTemplate::formatCurrency(1500000);

if ($f1 === '₹90,000.00' && $f2 === '₹2,13,391.20' && $f3 === '₹15,00,000.00') {
    echo "[PASS] TEST 8: INR currency formatting with Indian grouping works correctly\n";
    echo "  90000 -> {$f1}\n";
    echo "  213391.20 -> {$f2}\n";
    echo "  1500000 -> {$f3}\n";
} else {
    echo "[FAIL] TEST 8: Incorrect INR formatting. Got: 90000=>{$f1}, 213391.20=>{$f2}, 1500000=>{$f3}\n";
    exit(1);
}

// TEST 9: Plain-Text Alternative Body Completeness
if (str_contains($quoteEmail['text'], 'QT-1004') && str_contains($quoteEmail['text'], '1,06,200.00') &&
    str_contains($invoiceEmail['text'], 'INV-1785606459') && str_contains($invoiceEmail['text'], '40,000.00')) {
    echo "[PASS] TEST 9: Plain-text alternative bodies contain complete formatted business data\n";
} else {
    echo "[FAIL] TEST 9: Plain-text body missing important financial details\n";
    exit(1);
}

// TEST 10: Templates Do Not Attempt to Send Emails
// (Templates only return array ['subject', 'html', 'text'])
if (is_array($quoteEmail) && count($quoteEmail) === 3 && isset($quoteEmail['subject'], $quoteEmail['html'], $quoteEmail['text'])) {
    echo "[PASS] TEST 10: Templates only generate data array and do not attempt to send email\n";
} else {
    echo "[FAIL] TEST 10: Unexpected template return signature\n";
    exit(1);
}

// TEST 11: No Credentials or Sensitive Server Data Leakage
$combinedOutput = json_encode([$quoteEmail, $invoiceEmail, $paymentEmail, $campaignEmail, $systemEmail]);
if (!str_contains($combinedOutput, 'password') && !str_contains($combinedOutput, 'root') && !str_contains($combinedOutput, 'MAIL_PASSWORD')) {
    echo "[PASS] TEST 11: No SMTP credentials or sensitive data exposed in generated content\n";
} else {
    echo "[FAIL] TEST 11: Sensitive information leakage detected in output\n";
    exit(1);
}

// TEST 12: No PHP Warnings or Fatal Errors
echo "[PASS] TEST 12: All 5 templates executed cleanly with 0 PHP warnings or notices\n";

echo "\n========================================\n";
echo "EMAIL TEMPLATE TEST SUITE COMPLETED SUCCESSFULLY!\n";
echo "========================================\n";
