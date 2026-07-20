<?php
require_once __DIR__ . '/config.php';

// The legacy generic webhook accepted unsigned financial state changes.
// Provider callbacks must use their dedicated, signature-verified endpoints.
header('Cache-Control: no-store');
jsonResponse([
    'error' => 'Legacy webhook disabled',
    'message' => 'Use the provider-specific signed webhook endpoint.',
], 410);
