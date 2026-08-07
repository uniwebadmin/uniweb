<?php
$files = [
    '_inbox/Screenshot/Pdf/UniWeb_Merchant_Agreement_Template.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_Developer_Spec_Approved_Points.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_ZeroTouch_KYC_Automation_Spec.pdf'
];
foreach ($files as $f) {
    echo "=== " . basename($f) . " ===\n";
    $content = file_get_contents($f);
    
    // Find all stream objects and try to decompress
    $text = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams)) {
        foreach ($streams[1] as $stream) {
            // Try zlib decompress
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = @gzinflate($stream);
            }
            if ($decoded === false) {
                $decoded = @gzdecode($stream);
            }
            if ($decoded !== false) {
                // Extract text from Tj operators
                preg_match_all('/\((.*?)\)\s*Tj/', $decoded, $tj);
                foreach ($tj[1] as $t) { $text .= $t . ' '; }
                // Extract from TJ arrays
                preg_match_all('/\[(.*?)\]\s*TJ/', $decoded, $tj2);
                foreach ($tj2[1] as $block) {
                    preg_match_all('/\((.*?)\)/', $block, $inner);
                    $text .= implode(' ', $inner[1]) . ' ';
                }
            }
        }
    }
    $text = str_replace(['\\(', '\\)'], ['(', ')'], $text);
    echo substr($text, 0, 5000) . "\n\n";
}
