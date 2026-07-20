<?php
declare(strict_types=1);

/** Narrated platform tour — EN + HI scripts */

function getDemoTourSlides(?array $demo = null): array
{
    $payUrl = $demo['pay_url'] ?? (APP_URL . '/demo.php');
    return [
        [
            'id' => 'checkout',
            'title' => 'Multi-Method Checkout',
            'subtitle' => 'UPI · Cards · Netbanking · Wallets',
            'desc' => 'One test payment link demonstrates the checkout methods that can be enabled after partner approval.',
            'img' => 'assets/img/demo/demo-checkout.png',
            'embed' => $payUrl,
            'action' => ['Try ₹1 Payment', $payUrl],
            'narration_en' => 'Welcome to UniWeb. This is our multi-method checkout preview. The one rupee demonstration runs in Test Mode and does not move real money.',
        ],
        [
            'id' => 'dashboard',
            'title' => 'Merchant Dashboard',
            'subtitle' => 'Real-time collections',
            'desc' => 'Track today\'s sales, wallet balance, payment links and settlements from one dashboard.',
            'img' => 'assets/img/demo/demo-dashboard.png',
            'action' => ['Merchant Login', 'login.php'],
            'narration_en' => 'Merchants get a real-time dashboard. See today\'s collections, pending settlements, transaction history, and analytics. Everything updates instantly after each payment.',
        ],
        [
            'id' => 'payment-pack',
            'title' => 'Auto Payment Pack',
            'subtitle' => '6 method links — one click',
            'desc' => 'New merchant signup → auto UPI, VA, PayU, Razorpay, Cashfree links created instantly.',
            'img' => 'assets/img/demo/demo-payment-pack.png',
            'action' => ['Sign Up Free', 'merchant_register.php'],
            'narration_en' => 'When a new merchant signs up, UniWeb auto-creates a payment pack — separate links for UPI, virtual account, PayU cards, Razorpay, Cashfree, and more. Share on WhatsApp in one click.',
        ],
        [
            'id' => 'kyc',
            'title' => 'KYC & Compliance',
            'subtitle' => 'PAN · GST · Bank · AML',
            'desc' => 'Entity-specific document collection and admin review, with partner verification when activated.',
            'img' => 'assets/img/demo/demo-kyc.png',
            'action' => ['Compliance Info', 'compliance.php'],
            'narration_en' => 'KYC workflows collect entity-specific documents and route them for review. External verification is used only when the relevant partner module is activated.',
        ],
        [
            'id' => 'wallet',
            'title' => 'Wallet & Settlement',
            'subtitle' => 'Batch and settlement tracking',
            'desc' => 'Track eligible balances and batches; live bank transfer depends on an activated payout rail.',
            'img' => 'assets/img/demo/demo-wallet.png',
            'action' => ['View Pricing', 'index.php#pricing'],
            'narration_en' => 'Verified payments feed the merchant ledger and settlement batches. A settlement is complete only after the activated bank or payout partner confirms the transfer.',
        ],
    ];
}

function ensureSupportTicketTable(): void
{
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS support_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id VARCHAR(30) NOT NULL UNIQUE,
            merchant_id INT NOT NULL,
            category VARCHAR(40) DEFAULT 'general',
            subject VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            txn_reference VARCHAR(60) DEFAULT NULL,
            priority ENUM('low','medium','high') DEFAULT 'medium',
            status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
            admin_reply TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (merchant_id),
            INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $db->exec("ALTER TABLE support_tickets ADD COLUMN category VARCHAR(40) DEFAULT 'general' AFTER merchant_id"); } catch (Throwable $e) { /* ok */ }
        try { $db->exec("ALTER TABLE support_tickets ADD COLUMN txn_reference VARCHAR(60) DEFAULT NULL AFTER message"); } catch (Throwable $e) { /* ok */ }
        $db->exec("CREATE TABLE IF NOT EXISTS support_ticket_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            sender_type ENUM('merchant','admin') NOT NULL,
            sender_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (ticket_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function getSupportTicketCategories(): array
{
    return [
        'general' => 'General Support',
        'transaction' => 'Transaction Issue',
        'settlement' => 'Settlement / Payout',
        'compliance' => 'KYC / Compliance',
        'technical' => 'API / Technical',
        'dispute' => 'Chargeback / Dispute',
    ];
}
