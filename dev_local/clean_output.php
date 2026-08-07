<?php
$t = file_get_contents(__DIR__ . '/pdf_text_output.txt');
$t = str_replace(chr(0), '', $t);
$lines = explode("\n", $t);
foreach ($lines as $l) {
    $l = trim($l);
    if (strlen($l) > 2) echo $l . PHP_EOL;
}
