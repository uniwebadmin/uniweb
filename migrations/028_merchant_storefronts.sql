CREATE TABLE IF NOT EXISTS merchant_storefronts (
    merchant_id INT NOT NULL PRIMARY KEY,
    storefront_slug VARCHAR(80) NOT NULL UNIQUE,
    template_key VARCHAR(30) NOT NULL DEFAULT 'services',
    headline VARCHAR(160) NOT NULL DEFAULT '',
    description TEXT NULL,
    contact_text VARCHAR(255) NOT NULL DEFAULT '',
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_merchant_storefront_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
    INDEX idx_merchant_storefront_public (is_published, storefront_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
