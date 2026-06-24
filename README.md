# Nomba Payments for PrestaShop

A production-grade [Nomba](https://nomba.com) payment module for PrestaShop 8 —
hosted checkout (card + bank transfer), webhook fulfilment, server-side
verification, and refunds.

> Built for the DevCareer Nomba Hackathon — Integrations & Plugins track.

## Problem Statement

Many small and medium-sized merchants using PrestaShop struggle to offer modern, trustworthy payment experiences because integrating local and regional payment providers often requires complex custom development, fragile checkout flows, and limited support for secure server-side verification. This creates friction during checkout, increases abandoned carts, and makes it harder for merchants to accept payments confidently across card and bank transfer methods.

## Proposed Application Solution

This project addresses that gap by building a production-ready Nomba payment module for PrestaShop 8. The module will provide a seamless hosted checkout experience, support secure payment verification, webhook-based order fulfilment, and refund handling, allowing merchants to accept payments with minimal setup while keeping the checkout experience reliable and user-friendly.

## Implementation of Idea

The solution will be implemented as a PrestaShop payment module that integrates directly with the Nomba API. The module will:

- expose a payment option during checkout,
- redirect customers to Nomba’s hosted checkout,
- receive webhook notifications for successful or failed transactions,
- verify transactions server-side for security,
- support refund actions from the admin order flow.

## Tools, Technologies, and Resources

- PrestaShop 8
- PHP
- Docker and Docker Compose
- MariaDB
- Nomba API and webhook callbacks
- Smarty templates for payment UI and return pages
- GitHub for version control and collaboration

## Goals and Objectives

The team aims to deliver a functional and secure payment integration that:

- enables merchants to accept payments through Nomba from within PrestaShop,
- improves the reliability of order processing through webhook-based fulfilment,
- strengthens payment security with server-side verification,
- provides a solid foundation for future admin improvements, refund workflows, and production packaging.

## Status

Scaffold in place. Lifecycle build order:

1. [x] API client (`classes/NombaApi.php`) — auth, create order, verify, refund, HMAC verify
2. [ ] Checkout happy path — redirect → hosted checkout → webhook → fulfilment (in progress)
3. [ ] Refund + void from the admin order screen
4. [ ] Error states + webhook idempotency hardening
5. [ ] Admin config + checkout UI polish, docs, packaging (`.zip`)

## Local dev environment

Requires Docker. Brings up PrestaShop 8.1 + MariaDB with the module
live-mounted into the container.

```bash
docker compose up -d
# Storefront: http://localhost:8080
# Admin:      http://localhost:8080/admin-dev   (admin@nomba.test / nomba12345)
```

Install the module: Admin → Modules → Module Manager → search "Nomba" → Install,
then Configure and paste your **Test** keys.

## Nomba sandbox credentials

From the Nomba dashboard → **Settings → Webhooks & API Keys** (Test set):
Client ID, Client Secret, Account ID, Signature Key. Copy `.env.example` → `.env`.

- Sandbox base URL: `https://sandbox.nomba.com` (live: `https://api.nomba.com`)
- Webhooks need a public URL — use a tunnel (`cloudflared tunnel --url http://localhost:8080`)
  and register `<tunnel>/module/nomba/webhook` in the dashboard.

### Sandbox test cards

| Scenario           | Card                | OTP  |
| ------------------ | ------------------- | ---- |
| Success            | 5434 6210 7425 2808 | 9999 |
| 3DS challenge      | 4000 0000 0000 2503 | —    |
| Declined           | 5484 4972 1831 7651 | —    |
| OTP timeout        | success card        | 1234 |
| Invalid OTP        | success card        | 5464 |
| Insufficient funds | amount > 500,000    | —    |
| Expired card       | expiry 12/20        | —    |

## API endpoints used

| Purpose               | Method | Path                                  |
| --------------------- | ------ | ------------------------------------- |
| Auth token            | POST   | `/v1/auth/token/issue`                |
| Create checkout order | POST   | `/v1/checkout/order`                  |
| Verify transaction    | GET    | `/v1/checkout/transaction`            |
| Refund                | POST   | `/v1/checkout/{transactionId}/refund` |

## Step-by-step: Taking it live

### 1. Prerequisites

- **Docker** installed (v24+) with the `docker` command available
- **Nomba merchant account** with API keys from the [Nomba Dashboard](https://dashboard.nomba.com)
  → Settings → Webhooks & API Keys (use the **Test** set during development)
- A **public tunnel** tool: [`cloudflared`](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/) or [`ngrok`](https://ngrok.com/download)
  (needed so Nomba's servers can reach your webhook endpoint)

### 2. Set up credentials

```bash
cd nomba-prestashop
cp .env.example .env    # optional — just for reference
```

Fill in your four Nomba sandbox credentials:
- `NOMBA_CLIENT_ID` — from the dashboard
- `NOMBA_CLIENT_SECRET` — from the dashboard
- `NOMBA_ACCOUNT_ID` — from the dashboard
- `NOMBA_SIGNATURE_KEY` — from the dashboard

These will be pasted into the PrestaShop admin panel later.

### 3. Start the Docker stack

```bash
docker compose up -d
```

Wait ~60 seconds for PrestaShop to auto-install. Confirm it's up:
- **Storefront**: http://localhost:8080
- **Admin**: http://localhost:8080/admin-dev

**Default credentials**: `admin@nomba.test` / `nomba12345`

### 4. Install the module

1. Log into the PrestaShop admin
2. Go to **Modules** → **Module Manager**
3. Search for "Nomba"
4. Click **Install**

The module creates the `ps_nomba_transaction` table in the database automatically.

### 5. Configure the module

1. In the admin, go to **Modules** → **Module Manager** → find "Nomba Payments" → **Configure**
2. Set **Test (sandbox) mode** to **Yes** (default)
3. Paste your four sandbox credentials into the fields
4. Click **Save**
5. Click **Test Nomba Connection** — you should see a green confirmation that the API token was obtained

If the connection test fails, double-check your credentials and that the sandbox base URL is accessible from your Docker container.

### 6. Set up a webhook tunnel

Nomba's servers need to send webhooks to your local PrestaShop. Use a tunnel:

```bash
# Option A: cloudflared
cloudflared tunnel --url http://localhost:8080
# Outputs something like: https://random-name.trycloudflare.com

# Option B: ngrok
ngrok http 8080
# Outputs something like: https://random-name.ngrok.io
```

Now register your webhook in the **Nomba Dashboard**:
1. Go to **Settings** → **Webhooks & API Keys**
2. Add a webhook URL: `<your-tunnel-url>/module/nomba/webhook`
3. Subscribe to the `payment_success` event (and optionally `payment_failed`)
4. Set the **Signature Key** (the same one you stored in the module config)
5. Save

### 7. Test a sandbox payment

1. Open the storefront (http://localhost:8080)
2. Create a customer account or log in
3. Add a product to the cart and proceed to checkout
4. Select **Pay with Nomba (card or bank transfer)** and place the order
5. You'll be redirected to Nomba's hosted checkout page
6. Use the test card **`5434 6210 7425 2808`** with OTP **`9999`**
7. Complete the payment

**What should happen:**
1. Nomba processes the payment
2. Nomba sends a `payment_success` webhook to your tunnel URL
3. The webhook controller (`NombaWebhookModuleFrontController`):
   - Verifies the HMAC signature using the 9-field constructed string
   - Finds the `nomba_transaction` row by order reference
   - Calls `validateOrder()` to create the PrestaShop order
   - Updates the row with `status=SUCCESS`, `transaction_id`, `id_order`
4. The customer is redirected back to your store
5. The return controller (`NombaReturnModuleFrontController`):
   - Finds the now-fulfilled transaction
   - Redirects to the `order-confirmation` page

### 8. Verify the order

- In the admin panel, go to **Orders** and confirm the order exists with status "Payment accepted"
- Check the **Nomba Payment** panel at the bottom of the order detail page — it shows:
  - Transaction ID, order reference, amount, refunded amount
  - A **Refund via Nomba** button

### 9. Test a refund

1. Open any successfully paid order in the admin
2. Scroll to the **Nomba Payment** panel
3. Enter a partial refund amount (or leave blank for full refund)
4. Click **Refund via Nomba**
5. The `refunded_amount` updates in the database
6. The refunded amount is reflected in your Nomba dashboard

### 10. Test error scenarios

Use the test cards from the table below to verify graceful handling of:
- **Declined card** (`5484 4972 1831 7651`) — shows the return template with a warning
- **Insufficient funds** (amount > 500,000) — same, no order is created
- **OTP timeout** (success card + OTP `1234`) — transaction stays PENDING
- **Expired card** (expiry `12/20`) — Nomba rejects at the checkout page

### 11. Going to production

1. Switch to **production credentials** from the Nomba dashboard
2. Set **Test mode** to **No**
3. Update the webhook URL in the Nomba dashboard to your production endpoint
4. Create a distributable package:
   ```bash
   cd modules/nomba && bash build.sh
   ```
   Produces `nomba-prestashop-1.0.0.zip`
5. Install the `.zip` on the live PrestaShop via **Modules** → **Module Manager** → **Upload a module**

### 12. API verification notes (confirmed against docs)

The module aligns with Nomba's published OpenAPI spec at `developer.nomba.com`:

| Aspect | Status | Source |
|--------|--------|--------|
| Auth token `POST /v1/auth/token/issue` | ✅ Correct | OpenAPI + PHP sample |
| Create order `POST /v1/checkout/order` | ✅ Correct | OpenAPI spec |
| Verify `GET /v1/transactions/accounts/single?orderReference=` | ✅ Correct | Verify guide |
| Refund `POST /v1/checkout/refund` (transactionId in body) | ✅ Correct | OpenAPI spec |
| Webhook signature: 9-field colon-joined HMAC-SHA256, Base64 | ✅ Correct | PHP sample in webhook docs |
| Header `nomba-signature` (lowercase) | ✅ Correct | Webhook docs |
| `nomba-timestamp` header included in signature | ✅ Correct | Go/PHP/JS examples |

> **Note**: The exact webhook payload field paths for checkout orders (e.g. whether `data.order.orderReference` exists vs `data.orderReference`) should be confirmed with a live sandbox test. The code uses fallback chains for resilience.
