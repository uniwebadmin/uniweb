<?php
declare(strict_types=1);

/** Mask PII in merchant CSV exports — full data stays in-app only. */
function exportMaskPhone(?string $phone): string
{
    $digits = preg_replace('/\D/', '', (string)$phone) ?? '';
    if (strlen($digits) >= 4) {
        return '••••' . substr($digits, -4);
    }
    return $digits !== '' ? '••••' : '';
}

function exportMaskEmail(?string $email): string
{
    $email = trim((string)$email);
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    $local = (string)$local;
    $shown = function_exists('mb_substr') ? mb_substr($local, 0, 1) : substr($local, 0, 1);
    return $shown . '***@' . $domain;
}

function exportMaskName(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $masked = [];
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $first = function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
        $masked[] = $first . '***';
    }
    return implode(' ', $masked);
}

function exportCsvSafeCell($value): string
{
    $value = (string)$value;
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}
