# PROGRESS — Nomba Payments for PrestaShop

> **Read this first.** This is the single source of truth for where development
> stands and what to do next. It is written to be picked up by anyone — a human
> or another AI model — with no prior context. Update it at the end of every
> work session: move items between sections, check boxes, and append to the log.

---

## 1. What we are building & why

- **Goal:** a production-grade Nomba payment module for **PrestaShop 8**.
- **Event:** DevCareer **Nomba Hackathon**, "Integrations & Plugins" track
  (https://devcareer.io/programs/nomba-hackathon). Registered ~2026-06-24.
  Working budget ~2–3 weeks.
- **Why PrestaShop:** Nomba already ships WooCommerce + Shopify integrations, so
  those are off-limits. PrestaShop is an open, commercially-relevant gap and its
  payment-module contract maps cleanly onto the judging rubric.
- **Judging rubric (optimize for this):** integration completeness — *install,
  configure, transact, refund, webhook handling, error states* — plus code
  quality, packaging, and documentation. The full payment **lifecycle** is the
  thing being scored, so every lifecycle stage must demonstrably work.

## 2. Guiding principles (carry these forward)

- **Logic before UI.** Prove the end-to-end contract (checkout → webhook →
  fulfilment → refund) against the real sandbox *before* polishing any UI.
- **Real integrations, no stubs.** Every call hits Nomba's real sandbox.
- **No secrets in git.** `.env` is gitignored; only `.env.example` is committed.
- **Quality bar is high.** "Good enough" is not the target; clean, idiomatic
  PrestaShop code and awwwards-level final polish.

## 3. Key facts (so you don't have to re-derive them)

- **Nomba base URLs:** sandbox `https://sandbox.nomba.com`, live `https://api.nomba.com`.
  The module toggles between them via the "Test mode" setting.
- **Auth:** `POST /v1/auth/token/issue` with `grant_type=client_credentials`,
  `client_id`, `client_secret`, and an `accountId` request header → bearer token.
- **Endpoints used:** create order `POST /v1/checkout/order`; verify
  `GET /v1/checkout/transaction`; refund `POST /v1/checkout/{transactionId}/refund`.
- **Webhook:** Nomba POSTs `payment_success` to a dashboard-configured URL,
  HMAC-signed with the Signature Key. Webhook is the **authoritative** fulfilment
  path; the customer return URL only does a best-effort verify.
- **Credentials (4):** Client ID, Client Secret, Account ID, Signature Key —
  all from Nomba dashboard → Settings → Webhooks & API Keys (use the **Test** set).
- **Sandbox test cards / outcomes:** see the table in `README.md`.

## 4. Current state

**Sandbox-tested: checkout → payment → order fulfilment → order confirmation page all working.**
**Webhooks + refunds still need sandbox verification (blocked on public tunnel).**

Files and their purpose:

| File | Purpose | State |
|---|---|---|
| `docker-compose.yml` | PrestaShop 8.1 + MariaDB, module live-mounted | tested |
| `.env.example` | credential template | done |
| `modules/nomba/nomba.php` | PaymentModule: install/uninstall, config form, hooks (header, paymentOptions, paymentReturn, admin refund) | complete, sandbox-tested |
| `modules/nomba/classes/NombaApi.php` | API client: token (persistent cache), createOrder, verify, refund, HMAC verify | complete |
| `modules/nomba/controllers/front/redirect.php` | cart → createOrder → redirect to checkoutLink (DB insert wrapped in try-catch) | complete, sandbox-tested |
| `modules/nomba/controllers/front/webhook.php` | HMAC verify → validateOrder (idempotent); handles payment_success + payment_failed | complete, untested (needs tunnel) |
| `modules/nomba/controllers/front/return.php` | customer return → verify → validateOrder (fallback) → order-confirmation redirect | complete, sandbox-tested |
| `modules/nomba/sql/install.php` / `uninstall.php` | `nomba_transaction` table with refunded_amount tracking | complete |
| `modules/nomba/views/templates/front/*.tpl` | return + error templates with navigation links | polished, all cases handled (SUCCESS/PENDING/ORDER_FAILED/FAILED) |
| `modules/nomba/views/templates/hook/refund.tpl` | admin refund panel with full/partial refund form | complete, untested |
| `modules/nomba/views/css/nomba.css` | front-end styles | complete |
| `modules/nomba/build.sh` | packaging script → `.zip` | complete |

All PHP passes `php -l`.

## 5. Build roadmap

- [x] **0. Scaffold** — structure, docker env, config, docs.
- [x] **1. API client** — `NombaApi.php` with persistent token caching (Configuration).
- [x] **2. Checkout happy path** — redirect → hosted checkout → return controller → validateOrder → order confirmation. Sandbox-tested and working.
- [x] **3. Refund + void** — admin order screen (`hookDisplayAdminOrderMainBottom`)
      with refund form, full/partial refund via `NombaApi::refund()`, `refunded_amount` tracking.
      Code complete, untested against sandbox (needs transactionId from webhook).
- [x] **4. Error states + hardening** — Payment return template handles SUCCESS, PENDING, ORDER_FAILED, FAILED. All `validateOrder()` calls wrapped in try-catch. `cart->orderExists()` guard prevents double-fulfilment. `hookPaymentReturn` now assigns proper template variables (fixes "We could not confirm" false warning).
- [x] **5. Polish + package** — CSS via `hookHeader()`, admin config with "Test Connection",
      polished return/error templates with navigation links, admin refund panel, `build.sh` script
      for `.zip` packaging.

## 6. Open items (to complete before submission)

- [ ] **Webhook tunnel.** Set up ngrok/cloudflared: `cloudflared tunnel --url http://localhost:8080`
      then register `<tunnel>/module/nomba/webhook` in Nomba dashboard → Settings → Webhooks & API Keys.
      Subscribe to `payment_success` + `payment_failed` events. Paste the Signature Key into module config.
- [ ] **Verify webhook payload.** Once tunnel is up, make a sandbox payment and confirm:
  - `extractOrderReference()` finds the merchant reference at the correct field path
  - Webhook event type strings match `payment_success` / `payment_failed`
  - HMAC signature verification succeeds
  - The `transactionId` from the webhook is saved (enables refunds)
- [ ] **Test refund flow.** From admin order screen, enter a refund amount and submit.
      Confirm the Nomba API accepts the call and `refunded_amount` updates in the DB.
- [ ] **Test error states:**
  - Declined card (`5484 4972 1831 7651`) → show error template
  - OTP timeout (success card + OTP `1234`) → PENDING status shown
  - Insufficient funds (amount > 500,000) → graceful decline
- [ ] **Verify transaction ID capture.** The verify endpoint returns `data.id` (format `WEB-ONLINE_C-{...}`).
      The return controller now saves it, but confirm it matches the webhook's `transactionId`.
- [ ] **Package.** Run `bash modules/nomba/build.sh` and verify the `.zip` installs cleanly on a fresh PrestaShop.

## 7. Environment / how to run

- **Docker daemon** is installed (v29) but the dev user was **not in the `docker`
  group**, so docker needed `sudo`. Fix applied: `sudo usermod -aG docker $USER`
  — requires a full **logout/login** (or reboot) to take effect. Until then, run
  docker with `sudo`.
- Bring up the stack:
  ```bash
  docker compose -f nomba-prestashop/docker-compose.yml up -d
  ```
  Storefront `http://localhost:8080`, admin `http://localhost:8080/admin-dev`
  (`admin@nomba.test` / `nomba12345`).
- Webhooks need a public URL: run a tunnel
  (`cloudflared tunnel --url http://localhost:8080`) and register
  `<tunnel-url>/module/nomba/webhook` in the Nomba dashboard.
- Native PHP on this machine is 8.5 (too new for PrestaShop 8) — **only use it for
  `php -l` linting**, not for running the shop. The shop runs inside Docker.

## 8. Session log (newest first)

- **2026-06-30 (session 2)** — Full sandbox test pass completed.
  - Fixed `$this->name = 'kai'` → `'nomba'` (mismatch caused blank configure page;
    module ID was null, controller skipped rendering configure output).
  - Added leading-space trimming on config saves (saved secret had a space prefix → 403 Forbidden).
  - Verified correct sandbox verify endpoint:
    - `GET /v1/transactions/accounts/single?orderReference=...` rejected our PS-* reference;
    - `GET /v1/checkout/transaction?idType=orderReference&id=...` works.
    - `data.id` is the Nomba transaction ID (format `WEB-ONLINE_C-{...}`), NOT `data.transactionId`.
  - Fixed `return.php`:
    - Now calls `validateOrder()` when verify confirms SUCCESS (critical: DB was set to SUCCESS
      but no PrestaShop order was ever created — user saw payment success but no order).
    - All `validateOrder()` calls wrapped in `try-catch` so `PrestaShopException` (thrown in dev mode)
      doesn't reach the browser.
    - `cart->orderExists()` guard prevents double-fulfilment.
    - `fulfil()` returns `order_id` + `secure_key` directly instead of re-reading the DB row.
  - Fixed `webhook.php`: same guards + try-catch on `validateOrder()`.
  - Fixed `hookPaymentReturn`: now assigns `nomba_status` + `nomba_order_reference` from the
    transaction table (previously rendered with unassigned vars → always hit `{else}` →
    "We could not confirm" on the order confirmation page).
  - Updated `payment_return.tpl` with `ORDER_FAILED` case for graceful error display.
  - **Confirmed working end-to-end**: checkout → Nomba hosted checkout → pay with test card
    → return → order created → order confirmation page shows "Your Nomba payment was successful".
  - Remaining: webhook tunnel setup, webhook payload verification, refund testing, error-state testing, packaging.
- **2026-06-29** — Completed all remaining build steps:
  - Persistent token caching in Configuration (NombaApi::getToken)
  - Connection test button in admin config panel
  - SQL safety: DB insert wrapped in try-catch in redirect.php
  - Return controller now updates DB row on verify success/failure
  - Webhook handles `payment_failed` events (sets FAILED status)
  - Admin refund UI: `hookDisplayAdminOrderMainBottom` with full/partial refund
    form, refunded_amount tracking, error handling
  - Polished templates: error/page_return links, admin refund panel
  - CSS via hookHeader()
  - build.sh packaging script
  - Updated PROGRESS.md to reflect completed state
- **2026-06-24** — Researched Nomba API + PrestaShop module contract. Chose
  PrestaShop target. Scaffolded full project: docker env + module skeleton with a
  real `NombaApi` client and the three front controllers (redirect/webhook/return).
  All PHP lint-clean. Blocked on docker group re-login + sandbox test keys.
