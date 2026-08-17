<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
redirect(function_exists('adminPartnerDetailUrl') ? adminPartnerDetailUrl('decentro') : 'admin_gateway_detail.php?partner=decentro&tab=keys&env=test');
