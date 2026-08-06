<?php
declare(strict_types=1);

/**
 * Point 1 — Real Client IP + Live Geolocation helpers.
 *
 * getRealClientIp() reads CF-Connecting-IP / True-Client-IP / X-Forwarded-For
 * and returns the leftmost public IP, falling back to REMOTE_ADDR.
 *
 * Geo data (lat/lng/accuracy) is captured on the frontend via HTML5 Geolocation API
 * and passed as hidden form fields. These helpers validate and store it.
 */

/**
 * Return the real client IP address, respecting Cloudflare and proxy headers.
 * Takes the leftmost public IP from X-Forwarded-For, or CF-Connecting-IP, or True-Client-IP.
 */
function getRealClientIp(): string
{
    $candidates = [];

    // Cloudflare
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    // True-Client-IP (some CDNs)
    if (!empty($_SERVER['HTTP_TRUE_CLIENT_IP'])) {
        $candidates[] = trim($_SERVER['HTTP_TRUE_CLIENT_IP']);
    }
    // X-Forwarded-For: client, proxy1, proxy2 — take leftmost
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($parts as $p) {
            $candidates[] = trim($p);
        }
    }
    // REMOTE_ADDR fallback
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = trim($_SERVER['REMOTE_ADDR']);
    }

    foreach ($candidates as $ip) {
        $ip = trim($ip);
        if ($ip === '') continue;
        // Skip private / reserved ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return substr($ip, 0, 45);
        }
    }

    // If no public IP found, return the first candidate (could be private)
    foreach ($candidates as $ip) {
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return substr($ip, 0, 45);
        }
    }

    return '0.0.0.0';
}

/**
 * Get the User-Agent string (truncated for DB storage).
 */
function getClientUserAgent(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/**
 * Validate and normalize geo data from frontend.
 * Returns null if invalid.
 */
function normalizeGeoData(?float $lat, ?float $lng, ?float $accuracyM, string $source = 'html5'): ?array
{
    if ($lat === null || $lng === null) {
        return null;
    }
    // Validate ranges
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return null;
    }
    return [
        'lat' => round($lat, 6),
        'lng' => round($lng, 6),
        'accuracy_m' => $accuracyM !== null ? (int)round($accuracyM) : null,
        'geo_source' => in_array($source, ['html5', 'ip_fallback', 'denied'], true) ? $source : 'html5',
    ];
}

/**
 * Parse geo data from POST request (hidden form fields).
 * Returns normalized array or null.
 */
function parseGeoFromRequest(): ?array
{
    $lat = isset($_POST['geo_lat']) ? (float)$_POST['geo_lat'] : null;
    $lng = isset($_POST['geo_lng']) ? (float)$_POST['geo_lng'] : null;
    $accuracy = isset($_POST['geo_accuracy']) ? (float)$_POST['geo_accuracy'] : null;
    $source = trim((string)($_POST['geo_source'] ?? 'html5'));

    if ($lat === 0.0 && $lng === 0.0) {
        // Check for denied flag
        if ($source === 'denied' || !empty($_POST['geo_denied'])) {
            return [
                'lat' => null,
                'lng' => null,
                'accuracy_m' => null,
                'geo_source' => 'denied',
            ];
        }
        return null;
    }

    return normalizeGeoData($lat, $lng, $accuracy, $source);
}

/**
 * Parse geo data from JSON input (for API endpoints like kyc_media_receiver).
 */
function parseGeoFromJsonInput(string $jsonBody): ?array
{
    $data = json_decode($jsonBody, true);
    if (!is_array($data)) return null;
    $lat = isset($data['geo_lat']) ? (float)$data['geo_lat'] : null;
    $lng = isset($data['geo_lng']) ? (float)$data['geo_lng'] : null;
    $accuracy = isset($data['geo_accuracy']) ? (float)$data['geo_accuracy'] : null;
    $source = trim((string)($data['geo_source'] ?? 'html5'));

    if ($lat === 0.0 && $lng === 0.0) {
        if ($source === 'denied' || !empty($data['geo_denied'])) {
            return [
                'lat' => null,
                'lng' => null,
                'accuracy_m' => null,
                'geo_source' => 'denied',
            ];
        }
        return null;
    }

    return normalizeGeoData($lat, $lng, $accuracy, $source);
}

/**
 * Parse geo data from GET parameters (for chunked upload endpoints).
 */
function parseGeoFromQuery(): ?array
{
    $lat = isset($_GET['geo_lat']) ? (float)$_GET['geo_lat'] : null;
    $lng = isset($_GET['geo_lng']) ? (float)$_GET['geo_lng'] : null;
    $accuracy = isset($_GET['geo_accuracy']) ? (float)$_GET['geo_accuracy'] : null;
    $source = trim((string)($_GET['geo_source'] ?? 'html5'));

    if ($lat === 0.0 && $lng === 0.0) {
        if ($source === 'denied' || !empty($_GET['geo_denied'])) {
            return [
                'lat' => null,
                'lng' => null,
                'accuracy_m' => null,
                'geo_source' => 'denied',
            ];
        }
        return null;
    }

    return normalizeGeoData($lat, $lng, $accuracy, $source);
}
