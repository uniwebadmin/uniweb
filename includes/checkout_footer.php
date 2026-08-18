<?php
/** Compact three-row legal footer for checkout & payment pages — methods only, no partner brands. */
?>
<footer class="checkout-footer border-t border-gray-800/80 bg-dark-950/95">
    <div class="max-w-5xl mx-auto px-3 py-3">
        <div class="checkout-footer-row">
            <strong>Protected Checkout</strong>
            <div><?php foreach (['HTTPS Transport', 'Secure Sessions', 'Signed Callbacks', 'GST Registered'] as $item): ?><span class="checkout-footer-chip">✓ <?= e($item) ?></span><?php endforeach; ?></div>
        </div>
        <div class="checkout-footer-row">
            <strong>Payment Options</strong>
            <div>
                <?php foreach (['UPI', 'Visa', 'Mastercard', 'RuPay', 'Netbanking', 'Wallets'] as $item): ?><span class="checkout-footer-chip"><?= e($item) ?></span><?php endforeach; ?>
            </div>
        </div>
        <div class="checkout-footer-row checkout-footer-legal">
            <nav>
                <a href="terms.php" target="_blank" rel="noopener"><?= __('terms') ?></a>
                <a href="privacy.php" target="_blank" rel="noopener"><?= __('privacy_policy') ?></a>
                <a href="refund_policy.php" target="_blank" rel="noopener">Refunds</a>
                <a href="payment_status.php" target="_blank" rel="noopener"><?= __('track_payment') ?></a>
                <a href="contact.php" target="_blank" rel="noopener"><?= __('contact') ?></a>
            </nav>
            <span>&copy; <?= date('Y') ?> <?= COMPANY_LEGAL_NAME ?> · GST <?= COMPANY_GST ?> · CIN <?= COMPANY_CIN ?></span>
        </div>
    </div>
</footer>
