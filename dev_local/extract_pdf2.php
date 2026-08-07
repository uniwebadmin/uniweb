<?php
/**
 * Extract text from PDF files by decompressing ASCII85 + FlateDecode streams.
 */

function decodeAscii85(string $data): string {
    // Remove leading <~ and trailing ~>
    $data = trim($data);
    if (substr($data, 0, 2) === '<~') $data = substr($data, 2);
    if (substr($data, -2) === '~>') $data = substr($data, 0, -2);
    $data = preg_replace('/\s/', '', $data);
    
    $result = '';
    $len = strlen($data);
    $i = 0;
    while ($i < $len) {
        $group = '';
        $padding = 0;
        for ($j = 0; $j < 5 && $i < $len; $j++, $i++) {
            if ($data[$i] === 'z' && $j === 0) {
                $result .= "\x00\x00\x00\x00";
                continue 2;
            }
            if ($data[$i] === '~') { $padding = 5 - $j; break; }
            $group .= $data[$i];
        }
        if (strlen($group) === 0) continue;
        $padding = $padding > 0 ? $padding : 0;
        // Pad with 'u' (84)
        for ($k = strlen($group); $k < 5; $k++) $group .= 'u';
        $val = 0;
        for ($k = 0; $k < 5; $k++) {
            $c = ord($group[$k]) - 33;
            if ($c < 0 || $c > 84) $c = 84;
            $val = $val * 85 + $c;
        }
        $bytes = pack('N', $val);
        if ($padding > 0) $bytes = substr($bytes, 0, 5 - $padding);
        $result .= $bytes;
    }
    return $result;
}

function extractPdfText(string $filePath): string {
    $content = file_get_contents($filePath);
    $text = '';
    
    // Find all stream...endstream blocks
    preg_match_all('/stream\s*\n(.*?)\nendstream/s', $content, $streams);
    
    foreach ($streams[1] as $stream) {
        // Try ASCII85 + FlateDecode
        if (strpos($stream, '<~') !== false || preg_match('/^[A-Za-z0-9!#$%&()*+\-;<=>?@]^_`|~\s]+$/', $stream)) {
            $decoded = decodeAscii85($stream);
            $inflated = @gzinflate($decoded);
            if ($inflated !== false) {
                // Extract text from Tj/TJ operators
                preg_match_all('/\(([^)]*)\)\s*Tj/', $inflated, $tj);
                foreach ($tj[1] as $t) {
                    $text .= $t . "\n";
                }
                preg_match_all('/\[(.*?)\]\s*TJ/', $inflated, $tj2);
                foreach ($tj2[1] as $arr) {
                    preg_match_all('/\(([^)]*)\)/', $arr, $parts);
                    $text .= implode('', $parts[1]) . "\n";
                }
                continue;
            }
        }
        
        // Try raw FlateDecode
        $inflated = @gzinflate($stream);
        if ($inflated !== false) {
            preg_match_all('/\(([^)]*)\)\s*Tj/', $inflated, $tj);
            foreach ($tj[1] as $t) {
                $text .= $t . "\n";
            }
            preg_match_all('/\[(.*?)\]\s*TJ/', $inflated, $tj2);
            foreach ($tj2[1] as $arr) {
                preg_match_all('/\(([^)]*)\)/', $arr, $parts);
                $text .= implode('', $parts[1]) . "\n";
            }
        }
    }
    
    return $text;
}

$dir = __DIR__ . '/pdf_temp/';
$files = glob($dir . '*.pdf');
foreach ($files as $file) {
    echo "\n============================================\n";
    echo "FILE: " . basename($file) . "\n";
    echo "============================================\n\n";
    $text = extractPdfText($file);
    echo $text;
    echo "\n\n";
}
