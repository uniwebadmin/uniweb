<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/release_helpers.php')) {
    require_once __DIR__ . '/release_helpers.php';
}

/**
 * D1 — Onboarding State Machine
 *
 * Single transition helper for merchant onboarding states.
 * All state changes must go through merchant_transition().
 * Illegal jumps are blocked; every transition is audit-logged.
 */

/**
 * Legal state transitions.
 * Key = current state, value = array of allowed target states.
 */
function merchantTransitionMap(): array
{
    return [
        'draft'           => ['kyc_submitted', 'rejected', 'suspended'],
        'kyc_submitted'   => ['kyc_verified', 'kyc_failed', 'hold', 'rejected', 'under_review', 'suspended'],
        'under_review'    => ['kyc_verified', 'kyc_failed', 'hold', 'rejected', 'clarification', 'suspended'],
        'clarification'   => ['kyc_submitted', 'kyc_verified', 'hold', 'rejected', 'suspended'],
        'kyc_verified'    => ['queue_forward', 'hold', 'rejected', 'suspended'],
        'queue_forward'   => ['partner_pending', 'hold', 'rejected', 'suspended'],
        'partner_pending' => ['live', 'hold', 'rejected', 'suspended'],
        'kyc_failed'      => ['kyc_submitted', 'hold', 'rejected', 'suspended'],
        'hold'            => ['kyc_submitted', 'kyc_verified', 'queue_forward', 'partner_pending', 'rejected', 'suspended'],
        'rejected'        => ['kyc_submitted', 'suspended'],
        'live'            => ['hold', 'suspended'],
        'suspended'       => ['hold', 'kyc_submitted', 'live'],
    ];
}

/**
 * Get the current onboarding state for a merchant.
 * Falls back to deriving from kyc_status if onboarding_state is empty.
 */
function getMerchantOnboardingState(int $merchantId): string
{
    try {
        $st = getDB()->prepare('SELECT onboarding_state, kyc_status FROM merchants WHERE id=?');
        $st->execute([$merchantId]);
        $row = $st->fetch();
        if (!$row) return 'draft';
        $state = strtolower(trim((string)$row['onboarding_state']));
        if ($state === '') {
            $kyc = strtolower(trim((string)$row['kyc_status']));
            return match($kyc) {
                'verified' => 'kyc_verified',
                'submitted' => 'kyc_submitted',
                'rejected', 'clarification' => 'kyc_failed',
                default => 'draft',
            };
        }
        // Legacy onboarding_state values (pre state-machine normalize)
        return match($state) {
            'submitted' => 'kyc_submitted',
            'verified' => 'kyc_verified',
            default => $state,
        };
    } catch (Throwable $e) {
        return 'draft';
    }
}

/**
 * Transition a merchant to a new onboarding state.
 * Blocks illegal jumps, logs audit, sends notification.
 *
 * @return array ['ok' => bool, 'error' => string, 'from' => string, 'to' => string]
 */
function merchant_transition(int $merchantId, string $to, string $reason = ''): array
{
    $to = strtolower(trim($to));
    $from = getMerchantOnboardingState($merchantId);
    $map = merchantTransitionMap();

    // Validate target state is known
    $allStates = array_keys($map);
    if (!in_array($to, $allStates, true)) {
        return ['ok' => false, 'error' => 'Unknown target state: ' . $to, 'from' => $from, 'to' => $to];
    }

    // Check legal transition
    $allowed = $map[$from] ?? [];
    if (!in_array($to, $allowed, true)) {
        return ['ok' => false, 'error' => "Illegal transition: {$from} → {$to}", 'from' => $from, 'to' => $to];
    }

    // Map onboarding_state to kyc_status if needed
    $kycStatus = match($to) {
        'draft' => 'pending',
        'kyc_submitted', 'under_review', 'clarification' => 'submitted',
        'kyc_verified', 'queue_forward', 'partner_pending', 'live' => 'verified',
        'kyc_failed' => 'rejected',
        'hold' => null, // keep existing kyc_status
        'rejected' => 'rejected',
        'suspended' => null, // keep existing
        default => null,
    };

    try {
        if ($kycStatus !== null) {
            getDB()->prepare("UPDATE merchants SET onboarding_state=?, kyc_status=? WHERE id=?")
                ->execute([$to, $kycStatus, $merchantId]);
        } else {
            getDB()->prepare("UPDATE merchants SET onboarding_state=? WHERE id=?")
                ->execute([$to, $merchantId]);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'DB error: ' . $e->getMessage(), 'from' => $from, 'to' => $to];
    }

    // Audit log
    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit(
            'onboarding_transition',
            $merchantId,
            'merchant',
            (string)$merchantId,
            "State: {$from} → {$to}" . ($reason !== '' ? ' — ' . $reason : '')
        );
    }

    // Merchant notification — generic copy (no partner brand names on onboarding updates).
    $notifMsg = match ($to) {
        'kyc_verified' => 'Your KYC has been verified. Documents are being prepared for partner submission.',
        'queue_forward' => 'Your documents are scheduled for partner submission.',
        'partner_pending' => 'Your application has been forwarded to our banking partners.',
        'live' => 'Your account is now LIVE. You can accept real payments.',
        'kyc_failed' => 'KYC verification failed. ' . ($reason ?: 'Please check the errors and resubmit.'),
        'hold' => 'Your onboarding is on hold. ' . ($reason ?: 'Our team will contact you.'),
        'rejected' => 'Your application was rejected. ' . ($reason ?: 'Contact support for details.'),
        'suspended' => 'Your account has been suspended. ' . ($reason ?: 'Contact support.'),
        default => null,
    };
    if ($notifMsg !== null) {
        try {
            if (function_exists('notifyMerchant')) {
                notifyMerchant($merchantId, 'Onboarding Update', $notifMsg, 'onboarding:' . $to . ':' . $merchantId);
            } elseif (function_exists('createNotification')) {
                createNotification($merchantId, 'Onboarding Update', $notifMsg);
            }
        } catch (Throwable $e) {
            /* non-fatal */
        }
    }

    // Email notification for key transitions
    if (function_exists('sendTemplatedEmail')) {
        $emailTpl = match($to) {
            'kyc_verified' => 'kyc_approved',
            'kyc_failed' => 'kyc_rejected',
            'live' => 'merchant_live',
            default => null,
        };
        if ($emailTpl) {
            try { sendTemplatedEmail($merchantId, $emailTpl, ['reason' => $reason]); } catch (Throwable $e) {}
        }
    }

    return ['ok' => true, 'error' => '', 'from' => $from, 'to' => $to];
}

/**
 * Get a human-readable label for an onboarding state.
 */
function onboardingStateLabel(string $state): string
{
    return match($state) {
        'draft' => 'Draft',
        'kyc_submitted' => 'KYC Submitted',
        'under_review' => 'Under Review',
        'clarification' => 'Clarification Requested',
        'kyc_verified' => 'KYC Verified',
        'queue_forward' => 'Scheduled for Partner Submission',
        'partner_pending' => 'Partner Pending',
        'live' => 'Live',
        'kyc_failed' => 'KYC Failed',
        'hold' => 'On Hold',
        'rejected' => 'Rejected',
        'suspended' => 'Suspended',
        default => ucfirst($state),
    };
}

/**
 * Get onboarding timeline for a merchant (from immutable_audit_log).
 */
function getMerchantOnboardingTimeline(int $merchantId, int $limit = 20): array
{
    try {
        $st = getDB()->prepare(
            "SELECT event_id, actor_type, actor_id, action, reason, ip_address, created_at
             FROM immutable_audit_log
             WHERE merchant_id=? AND action='onboarding_transition'
             ORDER BY created_at DESC LIMIT ?"
        );
        $st->execute([$merchantId, $limit]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
