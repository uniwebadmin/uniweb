<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Platform Video Tour — 12 Guides';
$pageDescription = '12 guided video walkthroughs covering the public website, customer portal and merchant portal. Record and upload MP4 files to assets/videos/tours/ to activate each video.';
$bodyClass = 'bg-dark-950';
require_once __DIR__ . '/header.php';

$tours = [
    [
        'id' => 'website_home',
        'audience' => 'Website',
        'duration' => '1:30',
        'title_en' => 'Public Website & Features',
        'title_hi' => 'वेबसाइट और फीचर्स',
        'desc_en' => 'Walk through the UniWeb homepage, trust signals, solutions, pricing and the call-to-action flow.',
        'desc_hi' => 'UniWeb होमपेज, ट्रस्ट सिग्नल, सॉल्यूशन्स, प्राइसिंग और कॉल-टू-एक्शन फ्लो दिखाएं।',
        'file' => 'tour_01.mp4',
        'script_en' => "Welcome to UniWeb. This is the public website where any Indian business owner can learn about our payment platform. Here you see the hero section, supported payment methods, compliance trust badges, pricing, and the free merchant signup button. The entire site is built to look premium on every device and in light or dark mode.",
        'script_hi' => "UniWeb में आपका स्वागत है। यह पब्लिक वेबसाइट है जहाँ कोई भी भारतीय बिजनेस ओनर हमारे पेमेंट प्लेटफॉर्म के बारे में जान सकता है। यहाँ हीरो सेक्शन, सपोर्टेड पेमेंट मेथड्स, कंप्लायंस ट्रस्ट बैज, प्राइसिंग और फ्री मर्चेंट साइनअप बटन देखें। पूरी साइट हर डिवाइस और लाइट/डार्क मोड में प्रीमियम दिखती है।",
    ],
    [
        'id' => 'website_solutions',
        'audience' => 'Website',
        'duration' => '1:45',
        'title_en' => 'Solutions & Use-Cases',
        'title_hi' => 'सॉल्यूशन्स और उपयोग',
        'desc_en' => 'Show how retail, services, field teams and online stores use UniWeb links, QR and settlements.',
        'desc_hi' => 'रिटेल, सर्विस, फील्ड टीम और ऑनलाइन स्टोर कैसे UniWeb लिंक, QR और सेटलमेंट इस्तेमाल करते हैं, दिखाएं।',
        'file' => 'tour_02.mp4',
        'script_en' => "UniWeb fits every business shape. Retail stores use counter QR. Service providers send payment links by WhatsApp and email. Field agents carry instant-print QR. Online sellers embed checkout on their website. In this video we show each solution card and the one-click setup flow.",
        'script_hi' => "UniWeb हर बिजनेस टाइप के लिए फिट बैठता है। रिटेल स्टोर काउंटर QR इस्तेमाल करते हैं। सर्विस प्रोवाइडर WhatsApp और ईमेल से पेमेंट लिंक भेजते हैं। फील्ड एजेंट इंस्टेंट-प्रिंट QR ले जाते हैं। ऑनलाइन सेलर्स अपनी वेबसाइट पर चेकआउट एम्बेड करते हैं। इस वीडियो में हर सॉल्यूशन कार्ड और वन-क्लिक सेटअप फ्लो दिखाया गया है।",
    ],
    [
        'id' => 'website_pricing',
        'audience' => 'Website',
        'duration' => '1:15',
        'title_en' => 'Pricing, Status & Trust',
        'title_hi' => 'प्राइसिंग, स्टेटस और ट्रस्ट',
        'desc_en' => 'Cover pricing, system status, compliance, roadmap and support links from the public footer.',
        'desc_hi' => 'पब्लिक फुटर से प्राइसिंग, सिस्टम स्टेटस, कंप्लायंस, रोडमैप और सपोर्ट लिंक दिखाएं।',
        'file' => 'tour_03.mp4',
        'script_en' => "Pricing is transparent and there is no hidden setup fee. The status page shows live health of all partner gateways. The trust center explains RBI compliance, data security, refund and grievance policy. We also show the API docs and contact form for sales questions.",
        'script_hi' => "प्राइसिंग पारदर्शी है और कोई छिपा हुआ सेटअप शुल्क नहीं है। स्टेटस पेज पर सभी पार्टनर गेटवे की लाइव हेल्थ दिखती है। ट्रस्ट सेंटर में RBI कंप्लायंस, डेटा सिक्योरिटी, रिफंड और शिकायत नीति समझाई गई है। API डॉक्स और सेल्स के सवालों के लिए कॉन्टैक्ट फॉर्म भी दिखाएं।",
    ],
    [
        'id' => 'customer_checkout',
        'audience' => 'Customer',
        'duration' => '2:00',
        'title_en' => 'Customer Checkout Experience',
        'title_hi' => 'ग्राहक चेकआउट अनुभव',
        'desc_en' => 'Record the full payer journey: open link, choose UPI/cards/netbanking/wallets and complete the test payment.',
        'desc_hi' => 'पूरी पेयर जर्नी रिकॉर्ड करें: लिंक खोलना, UPI/कार्ड/नेटबैंकिंग/वॉलेट चुनना और टेस्ट पेमेंट पूरा करना।',
        'file' => 'tour_04.mp4',
        'script_en' => "This is the customer experience. A buyer receives a payment link by WhatsApp, SMS or email. They open the secure checkout page, see the merchant branding, enter their name and mobile, and choose UPI, card, netbanking or wallet. In Test Mode they can pay one rupee safely. The page works fast even on low-end phones.",
        'script_hi' => "यह ग्राहक अनुभव है। खरीदार को WhatsApp, SMS या ईमेल से पेमेंट लिंक मिलता है। वह सिक्योर चेकआउट पेज खोलता है, मर्चेंट ब्रांडिंग देखता है, नाम और मोबाइल डालता है, और UPI, कार्ड, नेटबैंकिंग या वॉलेट चुनता है। टेस्ट मोड में सुरक्षित रूप से एक रुपया दे सकता है। पेज कमज़ोर फोन पर भी तेज़ चलता है।",
    ],
    [
        'id' => 'customer_track',
        'audience' => 'Customer',
        'duration' => '1:20',
        'title_en' => 'Track Payment & Receipt',
        'title_hi' => 'पेमेंट ट्रैक करें और रसीद',
        'desc_en' => 'Show how a customer tracks any payment with the transaction ID and downloads the receipt.',
        'desc_hi' => 'ग्राहक ट्रांजैक्शन आईडी से किसी भी पेमेंट को कैसे ट्रैक करता है और रसीद डाउनलोड करता है, दिखाएं।',
        'file' => 'tour_05.mp4',
        'script_en' => "After payment, the customer sees a clear success page. They can save the receipt, copy the transaction reference, or share it. If they lost the link, they can visit Track Payment, enter their reference or mobile, and see the live status. Refund and complaint buttons are also available if something goes wrong.",
        'script_hi' => "पेमेंट के बाद ग्राहक को साफ सक्सेस पेज दिखता है। वह रसीद सेव कर सकता है, ट्रांजैक्शन रेफरेंस कॉपी कर सकता है या शेयर कर सकता है। अगर लिंक खो गया हो तो ट्रैक पेमेंट पर जाकर रेफरेंस या मोबाइल डालकर लाइव स्टेटस देख सकता है। अगर कोई गड़बड़ हो तो रिफंड और शिकायत बटन भी मौजूद हैं।",
    ],
    [
        'id' => 'customer_support',
        'audience' => 'Customer',
        'duration' => '1:10',
        'title_en' => 'Customer Support & Complaints',
        'title_hi' => 'ग्राहक सपोर्ट और शिकायत',
        'desc_en' => 'Demonstrate the customer complaint form, ticket creation and status tracking.',
        'desc_hi' => 'ग्राहक शिकायत फॉर्म, टिकट बनाना और स्टेटस ट्रैकिंग दिखाएं।',
        'file' => 'tour_06.mp4',
        'script_en' => "Customers can raise a support ticket without logging in. They enter their transaction reference, choose a category, and describe the issue. The merchant and admin get notified instantly. The customer can come back later and track the ticket status using the same mobile number.",
        'script_hi' => "ग्राहक लॉगिन किए बिना सपोर्ट टिकट उठा सकते हैं। वह अपना ट्रांजैक्शन रेफरेंस डालता है, कैटेगरी चुनता है और समस्या बताता है। मर्चेंट और एडमिन को तुरंत नोटिफिकेशन मिल जाता है। ग्राहक बाद में उसी मोबाइल नंबर से टिकट स्टेटस ट्रैक कर सकता है।",
    ],
    [
        'id' => 'merchant_signup',
        'audience' => 'Merchant',
        'duration' => '2:15',
        'title_en' => 'Merchant Signup & KYC',
        'title_hi' => 'मर्चेंट साइनअप और KYC',
        'desc_en' => 'From the signup form to email/phone OTP, business details and document upload.',
        'desc_hi' => 'साइनअप फॉर्म से ईमेल/फोन OTP, बिजनेस डिटेल और डॉक्युमेंट अपलोड तक दिखाएं।',
        'file' => 'tour_07.mp4',
        'script_en' => "A merchant starts by entering email, phone and password. They verify the phone and email with OTP. Then they choose business entity type, enter PAN, GST, bank account, and upload KYC documents. The system validates the form live. After submission the admin reviews the application and the merchant can start in Test Mode right away.",
        'script_hi' => "मर्चेंट ईमेल, फोन और पासवर्ड डालकर शुरुआत करता है। वह OTP से फोन और ईमेल वेरीफाई करता है। फिर बिजनेस एंटिटी टाइप चुनता है, PAN, GST, बैंक अकाउंट डालता है और KYC डॉक्युमेंट अपलोड करता है। सिस्टम फॉर्म को लाइव वैलिडेट करता है। सबमिशन के बाद एडमिन आवेदन रिव्यू करता है और मर्चेंट तुरंत टेस्ट मोड शुरू कर सकता है।",
    ],
    [
        'id' => 'merchant_dashboard',
        'audience' => 'Merchant',
        'duration' => '2:00',
        'title_en' => 'Merchant Dashboard Overview',
        'title_hi' => 'मर्चेंट डैशबोर्ड ओवरव्यू',
        'desc_en' => 'Show stats, wallet, test/live mode toggle, recent transactions and the onboarding checklist.',
        'desc_hi' => 'स्टैट्स, वॉलेट, टेस्ट/लाइव मोड टॉगल, हालिया ट्रांजैक्शन और ऑनबोर्डिंग चेकलिस्ट दिखाएं।',
        'file' => 'tour_08.mp4',
        'script_en' => "After login, the merchant sees the dashboard. The top cards show today's collections, wallet balance, pending settlement and total transactions. The test/live toggle lets them operate safely before approval. Below is the onboarding checklist, recent payments, and shortcuts to payment links, QR code and wallet.",
        'script_hi' => "लॉगिन के बाद मर्चेंट डैशबोर्ड देखता है। टॉप कार्ड में आज की कलेक्शन, वॉलेट बैलेंस, पेंडिंग सेटलमेंट और कुल ट्रांजैक्शन दिखते हैं। टेस्ट/लाइव टॉगल से अप्रूवल से पहले सुरक्षित रूप से काम किया जा सकता है। नीचे ऑनबोर्डिंग चेकलिस्ट, हालिया पेमेंट और पेमेंट लिंक, QR कोड और वॉलेट के शॉर्टकट हैं।",
    ],
    [
        'id' => 'merchant_links',
        'audience' => 'Merchant',
        'duration' => '2:10',
        'title_en' => 'Payment Links, QR & Website',
        'title_hi' => 'पेमेंट लिंक, QR और वेबसाइट',
        'desc_en' => 'Create payment links, bulk QR codes, a branded mini-website and share on WhatsApp.',
        'desc_hi' => 'पेमेंट लिंक, बल्क QR कोड, ब्रांडेड मिनी-वेबसाइट बनाना और WhatsApp पर शेयर करना दिखाएं।',
        'file' => 'tour_09.mp4',
        'script_en' => "Merchants can create a fixed or dynamic payment link in seconds. They can also bulk-generate QR codes for products, staff or counters. The mini-website builder lets them add logo, banner, products and contact text. Everything is shareable via WhatsApp, email and a short public URL.",
        'script_hi' => "मर्चेंट कुछ ही सेकंड में फिक्स्ड या डायनामिक पेमेंट लिंक बना सकता है। वह प्रोडक्ट, स्टाफ या काउंटर के लिए बल्क QR कोड भी जनरेट कर सकता है। मिनी-वेबसाइट बिल्डर में लोगो, बैनर, प्रोडक्ट और कॉन्टैक्ट टेक्स्ट जोड़ सकता है। हर चीज़ WhatsApp, ईमेल और शॉर्ट पब्लिक URL से शेयर करने योग्य है।",
    ],
    [
        'id' => 'merchant_wallet',
        'audience' => 'Merchant',
        'duration' => '1:50',
        'title_en' => 'Wallet, Settlements & Payouts',
        'title_hi' => 'वॉलेट, सेटलमेंट और पेआउट',
        'desc_en' => 'Explain wallet balance, settlement batches and the payout request flow.',
        'desc_hi' => 'वॉलेट बैलेंस, सेटलमेंट बैच और पेआउट रिक्वेस्ट फ्लो समझाएं।',
        'file' => 'tour_10.mp4',
        'script_en' => "The wallet shows available balance, reserved amount and total settled. Settlement batches group eligible transactions and show partner status. Merchants can request a payout to their linked bank account. Live money movement only happens after partner gateway activation and KYC approval.",
        'script_hi' => "वॉलेट में उपलब्ध बैलेंस, रिज़र्व अमाउंट और कुल सेटल्ड दिखता है। सेटलमेंट बैचेज़ योग्य ट्रांजैक्शन को ग्रुप करते हैं और पार्टनर स्टेटस दिखाते हैं। मर्चेंट अपने लिंक्ड बैंक अकाउंट में पेआउट रिक्वेस्ट कर सकता है। असली पैसा तभी ट्रांसफर होगा जब पार्टनर गेटवे एक्टिव और KYC अप्रूव्ड हो।",
    ],
    [
        'id' => 'merchant_reports',
        'audience' => 'Merchant',
        'duration' => '1:45',
        'title_en' => 'Transactions, Reports & Support',
        'title_hi' => 'ट्रांजैक्शन, रिपोर्ट और सपोर्ट',
        'desc_en' => 'Filter transactions, export CSV/PDF, raise support tickets and manage refunds.',
        'desc_hi' => 'ट्रांजैक्शन फिल्टर करना, CSV/PDF एक्सपोर्ट, सपोर्ट टिकट उठाना और रिफंड मैनेज करना दिखाएं।',
        'file' => 'tour_11.mp4',
        'script_en' => "The transactions page shows every payment with status, method and UTR. Merchants can filter by date, status or mode, and export CSV or PDF for their accountant. From here they can also raise a support ticket, request a refund, or view the detailed timeline of any payment.",
        'script_hi' => "ट्रांजैक्शन पेज पर हर पेमेंट स्टेटस, मेथड और UTR के साथ दिखता है। मर्चेंट डेट, स्टेटस या मोड से फिल्टर कर सकता है और अपने अकाउंटेंट के लिए CSV या PDF एक्सपोर्ट कर सकता है। यहीं से वह सपोर्ट टिकट उठा सकता है, रिफंड रिक्वेस्ट कर सकता है, या किसी भी पेमेंट का डिटेल टाइमलाइन देख सकता है।",
    ],
    [
        'id' => 'merchant_settings',
        'audience' => 'Merchant',
        'duration' => '2:05',
        'title_en' => 'Settings, API & Integrations',
        'title_hi' => 'सेटिंग्स, API और इंटीग्रेशन',
        'desc_en' => 'Cover profile, team members, notification settings, 2FA and API keys.',
        'desc_hi' => 'प्रोफाइल, टीम मेंबर्स, नोटिफिकेशन सेटिंग्स, 2FA और API कीज़ दिखाएं।',
        'file' => 'tour_12.mp4',
        'script_en' => "In settings, the merchant updates their profile, logo and bank accounts. They can add team members with restricted permissions, set notification preferences, enable two-factor authentication for security, and generate API keys for server-to-server integration. Webhook URLs and IP whitelists are also managed here.",
        'script_hi' => "सेटिंग्स में मर्चेंट अपनी प्रोफाइल, लोगो और बैंक अकाउंट अपडेट करता है। वह रिस्ट्रिक्टेड परमिशन के साथ टीम मेंबर्स जोड़ सकता है, नोटिफिकेशन प्रिफरेंस सेट कर सकता है, सिक्योरिटी के लिए टू-फैक्टर ऑथेंटिकेशन चालू कर सकता है, और सर्वर-टू-सर्वर इंटीग्रेशन के लिए API कीज़ जनरेट कर सकता है। वेबहुक URL और IP व्हाइटलिस्ट भी यहीं मैनेज होते हैं।",
    ],
];

$audiences = array_unique(array_column($tours, 'audience'));
$videoDir = __DIR__ . '/assets/videos/tours/';
?>

<section class="pt-24 pb-12 px-4 w-full max-w-6xl mx-auto min-w-0" id="tour-app">
    <div class="text-center mb-8">
        <p class="text-sky-400 text-xs font-bold uppercase tracking-widest mb-2">Platform Video Tour</p>
        <h1 class="text-2xl sm:text-4xl font-extrabold">12 Guided Video Walkthroughs</h1>
        <p class="text-gray-500 text-sm mt-2 max-w-2xl mx-auto">
            One page for public website, customer portal and merchant portal. Record and upload the MP4 files to <code class="text-brand-400">assets/videos/tours/</code> to activate each video.
        </p>
    </div>

    <div class="flex flex-wrap items-center justify-center gap-2 mb-8">
        <button type="button" data-filter="all" class="tour-filter px-4 py-2 rounded-full text-sm font-medium bg-brand-600 text-white">All</button>
        <?php foreach ($audiences as $aud): ?>
        <button type="button" data-filter="<?= e($aud) ?>" class="tour-filter px-4 py-2 rounded-full text-sm font-medium glass text-gray-300 hover:text-white"><?= e($aud) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($tours as $i => $t): $hasVideo = is_file($videoDir . $t['file']); ?>
        <article class="tour-card glass rounded-xl overflow-hidden border border-gray-800" data-audience="<?= e($t['audience']) ?>">
            <div class="relative aspect-video bg-dark-900">
                <?php if ($hasVideo): ?>
                <video controls class="w-full h-full object-cover" poster="assets/img/icons/video-poster.svg" preload="metadata" aria-label="<?= e($t['title_en']) ?>">
                    <source src="assets/videos/tours/<?= e($t['file']) ?>" type="video/mp4">
                    <p class="text-sm text-gray-400 p-4">Your browser does not support the video tag.</p>
                </video>
                <?php else: ?>
                <div class="w-full h-full flex flex-col items-center justify-center p-5 text-center">
                    <div class="w-12 h-12 rounded-full bg-brand-600/20 text-brand-400 flex items-center justify-center text-2xl mb-3">▶</div>
                    <p class="text-xs text-gray-500 uppercase tracking-wider mb-1">Video not uploaded</p>
                    <p class="text-sm text-gray-400">Record <span class="font-mono text-xs text-brand-400"><?= e($t['file']) ?></span></p>
                </div>
                <?php endif; ?>
                <span class="absolute top-3 left-3 text-[10px] uppercase font-bold tracking-wider px-2 py-1 rounded bg-dark-900/80 border border-gray-700 text-gray-300"><?= e($t['audience']) ?></span>
                <span class="absolute top-3 right-3 text-[10px] font-mono px-2 py-1 rounded bg-dark-900/80 border border-gray-700 text-gray-400"><?= e($t['duration']) ?></span>
            </div>
            <div class="p-4 sm:p-5">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h2 class="tour-title-en text-lg font-bold text-white leading-snug"><?= e($t['title_en']) ?></h2>
                    <h2 class="tour-title-hi hidden text-lg font-bold text-white leading-snug"><?= e($t['title_hi']) ?></h2>
                    <div class="flex gap-1 shrink-0">
                        <button type="button" data-lang="en" class="tour-lang-btn px-2 py-1 text-[10px] rounded bg-brand-600 text-white font-medium">EN</button>
                        <button type="button" data-lang="hi" class="tour-lang-btn px-2 py-1 text-[10px] rounded glass text-gray-400 font-medium">HI</button>
                    </div>
                </div>
                <p class="tour-desc-en text-sm text-gray-400 mb-4 leading-relaxed"><?= e($t['desc_en']) ?></p>
                <p class="tour-desc-hi hidden text-sm text-gray-400 mb-4 leading-relaxed"><?= e($t['desc_hi']) ?></p>

                <div class="rounded-lg bg-dark-900/60 border border-gray-800 p-3">
                    <p class="text-[11px] uppercase tracking-wider text-gray-500 font-semibold mb-2">Voiceover script</p>
                    <p class="tour-script-en text-sm text-gray-300 leading-relaxed text-justify"><?= nl2br(e($t['script_en'])) ?></p>
                    <p class="tour-script-hi hidden text-sm text-gray-300 leading-relaxed text-justify"><?= nl2br(e($t['script_hi'])) ?></p>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<script>
(function(){
    const app = document.getElementById('tour-app');
    const filterBtns = app.querySelectorAll('[data-filter]');
    const cards = app.querySelectorAll('.tour-card');

    filterBtns.forEach(btn => btn.addEventListener('click', function(){
        const f = this.dataset.filter;
        filterBtns.forEach(b => {
            b.classList.toggle('bg-brand-600', b.dataset.filter === f);
            b.classList.toggle('text-white', b.dataset.filter === f);
            b.classList.toggle('glass', b.dataset.filter !== f);
            b.classList.toggle('text-gray-300', b.dataset.filter !== f);
        });
        cards.forEach(c => {
            c.style.display = (f === 'all' || c.dataset.audience === f) ? '' : 'none';
        });
    }));

    cards.forEach(card => {
        const enTitle = card.querySelector('.tour-title-en');
        const hiTitle = card.querySelector('.tour-title-hi');
        const enDesc = card.querySelector('.tour-desc-en');
        const hiDesc = card.querySelector('.tour-desc-hi');
        const enScript = card.querySelector('.tour-script-en');
        const hiScript = card.querySelector('.tour-script-hi');
        const btns = card.querySelectorAll('.tour-lang-btn');

        function setLang(lang){
            enTitle.classList.toggle('hidden', lang !== 'en');
            hiTitle.classList.toggle('hidden', lang !== 'hi');
            enDesc.classList.toggle('hidden', lang !== 'en');
            hiDesc.classList.toggle('hidden', lang !== 'hi');
            enScript.classList.toggle('hidden', lang !== 'en');
            hiScript.classList.toggle('hidden', lang !== 'hi');
            btns.forEach(b => {
                const isActive = b.dataset.lang === lang;
                b.classList.toggle('bg-brand-600', isActive);
                b.classList.toggle('text-white', isActive);
                b.classList.toggle('glass', !isActive);
                b.classList.toggle('text-gray-400', !isActive);
            });
        }

        btns.forEach(b => b.addEventListener('click', () => setLang(b.dataset.lang)));
    });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
