# UniWeb Shopify Checkout App (scaffold)

This folder is the partner-ready scaffold for a Shopify custom app that creates UniWeb payment links and redirects the buyer to hosted checkout.

## Required before Live

1. Shopify Partner app credentials
2. UniWeb Live API key + secret for the merchant
3. HTTPS webhook destination on Shopify app backend
4. Written partner approval for Live collections

## Flow

1. Shopify checkout / draft order creates UniWeb payment link via `api.php` (`create_payment_link`) with `Idempotency-Key`
2. Buyer pays on UniWeb checkout
3. UniWeb merchant webhook (`payment.captured`) updates Shopify order / fulfillment hold
4. Refunds use UniWeb `create_refund` and wait for provider confirmation

## Env

```
UNIWEB_API_BASE=https://uniweb.co.in/api.php
UNIWEB_API_KEY=
UNIWEB_API_SECRET=
SHOPIFY_API_KEY=
SHOPIFY_API_SECRET=
```

Recurring / AutoPay and split settlements remain disabled until the corresponding UniWeb partner product is approved.
