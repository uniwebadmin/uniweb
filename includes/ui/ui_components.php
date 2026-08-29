<?php
declare(strict_types=1);

if (!class_exists('CapabilityState', false) && is_file(__DIR__ . '/../enums/capability_state.php')) {
    require_once __DIR__ . '/../enums/capability_state.php';
}

/** Status pill — text always visible; color is secondary (WCAG). */
function uiStatusPill(string $variant, string $label, ?string $hint = null): string
{
    $variant = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($variant))) ?: 'neutral';
    $label = trim($label);
    $hintText = $hint !== null && trim($hint) !== '' ? trim($hint) : '';
    $ariaLabel = $hintText !== '' ? $label . ' — ' . $hintText : $label;
    $title = $hintText !== '' ? ' title="' . htmlspecialchars($hintText, ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<span class="ui-pill ui-pill-' . $variant . '" role="status"'
        . ' aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '"' . $title . '>'
        . '<span class="ui-pill-text">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></span>';
}

function uiCapabilityPill(string $state, ?string $hintOverride = null): string
{
    $state = strtoupper(trim($state));
    $hint = $hintOverride ?? CapabilityState::defaultHint($state);
    return uiStatusPill(CapabilityState::badgeVariant($state), CapabilityState::label($state), $hint);
}

/** Payment / txn presentation badge — customer-safe labels only. */
function uiPaymentStatusPill(string $status): string
{
    $key = strtolower(trim(str_replace(' ', '_', $status)));
    $label = ucwords(str_replace('_', ' ', $key));
    $variant = match ($key) {
        'success', 'paid', 'captured', 'verified', 'active', 'completed', 'processed' => 'success',
        'pending', 'submitted', 'under_review', 'processing', 'initiated' => 'pending',
        'failed', 'rejected', 'error', 'cancelled', 'canceled' => 'failed',
        default => 'neutral',
    };
    return uiStatusPill($variant, $label);
}

function uiPageHint(string $title, string $body, string $tone = 'info'): string
{
    unset($tone);
    $title = trim($title);
    $body = trim($body);
    return '<aside class="ui-page-hint" role="note">'
        . '<p class="ui-page-hint-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p class="ui-page-hint-body">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</aside>';
}

function uiEmptyState(string $title, string $line, ?string $actionHref = null, ?string $actionLabel = null): string
{
    $html = '<div class="ui-empty-state" role="status">'
        . '<p class="ui-empty-state-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
    if ($actionHref !== null && $actionLabel !== null && $actionHref !== '') {
        $html .= '<a href="' . htmlspecialchars($actionHref, ENT_QUOTES, 'UTF-8') . '" class="ui-empty-state-action">'
            . htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    return $html . '</div>';
}

function uiWarnCallout(string $title, string $body): string
{
    return '<div class="ui-callout-warn" role="alert">'
        . '<p class="ui-callout-warn-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p></div>';
}

function uiSectionCard(string $title, string $innerHtml, ?string $hint = null): string
{
    $hintHtml = $hint !== null && trim($hint) !== ''
        ? '<p class="text-xs text-gray-500 mt-1 mb-3">' . htmlspecialchars(trim($hint), ENT_QUOTES, 'UTF-8') . '</p>'
        : '';
    return '<section class="ui-section-card">'
        . '<h3 class="ui-section-card-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>'
        . $hintHtml . $innerHtml . '</section>';
}

/** Legend row for Admin — LIVE / STUB / PARKED. */
function uiCapabilityLegend(): string
{
    $parts = [];
    foreach ([CapabilityState::LIVE, CapabilityState::STUB, CapabilityState::PARKED] as $state) {
        $parts[] = uiCapabilityPill($state);
    }
    return '<div class="flex flex-wrap gap-2 items-center" aria-label="Capability state legend">'
        . implode(' ', $parts) . '</div>';
}
