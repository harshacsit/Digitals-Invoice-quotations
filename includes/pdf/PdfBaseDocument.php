<?php

declare(strict_types=1);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/NumberToWordsINR.php';

class PdfBaseDocument extends FPDF
{
    protected array $companyInfo = [];
    protected string $docTitle = 'DOCUMENT';
    protected string $docNumber = '';
    protected string $docDate = '';
    protected string $docStatus = '';

    public function __construct(PDO $pdo, string $orientation = 'P', string $unit = 'mm', string $size = 'A4')
    {
        parent::__construct($orientation, $unit, $size);
        $this->AliasNbPages('{nb}');
        $this->SetMargins(12, 12, 12);
        $this->SetAutoPageBreak(true, 18);
        $this->loadCompanySettings($pdo);
    }

    /**
     * Load Company details from database settings table
     */
    protected function loadCompanySettings(PDO $pdo): void
    {
        $settings = [
            'company_name' => 'Bhimavaram Digitals',
            'company_tagline' => 'Media Screening & Billboard Advertising Co.',
            'company_address' => 'Main Road, Near Bus Stand Signal, Bhimavaram - 534201, AP',
            'company_phone' => '+91 98450 12233',
            'company_email' => 'billing@bhimavaramdigitals.in',
            'company_gstin' => '37AAACB1234C1Z5',
            'company_website' => 'www.bhimavaramdigitals.in',
            'bank_name' => 'State Bank of India',
            'bank_account_number' => '398811224455',
            'bank_ifsc' => 'SBIN0001234',
        ];

        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
            $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (is_array($dbSettings)) {
                foreach ($dbSettings as $k => $v) {
                    if (!empty(trim((string) $v))) {
                        $settings[$k] = trim((string) $v);
                    }
                }
            }
        } catch (Throwable $e) {
            // Use defaults if query fails
        }

        $this->companyInfo = $settings;
    }

    /**
     * Standard Document Header
     */
    public function Header(): void
    {
        // Draw top accent line
        $this->SetDrawColor(37, 99, 235); // #2563EB primary blue
        $this->SetLineWidth(1.2);
        $this->Line(12, 10, 198, 10);

        $this->SetY(14);

        // Left Header: Brand Logo / Company Mark
        $this->SetFillColor(37, 99, 235);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 12);
        $this->Rect(12, 14, 12, 12, 'F');
        $this->SetXY(12, 14);
        $this->Cell(12, 12, 'AD', 0, 0, 'C');

        // Company Details
        $this->SetXY(27, 14);
        $this->SetTextColor(15, 23, 42);
        $this->SetFont('Helvetica', 'B', 14);
        $this->Cell(100, 5, $this->companyInfo['company_name'], 0, 1, 'L');

        $this->SetX(27);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(100, 4, $this->companyInfo['company_address'], 0, 1, 'L');

        $this->SetX(27);
        $contactStr = 'Phone: ' . $this->companyInfo['company_phone'] . ' | Email: ' . $this->companyInfo['company_email'];
        $this->Cell(100, 4, $contactStr, 0, 1, 'L');

        if (!empty($this->companyInfo['company_gstin'])) {
            $this->SetX(27);
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetTextColor(30, 41, 59);
            $this->Cell(100, 4, 'GSTIN: ' . $this->companyInfo['company_gstin'], 0, 1, 'L');
        }

        // Right Header: Document Title & Metadata
        $this->SetXY(130, 14);
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(68, 6, strtoupper($this->docTitle), 0, 1, 'R');

        if (!empty($this->docNumber)) {
            $this->SetX(130);
            $this->SetFont('Helvetica', 'B', 10);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(68, 5, $this->docNumber, 0, 1, 'R');
        }

        if (!empty($this->docStatus)) {
            $this->SetX(130);
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetTextColor(37, 99, 235);
            $this->Cell(68, 4, 'STATUS: ' . strtoupper($this->docStatus), 0, 1, 'R');
        }

        // Horizontal divider line
        $this->SetY(34);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.4);
        $this->Line(12, 34, 198, 34);
        $this->Ln(3);
    }

    /**
     * Standard Document Footer
     */
    public function Footer(): void
    {
        $this->SetY(-16);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.4);
        $this->Line(12, $this->GetY(), 198, $this->GetY());

        $this->SetY(-13);
        $this->SetFont('Helvetica', 'I', 7.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(130, 4, 'This is a computer-generated document and does not require a physical signature.', 0, 0, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->Cell(56, 4, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }

    /**
     * Format currency amount as Rs. XX,XXX.XX
     */
    public function formatCurrency(float|int|string $amount): string
    {
        return 'Rs. ' . number_format((float) $amount, 2, '.', ',');
    }

    /**
     * Format date from YYYY-MM-DD to DD-MM-YYYY
     */
    public function formatDate(?string $dateStr): string
    {
        if (empty($dateStr)) {
            return '-';
        }
        $dObj = DateTime::createFromFormat('Y-m-d', substr($dateStr, 0, 10));
        return $dObj ? $dObj->format('d-m-Y') : $dateStr;
    }
}
