# Public Website Training Outline

Per page: purpose, who uses, main actions.
Headings in English + Hindi.

## Home / होमपेज
- **Purpose**: Landing page with product overview, CTAs
- **Who uses**: Public visitors, prospective merchants
- **Main actions**: Navigate to features, pricing, signup, demo
- **Notes**: No wallet/escrow/loan/NBFC claims. Features match enabled capabilities.

## About / हमारे बारे में
- **Purpose**: Company information, team, mission
- **Who uses**: Public visitors
- **Main actions**: Read about company
- **Notes**: Shows company legal name, CIN, GST, address

## Pricing / मूल्य निर्धारण
- **Purpose**: Show pricing for payment methods
- **Who uses**: Prospective merchants
- **Main actions**: View indicative rates per method
- **Notes**: Only shows rates when `publicPricingApproved` is true AND `getPaymentModes()` returns enabled methods. Otherwise shows "Commercial pricing is approval-based — contact sales."

## Solutions / समाधान
- **Purpose**: Feature overview — payment links, QR, recurring, settlements
- **Who uses**: Prospective merchants
- **Main actions**: Read about features, click CTA to signup
- **Notes**: No wallet/NBFC/crypto features listed

## Demo / डेमो
- **Purpose**: Try the product with a demo merchant and ₹1 test link
- **Who uses**: Prospective merchants
- **Main actions**: Click demo link, complete ₹1 test payment, see dashboard
- **Notes**: Seeds demo merchant via `ensureDemoMerchant()`. Test mode only.

## Trust / विश्वास
- **Purpose**: Security, compliance, trust signals
- **Who uses**: Prospective merchants, partners
- **Main actions**: Read about security measures, compliance posture
- **Notes**: Shows PCI-DSS readiness, PII encryption, RBI compliance

## Roadmap / रोडमैप
- **Purpose**: Product roadmap and future plans
- **Who uses**: Public visitors
- **Main actions**: Read about planned features
- **Notes**: Does NOT mention wallet/NBFC/crypto as planned features

## Responsibility Matrix / जिम्मेदारी मैट्रिक्स
- **Purpose**: RBI PA-PG compliance responsibility matrix
- **Who uses**: Compliance officers, prospective merchants
- **Main actions**: View responsibilities of UniWeb vs partner banks/PAs
- **Notes**: Required for RBI compliance

## Signup / पंजीकरण
- **Purpose**: New merchant registration
- **Who uses**: Prospective merchants
- **Main actions**: Fill business details, verify phone/email, create account
- **Notes**: Throttled by velocity check. Redirects to merchant_setup.php after signup.

## Legal Pages / कानूनी पृष्ठ
- **Terms** (`/terms.php`): Terms & Conditions
- **Privacy** (`/privacy.php`): Privacy Policy
- **Refund Policy** (`/refund_policy.php`): Refund Policy
- **Grievance** (`/grievance.php`): Grievance Redressal Officer details
- **Merchant Agreement** (`/merchant_agreement.php`): Merchant Agreement
- **Compliance** (`/compliance.php`): Compliance overview
- **PCI-DSS** (`/pci_dss.php`): PCI-DSS Readiness statement
- **Notes**: All legal pages reachable from footer. Grievance officer name/email/phone from config.

## Support / सहायता
- **Purpose**: Contact support
- **Who uses**: Merchants, customers
- **Main actions**: Submit support ticket, view contact channels (WhatsApp/email/Telegram)
- **Notes**: Support channels configured by admin in gateway_settings.php

## Blog / ब्लॉग
- **Purpose**: Content marketing, updates
- **Who uses**: Public visitors
- **Main actions**: Read blog posts
- **Notes**: Blog content from `includes/blog_content.php`
