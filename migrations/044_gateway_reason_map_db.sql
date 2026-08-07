-- Migration 044: Gateway reason map DB table with Hindi translations
-- A8: Admin-editable reason maps with English + Hindi messages

CREATE TABLE IF NOT EXISTS gateway_reason_maps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    error_code VARCHAR(100) NOT NULL UNIQUE,
    message_en VARCHAR(500) NOT NULL,
    message_hi VARCHAR(500) DEFAULT NULL,
    category ENUM('funds','timeout','risk','decline','limit','cancel','settlement','upi','other') NOT NULL DEFAULT 'other',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (error_code),
    INDEX idx_category (category, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed common reason maps from the existing PHP dictionary
INSERT IGNORE INTO gateway_reason_maps (error_code, message_en, message_hi, category) VALUES
('INSUFFICIENT_FUNDS', 'Insufficient balance in the customer''s account.', 'ग्राहक के खाते में पर्याप्त बैलेंस नहीं है।', 'funds'),
('INSUFFICIENT_BALANCE', 'Insufficient balance in the customer''s account.', 'ग्राहक के खाते में पर्याप्त बैलेंस नहीं है।', 'funds'),
('ACCOUNT_BLOCKED', 'Customer account is blocked or frozen by the bank.', 'ग्राहक का खाता बैंक द्वारा ब्लॉक या फ्रीज कर दिया गया है।', 'funds'),
('ACCOUNT_CLOSED', 'Customer account is closed. Ask them to pay from another account.', 'ग्राहक का खाता बंद है। दूसरे खाते से भुगतान करें।', 'funds'),
('INVALID_ACCOUNT', 'Bank account details are invalid. Ask the customer to retry with a correct account.', 'बैंक खाता विवरण गलत है। ग्राहक से सही विवरण के साथ फिर से प्रयास करें।', 'funds'),
('INVALID_VPA', 'UPI ID (VPA) is invalid or not found.', 'UPI ID गलत या नहीं मिला।', 'funds'),
('ACQUIRER_TIMEOUT', 'Bank did not respond in time. Please ask the customer to try again.', 'बैंक ने समय पर जवाब नहीं दिया। ग्राहक से फिर से कोशिश करें।', 'timeout'),
('GATEWAY_TIMEOUT', 'Bank did not respond in time. Please ask the customer to try again.', 'बैंक ने समय पर जवाब नहीं दिया। ग्राहक से फिर से कोशिश करें।', 'timeout'),
('TIMEOUT', 'Bank did not respond in time. Please ask the customer to try again.', 'बैंक ने समय पर जवाब नहीं दिया। ग्राहक से फिर से कोशिश करें।', 'timeout'),
('SERVER_ERROR', 'Technical issue from bank side. Please try again later.', 'बैंक की तरफ से तकनीकी समस्या। बाद में फिर से कोशिश करें।', 'timeout'),
('RISK_REJECTED', 'Payment blocked by risk checks. Customer may need to use another method.', 'रिस्क चेक के कारण भुगतान ब्लॉक हो गया। ग्राहक दूसरा तरीका अपनाए।', 'risk'),
('FRAUD_SUSPECTED', 'Payment blocked by fraud filters. Ask the customer to contact their bank.', 'फ्रॉड फिल्टर के कारण भुगतान ब्लॉक। ग्राहक अपने बैंक से संपर्क करें।', 'risk'),
('PAYMENT_DECLINED', 'Bank declined the payment. Ask the customer to retry or use another method.', 'बैंक ने भुगतान अस्वीकार कर दिया। ग्राहक फिर से कोशिश करें या दूसरा तरीका अपनाए।', 'decline'),
('AUTHENTICATION_FAILED', 'Customer authentication failed (OTP / 3-D Secure). Ask them to retry.', 'ग्राहक प्रमाणीकरण विफल (OTP / 3-D Secure)। फिर से कोशिश करें।', 'decline'),
('OTP_FAILED', 'OTP verification failed. Ask the customer to retry with the correct OTP.', 'OTP सत्यापन विफल। सही OTP के साथ फिर से कोशिश करें।', 'decline'),
('TRANSACTION_LIMIT_EXCEEDED', 'Transaction exceeds the bank / UPI limit for this customer.', 'लेनदेन बैंक / UPI की सीमा से अधिक है।', 'limit'),
('DAILY_LIMIT_EXCEEDED', 'Customer has reached their daily payment limit.', 'ग्राहक की दैनिक भुगतान सीमा पूरी हो गई है।', 'limit'),
('PAYMENT_CANCELLED', 'Customer cancelled the payment before completion.', 'ग्राहक ने भुगतान पूरा होने से पहले रद्द कर दिया।', 'cancel'),
('PAYMENT_EXPIRED', 'Payment session expired before the customer completed payment.', 'ग्राहक के भुगतान पूरा करने से पहले सत्र समाप्त हो गया।', 'cancel'),
('BENEFICIARY_BANK_REJECTED', 'Beneficiary bank rejected the transfer. Verify IFSC and account number, then retry.', 'लाभार्थी बैंक ने ट्रांसफर अस्वीकार कर दिया। IFSC और खाता संख्या जांचें, फिर फिर से कोशिश करें।', 'settlement'),
('INVALID_IFSC', 'IFSC code is invalid. Update bank details and retry settlement.', 'IFSC कोड गलत है। बैंक विवरण अपडेट करें और फिर से सेटलमेंट करें।', 'settlement'),
('UPI_COLLECT_EXPIRED', 'UPI collect request expired before the customer approved it.', 'ग्राहक के मंजूर करने से पहले UPI कलेक्ट रिक्वेस्ट समाप्त हो गई।', 'upi'),
('NPCI_REJECTED', 'UPI network rejected the payment. Ask the customer to try again.', 'UPI नेटवर्क ने भुगतान अस्वीकार कर दिया। ग्राहक से फिर से कोशिश करें।', 'upi');
