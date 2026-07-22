-- Expand gateway_submissions.gateway ENUM to include Axis Bank.
ALTER TABLE gateway_submissions
  MODIFY gateway ENUM('razorpay','cashfree','payu','decentro','phonepe','axis') NOT NULL;
