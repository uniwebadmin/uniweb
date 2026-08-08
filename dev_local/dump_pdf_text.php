<?php
$f = file(__DIR__ . '/../_inbox/pdf_text_clean.txt');
foreach ($f as $i => $l) {
    echo 'LINE ' . ($i + 1) . ' len=' . strlen($l) . ': ' . substr($l, 0, 800) . PHP_EOL;
    if (strlen($l) > 800) {
        echo '...TAIL: ' . substr($l, -800) . PHP_EOL;
    }
}
