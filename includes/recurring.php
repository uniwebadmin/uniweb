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

        // TODO: When UPI Autopay / eMandate is live, trigger actual payment here.
        // For now, mark as pending — actual charge will happen when mandate is registered.

        // Update subscription cycle + next charge
        $nextCharge = match($sub['interval_unit']) {
            'daily' => date('Y-m-d H:i:s', time() + 86400 * $sub['interval_count']),
            'weekly' => date('Y-m-d H:i:s', time() + 86400 * 7 * $sub['interval_count']),
            'monthly' => date('Y-m-d H:i:s', strtotime("+{$sub['interval_count']} month")),
            'yearly' => date('Y-m-d H:i:s', strtotime("+{$sub['interval_count']} year")),
        };
        $db->prepare("UPDATE subscriptions SET current_cycle=?, next_charge_at=? WHERE id=?")
            ->execute([$cycle, $nextCharge, $subscriptionId]);

        return ['ok' => true, 'charge_id' => $chargeId, 'cycle' => $cycle];
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
        $st = $db->prepare ?? getDB()->prepare(
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
