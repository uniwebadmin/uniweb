<?php
/**
 * PDF text extraction with proper ASCII85 + FlateDecode handling.
 */

function ascii85_decode(string $input): string {
    // Remove whitespace
    $input = preg_replace('/\s/', '', $input);
    // Remove <~ prefix and ~> suffix if present
    if (str_starts_with($input, '<~')) $input = substr($input, 2);
    if (str_ends_with($input, '~>')) $input = substr($input, 0, -2);
    
    $output = '';
    $len = strlen($input);
    $i = 0;
    
    while ($i < $len) {
        // Handle 'z' shorthand for zeros
        if ($input[$i] === 'z' && ($i % 5) === 0) {
            $output .= "\x00\x00\x00\x00";
            $i++;
            continue;
        }
        
        // Collect group of 5 chars
        $group = '';
        for ($j = 0; $j < 5 && $i < $len; $j++, $i++) {
            $group .= $input[$i];
        }
        
        if (strlen($group) === 0) break;
        
        // Calculate padding
        $padding = 5 - strlen($group);
        
        // Pad with 'u' (value 84)
        for ($k = strlen($group); $k < 5; $k++) {
            $group .= 'u';
        }
        
        // Decode 5 ASCII85 chars to 4 bytes
        $value = 0;
        for ($k = 0; $k < 5; $k++) {
            $c = ord($group[$k]);
            if ($c < 33 || $c > 117) $c = 117; // clamp
            $value = $value * 85 + ($c - 33);
        }
        
        // Pack as 4 big-endian bytes
        $bytes = pack('N', $value);
        
        // Remove padding bytes
        if ($padding > 0) {
            $bytes = substr($bytes, 0, 4 - $padding);
        }
        
        $output .= $bytes;
    }
    
    return $output;
}

function extractPdfText(string $filePath): string {
    $content = file_get_contents($filePath);
    $text = '';
    
    // Find all stream blocks
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams, PREG_SET_ORDER);
    
    foreach ($streams as $match) {
        $stream = rtrim($match[1]);
        if (strlen($stream) < 2) continue;
        
        // Check if this looks like ASCII85 encoded data
        $isAscii85 = true;
        for ($k = 0; $k < min(strlen($stream), 20); $k++) {
            $c = ord($stream[$k]);
            if ($c < 33 || $c > 126) { $isAscii85 = false; break; }
        }
        
        $decoded = $stream;
        if ($isAscii85) {
            $decoded = ascii85_decode($stream);
        }
        
        // Try gzinflate on decoded data
        $inflated = @gzinflate($decoded);
        if ($inflated === false) {
            $inflated = @gzuncompress($decoded);
        }
        
        if ($inflated !== false) {
            // Extract text operators
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
    if (trim($text) === '') {
        echo "[No text extracted]\n";
    } else {
        echo $text;
    }
    echo "\n\n";
}
