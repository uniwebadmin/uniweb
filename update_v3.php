<?php
if (($_GET['key'] ?? '') !== 'uniweb_update_v3') { http_response_code(403); die('Access denied.'); }
require_once __DIR__ . '/config.php';
try {
    $pdo = getDB();
    $sqls = [
        "ALTER TABLE merchants ADD COLUMN parent_merchant_id INT DEFAULT NULL AFTER id",
        "ALTER TABLE merchants ADD COLUMN agent_commission DECIMAL(5,2) DEFAULT 0.50 AFTER commission_rate",
        "CREATE TABLE IF NOT EXISTS blog_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(200) NOT NULL UNIQUE,
            title_en VARCHAR(300) NOT NULL,
            excerpt_en TEXT,
            content_en LONGTEXT,
            status ENUM('draft','published') DEFAULT 'published',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS aml_flags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            transaction_id INT DEFAULT NULL,
            flag_type VARCHAR(50) NOT NULL,
            severity ENUM('low','medium','high') DEFAULT 'medium',
            description TEXT,
            status ENUM('open','reviewed','cleared') DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB",
    ];
    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e;
        }
    }
    $count = (int)$pdo->query('SELECT COUNT(*) FROM blog_posts')->fetchColumn();
    if ($count === 0) {
        $posts = [
            ['upi-payments-guide-2026', 'Complete Guide to UPI Payments for Indian Merchants', 'Learn how UPI QR codes and payment links can grow your business with zero MDR.'],
            ['kyc-documents-checklist', 'KYC Documents Checklist for Every Business Type', 'Sole prop, Partnership, Pvt Ltd, OPC — what documents you need for UNIWEB verification.'],
            ['settlement-t1-explained', 'T+1 Settlement Explained: Get Paid Next Day', 'How UNIWEB settles your collections to your bank account within 24 hours.'],
        ];
        $stmt = $pdo->prepare('INSERT INTO blog_posts (slug,title_en,excerpt_en,content_en) VALUES (?,?,?,?)');
        foreach ($posts as [$slug,$title,$excerpt]) {
            $stmt->execute([$slug,$title,$excerpt,"<p>{$excerpt}</p>"]);
        }
    }
    $defaults = [
        ['smtp_host',''],['smtp_port','587'],['smtp_user',''],['smtp_pass',''],
        ['smtp_from_email','support@uniweb.co.in'],['smtp_from_name','UNIWEB'],
        ['aml_high_value_threshold','200000'],
    ];
    $s = $pdo->prepare('INSERT IGNORE INTO gateway_settings (setting_key, setting_value) VALUES (?,?)');
    foreach ($defaults as [$k,$v]) $s->execute([$k,$v]);

    echo '<!DOCTYPE html><html><head><title>v3</title><style>body{font-family:system-ui;max-width:600px;margin:60px auto;background:#0f172a;color:#e2e8f0}.ok{background:#065f46;padding:20px;border-radius:12px}h1{color:#10b981}</style></head><body>
    <h1>✓ Update v3 Complete</h1><div class="ok"><p>Sub-merchants, blog, AML flags, SMTP settings added.</p></div>
    <p>Delete this file. <a href="reports.php" style="color:#10b981">Reports</a> | <a href="faq.php" style="color:#10b981">FAQ</a></p></body></html>';
} catch (PDOException $e) {
    echo '<pre style="color:red">'.htmlspecialchars($e->getMessage()).'</pre>';
}
