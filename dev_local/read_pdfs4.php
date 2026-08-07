<?php
function decodeAscii85(string $data): string {
    // Remove leading <~ and trailing ~> if present
    $data = trim($data);
    if (str_starts_with($data, '<~')) $data = substr($data, 2);
    if (str_ends_with($data, '~>')) $data = substr($data, 0, -2);
    
    $output = '';
    $group = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $c = $data[$i];
        if ($c === 'z' && $group === '') {
            $output .= "\0\0\0\0";
            continue;
        }
        if (ctype_space($c)) continue;
        if ($c === '~') break;
        $group .= $c;
        if (strlen($group) === 5) {
            $val = 0;
            for ($j = 0; $j < 5; $j++) {
                $val = $val * 85 + (ord($group[$j]) - 33);
            }
            $output .= chr(($val >> 24) & 0xFF) . chr(($val >> 16) & 0xFF) . chr(($val >> 8) & 0xFF) . chr($val & 0xFF);
            $group = '';
        }
    }
    // Handle remaining chars
    if (strlen($group) > 0) {
        $pad = 5 - strlen($group);
        $val = 0;
        for ($j = 0; $j < strlen($group); $j++) {
            $val = $val * 85 + (ord($group[$j]) - 33);
        }
        for ($j = 0; $j < $pad; $j++) $val = $val * 85 + 84;
        $bytes = chr(($val >> 24) & 0xFF) . chr(($val >> 16) & 0xFF) . chr(($val >> 8) & 0xFF) . chr($val & 0xFF);
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
    
    // Find streams with ASCII85Decode + FlateDecode
    if (preg_match_all('/\/Filter\s*\[\s*\/ASCII85Decode\s*\/FlateDecode\s*\].*?stream\r?\n(.*?)\r?\nendstream/s', $content, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = decodeAscii85($stream);
            $inflated = @gzinflate($decoded);
            if ($inflated === false) $inflated = @gzuncompress($decoded);
            if ($inflated !== false) {
                // Extract text from Tj and TJ operators
                preg_match_all('/\((.*?)\)\s*Tj/', $inflated, $tj);
                foreach ($tj[1] as $t) { $text .= $t . ' '; }
                preg_match_all('/\[(.*?)\]\s*TJ/', $inflated, $tj2);
                foreach ($tj2[1] as $block) {
                    preg_match_all('/\((.*?)\)/', $block, $inner);
                    $text .= implode('', $inner[1]);
                }
                // Also look for Td/TD/T* for line breaks
                $text = preg_replace('/T\*/', "\n", $text);
                $text = preg_replace('/-?\d+\.\d+\s+-?\d+\.\d+\s+Td/', "\n", $text);
            }
        }
    }
    
    // Also try streams with just FlateDecode
    if (trim($text) === '') {
        if (preg_match_all('/\/Filter\s*\/FlateDecode\s*.*?stream\r?\n(.*?)\r?\nendstream/s', $content, $streams2)) {
            foreach ($streams2[1] as $stream) {
                $inflated = @gzuncompress($stream);
                if ($inflated === false) $inflated = @gzinflate(substr($stream, 2));
                if ($inflated !== false) {
                    preg_match_all('/\((.*?)\)\s*Tj/', $inflated, $tj);
                    foreach ($tj[1] as $t) { $text .= $t . ' '; }
                    preg_match_all('/\[(.*?)\]\s*TJ/', $inflated, $tj2);
                    foreach ($tj2[1] as $block) {
                        preg_match_all('/\((.*?)\)/', $block, $inner);
                        $text .= implode('', $inner[1]);
                    }
                }
            }
        }
    }
    
    $text = str_replace(['\\(', '\\)', '\\n', '\\r', '\\t'], ['(', ')', "\n", '', "\t"], $text);
    echo $text . "\n\n---END---\n\n";
}
