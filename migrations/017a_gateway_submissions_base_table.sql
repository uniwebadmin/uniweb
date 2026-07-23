-- Base table for gateway_submissions (admin "submit merchant to gateway" queue).
-- This table pre-dates the migrations system and was only ever created at
-- runtime by ensureGatewaySubmissionsTable() in includes/gateways.php, so a
-- genuinely fresh install (new dev sandbox, disaster recovery, etc.) never
-- gets it via migrations, which broke 018's ALTER TABLE (table not found)
-- on any environment where the runtime "ensure" helper was never triggered
-- first. Numbered 017a so it sorts before 018 (files apply in string-sort
-- order per includes/migrations.php) without renumbering already-applied
-- files. Mirrors ensureGatewaySubmissionsTable() exactly (5-gateway ENUM,
-- pre-Axis) — 018 immediately after this expands the ENUM to add 'axis'.
CREATE TABLE IF NOT EXISTS gateway_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    gateway ENUM('razorpay','cashfree','payu','decentro','phonepe') NOT NULL,
    status ENUM('draft','submitted','approved','rejected','pending_review') DEFAULT 'submitted',
    payload LONGTEXT,
    admin_id INT,
    admin_notes TEXT,
    gateway_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_merchant (merchant_id),
    INDEX idx_gateway (gateway)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
