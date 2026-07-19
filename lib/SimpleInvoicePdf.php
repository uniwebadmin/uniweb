<?php
declare(strict_types=1);

class SimpleInvoicePdf
{
    private string $content = '';
    private float $y = 800;
    private array $pages = [];

    public function addPage(): void
    {
        $this->pages[] = $this->content;
        $this->content = '';
        $this->y = 800;
    }

    public function text(float $x, float $y, string $text, int $size = 11, bool $bold = false): void
    {
        $font = $bold ? '/F2' : '/F1';
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $this->content .= "BT {$font} {$size} Tf {$x} {$y} Td ({$escaped}) Tj ET\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->content .= "{$x1} {$y1} m {$x2} {$y2} l S\n";
    }

    public function generate(array $inv, array $merchant): string
    {
        $invNo = $inv['invoice_id'];
        $this->text(50, 800, COMPANY_LEGAL_NAME, 14, true);
        $this->text(50, 785, 'GSTIN: ' . COMPANY_GST . ' | CIN: ' . COMPANY_CIN, 8);
        $this->text(50, 772, COMPANY_ADDRESS, 7);
        $this->text(50, 760, 'Email: ' . COMPANY_SUPPORT_EMAIL . ' | Phone: ' . COMPANY_PHONE, 7);
        $this->text(400, 800, 'TAX INVOICE', 16, true);
        $this->text(400, 785, 'Invoice No: ' . $invNo, 10);
        $this->text(400, 770, 'Date: ' . date('d M Y', strtotime($inv['created_at'])), 10);
        $this->line(50, 755, 545, 755);

        $this->text(50, 735, 'Bill From:', 11, true);
        $this->text(50, 720, $merchant['business_name'], 10);
        $this->text(50, 705, 'Name: ' . ($merchant['name'] ?? ''), 9);
        $this->text(50, 690, 'Merchant ID: ' . $merchant['merchant_code'], 9);
        if (!empty($merchant['email'])) $this->text(50, 675, 'Email: ' . $merchant['email'], 9);
        if (!empty($merchant['phone'])) $this->text(50, 660, 'Mobile: ' . $merchant['phone'], 9);
        if (!empty($merchant['gstin'])) $this->text(50, 645, 'GSTIN: ' . $merchant['gstin'], 9);
        if (!empty($merchant['address'])) $this->text(50, 630, 'Address: ' . $merchant['address'], 8);

        $this->text(300, 735, 'Bill To:', 11, true);
        $this->text(300, 720, $inv['customer_name'], 10);
        if (!empty($inv['customer_email'])) $this->text(300, 705, 'Email: ' . $inv['customer_email'], 9);
        if (!empty($inv['customer_phone'])) $this->text(300, 690, 'Mobile: ' . $inv['customer_phone'], 9);
        if (!empty($inv['customer_address'])) $this->text(300, 675, 'Address: ' . $inv['customer_address'], 8);

        $this->line(50, 615, 545, 615);
        $this->text(50, 595, 'Description', 10, true);
        $this->text(400, 595, 'Amount', 10, true);
        $this->line(50, 590, 545, 590);

        $items = json_decode($inv['items'] ?? '[]', true) ?: [['description' => 'Service', 'amount' => $inv['amount']]];
        $y = 570;
        foreach ($items as $item) {
            $this->text(50, $y, (string)($item['description'] ?? 'Item'), 10);
            $this->text(400, $y, CURRENCY_SYMBOL . number_format((float)($item['amount'] ?? 0), 2), 10);
            $y -= 18;
        }

        $y -= 10;
        $this->line(50, $y, 545, $y);
        $y -= 20;
        $this->text(300, $y, 'Subtotal:', 10);
        $this->text(400, $y, formatMoney((float)$inv['amount']), 10);
        $y -= 18;
        $this->text(300, $y, 'Tax:', 10);
        $this->text(400, $y, formatMoney((float)$inv['tax_amount']), 10);
        $y -= 18;
        $this->text(300, $y, 'Total:', 12, true);
        $this->text(400, $y, formatMoney((float)$inv['total_amount']), 12, true);

        if ($inv['due_date']) {
            $y -= 30;
            $this->text(50, $y, 'Due Date: ' . date('d M Y', strtotime($inv['due_date'])), 9);
        }
        $y -= 25;
        $this->text(50, $y, COMPANY_LEGAL_NAME . ' | MD: ' . COMPANY_CEO . ' | ' . APP_URL, 7);
        $y -= 12;
        $this->text(50, $y, 'This is a computer-generated invoice.', 7);

        $this->addPage();
        return $this->buildPdf();
    }

    private function buildPdf(): string
    {
        $pageContent = $this->pages[0] ?? $this->content;
        $objects = [];
        $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
        $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
        $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >> endobj\n";
        $stream = "4 0 obj << /Length " . strlen($pageContent) . " >> stream\n" . $pageContent . "endstream endobj\n";
        $objects[] = $stream;
        $objects[] = "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";
        $objects[] = "6 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >> endobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }
}
