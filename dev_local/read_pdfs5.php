<?php
function decodeAscii85(string $data): string {
    $data = trim($data);
    if (str_starts_with($data, '<~')) $data = substr($data, 2);
    if (str_ends_with($data, '~>')) $data = substr($data, 0, -2);
    $output = '';
    $group = [];
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $c = $data[$i];
        if ($c === 'z' && count($group) === 0) {
            $output .= "\0\0\0\0";
            continue;
        }
        if (ctype_space($c)) continue;
        if ($c === '~') { $i++; break; }
        $group[] = ord($c) - 33;
        if (count($group) === 5) {
            $val = 0;
            for ($j = 0; $j < 5; $j++) $val = $val * 85 + $group[$j];
            $output .= pack('N', $val);
            $group = [];
        }
    }
    if (count($group) > 0) {
        $pad = 5 - count($group);
        for ($j = 0; $j < $pad; $j++) $group[] = 84;
        $val = 0;
        for ($j = 0; $j < 5; $j++) $val = $val * 85 + $group[$j];
        $bytes = pack('N', $val);
        $output .= substr($bytes, 0, 4 - $pad);
    }
    return $output;
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
    
    // Find all stream blocks
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams, PREG_SET_ORDER);
    
    foreach ($streams as $match) {
        $stream = $match[1];
        
        // Check if this stream uses ASCII85Decode
        $beforeStream = substr($content, 0, strpos($content, $match[0]));
        $hasAscii85 = str_contains($beforeStream, '/ASCII85Decode');
        
        $decoded = $stream;
        if ($hasAscii85) {
            $decoded = decodeAscii85($stream);
        }
        
        // Try FlateDecode
        $inflated = @gzinflate($decoded);
        if ($inflated === false) $inflated = @gzuncompress($decoded);
        if ($inflated === false && !$hasAscii85) $inflated = @gzinflate(substr($decoded, 2));
        
        if ($inflated !== false) {
            // Extract text
            preg_match_all('/\((.*?)\)\s*Tj/', $inflated, $tj);
            foreach ($tj[1] as $t) { $text .= $t . ' '; }
            preg_match_all('/\[(.*?)\]\s*TJ/', $inflated, $tj2);
            foreach ($tj2[1] as $block) {
                preg_match_all('/\((.*?)\)/', $block, $inner);
                $text .= implode('', $inner[1]);
            }
        }
    }
    
    $text = str_replace(['\\(', '\\)', '\\n', '\\r'], ['(', ')', "\n", ''], $text);
    echo $text . "\n\n---END---\n\n";
}
