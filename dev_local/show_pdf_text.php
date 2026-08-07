<?php
$c = file_get_contents('_inbox/pdf_text_clean.txt');
$parts = explode('---END---', $c);
foreach ($parts as $i => $p) {
    echo "PART $i:\n";
    echo trim($p) . "\n\n";
}
