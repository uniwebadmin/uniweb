<?php
/** Legacy URL — pricing lives on the homepage */
require_once __DIR__ . '/config.php';
header('Location: ' . APP_URL . '/index.php#pricing', true, 301);
exit;
