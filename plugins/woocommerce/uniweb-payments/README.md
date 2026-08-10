# UniWeb Payments for WooCommerce

## What This Is

A **private** WooCommerce plugin that lets your customers pay via UniWeb hosted checkout (UPI, cards, netbanking). You install it from a zip file — it is **not** listed on WordPress.org. Distributed by UniWeb only.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce 7.0+
- A UniWeb merchant account with API keys generated

## Installation

1. Download the `uniweb-payments.zip` file from UniWeb.
2. Log in to WordPress Admin → **Plugins** → **Add New** → **Upload Plugin**.
3. Choose the zip file → click **Install Now**.
4. Click **Activate**.

## Configuration

1. Go to **WooCommerce → Settings → Payments**.
2. Find **UniWeb** in the list → toggle it **Enable**.
3. Click **Set up** / **Manage** to open the settings page.
4. Enter the following fields:

| Field | What to enter |
|-------|---------------|
| **API Key** | Your UniWeb API Key (from Merchant Portal → API Keys) |
| **API Secret** | Your UniWeb API Secret (password field — hidden after save) |
| **Mode** | `Test` for testing, `Live` for real payments |
| **API Base URL** | `https://uniweb.co.in/api.php` (pre-filled — do not change unless told) |
| **Title** | What customers see at checkout (default: "UPI / Cards via UniWeb") |

5. Click **Save changes**.

### Where to Get Your API Keys

1. Log in to your **UniWeb Merchant Portal** at https://uniweb.co.in/dashboard.php
2. Go to **API Keys** (or **Developers** section).
3. Generate a **Test** key pair for testing.
4. Generate a **Live** key pair for real payments (requires Live Mode + KYC verified).
5. Copy the Key and Secret → paste into WooCommerce settings above.

> **Important:** Never share your API Secret with anyone. UniWeb staff will never ask for it.

## Test Order Steps

1. Set Mode to **Test** in WooCommerce UniWeb settings.
2. Make sure your UniWeb merchant account is in **Test Mode**.
3. Add a product to cart on your WooCommerce store → proceed to checkout.
4. Select **Pay with UniWeb** as the payment method.
5. Click **Place Order** — you will be redirected to UniWeb hosted checkout.
6. Complete the test payment (use the test UPI ID or test card shown on the checkout page).
7. You will be redirected back to the store. Order status updates to **pending** → **processing** once UniWeb confirms payment.

### Switching to Live

1. Change Mode to **Live** in WooCommerce settings.
2. Replace Test API Key/Secret with your **Live** key pair.
3. Ensure your UniWeb merchant account is in **Live Mode** with KYC verified and a payment partner activated.
4. Place a real test order with a small amount to confirm.

## How It Works

- Plugin calls UniWeb API (`POST /api.php`) with order details + your API Key/Secret.
- UniWeb returns a `checkout_url` — customer is redirected there.
- After payment, UniWeb confirms via webhook. Order status updates automatically.
- Refunds: supported via WooCommerce refund flow if your UniWeb account supports it.

## Troubleshooting

| Issue | Fix |
|-------|-----|
| "UniWeb unavailable" | Check your hosting can reach `https://uniweb.co.in` |
| "UniWeb did not return a checkout URL" | Verify API Key and Secret are correct for the selected Mode |
| Order stuck in "pending" | Ensure webhook URL is configured in UniWeb Merchant Portal → Webhooks |
| 401 / Unauthorized | API keys may be for wrong mode (test vs live) — check Mode setting |

## Support

- Email: support@uniweb.co.in
- Merchant Portal: https://uniweb.co.in/dashboard.php

## Notes

- This plugin is **not** on WordPress.org. It is distributed privately by UniWeb.
- The plugin does **not** store any UniWeb platform secrets — only your merchant API Key/Secret in WordPress options.
- No NBFC, wallet, or lending features are included in this plugin.
