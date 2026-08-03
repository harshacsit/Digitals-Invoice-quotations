<?php

declare(strict_types=1);

require_once __DIR__ . '/PdfBaseDocument.php';

class QuotationPdfBuilder extends PdfBaseDocument
{
    private array $quotation = [];

    public function __construct(PDO $pdo, array $quotation)
    {
        $this->quotation = $quotation;
        $this->docTitle = 'QUOTATION';
        $this->docNumber = $quotation['quotation_number'] ?? 'QT-1001';
        $this->docDate = $quotation['quotation_date'] ?? date('Y-m-d');
        $this->docStatus = $quotation['status'] ?? 'draft';

        parent::__construct($pdo, 'P', 'mm', 'A4');
        $this->SetTitle('Quotation-' . $this->docNumber);
        $this->SetAuthor($this->companyInfo['company_name']);
    }

    public function buildPdf(): void
    {
        $this->AddPage();

        // 1. Metadata & Customer Billing Section
        $this->renderMetadataAndCustomerBlock();

        // 2. Items Table
        $this->renderItemsTable();

        // 3. Financial Summary & Amount in Words
        $this->renderFinancialSummary();

        // 4. Terms, Notes & Authorized Signatory Block
        $this->renderTermsAndSignatory();
    }

    /**
     * Render Customer Info & Metadata Summary
     */
    private function renderMetadataAndCustomerBlock(): void
    {
        $startY = $this->GetY();

        // Left Box: Customer Bill To
        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(226, 232, 240);
        $this->Rect(12, $startY, 94, 32, 'DF');

        $this->SetXY(15, $startY + 3);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(88, 4, 'CUSTOMER / PROPOSED FOR:', 0, 1, 'L');

        $c = $this->quotation['customer'] ?? [];
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

        // Right Box: Quotation Details
        $this->Rect(108, $startY, 90, 32, 'DF');

        $this->SetXY(111, $startY + 3);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(84, 4, 'QUOTATION DETAILS:', 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(15, 23, 42);

        $this->SetX(111);
        $this->Cell(35, 4.5, 'Quotation No:', 0, 0, 'L');
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->Cell(49, 4.5, $this->quotation['quotation_number'], 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8.5);
        $this->SetX(111);
        $this->Cell(35, 4.5, 'Quotation Date:', 0, 0, 'L');
        $this->Cell(49, 4.5, $this->formatDate($this->quotation['quotation_date']), 0, 1, 'L');

        $this->SetX(111);
        $this->Cell(35, 4.5, 'Valid Until:', 0, 0, 'L');
        $this->Cell(49, 4.5, $this->formatDate($this->quotation['valid_until']), 0, 1, 'L');

        $this->SetX(111);
        $this->Cell(35, 4.5, 'Status:', 0, 0, 'L');
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->Cell(49, 4.5, strtoupper($this->quotation['status']), 0, 1, 'L');

        $this->SetY($startY + 36);
    }

    /**
     * Render Quotation Items Table
     */
    private function renderItemsTable(): void
    {
        // Table Headers
        $this->SetFillColor(37, 99, 235);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 8.5);

        // Columns: # (8), Description (54), Type (30), Qty (12), Dur (18), Rate (30), Total (34)
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

        $items = $this->quotation['items'] ?? [];
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
     * Render Financial Summary & Amount in Words
     */
    private function renderFinancialSummary(): void
    {
        $startY = $this->GetY();

        // Amount in Words (Left Side)
        $totalAmount = (float) ($this->quotation['total_amount'] ?? 0);
        $wordsStr = convertNumberToWordsINR($totalAmount);

        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(226, 232, 240);
        $this->Rect(12, $startY, 106, 36, 'DF');

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
        $subtotal = (float) ($this->quotation['subtotal'] ?? 0);
        $discount = (float) ($this->quotation['discount_amount'] ?? 0);
        $taxable = (float) ($this->quotation['taxable_amount'] ?? 0);
        $cgst = (float) ($this->quotation['cgst_amount'] ?? 0);
        $sgst = (float) ($this->quotation['sgst_amount'] ?? 0);
        $igst = (float) ($this->quotation['igst_amount'] ?? 0);

        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(71, 85, 105);

        $rows = [
            ['Subtotal:', $this->formatCurrency($subtotal)],
            ['Discount:', '-' . $this->formatCurrency($discount)],
            ['Taxable Amount:', $this->formatCurrency($taxable)],
        ];

        if ($cgst > 0 || $sgst > 0) {
            $rows[] = ['CGST (' . ($this->quotation['cgst_rate'] ?? 9) . '%):', $this->formatCurrency($cgst)];
            $rows[] = ['SGST (' . ($this->quotation['sgst_rate'] ?? 9) . '%):', $this->formatCurrency($sgst)];
        }
        if ($igst > 0) {
            $rows[] = ['IGST (' . ($this->quotation['igst_rate'] ?? 18) . '%):', $this->formatCurrency($igst)];
        }

        foreach ($rows as $r) {
            $this->SetX(122);
            $this->Cell(40, 5, $r[0], 0, 0, 'L');
            $this->Cell(36, 5, $r[1], 0, 1, 'R');
        }

        // Grand Total Box
        $this->SetX(122);
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetFillColor(37, 99, 235);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(40, 7, 'TOTAL AMOUNT:', 1, 0, 'L', true);
        $this->Cell(36, 7, $this->formatCurrency($totalAmount), 1, 1, 'R', true);

        $this->SetY(max($this->GetY(), $startY + 40));
    }

    /**
     * Render Terms, Notes & Authorized Signatory Box
     */
    private function renderTermsAndSignatory(): void
    {
        $startY = $this->GetY();

        // Terms & Conditions Block
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(110, 4, 'TERMS & CONDITIONS & NOTES:', 0, 1, 'L');

        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(71, 85, 105);

        $terms = $this->quotation['terms_conditions'] ?? '1. Payment terms as agreed in proposal. 2. Subject to Bhimavaram jurisdiction.';
        $notes = $this->quotation['notes'] ?? '';

        $fullText = $terms . ($notes ? "\nNotes: " . $notes : '');
        $this->MultiCell(110, 3.8, $fullText, 0, 'L');

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
