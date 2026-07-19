<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
$pageTitle = 'Merchant Platform Agreement';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Merchant Contract',
    'title' => 'Merchant Services Agreement',
    'summary' => 'The contractual framework between UniWeb and an approved merchant, covering onboarding, permitted use, fees, settlements, customer disputes, data and security.',
    'effective' => '19 July 2026',
    'version' => merchantAgreementVersion(),
    'notice' => '<strong>Public reference copy:</strong> This page lets applicants review the standard terms. The binding merchant copy is presented inside the authenticated Merchant Portal and records the merchant, agreement version, acceptance time and technical audit details.',
    'sections' => merchantAgreementSections(),
]);
require_once __DIR__ . '/footer.php';
