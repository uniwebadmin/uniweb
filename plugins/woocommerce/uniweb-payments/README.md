# UniWeb Payments for WooCommerce

## Install Path

```
wp-content/plugins/uniweb-payments/
├── uniweb-payments.php   (main plugin file)
└── README.md             (this file)
```

Source location in UniWeb repo:

```
plugins/woocommerce/uniweb-payments/
```

## Installation

1. Copy the `uniweb-payments` folder into your WordPress `wp-content/plugins/` directory.
2. Log in to WordPress Admin → **Plugins** → activate **UniWeb Payments**.
3. Go to **WooCommerce → Settings → Payments** → enable **UniWeb**.
4. Configure the gateway fields:

| Field | Test Mode | Live Mode |
|-------|-----------|-----------|
| API Key | Your UniWeb test API key (from Merchant Portal → API Keys → Test) | Your UniWeb live API key |
| API Secret | Your UniWeb test API secret | Your UniWeb live API secret |
| Mode | `test` | `live` |
| API Base URL | `https://uniweb.co.in/api.php` | `https://uniweb.co.in/api.php` |

## Test Order Flow

1. Ensure your UniWeb merchant account is in **Test Mode**.
2. Generate test API keys from **Merchant Portal → API Keys** (test scope).
3. Enter the test keys in the WooCommerce gateway settings (Mode = `test`).
4. Add a product to cart on your WooCommerce store and proceed to checkout.
5. Select **Pay with UniWeb** as the payment method.
6. Click **Place Order** — you will be redirected to UniWeb hosted checkout.
7. Complete the test payment (use test UPI ID / test card details shown on the checkout page).
8. You will be redirected back to the store. The order status will update to **pending** → **processing** (on webhook confirmation from UniWeb).

## How It Works

- The plugin calls `POST /api.php` with `action=create_payment_link` and the order details.
- UniWeb returns a `checkout_url` — the customer is redirected there.
- After payment, UniWeb sends a webhook to the merchant's configured webhook URL.
- The merchant's UniWeb dashboard shows the transaction; WooCommerce order status updates via webhook (or can be manually checked).

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce 7.0+
- UniWeb merchant account with API keys generated
- For live payments: UniWeb merchant must be in **Live Mode** with an activated payment partner

## Troubleshooting

| Issue | Fix |
|-------|-----|
| "UniWeb unavailable" | Check API Base URL is reachable from your hosting |
| "UniWeb did not return a checkout URL" | Verify API Key and API Secret are correct for the selected Mode |
| Order stuck in "pending" | Ensure webhook URL is configured in UniWeb Merchant Portal → Webhooks |
| 401 / Unauthorized | API keys may be for a different mode (test vs live) — check Mode setting |

## Support

- UniWeb Support: support@uniweb.co.in
- Merchant Portal: https://uniweb.co.in/dashboard.php
