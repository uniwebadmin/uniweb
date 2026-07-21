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
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->sanitize($text));
        $this->content .= "BT {$font} {$size} Tf {$x} {$y} Td ({$escaped}) Tj ET\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->content .= "{$x1} {$y1} m {$x2} {$y2} l S\n";
    }

    /** Strip non-PDF-safe characters (Helvetica Latin-1 subset). */
    private function sanitize(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        // Keep printable ASCII + common Latin-1; drop the rest.
        return preg_replace('/[^\x20-\x7E\xA0-\xFF]/u', '', $text) ?? $text;
    }

    private function display(string $value, string $fallback = '—'): string
    {
        $value = trim($value);
        return $value !== '' ? $value : $fallback;
    }

    /** Compose a single-line postal address from merchant profile fields. */
    public static function merchantFullAddress(array $merchant): string
    {
        $parts = [];
        foreach (['address', 'city', 'district', 'state', 'pincode', 'country'] as $key) {
            $v = trim((string)($merchant[$key] ?? ''));
            if ($v !== '' && !in_array($v, $parts, true)) {
                $parts[] = $v;
            }
        }
        return implode(', ', $parts);
    }

    public function generate(array $inv, array $merchant): string
    {
        $invNo = trim((string)($inv['invoice_id'] ?? ''));
        if ($invNo === '') {
            $invNo = 'PENDING';
        }

        $bizName = $this->display((string)($merchant['business_name'] ?? ''), 'Merchant');
        $contactName = $this->display((string)($merchant['name'] ?? ''));
        $merchantEmail = $this->display((string)($merchant['email'] ?? ''));
        $merchantPhone = $this->display((string)($merchant['phone'] ?? ''));
        $merchantGst = $this->display((string)($merchant['gstin'] ?? ''), 'Not provided');
        $merchantAddr = $this->display(self::merchantFullAddress($merchant), 'Not provided');

        $custName = $this->display((string)($inv['customer_name'] ?? ''), 'Customer');
        $custEmail = $this->display((string)($inv['customer_email'] ?? ''));
        $custPhone = $this->display((string)($inv['customer_phone'] ?? ''));
        $custAddr = $this->display((string)($inv['customer_address'] ?? ''), 'Not provided');

        // —— Header (platform issuer) ——
        $this->text(50, 800, COMPANY_LEGAL_NAME, 14, true);
        $this->text(50, 785, 'GSTIN: ' . COMPANY_GST . ' | CIN: ' . COMPANY_CIN, 8);
        $this->text(50, 772, COMPANY_ADDRESS, 7);
        $this->text(50, 760, 'Email: ' . COMPANY_SUPPORT_EMAIL . ' | Phone: ' . COMPANY_PHONE, 7);
        $this->text(400, 800, 'TAX INVOICE', 16, true);
        $this->text(400, 785, 'Invoice No: ' . $invNo, 10, true);
        $created = !empty($inv['created_at']) ? date('d M Y', strtotime((string)$inv['created_at'])) : date('d M Y');
        $this->text(400, 770, 'Date: ' . $created, 10);
        $this->line(50, 755, 545, 755);

        // —— Bill From (merchant) — always show required identity fields ——
        $yL = 735.0;
        $this->text(50, $yL, 'Bill From:', 11, true);
        $yL -= 15;
        $this->text(50, $yL, $bizName, 10, true);
        $yL -= 14;
        $this->text(50, $yL, 'Contact: ' . $contactName, 9);
        $yL -= 13;
        $this->text(50, $yL, 'Merchant ID: ' . $this->display((string)($merchant['merchant_code'] ?? '')), 9);
        $yL -= 13;
        $this->text(50, $yL, 'Email: ' . $merchantEmail, 9);
        $yL -= 13;
        $this->text(50, $yL, 'Mobile: ' . $merchantPhone, 9);
        $yL -= 13;
        $this->text(50, $yL, 'GSTIN: ' . $merchantGst, 9);
        $yL -= 13;
        // Wrap long address across two lines if needed
        if (strlen($merchantAddr) > 55) {
            $this->text(50, $yL, 'Address: ' . substr($merchantAddr, 0, 55), 8);
            $yL -= 11;
            $this->text(50, $yL, substr($merchantAddr, 55, 70), 8);
            $yL -= 13;
        } else {
            $this->text(50, $yL, 'Address: ' . $merchantAddr, 8);
            $yL -= 13;
        }

        // —— Bill To (customer) — always show name / email / mobile / address ——
        $yR = 735.0;
        $this->text(300, $yR, 'Bill To:', 11, true);
        $yR -= 15;
        $this->text(300, $yR, $custName, 10, true);
        $yR -= 14;
        $this->text(300, $yR, 'Email: ' . $custEmail, 9);
        $yR -= 13;
        $this->text(300, $yR, 'Mobile: ' . $custPhone, 9);
        $yR -= 13;
        if (strlen($custAddr) > 45) {
            $this->text(300, $yR, 'Address: ' . substr($custAddr, 0, 45), 8);
            $yR -= 11;
            $this->text(300, $yR, substr($custAddr, 45, 60), 8);
            $yR -= 13;
        } else {
            $this->text(300, $yR, 'Address: ' . $custAddr, 8);
            $yR -= 13;
        }

        $sepY = min($yL, $yR) - 8;
        $this->line(50, $sepY, 545, $sepY);

        $y = $sepY - 20;
        $this->text(50, $y, 'Description', 10, true);
        $this->text(400, $y, 'Amount', 10, true);
        $y -= 5;
        $this->line(50, $y, 545, $y);

        $items = json_decode($inv['items'] ?? '[]', true) ?: [['description' => 'Service', 'amount' => $inv['amount'] ?? 0]];
        $y -= 20;
        foreach ($items as $item) {
            $desc = $this->display((string)($item['description'] ?? 'Item'), 'Item');
            if (strlen($desc) > 55) {
                $desc = substr($desc, 0, 52) . '...';
            }
            $this->text(50, $y, $desc, 10);
            $this->text(400, $y, CURRENCY_SYMBOL . number_format((float)($item['amount'] ?? 0), 2), 10);
            $y -= 18;
        }

        $y -= 10;
        $this->line(50, $y, 545, $y);
        $y -= 20;
        $this->text(300, $y, 'Subtotal:', 10);
        $this->text(400, $y, formatMoney((float)($inv['amount'] ?? 0)), 10);
        $y -= 18;
        $this->text(300, $y, 'Tax:', 10);
        $this->text(400, $y, formatMoney((float)($inv['tax_amount'] ?? 0)), 10);
        $y -= 18;
        $this->text(300, $y, 'Total:', 12, true);
        $this->text(400, $y, formatMoney((float)($inv['total_amount'] ?? 0)), 12, true);

        if (!empty($inv['due_date'])) {
            $y -= 30;
            $this->text(50, $y, 'Due Date: ' . date('d M Y', strtotime((string)$inv['due_date'])), 9);
        }
        $y -= 25;
        $this->text(50, $y, COMPANY_LEGAL_NAME . ' | MD: ' . COMPANY_CEO . ' | ' . APP_URL, 7);
        $y -= 12;
        $this->text(50, $y, 'This is a computer-generated invoice. Invoice No: ' . $invNo, 7);

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
