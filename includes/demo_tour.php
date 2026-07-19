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
            'desc' => 'One payment link — customer picks UPI, debit card, credit card, EMI or wallet. Live ₹1 test below.',
            'img' => 'assets/img/demo/demo-checkout.png',
            'embed' => $payUrl,
            'action' => ['Try ₹1 Payment', $payUrl],
            'narration_en' => 'Welcome to UniWeb. This is our multi-method checkout. Your customer receives one secure link and can pay using UPI, debit card, credit card, net banking, EMI, or wallets. Try the live one rupee demo payment on screen now.',
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
            'desc' => 'Decentro-powered verification. Admin review queue. Structured KYC onboarding for businesses and individuals.',
            'img' => 'assets/img/demo/demo-kyc.png',
            'action' => ['Compliance Info', 'faq.php#kyc'],
            'narration_en' => 'KYC and compliance are built in. PAN, GST, bank account, and Aadhaar verification via Decentro API. Admin can approve or reject. AML flags and a document vault support audit-ready record keeping.',
        ],
        [
            'id' => 'wallet',
            'title' => 'Wallet & Settlement',
            'subtitle' => 'T+1 free bank transfer',
            'desc' => 'Payments credit wallet → transfer to bank. Platform commission auto-deducted.',
            'img' => 'assets/img/demo/demo-wallet.png',
            'action' => ['View Pricing', 'index.php#pricing'],
            'narration_en' => 'Every successful payment credits the merchant wallet after commission. Merchants request settlement to transfer balance to their bank account — T plus one for free on standard plans. Platform wallet tracks UniWeb commission.',
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
