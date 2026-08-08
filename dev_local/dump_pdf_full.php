<?php
$f = file(__DIR__ . '/../_inbox/pdf_text_clean.txt');
// Print line 2 (Merchant Agreement) in full chunks
echo '=== MERCHANT AGREEMENT (line 2) ===' . PHP_EOL;
echo wordwrap($f[1], 120) . PHP_EOL;
echo PHP_EOL . '=== DEVELOPER SPEC (line 7) ===' . PHP_EOL;
echo wordwrap($f[6], 120) . PHP_EOL;
echo PHP_EOL . '=== ZEROTOUCH KYC (line 12) ===' . PHP_EOL;
echo wordwrap($f[11], 120) . PHP_EOL;
