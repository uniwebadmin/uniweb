<?php
function ascii85Decode(string $data): string {
    $data = trim($data);
    if (str_starts_with($data, '<~')) $data = substr($data, 2);
    $out = '';
    $grp = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $ch = $data[$i];
        if ($ch === 'z' && $grp === '') { $out .= "\0\0\0\0"; continue; }
        if (ctype_space($ch)) continue;
        if ($ch === '~') break;
        $grp .= $ch;
        if (strlen($grp) === 5) {
            $v = 0;
            for ($j = 0; $j < 5; $j++) $v = $v * 85 + (ord($grp[$j]) - 33);
            $out .= chr(($v >> 24) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
            $grp = '';
        }
    }
    if (strlen($grp) > 0) {
        $pad = 5 - strlen($grp);
        for ($j = 0; $j < $pad; $j++) $grp .= 'u';
        $v = 0;
        for ($j = 0; $j < 5; $j++) $v = $v * 85 + (ord($grp[$j]) - 33);
        $bytes = chr(($v >> 24) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
        $out .= substr($bytes, 0, 4 - $pad);
    }
    return $out;
}

$files = [
    '_inbox/Screenshot/Pdf/UniWeb_Merchant_Agreement_Template.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_Developer_Spec_Approved_Points.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_ZeroTouch_KYC_Automation_Spec.pdf'
];

foreach ($files as $f) {
    echo "=== " . basename($f) . " ===\n";
    $content = file_get_contents($f);
    $text = '';
    
    // Get all objects with their filter info
    // Pattern: /Filter [...] ... stream\r\nDATA\r\nendstream
    preg_match_all('/\/Filter\s*(\[[^\]]*\]|\/\w+)\s*.*?stream\r?\n(.*?)\r?\nendstream/s', $content, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $m) {
        $filter = $m[1];
        $stream = $m[2];
        $hasAscii85 = str_contains($filter, 'ASCII85Decode');
        $hasFlate = str_contains($filter, 'FlateDecode');
        
        if (!$hasAscii85 && !$hasFlate) continue;
        
        $data = $stream;
        
        if ($hasAscii85) {
            $data = ascii85Decode($data);
        }
        
        if ($hasFlate) {
            $inflated = @gzuncompress($data);
            if ($inflated === false) $inflated = @gzinflate($data);
            if ($inflated !== false) {
                // Extract text from PDF content stream
                preg_match_all('/\((.*?)\)\s*Tj/', $inflated, $tj);
                foreach ($tj[1] as $t) { $text .= $t . ' '; }
                preg_match_all('/\[(.*?)\]\s*TJ/', $inflated, $tj2);
                foreach ($tj2[1] as $block) {
                    preg_match_all('/\((.*?)\)/', $block, $inner);
                    $text .= implode('', $inner[1]);
                }
                // Add line breaks on Td operators
                $text = preg_replace('/-?\d+\.?\d*\s+-?\d+\.?\d*\s+Td\b/', "\n", $text);
            }
        }
    }
    
    $text = str_replace(['\\(', '\\)', '\\n', '\\r', '\\t'], ['(', ')', "\n", '', "\t"], $text);
    echo trim($text) . "\n\n---END---\n\n";
}
