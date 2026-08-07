<?php
$dir = __DIR__ . '/pdf_temp/';
$files = glob($dir . '*.pdf');
foreach ($files as $file) {
    echo "\n=== " . basename($file) . " ===\n\n";
    $content = file_get_contents($file);
    // Extract text between parentheses (PDF text strings)
    preg_match_all('/\(([^)]+)\)/', $content, $matches);
    $text = implode(' ', $matches[1]);
    // Also try BT...ET blocks with Tj/TJ operators
    preg_match_all('/BT\s+(.*?)\s+ET/s', $content, $btBlocks);
    foreach ($btBlocks[1] as $block) {
        preg_match_all('/\(([^)]+)\)\s*Tj/', $block, $tj);
        foreach ($tj[1] as $t) {
            if (strlen(trim($t)) > 0) echo $t . ' ';
        }
        preg_match_all('/\[(.*?)\]\s*TJ/', $block, $tj2);
        foreach ($tj2[1] as $arr) {
            preg_match_all('/\(([^)]+)\)/', $arr, $parts);
            foreach ($parts[1] as $p) {
                if (strlen(trim($p)) > 0) echo $p . ' ';
            }
        }
    }
    echo "\n\n--- Raw text extraction ---\n";
    // Fallback: extract all readable text
    $raw = preg_replace('/[^[:print:]\s]/', ' ', $content);
    $raw = preg_replace('/\s+/', ' ', $raw);
    // Find meaningful text segments
    preg_match_all('/[A-Z][a-zA-Z0-9\s\-\.\,\:\;\(\)\/\%\@\#\&\*\+\=\_\!\?]{3,}/', $raw, $words);
    foreach ($words[0] as $w) {
        $w = trim($w);
        if (strlen($w) > 3) echo $w . "\n";
    }
    echo "\n";
}
