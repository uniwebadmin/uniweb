<?php
/**
 * Short URL helper: /pci.php → PCI-DSS statement (old bookmarks).
 */
require_once __DIR__ . '/config.php';
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
redirect('pci_dss.php' . $qs);
