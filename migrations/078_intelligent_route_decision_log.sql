-- Intelligent routing decision audit log (production DDL — replaces runtime-only CREATE).
-- Safe to re-run: CREATE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS intelligent_route_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT DEFAULT NULL,
    link_id VARCHAR(64) DEFAULT NULL,
    txn_id VARCHAR(40) DEFAULT NULL,
    method_key VARCHAR(32) DEFAULT NULL,
    amount DECIMAL(12,2) DEFAULT NULL,
    chosen_partner VARCHAR(24) DEFAULT NULL,
    strategy VARCHAR(24) NOT NULL DEFAULT 'score',
    reason_code VARCHAR(64) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    scores_json TEXT DEFAULT NULL,
    candidates_json TEXT DEFAULT NULL,
    engine_on TINYINT(1) NOT NULL DEFAULT 0,
    outcome ENUM('selected','failover','fallback_fixed','none','error','attempt_failed') NOT NULL DEFAULT 'selected',
    attempt_index TINYINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ir_time (created_at),
    INDEX idx_ir_partner (chosen_partner, created_at),
    INDEX idx_ir_outcome (outcome, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
