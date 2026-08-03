<?php

declare(strict_types=1);

require_once __DIR__ . '/PdfBaseDocument.php';

class InvoicePdfBuilder extends PdfBaseDocument
{
    private array $invoice = [];
    private array $payments = [];

    public function __construct(PDO $pdo, array $invoice, array $payments = [])
    {
        $this->invoice = $invoice;
        $this->payments = $payments;
        $this->docTitle = 'TAX INVOICE';
        $this->docNumber = $invoice['invoice_number'] ?? 'INV-1001';
        $this->docDate = $invoice['invoice_date'] ?? date('Y-m-d');
        $this->docStatus = $invoice['computed_status'] ?? $invoice['status'] ?? 'unpaid';

        parent::__construct($pdo, 'P', 'mm', 'A4');
        $this->SetTitle('Invoice-' . $this->docNumber);
        $this->SetAuthor($this->companyInfo['company_name']);
    }

    public function buildPdf(): void
    {
        $this->AddPage();

        // 1. Metadata & Customer Billing Section
        $this->renderMetadataAndCustomerBlock();

        // 2. Invoice Items Table
        $this->renderItemsTable();

        // 3. Financial Summary & Amount in Words
        $this->renderFinancialSummary();

        // 4. Payment History Ledger Section
        $this->renderPaymentHistory();

        // 5. Terms, Bank Details & Authorized Signatory Block
        $this->renderTermsAndSignatory();
    }

    /**
     * Render Customer Info & Invoice Metadata Summary
     */
    private function renderMetadataAndCustomerBlock(): void
    {
        $startY = $this->GetY();

        // Left Box: Customer Bill To
        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(226, 232, 240);
        $this->Rect(12, $startY, 94, 34, 'DF');

        $this->SetXY(15, $startY + 3);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(88, 4, 'BILLED TO / CUSTOMER:', 0, 1, 'L');

        $c = $this->invoice['customer'] ?? [];
        $this->SetX(15);
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(88, 5, $c['company_name'] ?? 'N/A', 0, 1, 'L');

        $this->SetX(15);
        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(88, 4, 'Contact: ' . ($c['contact_person'] ?? 'N/A'), 0, 1, 'L');

        $this->SetX(15);
        $this->Cell(88, 4, 'Phone: ' . ($c['phone'] ?? '-') . ' | Email: ' . ($c['email'] ?? '-'), 0, 1, 'L');

        // Right Box: Invoice Details
        $this->Rect(108, $startY, 90, 34, 'DF');

        $this->SetXY(111, $startY + 3);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(84, 4, 'INVOICE DETAILS:', 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(15, 23, 42);

        $this->SetX(111);
        $this->Cell(35, 4.5, 'Invoice No:', 0, 0, 'L');
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->Cell(49, 4.5, $this->invoice['invoice_number'], 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8.5);
        $this->SetX(111);
        $this->Cell(35, 4.5, 'Invoice Date:', 0, 0, 'L');
        $this->Cell(49, 4.5, $this->formatDate($this->invoice['invoice_date']), 0, 1, 'L');

        $this->SetX(111);
        $this->Cell(35, 4.5, 'Due Date:', 0, 0, 'L');
        $this->Cell(49, 4.5, $this->formatDate($this->invoice['due_date']), 0, 1, 'L');

        $qRef = (!empty($this->invoice['quotation']) && is_array($this->invoice['quotation']) && !empty($this->invoice['quotation']['quotation_number']))
            ? $this->invoice['quotation']['quotation_number']
            : '-';
        $this->SetX(111);
        $this->Cell(35, 4.5, 'Quotation Ref:', 0, 0, 'L');
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->Cell(49, 4.5, $qRef, 0, 1, 'L');

        $this->SetY($startY + 38);
    }

    /**
     * Render Invoice Items Table (Locked Historical Prices)
     */
    private function renderItemsTable(): void
    {
        // Table Headers
        $this->SetFillColor(37, 99, 235);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 8.5);

        $this->Cell(8, 7, '#', 1, 0, 'C', true);
        $this->Cell(54, 7, 'Screen / Space Description', 1, 0, 'L', true);
        $this->Cell(30, 7, 'Type / Dim', 1, 0, 'L', true);
        $this->Cell(12, 7, 'Qty', 1, 0, 'C', true);
        $this->Cell(18, 7, 'Duration', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Unit Rate', 1, 0, 'R', true);
        $this->Cell(34, 7, 'Line Total', 1, 1, 'R', true);

        // Table Rows
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(15, 23, 42);
        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(226, 232, 240);

        $items = $this->invoice['items'] ?? [];
        $fill = false;
        $i = 1;

        foreach ($items as $item) {
            $desc = $item['description'] ?? $item['screen_name'] ?? 'Advertising Space';
            $typeDim = ($item['screen_type'] ?? '') . ($item['screen_city'] ? ' (' . $item['screen_city'] . ')' : '');
            $qty = number_format((float) ($item['quantity'] ?? 1), 0);
            $dur = ($item['duration_months'] ?? 1) . ' mo';
            $rate = $this->formatCurrency($item['unit_price'] ?? 0);
            $total = $this->formatCurrency($item['line_total'] ?? 0);

            $this->Cell(8, 6.5, (string) $i, 'LRB', 0, 'C', $fill);
            $this->Cell(54, 6.5, substr($desc, 0, 32), 'LRB', 0, 'L', $fill);
            $this->Cell(30, 6.5, substr($typeDim, 0, 18), 'LRB', 0, 'L', $fill);
            $this->Cell(12, 6.5, $qty, 'LRB', 0, 'C', $fill);
            $this->Cell(18, 6.5, $dur, 'LRB', 0, 'C', $fill);
            $this->Cell(30, 6.5, $rate, 'LRB', 0, 'R', $fill);
            $this->Cell(34, 6.5, $total, 'LRB', 1, 'R', $fill);

            $fill = !$fill;
            $i++;
        }

        $this->Ln(4);
    }

    /**
     * Render Financial Summary, Paid Amount, Balance & Amount in Words
     */
    private function renderFinancialSummary(): void
    {
        $startY = $this->GetY();

        // Amount in Words Box (Left Side)
        $totalAmount = (float) ($this->invoice['total_amount'] ?? 0);
        $wordsStr = convertNumberToWordsINR($totalAmount);

        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(226, 232, 240);
        $this->Rect(12, $startY, 106, 42, 'DF');

        $this->SetXY(15, $startY + 3);
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(100, 4, 'AMOUNT IN WORDS:', 0, 1, 'L');

        $this->SetX(15);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(15, 23, 42);
        $this->MultiCell(100, 4.5, $wordsStr, 0, 'L');

        // Financial Totals Table (Right Side)
        $this->SetXY(122, $startY);
        $subtotal = (float) ($this->invoice['subtotal'] ?? 0);
        $discount = (float) ($this->invoice['discount_amount'] ?? 0);
        $taxable = (float) ($this->invoice['taxable_amount'] ?? 0);
        $cgst = (float) ($this->invoice['cgst_amount'] ?? 0);
        $sgst = (float) ($this->invoice['sgst_amount'] ?? 0);
        $igst = (float) ($this->invoice['igst_amount'] ?? 0);
        $paid = (float) ($this->invoice['paid_amount'] ?? 0);
        $balance = (float) ($this->invoice['balance_amount'] ?? ($totalAmount - $paid));

        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(71, 85, 105);

        $rows = [
            ['Subtotal:', $this->formatCurrency($subtotal)],
            ['Discount:', '-' . $this->formatCurrency($discount)],
            ['Taxable Amount:', $this->formatCurrency($taxable)],
        ];

        if ($cgst > 0 || $sgst > 0) {
            $rows[] = ['CGST (' . ($this->invoice['cgst_rate'] ?? 9) . '%):', $this->formatCurrency($cgst)];
            $rows[] = ['SGST (' . ($this->invoice['sgst_rate'] ?? 9) . '%):', $this->formatCurrency($sgst)];
        }
        if ($igst > 0) {
            $rows[] = ['IGST (' . ($this->invoice['igst_rate'] ?? 18) . '%):', $this->formatCurrency($igst)];
        }

        foreach ($rows as $r) {
            $this->SetX(122);
            $this->Cell(40, 4.5, $r[0], 0, 0, 'L');
            $this->Cell(36, 4.5, $r[1], 0, 1, 'R');
        }

        // Total, Paid & Balance Due Rows
        $this->SetX(122);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(40, 5, 'TOTAL AMOUNT:', 0, 0, 'L');
        $this->Cell(36, 5, $this->formatCurrency($totalAmount), 0, 1, 'R');

        $this->SetX(122);
        $this->SetTextColor(22, 163, 74); // Green
        $this->Cell(40, 5, 'PAID AMOUNT:', 0, 0, 'L');
        $this->Cell(36, 5, $this->formatCurrency($paid), 0, 1, 'R');

        $this->SetX(122);
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetFillColor(37, 99, 235);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(40, 6.5, 'BALANCE DUE:', 1, 0, 'L', true);
        $this->Cell(36, 6.5, $this->formatCurrency($balance), 1, 1, 'R', true);

        $this->SetY(max($this->GetY(), $startY + 46));
    }

    /**
     * Render Payment History Ledger (Part 11 requirement)
     */
    private function renderPaymentHistory(): void
    {
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(186, 5, 'PAYMENT HISTORY LEDGER:', 0, 1, 'L');

        if (empty($this->payments)) {
            $this->SetFont('Helvetica', 'I', 8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(186, 5, 'No payments recorded for this invoice yet.', 0, 1, 'L');
            $this->Ln(2);
            return;
        }

        // Table Header
        $this->SetFillColor(241, 245, 249);
        $this->SetTextColor(15, 23, 42);
        $this->SetFont('Helvetica', 'B', 8);
        $this->Cell(30, 5.5, 'Date', 1, 0, 'C', true);
        $this->Cell(40, 5.5, 'Payment Method', 1, 0, 'L', true);
        $this->Cell(76, 5.5, 'Reference / Txn ID', 1, 0, 'L', true);
        $this->Cell(40, 5.5, 'Amount Paid', 1, 1, 'R', true);

        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(51, 65, 85);

        foreach ($this->payments as $p) {
            $this->Cell(30, 5, $this->formatDate($p['payment_date']), 1, 0, 'C');
            $this->Cell(40, 5, strtoupper($p['payment_method']), 1, 0, 'L');
            $this->Cell(76, 5, $p['reference_number'] ?: '-', 1, 0, 'L');
            $this->Cell(40, 5, $this->formatCurrency($p['amount']), 1, 1, 'R');
        }

        $this->Ln(2);
    }

    /**
     * Render Terms, Bank Details & Authorized Signatory Box
     */
    private function renderTermsAndSignatory(): void
    {
        $startY = $this->GetY();

        // Bank Details & Terms (Left Side)
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(110, 4, 'BANK REMITTANCE DETAILS:', 0, 1, 'L');

        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(71, 85, 105);

        $bankText = "Bank: " . ($this->companyInfo['bank_name'] ?? 'State Bank of India') . "\n" .
                    "A/C No: " . ($this->companyInfo['bank_account_number'] ?? '398811224455') . "\n" .
                    "IFSC: " . ($this->companyInfo['bank_ifsc'] ?? 'SBIN0001234') . "\n" .
                    "Terms: " . ($this->invoice['terms_conditions'] ?? 'Payment due as per invoice terms.');

        $this->MultiCell(110, 3.8, $bankText, 0, 'L');

        // Right Block: Authorized Signatory
        $this->SetXY(132, $startY + 2);
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(64, 4, 'For ' . $this->companyInfo['company_name'], 0, 1, 'R');

        $this->SetXY(132, $startY + 18);
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(64, 4, 'Authorized Signatory', 'T', 1, 'C');
    }
}
