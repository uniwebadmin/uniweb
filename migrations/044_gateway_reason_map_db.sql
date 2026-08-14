-- Migration 044: Gateway reason map seed data with Hindi translations
-- A8: Admin-editable reason maps with English + Hindi messages
-- Table gateway_reason_maps is created by migration 040 (partner_control_plane.sql)
-- with columns: partner_key, raw_code, msg_en, msg_hi, is_active

-- Seed common reason maps (partner_key='generic' for all partners)
-- 044-fix: table may be missing, or may pre-exist without partner_key / msg columns.
-- CREATE + ALTER ADD before INSERT so half-applied DBs never fail on Unknown column.
CREATE TABLE IF NOT EXISTS gateway_reason_maps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_key VARCHAR(40) NOT NULL DEFAULT '',
    raw_code VARCHAR(120) NOT NULL,
    msg_en VARCHAR(500) NOT NULL DEFAULT '',
    msg_hi VARCHAR(500) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_partner_code (partner_key, raw_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE gateway_reason_maps ADD COLUMN partner_key VARCHAR(40) NOT NULL DEFAULT '';
ALTER TABLE gateway_reason_maps ADD COLUMN raw_code VARCHAR(120) NOT NULL DEFAULT '';
ALTER TABLE gateway_reason_maps ADD COLUMN msg_en VARCHAR(500) NOT NULL DEFAULT '';
ALTER TABLE gateway_reason_maps ADD COLUMN msg_hi VARCHAR(500) NOT NULL DEFAULT '';
ALTER TABLE gateway_reason_maps ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
INSERT IGNORE INTO gateway_reason_maps (partner_key, raw_code, msg_en, msg_hi) VALUES
('generic', 'INSUFFICIENT_FUNDS', 'Insufficient balance in the customer''s account.', 'ग्राहक के खाते में पर्याप्त बैलेंस नहीं है।'),
('generic', 'INSUFFICIENT_BALANCE', 'Insufficient balance in the customer''s account.', 'ग्राहक के खाते में पर्याप्त बैलेंस नहीं है।'),
('generic', 'ACCOUNT_BLOCKED', 'Customer account is blocked or frozen by the bank.', 'ग्राहक का खाता बैंक द्वारा ब्लॉक या फ्रीज कर दिया गया है।'),
('generic', 'ACCOUNT_CLOSED', 'Customer account is closed. Ask them to pay from another account.', 'ग्राहक का खाता बंद है। दूसरे खाते से भुगतान करें।'),
('generic', 'INVALID_ACCOUNT', 'Bank account details are invalid. Ask the customer to retry with a correct account.', 'बैंक खाता विवरण गलत है। ग्राहक से सही विवरण के साथ फिर से प्रयास करें।'),
('generic', 'INVALID_VPA', 'UPI ID (VPA) is invalid or not found.', 'UPI ID गलत या नहीं मिला।'),
('generic', 'ACQUIRER_TIMEOUT', 'Bank did not respond in time. Please ask the customer to try again.', 'बैंक ने समय पर जवाब नहीं दिया। ग्राहक से फिर से कोशिश करें।'),
('generic', 'GATEWAY_TIMEOUT', 'Bank did not respond in time. Please ask the customer to try again.', 'बैंक ने समय पर जवाब नहीं दिया। ग्राहक से फिर से कोशिश करें।'),
('generic', 'TIMEOUT', 'Bank did not respond in time. Please ask the customer to try again.', 'बैंक ने समय पर जवाब नहीं दिया। ग्राहक से फिर से कोशिश करें।'),
('generic', 'SERVER_ERROR', 'Technical issue from bank side. Please try again later.', 'बैंक की तरफ से तकनीकी समस्या। बाद में फिर से कोशिश करें।'),
('generic', 'RISK_REJECTED', 'Payment blocked by risk checks. Customer may need to use another method.', 'रिस्क चेक के कारण भुगतान ब्लॉक हो गया। ग्राहक दूसरा तरीका अपनाए।'),
('generic', 'FRAUD_SUSPECTED', 'Payment blocked by fraud filters. Ask the customer to contact their bank.', 'फ्रॉड फिल्टर के कारण भुगतान ब्लॉक। ग्राहक अपने बैंक से संपर्क करें।'),
('generic', 'PAYMENT_DECLINED', 'Bank declined the payment. Ask the customer to retry or use another method.', 'बैंक ने भुगतान अस्वीकार कर दिया। ग्राहक फिर से कोशिश करें या दूसरा तरीका अपनाए।'),
('generic', 'AUTHENTICATION_FAILED', 'Customer authentication failed (OTP / 3-D Secure). Ask them to retry.', 'ग्राहक प्रमाणीकरण विफल (OTP / 3-D Secure)। फिर से कोशिश करें।'),
('generic', 'OTP_FAILED', 'OTP verification failed. Ask the customer to retry with the correct OTP.', 'OTP सत्यापन विफल। सही OTP के साथ फिर से कोशिश करें।'),
('generic', 'TRANSACTION_LIMIT_EXCEEDED', 'Transaction exceeds the bank / UPI limit for this customer.', 'लेनदेन बैंक / UPI की सीमा से अधिक है।'),
('generic', 'DAILY_LIMIT_EXCEEDED', 'Customer has reached their daily payment limit.', 'ग्राहक की दैनिक भुगतान सीमा पूरी हो गई है।'),
('generic', 'PAYMENT_CANCELLED', 'Customer cancelled the payment before completion.', 'ग्राहक ने भुगतान पूरा होने से पहले रद्द कर दिया।'),
('generic', 'PAYMENT_EXPIRED', 'Payment session expired before the customer completed payment.', 'ग्राहक के भुगतान पूरा करने से पहले सत्र समाप्त हो गया।'),
('generic', 'BENEFICIARY_BANK_REJECTED', 'Beneficiary bank rejected the transfer. Verify IFSC and account number, then retry.', 'लाभार्थी बैंक ने ट्रांसफर अस्वीकार कर दिया। IFSC और खाता संख्या जांचें, फिर फिर से कोशिश करें।'),
('generic', 'INVALID_IFSC', 'IFSC code is invalid. Update bank details and retry settlement.', 'IFSC कोड गलत है। बैंक विवरण अपडेट करें और फिर से सेटलमेंट करें।'),
('generic', 'UPI_COLLECT_EXPIRED', 'UPI collect request expired before the customer approved it.', 'ग्राहक के मंजूर करने से पहले UPI कलेक्ट रिक्वेस्ट समाप्त हो गई।'),
('generic', 'NPCI_REJECTED', 'UPI network rejected the payment. Ask the customer to try again.', 'UPI नेटवर्क ने भुगतान अस्वीकार कर दिया। ग्राहक से फिर से कोशिश करें।');
