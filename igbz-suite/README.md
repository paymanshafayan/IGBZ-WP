# IGBZ Suite — WordPress + WooCommerce

A faithful port of the IGBZ product from nopCommerce to WordPress and WooCommerce.

The nopCommerce original shipped as four separate plugins. This port ships as **one plugin with
five toggleable modules**, so an operator installs once and turns on only what the site needs.

| Module | Id | Replaces (nop plugin) | What it does |
| --- | --- | --- | --- |
| Multi-Tenant Stores | `multitenant` | `IGBZ.MultiTenant` | Tenants, wallet, subscription plans, BNPL, affiliate, LMS, OTP login, marketplace feeds, Iranian payment gateways, direct-bank IPGs, master-payment escrow, courier app, domains, SEO, i18n, legal auth |
| Instagram Automation | `instagram` | `IGBZ.Instagram` | Content generation and auto-publishing via **Manus**, comment-to-DM funnels via **ManyChat/ChatPlace**, product intake from the phone, AI studio, giveaways, and the **VIP channel** (paid private feed) |
| Master Site Hub | `hub` | `IGBZ.Hub` | Public store directory, tenant signup, domain verification, VIP links, content blocks |
| Mobile REST API | `rest_api` | `IGBZ.MobileApi` | JWT auth, catalog, account, store-admin endpoints, FCM push, device registry |
| FX payment gateway | `fx` | *(new)* | Foreign-currency payment intermediary: Rial top-ups into a dollar wallet (10% fee), Manus metering, monthly bills, PST.NET/RedotPay payout adapters, USDT on-ramp (Nobitex), manual settlement, operator reports |

### The one functional change from the nopCommerce version

The Instagram **Graph API** is not used — but for an availability reason, not a policy one: it
cannot be obtained as a service provider from Iran, and any service that *can* provide it for us
(ChatPlace and its MCP, for example) is welcome. Until then the workflows run on:

* **Manus** — niche research and trend discovery, graphic design, reels and short
  video, caption writing, hashtag selection, and auto-publishing/scheduling of posts, stories and
  reels at the page's peak-engagement hours. No manual download/upload step.
* **ManyChat** (inactive fallback) / **ChatPlace** (selected) — DM funnels ("comment the word X
  and I'll DM you the link"), over a real-time **webhook** and the provider API. The senior admin
  switches between the two with `dm.provider = manychat|chatplace`.

Everything Instagram-facing sits behind `Contracts\PublisherInterface`,
`Contracts\ContentGeneratorInterface` and the `Gateways\` DM clients, so a Graph API adapter can
be dropped back in later without touching the rest of the plugin. No direct Graph calls exist in
this codebase, and browser automation of Instagram (e.g. Windsor) is rejected per Meta ToS.

---

## Requirements

| | |
| --- | --- |
| WordPress | 6.3 or newer |
| WooCommerce | 8.0 or newer (HPOS and cart/checkout blocks are both declared compatible) |
| PHP | 8.1 or newer |
| MySQL / MariaDB | 5.7+ / 10.3+ (SQLite also works — see below) |
| PHP extensions | `openssl` (required — settings are encrypted at rest), `mbstring`, `json`, `hash` |
| Cron | WordPress cron must run. A real system cron is strongly recommended (see below). |

No Composer install is needed. The plugin ships its own PSR-4 autoloader.

### Verified against

Last verified on a live install running **WordPress 6.5 / WooCommerce 9.4.2 / PHP 8.2** with HPOS
enabled, exercising real `WC_Product` and `WC_Order` objects through the WooCommerce CRUD layer:

- all six gateways (`igbz_wallet`, `igbz_bnpl`, `igbz_zarinpal`, `igbz_idpay`, `igbz_nextpay`,
  `igbz_payir`) appear in **WooCommerce → Settings → Payments** and their settings screens render;
- paying a real order with the wallet gateway debits the ledger for the exact order total, moves the
  order to `processing`, sets the transaction id and credits the configured cashback;
- the ManyChat funnel answers a real webhook delivery with the v2 envelope, is idempotent per
  `comment_id` and enforces `per_user_limit`;
- WooCommerce's own admin screens (Home, Settings, Status, Products, Orders) stay clean.

### SQLite / WordPress Playground

The plugin detects SQLite (WordPress Playground, or the `sqlite-database-integration` plugin) and
adapts the two pieces of SQL that are not portable:

* `Db::upsert()` emits `INSERT … ON DUPLICATE KEY UPDATE` on MySQL and
  `INSERT … ON CONFLICT … DO UPDATE` on SQLite, mapping `GREATEST` onto SQLite's multi-argument
  `MAX`. Used by the wallet balance cache, Instagram insights and LMS lesson progress.
* `Db::lock()` uses `GET_LOCK` on MySQL. SQLite is single-writer, so locking is a no-op there.

SQLite is fine for demos and review. **Use MySQL or MariaDB in production** — the concurrency
guarantees the wallet relies on are only meaningful there.

You can boot a disposable demo with no server at all:

[Launch in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/paymanshafayan/IGBZ-WP/refs/heads/main/_playground/blueprint.json)

Outbound HTTP is proxied there, so payment gateways and the Manus/ManyChat calls will not reach
real endpoints.

---

## Installation

1. Copy the `igbz-suite` directory into `wp-content/plugins/`, or zip it and upload it through
   **Plugins → Add New → Upload Plugin**.
2. Activate **IGBZ Suite**. On activation the plugin creates its 70 database tables, registers its
   roles and capabilities, schedules its cron events and seeds default settings.
3. Go to **IGBZ → Settings → Modules** and enable the modules you need. Only *Multi-Tenant Stores*
   is on by default.
4. Work through the settings tabs for the modules you enabled (see *Configuration* below).
5. Check **IGBZ → Status**. Every module reports its own health rows there; a red row tells you
   exactly which setting is missing.

### Encryption key (do this before entering any credentials)

API keys and secrets are encrypted with AES-256-GCM before they are written to the options table.
The encryption key is derived from `IGBZ_ENCRYPTION_KEY`, `AUTH_KEY` and `SECURE_AUTH_SALT`.

Add a dedicated key to `wp-config.php`:

```php
define( 'IGBZ_ENCRYPTION_KEY', 'a-long-random-string-you-generate-once' );
```

If you skip this, the derivation still works but falls back to the WordPress salts alone — which
means **rotating your WordPress salts will make every stored credential unreadable** and you will
have to re-enter them. Set `IGBZ_ENCRYPTION_KEY` first, and back it up with your database.

### Real cron

WordPress' pseudo-cron only fires when someone visits the site, which is not good enough for
payment reconciliation, BNPL reminders or scheduled Instagram publishing. Disable it and use a
system cron:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
* * * * * cd /path/to/wordpress && wp cron event run --due-now > /dev/null 2>&1
```

The plugin registers three recurrences: `igbz_cron_five_minutes`, `igbz_cron_hourly` and
`igbz_cron_daily`.

---

## Configuration

Settings live in a single `igbz_settings` option, keyed with dotted names. The admin screen is
**IGBZ → Settings**, split into tabs. General, Modules and Advanced are always visible; the rest
appear only when their module is enabled.

Secret fields are shown masked (`••••••••••••`). Re-submitting the form without touching a masked
field preserves the stored value, so you never have to re-type a key to change something else on
the same tab.

### General

| Key | Default | Notes |
| --- | --- | --- |
| `general.default_currency` | `IRT` | `IRT` (Toman) or `IRR` (Rial). Drives the gateway conversion — see *Money*. |
| `general.tenant_resolution` | `domain` | `domain`, `path` or `single`. |
| `general.tenant_path_base` | `store` | Used when resolution is `path` → `example.com/store/acme`. |
| `general.default_tenant_id` | `0` | Tenant used when nothing else resolves. |
| `general.allow_self_signup` | `true` | Lets visitors request a store from the hub. |
| `general.auto_approve_tenants` | `false` | Leave off unless signup is paid and verified. |

### Payments

Four Iranian PSPs were implemented first: **Zarinpal**, **IDPay**, **NextPay** and **Pay.ir**.
Phase 6 added **eight direct-bank IPG adapters** on `GatewayInterface` through the shared base
`Payments\AbstractIpgGateway` — **Sadad** (REST + RSA-SHA1), **Asan Pardakht**, **Parsian**
(SOAP), **Iran Kish** (REST v3), **Mellat** (SOAP bpPay/bpVerify/bpSettle), **Saman** (three-key
RSA), **Pasargad** (RSA signing), **Sepehr** — plus **BalePay** and the generic
**HttpPspGateway** for any configurable PSP. Each is registered with WooCommerce as its own
gateway but only appears at checkout when it is both enabled and configured, and — for the
Iranian PSP/bank set (`zarinpal`, `idpay`, `nextpay`, `payir`, `httppsp`, `sadad`,
`asanpardakht`, `parsian`, `irankish`, `mellat`, `saman`, `pasargad`, `sepehr`) — only when the
store has a **DNS-verified standalone domain and an active Enamad flag**
(`legal.enamad_active`). Wallet, BNPL, FX, BalePay and crypto are not gated. The Payments admin
page lists every gateway with a **Test connection** button (a 1-Rial probe request).

| Key | Notes |
| --- | --- |
| `payments.default_gateway` | Used by wallet top-ups and subscription renewals. Falls back to the first enabled and configured gateway. |
| `payments.currency_multiplier` | Default `10`. See below. |
| `payments.zarinpal.enabled` / `.merchant_id` / `.sandbox` | Merchant id is the 36-character UUID. |
| `payments.idpay.enabled` / `.api_key` / `.sandbox` | Sandbox sends `X-SANDBOX: 1`. |
| `payments.nextpay.enabled` / `.api_key` | The api key is a UUID. |
| `payments.payir.enabled` / `.api_key` / `.sandbox` | Sandbox sends the literal key `test`; no real key needed. Pay.ir enforces a 10,000 Rial minimum. |
| `payments.sadad.merchant_id` / `.terminal_id` / `.private_key` | Sadad RSA-SHA1 signer. |
| `payments.asanpardakht.api_key` / `.merchant_config` | Asan Pardakht REST. |
| `payments.parsian.login_account` | Parsian SOAP. |
| `payments.irankish.api_key` / `.terminal_id` | Iran Kish REST v3. |
| `payments.mellat.username` / `.password` / `.terminal_id` | Mellat SOAP bpPay/bpVerify/bpSettle. |
| `payments.saman.terminal_id` / `.public_key` / `.private_key` | Saman three-key RSA. |
| `payments.pasargad.merchant_code` / `.terminal_code` / `.private_key` | Pasargad RSA signing. |
| `payments.sepehr.api_key` / `.terminal_id` | Sepehr. |
| `payments.httppsp.*` | Generic configurable PSP: `send_url`, `verify_url`, `redirect_base`, `auth_scheme`/`api_key`, and `field_*` mappings. |

> **Adapter trap:** every bank gateway must `use IGBZ\Suite\Support\Http;` (the abstract base
> type-hints it). Forgetting the import raises a `TypeError` at payment time, not at save time.

**Money.** Every Iranian PSP settles in **Rial**, while nearly every Iranian shop prices in
**Toman**. `Payments\Money` centralises the conversion so a wrong factor cannot overcharge a
customer tenfold:

* Store currency `IRT`/`TOMAN`/`TMN` → amounts are multiplied by `payments.currency_multiplier`
  (default 10) on the way to the gateway.
* Store currency `IRR`/`RIAL` → factor forced to 1; never converted.
* Any non-Iranian currency → factor forced to 1.
* A multiplier of zero or less falls back to 10 rather than zeroing the charge.

**Callback URL.** Each gateway is handed
`https://your-site/?igbz_payment_callback=<gateway-id>&payment_id=<id>`. Register that pattern in
the PSP dashboard if it asks for a fixed return URL. Every adapter re-checks the verified amount
against the stored amount and rejects a mismatch, and a repeated callback resolves to
`already_verified` instead of crediting twice.

### Wallet, Plans, BNPL, Affiliate, LMS, OTP, Marketplace

These tabs mirror the nopCommerce settings one for one; the defaults are seeded on activation and
every field is documented inline on the settings screen. Highlights:

* **Wallet** — ledger with a unique idempotency key per `(tenant, user, reason, reference)`, so a
  retried job cannot double-credit. Order cashback percentage, top-up bounds, and a
  pay-with-wallet checkout gateway.
* **BNPL** — internal provider plus adapter slots for SnappPay and Tara. `bnpl.fee_percent` is
  applied **only to the financed remainder** (`amount − down payment`), and the instalment schedule
  is rounded so it sums exactly to the total.
* **LMS** — courses, lessons, enrolments, progress, quizzes and final exams, certificates, and
  time-limited signed video links (`lms.video_link_ttl`, HMAC-signed with `lms.video_hmac_secret`).
  Quizzes reach the client only through `LmsService::questions_for_client()`, which strips the
  correct answers — never serialise a quiz row straight to a response. A certificate is issued only
  when the global `lms.certificate_enabled` **and** the course's own `certificate_enabled` agree.
  Refunding a WooCommerce order revokes the enrolments it paid for, matched on `enrollments.order_id`.
  This is the **heavy-security** half of the product: device-bound links, watermarking and
  screen-capture defences belong here, not in the VIP channel.
* **OTP** — phone login with Kavenegar and SMS.ir providers, plus a `log` provider that writes the
  code to the IGBZ log for development.
* **Marketplace** — Torob, Emalls and Google Merchant product feeds, plus phase-9 adapters:
  Digikala (`marketplace.digikala_api_key`/`.digikala_base_url`), Divar
  (`marketplace.divar_token`/`.divar_base_url`) and **Basalam** (`basalam.api_key`,
  `basalam.gharhe_id`, `basalam.enabled`) with **auto-publish of Instagram-made content to
  Basalam** when enabled.

### Phase 6–14 subsystems (DB v16)

* **Master payment (escrow)** — `MasterPaymentService` holds funds centrally
  (`ig_master_*` tables), releases them on delivery confirmation via a cron, tracks disputes, and
  records a digital agreement per transaction. The senior admin can list withdrawals; tenants can
  request one from the REST API. Idempotent by construction (unique reference per request).
* **Courier app (phase 7)** — `CourierService` plans **sequential routes** (the `arrived` button
  opens the next shipment), resolves shipments by barcode, and requires a **customer-held
  delivery PIN** (`random_int`, compared with `hash_equals`). COD supports cash / gateway / card
  reader / in-app. Live tracking rows (`ig_courier_tracking`) and a per-shipment chat
  (`ig_courier_chat`) are exposed over REST. `LabelPrintingService` renders standard labels with
  barcodes and the customer-only PIN (`logistics.*` keys configure courier costs and PIN length).
  Shipping adapters: `ShippingAdapterInterface` + `HttpShippingAdapter` for Tapin/Postex
  (`logistics.tapin_*`, `logistics.postex_*`).
* **Domains (phase 10)** — `DomainService`: search, register, subdomain provisioning, DNS
  verification (`/domains/{id}/verify-dns`), and **transfer** (`/domains/transfer`).
  `WebPresenceService` auto-registers a verified domain on Google/Bing webmaster tools.
  `domain.provider*` keys configure the domain reseller API.
* **Legal (phase 6)** — `NationalIdVerifier` (Shahkar) checks national IDs before high-value
  purchases; the verifier stays **locked until the senior admin stores `legal.shahkar_api_key`**
  (plus `legal.shahkar_base_url`). Every store gets default legal pages; `legal.enamad_active`
  gates the direct-bank gateways.
* **SEO (phase 10)** — `SeoService` generates meta + hashtags with a deterministic template or AI;
  the `igbz-seo` page can **save generated meta onto real products**. `ProductFeedService` serves
  Yektanet/Tapsell feeds from the real catalog (`?igbz_feed=`), `AdNetworkService` is a generic
  HTTP adapter for Triboon (`seo.triboon_*`).
* **i18n (phase 12)** — `I18nService` exposes the admin-chosen languages to the app
  (`/i18n/config`); `TranslationService` + `HttpTranslationAdapter` auto-translate content
  (`translation.*` keys).
* **Giveaways (phase 14)** — `GiveawayService` draws winners from **real comments** in
  `ig_funnel_hits` using `random_int`; `AiCreditsService` meters customer AI-studio usage with a
  ledger (`ig_ai_credit_ledger`) topped up by a purchase percentage or a cash top-up.

### FX payment gateway (module `fx`)

The foreign-currency intermediary for the tools themselves (Manus/ManyChat costs), separate from
the store's own international revenue:

* **Top-up** — the admin charges a dollar amount; a **10% fee** (`fx.fee_percent`) is added on
  the currency, converted at a locked rate (`fx.rate_source` auto → `fx.rate_url`/`fx.rate_json_path`,
  fallback `fx.rate_manual`), paid through the Iranian gateways with `purpose=fx_topup`; only the
  net amount lands in the dollar wallet.
* **Metering** — Manus tasks and ManyChat DMs are metered against the tenant's own balance at
  dispatch time (no queue; insufficient balance is rejected immediately with a top-up message).
* **Bills** — `FxBillingService` creates monthly bills and a daily cron settles due bills via the
  payout adapter; a failed payout reverses the charge and leaves the bill `due`.
* **Payouts** — `FxPayoutAdapterInterface` with **PST.NET** (primary, Cyprus-company card,
  `fx.pstnet_*`) and **RedotPay** (pilot, `fx.redotpay_*`), switched via `fx.payout_provider`,
  plus a manual settlement button and a payout webhook
  (`POST /igbz/v1/fx/payout-webhook/{provider}`, shared `fx.webhook_token`).
* **USDT ramp** — `HttpRampAdapter` (Nobitex defaults) buys USDT when the card balance is below
  `fx.ramp_min_card_balance`, capped per run (`fx.ramp_max_irt_per_run`); `fx.ramp_enabled`
  defaults to **false** on purpose.
* **Operator reports** — `FxReportsService` aggregates top-ups, fees, metering, refunds, ramp
  purchases and bills over a chosen period on the FX page.

### Manus (Instagram content)

| Key | Notes |
| --- | --- |
| `manus.api_key` | Sent as the `x-manus-api-key` header. Required. |
| `manus.project_id` | Optional; groups tasks in one Manus project. |
| `manus.agent_profile` | `manus-1.6` (default), `manus-1.6-lite` or `manus-1.6-max`. |
| `manus.locale` / `manus.content_language` | `fa-IR` and `Persian (Farsi)` by default. |
| `manus.auto_generate` / `manus.auto_schedule` / `manus.collect_insights` | Cron-driven automation switches. |
| `manus.default_peak_hours` | `12:00,18:30,21:00`. Used until real insights exist. |
| `manus.min_gap_minutes` | `90`. Minimum spacing between two scheduled posts. |
| `manus.poll_interval` | `300` seconds. Only used when webhooks are not configured. |
| `manus.webhook_token` | Shared secret for the Manus callback (see below). |

Manus tasks are **asynchronous**. The plugin will poll `task.detail` on the five-minute cron, but a
webhook is much better:

```
POST https://your-site/wp-json/igbz/v1/manus/task?token=<manus.webhook_token>
```

The endpoint accepts the token as `?token=`, an `X-IGBZ-Token` header, or an HMAC of the raw body in
`X-Manus-Signature` (`hash_hmac('sha256', body, token)`) — configure whichever Manus offers you.
It handles `task_created`, `task_progress` and `task_stopped`, and pulls `attachments[]` into the
content record.

**Scheduling.** `ContentScheduler::next_peak_slot()` picks a publish time in this order: the
account's explicit `peak_hours`, then hours learned from the `ig_insights`
`engagement_by_hour` data, then `manus.default_peak_hours` — always respecting
`manus.min_gap_minutes`. Set each account's timezone on **IGBZ → Instagram → Accounts**; slots are
computed in the account's own timezone, not the server's.

### DM funnels: ManyChat (inactive fallback) and ChatPlace (selected)

The senior admin picks the provider with `dm.provider = manychat|chatplace`. ManyChat stays
implemented and inactive by default; ChatPlace is the chosen provider (flat ~$20/mo, built-in AI
agent, VIRALE trend research, official Meta partner, MCP-ready). `ChatPlaceClient` implements the
same `Gateways\DmClientInterface` contract with its own `chatplace.api_key` / `chatplace.base_url`.

### ManyChat (DM funnels)

Both developer integration paths from the ManyChat docs are supported.

**1. Webhook (preferred, real-time).** ManyChat has no generic inbound-webhook subscription API, so
the real-time path is a flow's **External Request** action (a ManyChat Pro feature). Point it at one
of:

| Endpoint | Purpose |
| --- | --- |
| `POST /wp-json/igbz/v1/manychat/comment` | New Comment / keyword events. The main funnel entry point. |
| `POST /wp-json/igbz/v1/manychat/event` | Any other Instagram interaction (story reply, DM keyword, mention). |
| `POST /wp-json/igbz/v1/manychat/subscriber` | Store/refresh a subscriber profile; returns the linked WordPress user, wallet balance and order count. |
| `GET  /wp-json/igbz/v1/manychat/ping` | Connectivity check. |

Authentication is a shared secret from `manychat.webhook_token`, accepted as `?token=`, an
`X-IGBZ-Token` header, or `Authorization: Bearer …`.

The comment endpoint accepts:

```json
{
  "subscriber_id": "1234567890",
  "comment_text": "LINK",
  "comment_id": "179…",
  "post_id": "178…",
  "timestamp": 1723459200,
  "ig_username": "customer",
  "ig_user_id": "178…",
  "first_name": "Sara",
  "last_name": "M",
  "account_id": 1,
  "tenant_id": 0
}
```

and replies with a ManyChat **Dynamic Content** envelope (`{"version":"v2","content":{…}}`)
carrying the message and a URL button, plus flat `igbz_link`, `igbz_coupon`, `igbz_message`,
`igbz_funnel` and `igbz_hit_id` fields so the flow can map them straight into custom fields.

> **ManyChat waits about 10 seconds for a response.** Anything slower is treated as a failure. With
> `manychat.async_reply` on (the default) the endpoint answers immediately and any slow work —
> issuing a unique coupon, generating a link — is finished on the next cron tick and pushed back to
> the subscriber with `setCustomField` + `sendFlow`. Leave this on unless you know your funnel does
> no slow work.

**2. ManyChat API (`GET` a subscriber's profile once a comment pulled them into a Flow).** Set
`manychat.api_key` (a Pro plan is required) and the plugin will call
`https://api.manychat.com/fb/` with `Authorization: Bearer …`. `Gateways\ManyChatClient` wraps
`subscriber/getInfo`, `findByName`, `findByCustomField`, `findBySystemField`, `getInfoByUserRef`,
`updateSubscriber`, `addTag`, `addTagByName`, `removeTag`, `setCustomField(ByName)`,
`sending/sendContent`, `sending/sendFlow`, and the `page/*` metadata endpoints (tags, custom fields,
bot fields, flows). Flow lists are cached because ManyChat rate-limits `getFlows` to 10 requests
per second against 100 for other page calls.

Other keys: `manychat.default_flow_ns` (flow to trigger when a funnel does not name one),
`manychat.link_field_name` / `manychat.coupon_field_name` (custom fields written by the async path),
`manychat.button_label`, `manychat.duplicate_message`.

**Funnels** are managed at **IGBZ → Instagram → Funnels**: keyword plus match mode
(`exact`, `contains`, `starts`, `regex`), an optional post id to scope it to one post, a target
(`url`, `product`, `coupon` or `flow`), per-user and total limits, an optional wallet credit, and a
date window.

### Master Site Hub

`hub.subdomain_base` and `hub.cname_target` drive the domain verification instructions shown to
tenants; `hub.vip_link_secret` signs time-limited VIP links (`hub.vip_link_ttl`, default 900s);
`hub.mother_origin` is the allowed CORS origin for the hub REST controller. Shortcodes:
`[igbz_store_directory]`, `[igbz_hub_grid]`, `[igbz_hub_stats]`, `[igbz_hub_blocks]`.

### Mobile REST API

`api.jwt_secret` signs HS256 tokens (`api.jwt_ttl`, default 1 hour) with rotating refresh tokens
(`api.refresh_ttl`, default 30 days) — a fix for the nop version's 30-day non-refreshable token.
Tokens carry a `jti` and are revoked on password reset and profile update, and per-device sessions
can be listed and revoked. Push uses FCM v1: set `api.fcm_project_id` and paste the service-account
JSON into `api.fcm_service_account`.

### VIP channel (paid private feed)

The product's answer to Instagram Close Friends, and the reason it exists: Close Friends cannot be
monetised or re-shared. Instagram disables the share button on Close Friends content, oEmbed serves
public media only, and list membership has no API — so a paid private feed has to live in **our own
app**. The public Instagram post is the teaser; the real media sits on our storage behind an
entitlement check.

**Security level is deliberately light here.** A VIP post is protected to about the same degree as
a Close Friends post: signed short-lived URLs and a server-side entitlement check, and nothing
more. No watermarking, no device binding, no screen-capture defence — those belong to the LMS.
`VipMediaService` carries this note at the top of the file so it does not get "hardened" by mistake.

| Service id | Responsibility |
| --- | --- |
| `vip.access` | Entitlement: author, active subscription, single-post purchase, or free post |
| `vip.posts` | CRUD, scheduling, expiry, shortcodes, feed assembly with locked/unlocked shaping |
| `vip.media` | HMAC-signed, short-lived media URLs; re-checks entitlement at serve time |
| `vip.social` | Likes, saves (and the offline-copy stamp), comments and replies, view counts, pinned comments |
| `vip.messages` | Per-post direct messages to the admin, threaded |
| `vip.billing` | Subscriptions, single-post purchases, tips |

**The post keeps the Instagram shape.** Carousel and video media, caption, hashtags, location,
likes, threaded comments, saves, view counts and a direct-message thread — a member should feel
they are looking at an Instagram post inside our app.

**Two ways to pay, plus tips.** A recurring subscription, or a one-off purchase of a single post.
Public posts additionally expose a financial-support button; `vip.tip_min` defaults to 10,000 and
`vip.tip_presets` to 50/100/200/500k. A successful tip fires `igbz_vip_tip_received`.

**The share landing page.** `/vip/p/{shortcode}` is a public page rendered on `template_redirect`.
It shows the teaser, then offers *buy the subscription* or *buy just this post*, alongside app
download links and an explanation of what the VIP channel is. This is where the OS share sheet
lands when a member picks our app.

**Media URLs** look like `?igbz_vip_media=<post>&i=&u=&e=&s=`: HMAC-signed and expiring, and the
entitlement is checked again when the bytes are served, not only when the link is minted. A forged
signature gets 403; an expired one gets 410.

**Expiry — ratified policy.** A VIP post lives for `vip.default_expiry_days` (**7** by default)
and is then **removed from the server**: the five-minute sweep purges the media and marks the row
`deleted`. The window belongs to the **IGBZ senior admin** (Settings → VIP channel); a store admin
cannot set it per post — the editor states it instead, and suggests posting the content to their own
Instagram Close Friends if they want to keep it (advice to a human; no Instagram API is involved).

The buyer is told before they pay: the share page prints the exact deletion date **above** the buy
buttons, and points at the save icon. Saving is real — `vip_post_saves` plus
`GET /vip/posts/{id}/offline`, which hands an entitled member a longer-lived signed link
(`vip.offline_link_ttl`, 3600s) so the app can keep a private copy in its own storage. The saved row
and the purchase record both survive the purge; only the content goes. Full rationale in
`DESIGN-VIP-EXPIRY.md`.

> The offline copy is a **VIP-only** relaxation, agreed because the post is deleted in a week. The
> LMS has no offline path and must not gain one.

**Admin dashboard.** `igbz-vip` has five tabs — Posts, Comments, Inbox, Members, Plans — so the
channel is run like an Instagram page: publish and schedule, read and reply to comments and DMs,
and see revenue and membership figures.

Ten `vip_*` tables (DB v10, plus `vip_post_saves` in v17) and 32 `/vip/*` REST routes.
`tests/VipChannelTest.php` covers 25 scenarios.

---

## Admin screens

Everything lives under one top-level **IGBZ** menu, and every screen is capability gated. (The
nopCommerce original never implemented `IAdminMenuPlugin`, so roughly 26 of its admin controllers
were reachable only by typing a URL; that is fixed here.)

| Screen | Slug | Module |
| --- | --- | --- |
| Status | `igbz` | always |
| Settings | `igbz-settings` | always |
| Tenants | `igbz-tenants` | multitenant |
| Wallet | `igbz-wallet` | multitenant |
| Plans | `igbz-plans` | multitenant |
| BNPL / Instalments | `igbz-bnpl` | multitenant |
| Affiliate | `igbz-affiliate` | multitenant |
| Courses | `igbz-courses` | multitenant |
| Payments | `igbz-payments` | multitenant |
| Master payment | `igbz-master-payment` | multitenant |
| Logistics | `igbz-logistics` | multitenant |
| Marketplaces | `igbz-marketplaces` | multitenant |
| SEO & ads | `igbz-seo` | multitenant |
| Translator | `igbz-translator` | multitenant |
| Gamification | `igbz-gamification` | multitenant |
| Domain | `igbz-domains` | multitenant |
| FX payments | `igbz-fx` | fx |
| IG Accounts | `igbz-ig-accounts` | instagram |
| IG Content | `igbz-ig-content` | instagram |
| IG Funnels | `igbz-ig-funnels` | instagram |
| IG Subscribers | `igbz-ig-subscribers` | instagram |
| IG Insights | `igbz-ig-insights` | instagram |
| IG Intake | `igbz-ig-intake` | instagram |
| VIP Channel | `igbz-vip` | instagram |
| AI Studio | `igbz-ai-studio` | instagram |
| Giveaways | `igbz-giveaways` | instagram |
| Hub | `igbz-hub` | hub |
| Mobile API | `igbz-mobile-api` | rest_api |

---

## Storefront shortcodes

| Shortcode | Attributes |
| --- | --- |
| `[igbz_courses]` | `limit` (12), `level`, `columns` (3) |
| `[igbz_course]` | `slug` — falls back to `?igbz_course=<slug>` |
| `[igbz_plans]` | — |
| `[igbz_bnpl_calculator]` | — |
| `[igbz_wallet_balance]` | — |
| `[igbz_otp_login]` | — |

---

## REST API

Namespace `igbz/v1` (the hub controller uses `igbz-hub/v1`).

```
GET  /igbz/v1/auth/login-options
POST /igbz/v1/auth/otp/request        { phone }
POST /igbz/v1/auth/otp/verify         { phone, code, device_id? }
POST /igbz/v1/auth/password           { username, password, device_id? }
POST /igbz/v1/auth/refresh            { refresh_token, device_id? }
POST /igbz/v1/auth/logout
GET  /igbz/v1/auth/sessions
POST /igbz/v1/auth/sessions/revoke    { jti? | all }
GET  /igbz/v1/auth/me

GET  /igbz/v1/catalog/products
GET  /igbz/v1/catalog/products/<id>
GET  /igbz/v1/catalog/categories
GET  /igbz/v1/catalog/search-suggest

GET  /igbz/v1/account/profile
GET  /igbz/v1/account/orders
GET  /igbz/v1/account/orders/<id>
GET  /igbz/v1/account/wallet
POST /igbz/v1/account/wallet/topup
GET  /igbz/v1/account/instalments
POST /igbz/v1/account/instalments/<id>/pay
GET  /igbz/v1/account/courses
POST /igbz/v1/account/courses/progress
GET  /igbz/v1/account/affiliate
GET  /igbz/v1/account/payments

POST /igbz/v1/devices/register
POST /igbz/v1/devices/unregister
GET  /igbz/v1/devices
POST /igbz/v1/devices/test
POST /igbz/v1/notifications/send

GET  /igbz/v1/app/config
GET  /igbz/v1/app/resolve-store

GET  /igbz/v1/admin/summary
GET  /igbz/v1/admin/orders
GET  /igbz/v1/admin/customers
GET  /igbz/v1/admin/categories
GET  /igbz/v1/admin/categories/tree
GET  /igbz/v1/admin/domains
POST /igbz/v1/admin/domains/<id>/verify
POST /igbz/v1/admin/tenants/<id>/status
POST /igbz/v1/admin/vip-link

POST /igbz/v1/manychat/comment
POST /igbz/v1/manychat/event
POST /igbz/v1/manychat/subscriber
GET  /igbz/v1/manychat/ping
POST /igbz/v1/manus/task

GET  /igbz/v1/fx/balance
POST /igbz/v1/fx/topup
GET  /igbz/v1/fx/ledger
GET  /igbz/v1/fx/prices
GET  /igbz/v1/fx/bills
POST /igbz/v1/fx/payout-webhook/<provider>     { token | X-IGBZ-Token | Bearer }

GET  /igbz/v1/ai/credits
POST /igbz/v1/ai/studio/generate

GET  /igbz/v1/courier/me
GET  /igbz/v1/courier/shipments
GET  /igbz/v1/courier/shipments/<barcode>
POST /igbz/v1/courier/routes/plan
POST /igbz/v1/courier/shipments/<id>/arrived
POST /igbz/v1/courier/shipments/<id>/deliver
POST /igbz/v1/courier/shipments/<id>/cod
GET  /igbz/v1/courier/tracking/<id>
GET  /igbz/v1/courier/chat/<id>
POST /igbz/v1/courier/chat/<id>/send
POST /igbz/v1/shipments/<id>/status
GET  /igbz/v1/shipments/<id>/tracking
POST /igbz/v1/checkout/cod-pay

GET  /igbz/v1/domains
GET  /igbz/v1/domains/search
POST /igbz/v1/domains/register
POST /igbz/v1/domains/transfer
POST /igbz/v1/domains/subdomain
POST /igbz/v1/domains/<id>/verify-dns
GET  /igbz/v1/domains/web-presence
POST /igbz/v1/domains/web-presence/register
GET  /igbz/v1/i18n/config
GET  /igbz/v1/master-payment
POST /igbz/v1/master-payment/agreement
POST /igbz/v1/master-payment/withdraw

GET  /igbz-hub/v1/stores
GET  /igbz-hub/v1/stores/<slug>
GET  /igbz-hub/v1/plans
GET  /igbz-hub/v1/landing
GET  /igbz-hub/v1/blocks
GET  /igbz-hub/v1/blocks/<page_key>
GET  /igbz-hub/v1/check-slug
POST /igbz-hub/v1/signup
POST /igbz-hub/v1/signup/verify-payment
```

Unlike the nop version, CORS is restricted to `hub.mother_origin` rather than `AllowAnyOrigin()`.

---

## Architecture

```
igbz-suite.php            bootstrap, constants, igbz() accessor
uninstall.php             drops data only when purge_on_uninstall is set
src/Support/              autoloader, container, settings, crypto, db, http,
                          logger, schema, capabilities, cron, activator, admin shell
src/Modules/MultiTenant/  tenants, wallet, plans, bnpl, affiliate, lms, otp,
                          marketplace, payments (incl. 8 bank IPGs), master payment,
                          logistics (courier app), domain, seo, translation/i18n,
                          gamification, legal auth, admin pages, storefront
src/Modules/Instagram/    manus + manychat/chatplace services, funnels, subscribers,
                          insights, scheduler, webhooks, ai studio, giveaways, admin pages
src/Modules/Hub/          directory, signup, domains, vip links, blocks, REST
src/Modules/RestApi/      jwt auth, controllers (incl. fx/ai/courier/domain), fcm push, device registry
src/Modules/Fx/           FX payment gateway: wallet, rates, billing, payouts, ramp, reports
assets/                   css + js
languages/                igbz-suite.pot
tests/                    dependency-free test runner
```

`Support\Plugin` is a small singleton container. Core services have accessors —
`igbz()->settings()`, `igbz()->logger()`, `igbz()->db()`, `igbz()->http()`, `igbz()->tenancy()` —
and everything a module binds is reached with `igbz()->get( $id )`. Resolved services are
singletons; an unknown id throws.

| Module | Service ids |
| --- | --- |
| core | `settings`, `logger`, `db`, `http`, `tenancy` |
| multitenant | `tenants`, `wallet`, `plans`, `bnpl.providers`, `bnpl`, `affiliate`, `lms`, `lms.vod`, `payments`, `otp`, `legal.nid`, `marketplace`, `marketplace.sync`, `marketplace.mappings`, `marketplace.basalam`, `logistics`, `logistics.courier`, `logistics.labels`, `master.payment`, `domain`, `webpresence`, `seo`, `translation`, `translation.adapter`, `i18n`, `gamification`, `gamification.carts` |
| instagram | `ig.prompts`, `ig.manus_client`, `ig.manus`, `ig.scheduler`, `ig.insights`, `ig.manychat`, `ig.subscribers`, `ig.funnels`, `ig.intake`, `ig.credentials`, `ig.skus`, `ig.stt_http`, `ig.stt_manus`, `ig.translations`, `ai.studio`, `ai.credits`, `giveaways`, `vip.access`, `vip.media`, `vip.posts`, `vip.social`, `vip.messages`, `vip.billing` |
| hub | `hub.stats`, `hub.directory`, `hub.vip`, `hub.domains`, `hub.blocks`, `hub.signup` |
| rest_api | `api.tokens`, `api.auth`, `api.devices`, `api.google_auth`, `api.push`, `api.notifications` |
| fx | `fx.wallet`, `fx.accounts`, `fx.rates`, `fx.meter`, `fx.payouts`, `fx.ramp`, `fx.reports` |

A module's services only exist while that module is enabled, so guard cross-module calls with
`igbz()->has( 'wallet' )`.

**Tenancy** is single-site with a `tenant_id` column, not WordPress Multisite. All **72 tables
(DB v17)** carry `tenant_id` except `tenants`, `tenant_domains`, `tenant_members`, `plans`,
`logs`, `lesson_progress` (scope inherited through `enrollment_id`), `vip_post_likes`,
`vip_post_saves`, `vip_post_views`, `fx_rates`, `fx_prices`, `ig_label_group_items`, `ig_courier_tracking` and
`ig_courier_chat` (the exact whitelist lives in `tests/SchemaTest.php`). Products and orders are
scoped with the `_igbz_tenant_id` meta key, where `0` or absent means platform-shared.

### Extension points

Filters:

```php
igbz_register_payment_gateways   // add a PSP adapter
igbz_register_bnpl_providers     // add a BNPL provider (SnappPay, Tara, Digipay)
igbz_register_fx_payout_providers // add an FX payout provider (PST.NET, RedotPay, …)
igbz_dm_gateways                 // DM provider registry (manychat, chatplace, custom)
igbz_manus_prompt_*              // rewrite any Manus prompt
igbz_marketplace_feed_item       // reshape a marketplace feed item
igbz_speech_to_text_engines      // STT provider registry
igbz_lms_video_source / igbz_vip_media_source  // swap in a CDN-backed media URL
```

Actions:

```php
igbz_booted
igbz_tenant_created  igbz_tenant_updated  igbz_tenant_deleted
igbz_wallet_entry_created
igbz_payment_verified  igbz_payment_failed
igbz_subscription_started  igbz_subscription_renewed
igbz_subscription_cancelled  igbz_subscription_expired
igbz_bnpl_contract_created  igbz_bnpl_contract_declined
igbz_bnpl_contract_activated  igbz_bnpl_contract_cancelled
igbz_bnpl_contract_settled  igbz_bnpl_contract_defaulted
igbz_bnpl_installment_paid  igbz_bnpl_reminder_due
igbz_affiliate_enrolled  igbz_referral_converted
igbz_affiliate_commission_recorded
igbz_lms_enrolled  igbz_lms_course_completed  igbz_lms_quiz_submitted
igbz_otp_verified  igbz_otp_user_registered
igbz_manychat_event  igbz_funnel_hit  igbz_funnel_delivered
igbz_hub_signup_completed
igbz_intake_created … igbz_intake_product_created … igbz_intake_published
igbz_vip_post_published  igbz_vip_post_liked  igbz_vip_comment_added
igbz_vip_message_sent  igbz_vip_tip_received  igbz_vip_membership_*
igbz_ig_insights_stored  igbz_ig_subscriber_linked
```

### Roles and capabilities

Roles `igbz_tenant_owner`, `igbz_tenant_staff` and `igbz_instructor` are created on activation.
Capabilities: `igbz_manage_suite`, `igbz_manage_tenants`, `igbz_manage_own_tenant`,
`igbz_manage_wallet`, `igbz_manage_plans`, `igbz_manage_bnpl`, `igbz_manage_lms`,
`igbz_manage_affiliate`, `igbz_manage_instagram`, `igbz_manage_api`.

---

## Tests

The suite is dependency-free — no Composer, no PHPUnit — so it runs anywhere PHP does:

```bash
php igbz-suite/tests/run.php
```

`tests/bootstrap.php` provides doubles for the WordPress functions the tested classes touch, plus a
fake `$wpdb`. **1209 assertions across 23 cases**, plus a syntax check over 235 files
(`bash _devenv/test.sh` runs both).

Coverage is deliberately aimed at the code where a bug costs money or leaks data: `Crypto`,
`Settings` (encryption at rest and mask handling), `Schema` (tenant scoping and dbDelta formatting),
`Jwt`, the BNPL instalment schedule, `Money`, the PSP adapter contracts, the module registry, the
Manus prompt builder, cron scheduling, account credentials, publish verification, funnel delivery,
product intake, direct messages, the VIP channel, the LMS, post identity, the FX payment gateway
(`FxTest`), the phase 6–14 services (`PhasesTest`, `Phases2Test` — escrow, courier delivery/COD,
labels, routing) and the direct-bank IPG adapters (`IpgAdaptersTest` — config gating, RSA
sign/encrypt, SOAP payloads).

Two things about this suite are worth knowing before you trust it:

* **There is no auto-discovery.** A case must be listed in `$cases` in `tests/run.php`, *and* each
  test method must be called from that class's own `run()`. A method you forget to call is silently
  never executed.
* **In-memory doubles can agree with a bug.** `FunnelDb` once hard-coded the ordering the query was
  *supposed* to produce, so an inverted comparison in the real SQL passed anyway. Doubles must
  derive their behaviour from the statement under test. Prove a new test works by mutating the
  production code and watching it go red — a suite that is green on its first run has demonstrated
  nothing.

---

## Uninstall

Deactivating leaves everything in place. Deleting the plugin drops all IGBZ tables, options, user
meta, cron events and roles **only if** you first tick *Remove all data on uninstall* on the
Advanced settings tab. Otherwise the data survives a delete and reinstall.

---

## Licence

GPL-2.0-or-later.
