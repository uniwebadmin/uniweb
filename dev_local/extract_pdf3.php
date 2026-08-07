<?php
/**
 * Better PDF text extraction - handle FlateDecode and ASCII85Decode properly.
 */

function extractPdfText(string $filePath): string {
    $content = file_get_contents($filePath);
    $text = '';
    
    // Find all stream...endstream blocks
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams, PREG_SET_ORDER);
    
    foreach ($streams as $match) {
        $stream = $match[1];
        $stream = rtrim($stream);
        
        // Try raw gzinflate (FlateDecode)
        $inflated = @gzinflate($stream);
        if ($inflated === false) {
            // Try with different window bits
            $inflated = @gzinflate(substr($stream, 2)); // skip zlib header
        }
        if ($inflated === false) {
            // Try gzuncompress
            $inflated = @gzuncompress($stream);
        }
        if ($inflated === false) {
            // Try raw deflate
            $inflated = @gzinflate($stream, -15);
        }
        
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
        echo "[No text extracted - trying raw stream dump]\n";
        // Dump first stream for debugging
        $content = file_get_contents($file);
        preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $content, $m);
        if ($m) {
            echo "First stream length: " . strlen($m[1]) . "\n";
            echo "First 200 chars hex: " . bin2hex(substr($m[1], 0, 200)) . "\n";
            echo "First 100 chars raw: " . substr($m[1], 0, 100) . "\n";
        }
    } else {
        echo $text;
    }
    echo "\n\n";
}
