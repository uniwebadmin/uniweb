<?php
declare(strict_types=1);

/**
 * Owner-only portal login hub — not linked from public site or individual login pages.
 */
function ownerPortalHubAllowed(): bool
{
    if (function_exists('isSuperAdmin') && isSuperAdmin()) {
        return true;
    }
    $expected = trim((string)(function_exists('getSetting') ? getSetting('owner_portal_hub_key', '') : ''));
    if ($expected === '') {
        return false;
    }
    $provided = trim((string)($_GET['k'] ?? ''));
    return $provided !== '' && hash_equals($expected, $provided);
}
