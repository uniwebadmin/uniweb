<?php
$files = [
    '_inbox/Screenshot/Pdf/UniWeb_Merchant_Agreement_Template.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_Developer_Spec_Approved_Points.pdf',
    '_inbox/Screenshot/Pdf/UniWeb_ZeroTouch_KYC_Automation_Spec.pdf'
];

foreach ($files as $f) {
    echo "=== " . basename($f) . " ===\n";
    $content = file_get_contents($f);
    
    // Find all stream blocks with their filter info
    preg_match_all('/(.*?)stream\r?\n(.*?)\r?\nendstream/s', $content, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $m) {
        $before = $m[1];
        $stream = $m[2];
        
        $hasAscii85 = str_contains($before, '/ASCII85Decode');
        $hasFlate = str_contains($before, '/FlateDecode');
        
        if (!$hasFlate && !$hasAscii85) continue;
        
        $data = $stream;
        
        if ($hasAscii85) {
            // ASCII85 decode
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
            $data = $out;
        }
        
        if ($hasFlate) {
            $inflated = @gzinflate($data);
            if ($inflated === false) $inflated = @gzuncompress($data);
            if ($inflated === false) $inflated = @gzinflate(substr($data, 2));
            if ($inflated !== false) {
                // Print raw decompressed for debugging
                $clean = preg_replace('/[^\x20-\x7E\n\r]/', '', $inflated);
                if (strlen($clean) > 50) {
                    echo substr($clean, 0, 6000) . "\n";
                }
            }
        }
    }
    echo "\n---END---\n\n";
}
