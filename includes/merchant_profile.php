<?php
declare(strict_types=1);

function merchantProfileComplete(array $merchant): bool
{
    $name = trim((string)($merchant['name'] ?? ''));
    $bn = trim((string)($merchant['business_name'] ?? ''));
    $state = trim((string)($merchant['state'] ?? ''));
    $district = trim((string)($merchant['district'] ?? ''));
    $city = trim((string)($merchant['city'] ?? ''));
    $pin = trim((string)($merchant['pincode'] ?? ''));
    if ($name === '' || $bn === '' || $bn === 'My Business') {
        return false;
    }
    return $state !== '' && $district !== '' && $city !== '' && $pin !== '';
}
