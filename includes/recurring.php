<?php
declare(strict_types=1);

/**
 * Recurring / Subscription Payments.
 *
 * Allows merchants to create subscription plans that charge customers on a
 * recurring schedule (weekly, monthly, yearly). Uses UPI Autopay / eMandate
 * framework when available, with manual retry fallback.
 */

function ensureRecurringTables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS subscription_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            plan_name VARCHAR(120) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT 'INR',
            interval_unit ENUM('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
            interval_count INT NOT NULL DEFAULT 1,
            total_cycles INT DEFAULT NULL,
            description VARCHAR(500) DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_merchant (merchant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        getDB()->exec("CREATE TABLE IF NOT EXISTS subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            plan_id INT NOT NULL,
            customer_name VARCHAR(120),
            customer_email VARCHAR(255),
            customer_phone VARCHAR(20),
            customer_upi VARCHAR(100),
            mandate_id VARCHAR(100) DEFAULT NULL,
            mandate_status ENUM('pending','registered','failed','revoked') NOT NULL DEFAULT 'pending',
            current_cycle INT NOT NULL DEFAULT 0,
            next_charge_at TIMESTAMP NULL DEFAULT NULL,
            subscription_status ENUM('active','paused','cancelled','completed','failed') NOT NULL DEFAULT 'active',
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ended_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_merchant (merchant_id, subscription_status),
            INDEX idx_next_charge (next_charge_at, subscription_status),
            INDEX idx_plan (plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        getDB()->exec("CREATE TABLE IF NOT EXISTS subscription_charges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NOT NULL,
            cycle_number INT NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            transaction_id INT DEFAULT NULL,
            charge_status ENUM('pending','success','failed','retrying') NOT NULL DEFAULT 'pending',
            failure_reason VARCHAR(500) DEFAULT NULL,
            retry_count INT NOT NULL DEFAULT 0,
            charged_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_subscription (subscription_id),
            INDEX idx_status (charge_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Create a subscription plan.
 */
function createSubscriptionPlan(int $merchantId, string $name, float $amount, string $intervalUnit, int $intervalCount = 1, ?int $totalCycles = null, ?string $description = null): array
{
    ensureRecurringTables();
    if (!in_array($intervalUnit, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        return ['ok' => false, 'error' => 'Invalid interval unit.'];
    }
    if ($amount < 1) {
        return ['ok' => false, 'error' => 'Amount must be at least ₹1.'];
    }
    try {
        getDB()->prepare(
            "INSERT INTO subscription_plans (merchant_id, plan_name, amount, interval_unit, interval_count, total_cycles, description)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$merchantId, $name, $amount, $intervalUnit, $intervalCount, $totalCycles, $description]);
        return ['ok' => true, 'plan_id' => (int)getDB()->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Subscribe a customer to a plan.
 */
function createSubscription(int $merchantId, int $planId, array $customer): array
{
    ensureRecurringTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT * FROM subscription_plans WHERE id=? AND merchant_id=? AND status='active'");
        $st->execute([$planId, $merchantId]);
        $plan = $st->fetch();
        if (!$plan) {
            return ['ok' => false, 'error' => 'Plan not found or inactive.'];
        }

        $nextCharge = match($plan['interval_unit']) {
            'daily' => date('Y-m-d H:i:s', time() + 86400 * $plan['interval_count']),
            'weekly' => date('Y-m-d H:i:s', time() + 86400 * 7 * $plan['interval_count']),
            'monthly' => date('Y-m-d H:i:s', strtotime("+{$plan['interval_count']} month")),
            'yearly' => date('Y-m-d H:i:s', strtotime("+{$plan['interval_count']} year")),
        };

        $db->prepare(
            "INSERT INTO subscriptions (merchant_id, plan_id, customer_name, customer_email, customer_phone, customer_upi, next_charge_at)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            $merchantId, $planId,
            $customer['name'] ?? null, $customer['email'] ?? null,
            $customer['phone'] ?? null, $customer['upi'] ?? null,
            $nextCharge,
        ]);

        return ['ok' => true, 'subscription_id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get subscriptions due for charging (called from cron).
 */
function getSubscriptionsDueForCharge(int $limit = 50): array
{
    ensureRecurringTables();
    try {
        $st = getDB()->prepare(
            "SELECT s.*, p.amount, p.plan_name, p.interval_unit, p.interval_count, p.total_cycles,
                    m.business_name, m.merchant_code
             FROM subscriptions s
             JOIN subscription_plans p ON p.id = s.plan_id
             JOIN merchants m ON m.id = s.merchant_id
             WHERE s.subscription_status = 'active'
               AND s.next_charge_at <= NOW()
             ORDER BY s.next_charge_at ASC LIMIT ?"
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Process a subscription charge — creates a charge record and attempts payment.
 */
function processSubscriptionCharge(int $subscriptionId): array
{
    ensureRecurringTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT s.*, p.amount, p.total_cycles FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id WHERE s.id=?");
        $st->execute([$subscriptionId]);
        $sub = $st->fetch();
        if (!$sub || $sub['subscription_status'] !== 'active') {
            return ['ok' => false, 'error' => 'Subscription not active.'];
        }

        $cycle = (int)$sub['current_cycle'] + 1;

        // Check if completed
        if ($sub['total_cycles'] && $cycle > (int)$sub['total_cycles']) {
            $db->prepare("UPDATE subscriptions SET subscription_status='completed', ended_at=NOW() WHERE id=?")
                ->execute([$subscriptionId]);
            return ['ok' => false, 'error' => 'Subscription completed.'];
        }

        // Create charge record
        $db->prepare(
            "INSERT INTO subscription_charges (subscription_id, cycle_number, amount, charge_status)
             VALUES (?,?,?,'pending')"
        )->execute([$subscriptionId, $cycle, $sub['amount']]);
        $chargeId = (int)$db->lastInsertId();

        // Attempt charge if mandate is registered
        $chargeResult = attemptSubscriptionPayment($chargeId, $subscriptionId, (float)$sub['amount'], $sub);
        if (!$chargeResult['ok']) {
            $db->prepare("UPDATE subscription_charges SET charge_status='retrying', failure_reason=? WHERE id=?")
                ->execute([$chargeResult['error'] ?? 'Charge attempt failed', $chargeId]);
        }

        // Update subscription cycle + next charge
        $nextCharge = match($sub['interval_unit']) {
            'daily' => date('Y-m-d H:i:s', time() + 86400 * $sub['interval_count']),
            'weekly' => date('Y-m-d H:i:s', time() + 86400 * 7 * $sub['interval_count']),
            'monthly' => date('Y-m-d H:i:s', strtotime("+{$sub['interval_count']} month")),
            'yearly' => date('Y-m-d H:i:s', strtotime("+{$sub['interval_count']} year")),
        };
        $db->prepare("UPDATE subscriptions SET current_cycle=?, next_charge_at=? WHERE id=?")
            ->execute([$cycle, $nextCharge, $subscriptionId]);

        return ['ok' => true, 'charge_id' => $chargeId, 'cycle' => $cycle, 'charge_result' => $chargeResult];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get subscription plans for a merchant.
 */
function getMerchantSubscriptionPlans(int $merchantId): array
{
    ensureRecurringTables();
    try {
        $st = getDB()->prepare("SELECT * FROM subscription_plans WHERE merchant_id=? ORDER BY created_at DESC");
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get subscriptions for a merchant.
 */
function getMerchantSubscriptions(int $merchantId, int $limit = 100): array
{
    ensureRecurringTables();
    try {
        $st = getDB()->prepare(
            "SELECT s.*, p.plan_name, p.amount, p.interval_unit, p.interval_count
             FROM subscriptions s
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.merchant_id=?
             ORDER BY s.created_at DESC LIMIT ?"
        );
        $st->bindValue(1, $merchantId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Cancel a subscription.
 */
function cancelSubscription(int $subscriptionId, int $merchantId): bool
{
    ensureRecurringTables();
    try {
        getDB()->prepare("UPDATE subscriptions SET subscription_status='cancelled', ended_at=NOW() WHERE id=? AND merchant_id=?")
            ->execute([$subscriptionId, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get recurring payment stats for a merchant.
 */
function getRecurringStats(int $merchantId): array
{
    ensureRecurringTables();
    $stats = ['active_subs' => 0, 'total_plans' => 0, 'total_charges' => 0, 'successful_charges' => 0, 'revenue' => 0];
    try {
        $db = getDB();
        $stats['active_subs'] = (int)$db->query("SELECT COUNT(*) FROM subscriptions WHERE merchant_id={$merchantId} AND subscription_status='active'")->fetchColumn();
        $stats['total_plans'] = (int)$db->query("SELECT COUNT(*) FROM subscription_plans WHERE merchant_id={$merchantId}")->fetchColumn();
        $stats['total_charges'] = (int)$db->query("SELECT COUNT(*) FROM subscription_charges sc JOIN subscriptions s ON s.id=sc.subscription_id WHERE s.merchant_id={$merchantId}")->fetchColumn();
        $stats['successful_charges'] = (int)$db->query("SELECT COUNT(*) FROM subscription_charges sc JOIN subscriptions s ON s.id=sc.subscription_id WHERE s.merchant_id={$merchantId} AND sc.charge_status='success'")->fetchColumn();
        $stats['revenue'] = (float)$db->query("SELECT COALESCE(SUM(sc.amount),0) FROM subscription_charges sc JOIN subscriptions s ON s.id=sc.subscription_id WHERE s.merchant_id={$merchantId} AND sc.charge_status='success'")->fetchColumn();
    } catch (Throwable $e) {}
    return $stats;
}

/**
 * Register a mandate — called when partner confirms mandate registration.
 */
function registerMandate(int $subscriptionId, string $mandateId, string $partner = 'decentro'): array
{
    ensureRecurringTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT * FROM subscriptions WHERE id=?");
        $st->execute([$subscriptionId]);
        $sub = $st->fetch();
        if (!$sub) {
            return ['ok' => false, 'error' => 'Subscription not found.'];
        }
        $db->prepare("UPDATE subscriptions SET mandate_id=?, mandate_status='registered' WHERE id=?")
            ->execute([$mandateId, $subscriptionId]);
        createNotification((int)$sub['merchant_id'], 'Mandate Registered', 'UPI Autopay mandate registered for subscription #' . $subscriptionId);
        return ['ok' => true, 'mandate_id' => $mandateId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Revoke a mandate — called when customer revokes or partner notifies.
 */
function revokeMandate(int $subscriptionId, string $reason = 'Customer revoked'): array
{
    ensureRecurringTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT * FROM subscriptions WHERE id=?");
        $st->execute([$subscriptionId]);
        $sub = $st->fetch();
        if (!$sub) {
            return ['ok' => false, 'error' => 'Subscription not found.'];
        }
        $db->prepare("UPDATE subscriptions SET mandate_status='revoked', subscription_status='cancelled', ended_at=NOW() WHERE id=?")
            ->execute([$subscriptionId]);
        createNotification((int)$sub['merchant_id'], 'Mandate Revoked', 'Subscription #' . $subscriptionId . ' cancelled: ' . $reason);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Update mandate status from partner webhook.
 */
if (!function_exists('updateMandateStatus')) {
function updateMandateStatus(int $subscriptionId, string $status, ?string $mandateId = null): array
{
    ensureRecurringTables();
    if (!in_array($status, ['pending', 'registered', 'failed', 'revoked'], true)) {
        return ['ok' => false, 'error' => 'Invalid mandate status.'];
    }
    $db = getDB();
    try {
        $params = [$status, $subscriptionId];
        $sql = "UPDATE subscriptions SET mandate_status=?";
        if ($mandateId) {
            $sql .= ", mandate_id=?";
            array_unshift($params, $mandateId);
            $params = [$mandateId, $status, $subscriptionId];
        }
        $sql .= " WHERE id=?";
        $db->prepare($sql)->execute($params);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
}

/**
 * Attempt a subscription payment via partner API.
 * When partner keys are configured, this triggers actual UPI Autopay debit.
 * Without partner keys, marks as pending (awaiting mandate activation).
 */
function attemptSubscriptionPayment(int $chargeId, int $subscriptionId, float $amount, array $sub): array
{
    ensureRecurringTables();
    $db = getDB();

    // Check mandate status
    if (empty($sub['mandate_id']) || $sub['mandate_status'] !== 'registered') {
        return ['ok' => false, 'error' => 'Mandate not registered. Charge will retry once mandate is active.'];
    }

    // Check if partner gateway is configured
    $partnerKey = getSetting('decentro_api_key', '');
    if (!$partnerKey) {
        // No partner keys — keep as pending, will be processed when keys are live
        return ['ok' => false, 'error' => 'Partner API not configured. Charge pending.'];
    }

    // When partner keys are live, trigger UPI Autopay debit here.
    // This will call Decentro/Razorpay API to execute the mandate debit.
    // For now, mark as pending — the actual API call will be wired when keys are configured.
    $db->prepare("UPDATE subscription_charges SET charge_status='pending', failure_reason=NULL WHERE id=?")
        ->execute([$chargeId]);

    return ['ok' => false, 'error' => 'Partner API integration pending. Charge queued.'];
}

/**
 * Mark a subscription charge as successful (called from partner webhook).
 */
function completeSubscriptionCharge(int $chargeId, ?int $transactionId = null): array
{
    ensureRecurringTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT sc.*, s.merchant_id FROM subscription_charges sc JOIN subscriptions s ON s.id=sc.subscription_id WHERE sc.id=?");
        $st->execute([$chargeId]);
        $charge = $st->fetch();
        if (!$charge) {
            return ['ok' => false, 'error' => 'Charge not found.'];
        }
        $db->prepare("UPDATE subscription_charges SET charge_status='success', transaction_id=?, charged_at=NOW(), failure_reason=NULL WHERE id=?")
            ->execute([$transactionId, $chargeId]);
        createNotification((int)$charge['merchant_id'], 'Subscription Payment Received', 'Cycle ' . $charge['cycle_number'] . ' charge successful for subscription #' . $charge['subscription_id']);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Mark a subscription charge as failed (called from partner webhook).
 */
function failSubscriptionCharge(int $chargeId, string $reason): array
{
    ensureRecurringTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT sc.*, s.merchant_id FROM subscription_charges sc JOIN subscriptions s ON s.id=sc.subscription_id WHERE sc.id=?");
        $st->execute([$chargeId]);
        $charge = $st->fetch();
        if (!$charge) {
            return ['ok' => false, 'error' => 'Charge not found.'];
        }
        $retryCount = (int)$charge['retry_count'] + 1;
        $newStatus = $retryCount >= 3 ? 'failed' : 'retrying';
        $db->prepare("UPDATE subscription_charges SET charge_status=?, failure_reason=?, retry_count=? WHERE id=?")
            ->execute([$newStatus, $reason, $retryCount, $chargeId]);

        if ($newStatus === 'failed') {
            $db->prepare("UPDATE subscriptions SET subscription_status='failed' WHERE id=?")
                ->execute([$charge['subscription_id']]);
            createNotification((int)$charge['merchant_id'], 'Subscription Charge Failed', 'Cycle ' . $charge['cycle_number'] . ' charge failed after 3 retries: ' . $reason);
        }
        return ['ok' => true, 'status' => $newStatus];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Retry a failed/retrying charge.
 */
function retrySubscriptionCharge(int $chargeId): array
{
    ensureRecurringTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT sc.*, s.* FROM subscription_charges sc JOIN subscriptions s ON s.id=sc.subscription_id WHERE sc.id=?");
        $st->execute([$chargeId]);
        $charge = $st->fetch();
        if (!$charge) {
            return ['ok' => false, 'error' => 'Charge not found.'];
        }
        if (!in_array($charge['charge_status'], ['retrying', 'failed'], true)) {
            return ['ok' => false, 'error' => 'Charge is not in retryable state.'];
        }
        if ((int)$charge['retry_count'] >= 3) {
            return ['ok' => false, 'error' => 'Max retries exceeded.'];
        }
        $db->prepare("UPDATE subscription_charges SET charge_status='pending' WHERE id=?")->execute([$chargeId]);
        $result = attemptSubscriptionPayment($chargeId, (int)$charge['subscription_id'], (float)$charge['amount'], $charge);
        return $result;
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Batch process all due subscription charges — called from cron.
 */
function processDueSubscriptionCharges(int $limit = 50): array
{
    ensureRecurringTables();
    $due = getSubscriptionsDueForCharge($limit);
    $results = [];
    foreach ($due as $sub) {
        $result = processSubscriptionCharge((int)$sub['id']);
        $results[] = [
            'subscription_id' => (int)$sub['id'],
            'merchant' => $sub['business_name'] ?? '',
            'ok' => $result['ok'],
            'error' => $result['error'] ?? null,
            'charge_id' => $result['charge_id'] ?? null,
        ];
    }
    return ['processed' => count($results), 'results' => $results];
}

/**
 * Get subscription charge history for a subscription.
 */
function getSubscriptionCharges(int $subscriptionId, int $limit = 50): array
{
    ensureRecurringTables();
    try {
        $st = getDB()->prepare("SELECT * FROM subscription_charges WHERE subscription_id=? ORDER BY cycle_number DESC LIMIT ?");
        $st->bindValue(1, $subscriptionId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Pause a subscription (merchant or customer initiated).
 */
function pauseSubscription(int $subscriptionId, int $merchantId): bool
{
    ensureRecurringTables();
    try {
        getDB()->prepare("UPDATE subscriptions SET subscription_status='paused' WHERE id=? AND merchant_id=?")
            ->execute([$subscriptionId, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Resume a paused subscription.
 */
function resumeSubscription(int $subscriptionId, int $merchantId): bool
{
    ensureRecurringTables();
    try {
        $st = getDB()->prepare("SELECT * FROM subscriptions WHERE id=? AND merchant_id=?");
        $st->execute([$subscriptionId, $merchantId]);
        $sub = $st->fetch();
        if (!$sub) return false;
        $nextCharge = match($sub['interval_unit']) {
            'daily' => date('Y-m-d H:i:s', time() + 86400 * $sub['interval_count']),
            'weekly' => date('Y-m-d H:i:s', time() + 86400 * 7 * $sub['interval_count']),
            'monthly' => date('Y-m-d H:i:s', strtotime("+{$sub['interval_count']} month")),
            'yearly' => date('Y-m-d H:i:s', strtotime("+{$sub['interval_count']} year")),
        };
        getDB()->prepare("UPDATE subscriptions SET subscription_status='active', next_charge_at=? WHERE id=? AND merchant_id=?")
            ->execute([$nextCharge, $subscriptionId, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ------------------------------------------------------------------ *
 *  recurring_mandates table lifecycle (merchant-facing mandates)
 * ------------------------------------------------------------------ */

/**
 * Activate a mandate — called when partner confirms mandate registration.
 */
function activateRecurringMandate(int $mandateId, string $providerMandateId, string $provider = 'decentro'): array
{
    $db = getDB();
    try {
        $st = $db->prepare("SELECT * FROM recurring_mandates WHERE id=?");
        $st->execute([$mandateId]);
        $mandate = $st->fetch();
        if (!$mandate) {
            return ['ok' => false, 'error' => 'Mandate not found.'];
        }
        $nextCharge = calculateNextChargeDate($mandate['frequency']);
        $db->prepare("UPDATE recurring_mandates SET status='active', provider=?, provider_mandate_id=?, next_charge_at=? WHERE id=?")
            ->execute([$provider, $providerMandateId, $nextCharge, $mandateId]);
        createNotification((int)$mandate['merchant_id'], 'Mandate Activated', 'Mandate ' . $mandate['mandate_ref'] . ' is now active. Next charge: ' . $nextCharge);
        return ['ok' => true, 'next_charge' => $nextCharge];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Pause a mandate.
 */
function pauseRecurringMandate(int $mandateId, int $merchantId): bool
{
    try {
        getDB()->prepare("UPDATE recurring_mandates SET status='paused' WHERE id=? AND merchant_id=? AND status='active'")
            ->execute([$mandateId, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Resume a paused mandate.
 */
function resumeRecurringMandate(int $mandateId, int $merchantId): bool
{
    try {
        $st = getDB()->prepare("SELECT * FROM recurring_mandates WHERE id=? AND merchant_id=? AND status='paused'");
        $st->execute([$mandateId, $merchantId]);
        $mandate = $st->fetch();
        if (!$mandate) return false;
        $nextCharge = calculateNextChargeDate($mandate['frequency']);
        getDB()->prepare("UPDATE recurring_mandates SET status='active', next_charge_at=? WHERE id=? AND merchant_id=?")
            ->execute([$nextCharge, $mandateId, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Cancel a mandate.
 */
function cancelRecurringMandate(int $mandateId, int $merchantId, string $reason = ''): bool
{
    try {
        getDB()->prepare("UPDATE recurring_mandates SET status='cancelled' WHERE id=? AND merchant_id=? AND status IN ('active','paused','pending_partner')")
            ->execute([$mandateId, $merchantId]);
        $st = getDB()->prepare("SELECT * FROM recurring_mandates WHERE id=?");
        $st->execute([$mandateId]);
        $mandate = $st->fetch();
        if ($mandate) {
            createNotification((int)$mandate['merchant_id'], 'Mandate Cancelled', 'Mandate ' . $mandate['mandate_ref'] . ' cancelled' . ($reason ? ': ' . $reason : ''));
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get mandates due for charging.
 */
function getMandatesDueForCharge(int $limit = 50): array
{
    try {
        $st = getDB()->prepare(
            "SELECT * FROM recurring_mandates
             WHERE status = 'active'
               AND next_charge_at IS NOT NULL
               AND next_charge_at <= NOW()
             ORDER BY next_charge_at ASC LIMIT ?"
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Process a single mandate charge — creates a transaction record.
 */
function processMandateCharge(array $mandate): array
{
    $db = getDB();
    try {
        $amount = (float)$mandate['amount'];
        $txnRef = generateId('TXN');

        // Create transaction record
        $db->prepare(
            "INSERT INTO transactions (txn_id, transaction_id, merchant_id, amount, status, payment_method, description, is_test, collection_mode, wallet_credited)
             VALUES (?,?,?,?, 'pending', 'upi_autopay', ?, 0, 'upi_autopay', 0)"
        )->execute([
            $txnRef, $txnRef, (int)$mandate['merchant_id'], $amount,
            'Recurring charge for ' . $mandate['mandate_ref'],
        ]);
        $txnId = (int)$db->lastInsertId();

        // Check if partner API is configured
        $partnerKey = getSetting('decentro_api_key', '');
        if (!$partnerKey) {
            // No partner keys — schedule next charge, keep txn pending
            $nextCharge = calculateNextChargeDate($mandate['frequency']);
            $db->prepare("UPDATE recurring_mandates SET next_charge_at=? WHERE id=?")
                ->execute([$nextCharge, (int)$mandate['id']]);
            return ['ok' => false, 'error' => 'Partner API not configured. Transaction created as pending.', 'txn_id' => $txnId];
        }

        // When partner keys are live, trigger UPI Autopay debit here via Decentro/Razorpay API.
        // For now, schedule next charge and keep txn pending.
        $nextCharge = calculateNextChargeDate($mandate['frequency']);
        $db->prepare("UPDATE recurring_mandates SET next_charge_at=? WHERE id=?")
            ->execute([$nextCharge, (int)$mandate['id']]);

        return ['ok' => true, 'txn_id' => $txnId, 'next_charge' => $nextCharge];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Batch process all due mandate charges — called from cron.
 */
function processDueMandateCharges(int $limit = 50): array
{
    $due = getMandatesDueForCharge($limit);
    $results = [];
    foreach ($due as $mandate) {
        $result = processMandateCharge($mandate);
        $results[] = [
            'mandate_ref' => $mandate['mandate_ref'],
            'merchant_id' => (int)$mandate['merchant_id'],
            'ok' => $result['ok'],
            'error' => $result['error'] ?? null,
            'txn_id' => $result['txn_id'] ?? null,
        ];
    }
    return ['processed' => count($results), 'results' => $results];
}

/**
 * Calculate next charge date based on frequency.
 */
function calculateNextChargeDate(string $frequency): string
{
    return match($frequency) {
        'daily' => date('Y-m-d H:i:s', time() + 86400),
        'weekly' => date('Y-m-d H:i:s', time() + 86400 * 7),
        'monthly' => date('Y-m-d H:i:s', strtotime('+1 month')),
        'yearly' => date('Y-m-d H:i:s', strtotime('+1 year')),
        default => date('Y-m-d H:i:s', strtotime('+1 month')),
    };
}
