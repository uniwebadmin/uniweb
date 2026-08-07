<?php
$files = [
    '_inbox/Screenshot/Pdf/UniWeb_Merchant_Agreement_Template.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_Developer_Spec_Approved_Points.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_ZeroTouch_KYC_Automation_Spec.pdf'
];
foreach ($files as $f) {
    echo "=== " . basename($f) . " ===\n";
    $content = file_get_contents($f);
    
    // Method 1: Find FlateDecode streams
    $text = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams)) {
        foreach ($streams[1] as $stream) {
            // Try different decompression methods
            $decoded = @gzuncompress($stream);
            if ($decoded === false) $decoded = @gzinflate(substr($stream, 2));
            if ($decoded === false) $decoded = @gzdecode($stream);
            if ($decoded !== false) {
                // Extract text from PDF text operators
                preg_match_all('/\((.*?)\)\s*Tj/', $decoded, $tj);
                foreach ($tj[1] as $t) { $text .= $t . ' '; }
                preg_match_all('/\[(.*?)\]\s*TJ/', $decoded, $tj2);
                foreach ($tj2[1] as $block) {
                    preg_match_all('/\((.*?)\)/', $block, $inner);
                    $text .= implode('', $inner[1]);
                }
            }
        }
    }
    
    // Method 2: If no text found, try raw extraction of all parenthesized strings before Tj
    if (trim($text) === '') {
        // Look for text in all compressed streams with FlateDecode
        if (preg_match_all('/\/Filter\s*\/FlateDecode\s*.*?stream\r?\n(.*?)\r?\nendstream/s', $content, $streams2)) {
            foreach ($streams2[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) $decoded = @gzinflate(substr($stream, 2));
                if ($decoded !== false) {
                    preg_match_all('/\((.*?)\)\s*Tj/', $decoded, $tj);
                    foreach ($tj[1] as $t) { $text .= $t . ' '; }
                    preg_match_all('/\[(.*?)\]\s*TJ/', $decoded, $tj2);
                    foreach ($tj2[1] as $block) {
                        preg_match_all('/\((.*?)\)/', $block, $inner);
                        $text .= implode('', $inner[1]);
                    }
                }
            }
        }
    }
    
    // Method 3: Just grab all readable ASCII strings
    if (trim($text) === '') {
        // Try to find any text-like content
        preg_match_all('/[\x20-\x7E]{8,}/', $content, $ascii);
        $text = implode("\n", array_slice($ascii[0], 0, 100));
    }
    
    $text = str_replace(['\\(', '\\)', '\\n', '\\r'], ['(', ')', "\n", ''], $text);
    echo substr($text, 0, 8000) . "\n\n---END---\n\n";
}
