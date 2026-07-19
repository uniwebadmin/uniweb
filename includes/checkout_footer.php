<?php
/** Compact three-row legal footer for checkout & payment pages */
$checkoutPartners = array_values(array_filter([
    isGatewayConfigured('razorpay') ? 'Razorpay' : null,
    isGatewayConfigured('cashfree') ? 'Cashfree' : null,
    isGatewayConfigured('payu') ? 'PayU' : null,
    isGatewayConfigured('axis') ? 'Axis Bank' : null,
]));
?>
<footer class="checkout-footer border-t border-gray-800/80 bg-dark-950/95">
    <div class="max-w-5xl mx-auto px-3 py-3">
        <div class="checkout-footer-row">
            <strong>Secure &amp; Compliant</strong>
            <div><?php foreach (['256-bit SSL', 'PCI DSS Ready', 'RBI UPI 0% MDR', 'GST Registered'] as $item): ?><span class="checkout-footer-chip">✓ <?= e($item) ?></span><?php endforeach; ?></div>
        </div>
        <div class="checkout-footer-row">
            <strong>Payment Options</strong>
            <div>
                <?php foreach (['UPI', 'Visa', 'Mastercard', 'RuPay', 'Netbanking', 'Wallets'] as $item): ?><span class="checkout-footer-chip"><?= e($item) ?></span><?php endforeach; ?>
                <?php if ($checkoutPartners): ?><span class="checkout-footer-muted">via <?= e(implode(' · ', $checkoutPartners)) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="checkout-footer-row checkout-footer-legal">
            <nav>
                <a href="terms.php" target="_blank"><?= __('terms') ?></a>
                <a href="privacy.php" target="_blank"><?= __('privacy_policy') ?></a>
                <a href="refund_policy.php" target="_blank">Refunds</a>
                <a href="payment_status.php" target="_blank"><?= __('track_payment') ?></a>
                <a href="contact.php" target="_blank"><?= __('contact') ?></a>
            </nav>
            <span>&copy; <?= date('Y') ?> <?= COMPANY_LEGAL_NAME ?> · GST <?= COMPANY_GST ?> · CIN <?= COMPANY_CIN ?></span>
        </div>
    </div>
</footer>
