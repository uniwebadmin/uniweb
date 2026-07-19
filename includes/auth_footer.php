<?php
/** Compact legal footer for signup / login / setup pages */
?>
<footer class="border-t border-gray-800/60 bg-dark-950/90 mt-8">
    <div class="max-w-md mx-auto px-4 py-6 text-center">
        <nav class="flex flex-wrap justify-center gap-x-4 gap-y-2 text-xs mb-3">
            <a href="terms.php" class="text-gray-400 hover:text-brand-400 transition"><?= __('terms') ?></a>
            <a href="privacy.php" class="text-gray-400 hover:text-brand-400 transition"><?= __('privacy_policy') ?></a>
            <a href="refund_policy.php" class="text-gray-400 hover:text-brand-400 transition">Refunds</a>
            <a href="faq.php" class="text-gray-400 hover:text-brand-400 transition"><?= __('faq') ?></a>
            <a href="contact.php" class="text-gray-400 hover:text-brand-400 transition"><?= __('contact') ?></a>
        </nav>
        <p class="text-[11px] text-gray-600">&copy; <?= date('Y') ?> <?= COMPANY_LEGAL_NAME ?></p>
        <p class="text-[10px] text-gray-700 mt-1">GST <?= COMPANY_GST ?> · CIN <?= COMPANY_CIN ?></p>
    </div>
</footer>
