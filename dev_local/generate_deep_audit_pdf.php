<?php
/**
 * UniWeb Deep A–Z Audit PDF generator (English, 3-point format).
 * Run: php dev_local/generate_deep_audit_pdf.php
 */
declare(strict_types=1);

$findings = require __DIR__ . '/deep_audit_findings.php';

class AuditPdf
{
    private array $pages = [];
    private string $buf = '';
    private float $y = 800;
    private const LEFT = 48.0;
    private const RIGHT = 547.0;
    private const BOTTOM = 50.0;
    private const TOP = 800.0;

    private function sanitize(string $t): string
    {
        $t = str_replace(["\r", "\n", "\t"], ' ', $t);
        $t = preg_replace('/[^\x20-\x7E]/', '', $t) ?? $t;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
    }

    private function ensureSpace(float $need): void
    {
        if ($this->y - $need < self::BOTTOM) {
            $this->pages[] = $this->buf;
            $this->buf = '';
            $this->y = self::TOP;
            $this->header();
        }
    }

    private function header(): void
    {
        $this->drawText(self::LEFT, $this->y, 'UniWeb — Deep A-to-Z Audit Report', 11, true);
        $this->y -= 14;
        $this->drawText(self::LEFT, $this->y, 'English · Problem / Expectation / Solution · Generated ' . gmdate('Y-m-d H:i') . ' UTC', 8, false);
        $this->y -= 10;
        $this->buf .= '48 ' . ($this->y + 2) . " m 547 " . ($this->y + 2) . " l S\n";
        $this->y -= 16;
    }

    private function drawText(float $x, float $y, string $text, int $size, bool $bold): void
    {
        $font = $bold ? '/F2' : '/F1';
        $this->buf .= "BT {$font} {$size} Tf {$x} {$y} Td (" . $this->sanitize($text) . ") Tj ET\n";
    }

    private function wrap(string $text, int $size, float $maxWidth): array
    {
        $avg = $size * 0.48;
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $trial = $cur === '' ? $w : $cur . ' ' . $w;
            if (strlen($trial) * $avg > $maxWidth && $cur !== '') {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $trial;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines ?: [''];
    }

    public function addParagraph(string $text, int $size = 9, bool $bold = false, float $indent = 0.0): void
    {
        $width = self::RIGHT - self::LEFT - $indent;
        $lines = $this->wrap($text, $size, $width);
        $lh = $size + 3;
        foreach ($lines as $line) {
            $this->ensureSpace($lh + 2);
            $this->drawText(self::LEFT + $indent, $this->y, $line, $size, $bold);
            $this->y -= $lh;
        }
        $this->y -= 2;
    }

    public function addBlank(float $h = 8): void
    {
        $this->ensureSpace($h);
        $this->y -= $h;
    }

    public function start(): void
    {
        $this->header();
    }

    public function finish(): string
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
        }
        return $this->build();
    }

    private function build(): string
    {
        $n = count($this->pages);
        if ($n < 1) {
            $this->pages[] = '';
            $n = 1;
        }
        $objs = [];
        $objs[] = null; // 1-based
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (3 + $i * 2) . ' 0 R';
        }
        $objs[1] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
        $objs[2] = "2 0 obj << /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$n} >> endobj\n";
        $font1 = 3 + $n * 2;
        $font2 = $font1 + 1;
        for ($i = 0; $i < $n; $i++) {
            $pageObj = 3 + $i * 2;
            $contentObj = $pageObj + 1;
            $stream = $this->pages[$i];
            $objs[$pageObj] = "{$pageObj} 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents {$contentObj} 0 R /Resources << /Font << /F1 {$font1} 0 R /F2 {$font2} 0 R >> >> >> endobj\n";
            $objs[$contentObj] = "{$contentObj} 0 obj << /Length " . strlen($stream) . " >> stream\n{$stream}endstream endobj\n";
        }
        $objs[$font1] = "{$font1} 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";
        $objs[$font2] = "{$font2} 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >> endobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        $max = $font2;
        for ($i = 1; $i <= $max; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $objs[$i];
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer << /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }
}

$pdf = new AuditPdf();
$pdf->start();

$pdf->addParagraph('Workspace: uniweb1 (local laptop). Scope: Admin, Merchant, Sub-merchant, Staff, Team, Customer, public site, search, duplicates, market peers, white-label.', 9);
$pdf->addParagraph('Exclusions (by Owner policy): NBFC product stay hidden; no customer PPI wallet; no live Route/Split until Owner + keys + commercial.', 9);
$pdf->addParagraph('Format for every ticket: (1) THE PROBLEM (2) THE EXPECTATION (3) THE SOLUTION / ACTION.', 9, true);
$pdf->addBlank(10);

foreach ($findings as $section) {
    $pdf->addParagraph($section['title'], 12, true);
    if (!empty($section['intro'])) {
        $pdf->addParagraph($section['intro'], 9);
    }
    $pdf->addBlank(4);
    foreach ($section['items'] as $item) {
        $pdf->addParagraph($item['id'] . ' · ' . $item['title'], 10, true);
        $pdf->addParagraph('1) THE PROBLEM (What is wrong)', 9, true, 4);
        $pdf->addParagraph($item['problem'], 9, false, 8);
        $pdf->addParagraph('2) THE EXPECTATION (What should be)', 9, true, 4);
        $pdf->addParagraph($item['expectation'], 9, false, 8);
        $pdf->addParagraph('3) THE SOLUTION / ACTION (How to fix)', 9, true, 4);
        $pdf->addParagraph($item['solution'], 9, false, 8);
        $pdf->addBlank(6);
    }
}

$bytes = $pdf->finish();
$outName = 'UniWeb_Deep_A_to_Z_Audit_' . gmdate('Ymd_His') . '.pdf';
$paths = [
    'C:/Users/start/Downloads/' . $outName,
    __DIR__ . '/pdf_temp/' . $outName,
];
foreach ($paths as $p) {
    $dir = dirname($p);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents($p, $bytes);
    echo "Wrote {$p} (" . strlen($bytes) . " bytes)\n";
}

// Also write markdown twin for repo
$md = "# UniWeb Deep A-to-Z Audit\n\n**Generated:** " . gmdate('Y-m-d H:i') . " UTC  \n**PDF:** `{$outName}`  \n**Format:** Problem → Expectation → Solution (English)\n\n";
foreach ($findings as $section) {
    $md .= "## {$section['title']}\n\n";
    if (!empty($section['intro'])) {
        $md .= $section['intro'] . "\n\n";
    }
    foreach ($section['items'] as $item) {
        $md .= "### {$item['id']} · {$item['title']}\n\n";
        $md .= "1) **THE PROBLEM:** {$item['problem']}\n\n";
        $md .= "2) **THE EXPECTATION:** {$item['expectation']}\n\n";
        $md .= "3) **THE SOLUTION / ACTION:** {$item['solution']}\n\n";
    }
}
file_put_contents(dirname(__DIR__) . '/DEEP_AUDIT_FULL_A_TO_Z.md', $md);
echo "Wrote DEEP_AUDIT_FULL_A_TO_Z.md\n";
