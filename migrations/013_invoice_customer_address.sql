-- Invoice PDF field completeness: customer address on invoices.
-- Mirrors ensureInvoiceSchema() in includes/schema_ensure.php.
-- Idempotent: CREATE includes the column; ALTER uses IF NOT EXISTS for older tables.

CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id VARCHAR(40) NOT NULL UNIQUE,
    merchant_id INT NOT NULL,
    customer_name VARCHAR(190) NOT NULL,
    customer_email VARCHAR(150) DEFAULT NULL,
    customer_phone VARCHAR(20) DEFAULT NULL,
    customer_address VARCHAR(500) DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    items TEXT,
    status VARCHAR(30) NOT NULL DEFAULT 'sent',
    due_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inv_merchant (merchant_id),
    INDEX idx_inv_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Older installs that already had invoices without address.
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS customer_address VARCHAR(500) DEFAULT NULL;
