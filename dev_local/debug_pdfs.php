<?php
// Debug: check what filters and stream lengths we have
$files = [
    '_inbox/Screenshot/Pdf/UniWeb_Merchant_Agreement_Template.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_Developer_Spec_Approved_Points.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_ZeroTouch_KYC_Automation_Spec.pdf'
];

foreach ($files as $f) {
    echo "=== " . basename($f) . " ===\n";
    $content = file_get_contents($f);
    echo "File size: " . strlen($content) . "\n";
    
    // Count streams
    $count = preg_match_all('/stream\r?\n/', $content);
    echo "Stream markers: $count\n";
    
    // Find filter declarations
    preg_match_all('/\/Filter\s*([^\r\n]*)/', $content, $filters);
    echo "Filters found: " . implode('; ', array_slice($filters[1], 0, 10)) . "\n";
    
    // Try to find first stream and show raw bytes
    if (preg_match('/stream\r?\n(.{0,100})/s', $content, $m)) {
        echo "First stream start (hex): " . bin2hex(substr($m[1], 0, 20)) . "\n";
        echo "First stream start (raw): " . substr(preg_replace('/[^\x20-\x7E]/', '?', $m[1]), 0, 80) . "\n";
    }
    
    // Check if it's ReportLab
    if (str_contains($content, 'ReportLab')) echo "Generator: ReportLab\n";
    
    echo "\n";
}
