<?php
function ascii85Decode(string $data): string {
    $data = trim($data);
    if (str_starts_with($data, '<~')) $data = substr($data, 2);
    $out = '';
    $grp = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $ch = $data[$i];
        if ($ch === 'z' && $grp === '') { $out .= "\0\0\0\4"; continue; }
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

$f = '_inbox/Screenshot/Pdf/UniWeb_ZeroTouch_KYC_Automation_Spec.pdf';
$content = file_get_contents($f);

// Find ASCII85 streams specifically
preg_match_all('/\/ASCII85Decode/', $content, $a85, PREG_OFFSET_CAPTURE);
echo "ASCII85Decode markers: " . count($a85[0]) . "\n";

// Get stream content after ASCII85Decode filter
preg_match_all('/\/Filter\s*\[\s*\/ASCII85Decode\s*\/FlateDecode\s*\][^\r\n]*\r?\nstream\r?\n(.*?)\r?\nendstream/s', $content, $matches, PREG_SET_ORDER);

echo "ASCII85+Flate streams found: " . count($matches) . "\n";

foreach ($matches as $idx => $m) {
    $stream = $m[1];
    echo "\n--- Stream $idx (len=" . strlen($stream) . ") ---\n";
    echo "First 40 chars: " . substr(preg_replace('/[^\x20-\x7E]/', '?', $stream), 0, 40) . "\n";
    
    $decoded = ascii85Decode($stream);
    echo "After A85 (len=" . strlen($decoded) . "): " . bin2hex(substr($decoded, 0, 10)) . "\n";
    
    $inflated = @gzinflate($decoded);
    if ($inflated === false) $inflated = @gzuncompress($decoded);
    if ($inflated === false) {
        echo "Inflate failed. Trying raw deflate...\n";
        // Maybe it's raw deflate without zlib header
        $inflated = @gzinflate(substr($decoded, 2));
    }
    
    if ($inflated !== false) {
        echo "Inflated len: " . strlen($inflated) . "\n";
        // Show readable content
        $clean = '';
        $inQuote = false;
        for ($i = 0; $i < strlen($inflated); $i++) {
            $c = $inflated[$i];
            $o = ord($c);
            if ($c === '(') { $inQuote = true; $clean .= $c; continue; }
            if ($c === ')') { $inQuote = false; $clean .= $c . ' '; continue; }
            if ($inQuote && $o >= 32 && $o <= 126) $clean .= $c;
            elseif ($inQuote && $c === '\\') $clean .= $c;
        }
        echo "Text content:\n" . substr($clean, 0, 4000) . "\n";
    } else {
        echo "All inflate attempts failed\n";
    }
}
