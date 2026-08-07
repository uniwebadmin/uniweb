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

$output = '';

foreach ($files as $f) {
    $output .= "=== " . basename($f) . " ===\n";
    $content = file_get_contents($f);
    $text = '';
    
    $offset = 0;
    while (($pos = strpos($content, 'stream', $offset)) !== false) {
        $before = substr($content, max(0, $pos - 300), 300);
        $hasAscii85 = str_contains($before, 'ASCII85Decode');
        $hasFlate = str_contains($before, 'FlateDecode');
        $isFont = str_contains($before, 'Length1');
        
        $dataStart = $pos + 6;
        if ($content[$dataStart] === "\r") $dataStart++;
        if ($content[$dataStart] === "\n") $dataStart++;
        
        $endPos = strpos($content, 'endstream', $dataStart);
        if ($endPos === false) break;
        
        $streamData = substr($content, $dataStart, $endPos - $dataStart);
        $streamData = rtrim($streamData, "\r\n");
        $offset = $endPos + 9;
        
        if ($isFont || (!$hasFlate && !$hasAscii85)) continue;
        
        $data = $streamData;
        if ($hasAscii85) $data = ascii85Decode($data);
        
        if ($hasFlate) {
            $inflated = @gzuncompress($data);
            if ($inflated === false) $inflated = @gzinflate($data);
            if ($inflated === false) $inflated = @gzinflate(substr($data, 2));
        } else {
            $inflated = $data;
        }
        
        if ($inflated !== false && strlen($inflated) > 10) {
            preg_match_all('/\(([^)]*)\)\s*Tj/', $inflated, $tj);
            foreach ($tj[1] as $t) { $text .= $t . ' '; }
            preg_match_all('/\[([^\]]*)\]\s*TJ/', $inflated, $tj2);
            foreach ($tj2[1] as $block) {
                preg_match_all('/\(([^)]*)\)/', $block, $inner);
                $text .= implode('', $inner[1]);
            }
        }
    }
    
    // Clean null bytes and control chars
    $text = str_replace(["\0", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07"], '', $text);
    $text = str_replace(['\\(', '\\)', '\\n', '\\r', '\\t'], ['(', ')', "\n", '', "\t"], $text);
    $output .= trim($text) . "\n\n---END---\n\n";
}

file_put_contents('_inbox/pdf_text_clean.txt', $output);
echo "Saved to _inbox/pdf_text_clean.txt (" . strlen($output) . " bytes)\n";
