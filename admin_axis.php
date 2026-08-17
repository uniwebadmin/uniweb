<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
redirect(function_exists('adminPartnerDetailUrl') ? adminPartnerDetailUrl('axis') : 'admin_gateway_detail.php?partner=axis&tab=keys&env=test');
