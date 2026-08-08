<?php
declare(strict_types=1);

class SimpleStatementPdf
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

    private function sanitize(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        return preg_replace('/[^\x20-\x7E\xA0-\xFF]/u', '', $text) ?? $text;
    }

    private function fmt(float $n): string
    {
        if (function_exists('formatMoney')) {
            return formatMoney($n);
        }
        $sym = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'Rs.';
        return $sym . number_format($n, 2);
    }

    private function display(string $value, string $fallback = '-'): string
    {
        $value = trim($value);
        return $value !== '' ? $value : $fallback;
    }

    public function generate(array $merchant, array $transactions, array $summary, string $fromDate, string $toDate): string
    {
        $bizName = $this->display((string)($merchant['business_name'] ?? ''), 'Merchant');
        $merchantCode = $this->display((string)($merchant['merchant_code'] ?? ''));
        $merchantEmail = $this->display((string)($merchant['email'] ?? ''));
        $merchantPhone = $this->display((string)($merchant['phone'] ?? ''));

        // Header
        $this->text(50, 800, COMPANY_LEGAL_NAME, 14, true);
        $this->text(50, 785, 'GSTIN: ' . COMPANY_GST . ' | CIN: ' . COMPANY_CIN, 8);
        $this->text(50, 772, COMPANY_ADDRESS, 7);
        $this->text(50, 760, 'Email: ' . COMPANY_SUPPORT_EMAIL . ' | Phone: ' . COMPANY_PHONE, 7);
        $this->text(350, 800, 'MERCHANT STATEMENT', 14, true);
        $this->text(350, 785, 'Period: ' . $fromDate . ' to ' . $toDate, 9);
        $this->text(350, 772, 'Generated: ' . date('d M Y H:i'), 8);
        $this->line(50, 755, 545, 755);

        // Merchant info
        $y = 735;
        $this->text(50, $y, 'Merchant:', 11, true);
        $this->text(50, $y - 15, $bizName, 10, true);
        $this->text(50, $y - 28, 'ID: ' . $merchantCode, 9);
        $this->text(50, $y - 40, 'Email: ' . $merchantEmail, 9);
        $this->text(50, $y - 52, 'Phone: ' . $merchantPhone, 9);

        // Summary box
        $sy = $y - 75;
        $this->line(50, $sy + 5, 545, $sy + 5);
        $this->text(50, $sy, 'SUMMARY', 10, true);
        $this->text(50, $sy - 15, 'Total Transactions: ' . (int)($summary['count'] ?? 0), 9);
        $this->text(50, $sy - 28, 'Successful: ' . (int)($summary['success_count'] ?? 0), 9);
        $this->text(50, $sy - 41, 'Failed: ' . (int)($summary['failed_count'] ?? 0), 9);
        $this->text(300, $sy - 15, 'Total Collected: ' . $this->fmt((float)($summary['total_amount'] ?? 0)), 9);
        $this->text(300, $sy - 28, 'Platform Fee: ' . $this->fmt((float)($summary['total_fee'] ?? 0)), 9);
        $this->text(300, $sy - 41, 'Net Settled: ' . $this->fmt((float)($summary['net_amount'] ?? 0)), 9, true);
        $this->line(50, $sy - 50, 545, $sy - 50);

        // Transactions table header
        $y = $sy - 70;
        $this->text(50, $y, 'Txn ID', 9, true);
        $this->text(160, $y, 'Date', 9, true);
        $this->text(250, $y, 'Method', 9, true);
        $this->text(350, $y, 'Status', 9, true);
        $this->text(460, $y, 'Amount', 9, true);
        $y -= 5;
        $this->line(50, $y, 545, $y);
        $y -= 16;

        $count = 0;
        $maxRows = 35;
        foreach ($transactions as $txn) {
            if ($count >= $maxRows) {
                $this->text(50, $y, '... and ' . (count($transactions) - $count) . ' more transactions. Download CSV for full list.', 8);
                break;
            }
            $txnId = $this->display((string)($txn['txn_id'] ?? ''));
            if (strlen($txnId) > 16) {
                $txnId = substr($txnId, 0, 14) . '..';
            }
            $date = !empty($txn['created_at']) ? date('d M Y', strtotime((string)$txn['created_at'])) : '-';
            $method = $this->display((string)($txn['payment_method'] ?? ''));
            if (strlen($method) > 12) {
                $method = substr($method, 0, 10) . '..';
            }
            $status = $this->display((string)($txn['status'] ?? ''));
            $amount = $this->fmt((float)($txn['amount'] ?? 0));

            $this->text(50, $y, $txnId, 8);
            $this->text(160, $y, $date, 8);
            $this->text(250, $y, $method, 8);
            $this->text(350, $y, $status, 8);
            $this->text(460, $y, $amount, 8);
            $y -= 15;
            $count++;

            if ($y < 60) {
                $this->addPage();
                $y = 800;
                $this->text(50, $y, 'Txn ID', 9, true);
                $this->text(160, $y, 'Date', 9, true);
                $this->text(250, $y, 'Method', 9, true);
                $this->text(350, $y, 'Status', 9, true);
                $this->text(460, $y, 'Amount', 9, true);
                $y -= 5;
                $this->line(50, $y, 545, $y);
                $y -= 16;
            }
        }

        $y -= 20;
        $this->line(50, $y + 5, 545, $y + 5);
        $this->text(50, $y, COMPANY_LEGAL_NAME . ' | ' . APP_URL, 7);
        $this->text(50, $y - 12, 'This is a computer-generated statement. Merchant: ' . $merchantCode, 7);

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
