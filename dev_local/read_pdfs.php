<?php
$files = [
    '_inbox/Screenshot/Pdf/UniWeb_Merchant_Agreement_Template.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_Developer_Spec_Approved_Points.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_ZeroTouch_KYC_Automation_Spec.pdf'
];
foreach ($files as $f) {
    echo "=== " . basename($f) . " ===\n";
    $content = file_get_contents($f);
    $text = '';
    // Try extracting text from parentheses followed by Tj or TJ
    preg_match_all('/\((.*?)\)\s*Tj/', $content, $tj);
    $text = implode(' ', $tj[1]);
    // Also try TJ arrays
    if (trim($text) === '') {
        preg_match_all('/\[(.*?)\]\s*TJ/', $content, $tj2);
        foreach ($tj2[1] as $block) {
            preg_match_all('/\((.*?)\)/', $block, $inner);
            $text .= implode(' ', $inner[1]) . ' ';
        }
    }
    // Clean up
    $text = str_replace(['\\(', '\\)'], ['(', ')'], $text);
    echo substr($text, 0, 5000) . "\n\n";
}
