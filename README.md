# IGBZ-WP

The IGBZ product ported from **nopCommerce** to **WordPress + WooCommerce**.

## Contents

| Path | What it is |
| --- | --- |
| [`PROJECT-STATE.md`](PROJECT-STATE.md) | **Start here.** Current state of the project (in Persian): what is built, decided and still gated. |
| [`ADR/ADR-0004-PADO-ZERNIO-SOCIAL-ARCHITECTURE.md`](ADR/ADR-0004-PADO-ZERNIO-SOCIAL-ARCHITECTURE.md) | Accepted Pado, DeepInfra and Zernio architecture; supersedes ADR-0002. |
| [`DESIGN-INSTAGRAM-PADO-ZERNIO.md`](DESIGN-INSTAGRAM-PADO-ZERNIO.md) | Detailed target design, trust boundaries, flows, provider gates and migration scope. |
| [`igbz-suite/`](igbz-suite/) | The plugin. One plugin, six toggleable modules. See its [README](igbz-suite/README.md) for installation, configuration and the API reference. |
| [`DESIGN-VIP.md`](DESIGN-VIP.md) | Design document for the VIP channel (in Persian): tables, endpoints, flows, and the security decision. |
| [`REVIEW-IGBZ-NopCommerce.md`](REVIEW-IGBZ-NopCommerce.md) | Read-only review of the original nopCommerce repository (in Persian), which this port is based on. |
| [`_devenv/`](_devenv/) | Offline development environment and the [agent brief](_devenv/AGENT-BRIEF.md) — subsystem internals and hard-won traps. Tooling, not part of the shipped plugin. |

## The port at a glance

The nopCommerce original was four separate plugins. This repository contains **one plugin with six
toggleable modules**. The accepted target architecture is versioned in
[`ADR-0004`](ADR/ADR-0004-PADO-ZERNIO-SOCIAL-ARCHITECTURE.md):

* **Multi-Tenant Stores** — the target is WordPress Multisite with one data-plane site per store and
  a shared IGBZ control plane. The current code is still single-site with `tenant_id` columns and
  requires migration.
* **Pado** — each store has a constrained, versioned Playbook runtime for marketing analysis,
  planning, production and learning. DeepInfra is the v1 inference target; each store owns and pays
  for its account and IGBZ does not store that credential.
* **Instagram Automation** — Zernio is the sole social provider, paid centrally by IGBZ with a
  separate profile per store. It covers account connection, publishing/scheduling, inbox,
  comment-to-DM and analytics. WooCommerce remains the commerce source of truth.
* **FX** — existing foreign-payment and payout infrastructure; legacy Manus/ManyChat metering must
  be migrated because DeepInfra is paid directly by each store and Zernio is included in IGBZ billing.
* **Master Site Hub** — public store directory, tenant signup, domain verification and VIP links.
* **Mobile REST API** — JWT auth with rotating refresh tokens, catalog/account/admin endpoints and
  FCM push.

The historical Agent Reach Instagram-session proposal is rejected. The existing plugin still contains integrations for Manus, ManyChat and ChatPlace; they are
legacy implementation debt, not accepted providers, and must not receive new production credentials.
Ayrshare is not to be introduced. Direct Meta API calls, Instagram scraping, browser cookies and
sessions are outside the target. See
[`DESIGN-INSTAGRAM-PADO-ZERNIO.md`](DESIGN-INSTAGRAM-PADO-ZERNIO.md) for the target flow and gates.

## The VIP channel

A paid private feed that lives **inside our own app**, replacing Instagram Close Friends — which
cannot be monetised, cannot be re-shared (Instagram disables the share button on it), cannot be
embedded (oEmbed serves public media only) and has no membership API. The public Instagram post
becomes the teaser; the real media sits on our storage behind an entitlement check.

A VIP post keeps the Instagram shape — carousel and video, caption, hashtags, likes, threaded
comments, saves, view counts and a direct-message thread — so a member feels they are looking at an
Instagram post. Sharing one lands on `/vip/p/{shortcode}`, which offers either the subscription or
that single post, plus app download links. Public posts also carry a financial-support button.

Its security is deliberately **light** — roughly a Close Friends post: signed short-lived media URLs
and a server-side entitlement check. The heavy measures (device binding, watermarking,
screen-capture defence) belong to the **LMS**, not here.

Details in [`DESIGN-VIP.md`](DESIGN-VIP.md); the post-expiry policy is still an open question for
the client, recorded in [`PROJECT-STATE.md`](PROJECT-STATE.md).

## Registering a product without opening the admin

A shopkeeper photographs something on the counter and the plugin does everything else. The
WooCommerce product editor is never involved — the app plus the AI pipeline is the only way a
product gets created.

| # | Step | Where |
|---|------|-------|
| 1–2 | Tap *register image*, shoot live or pick from the gallery | app |
| 3 | The assistant grades the photo for background removal and video suitability; an unusable photo comes back with **specific, fixable reasons** and the seller retries | `POST /intake/photo` |
| 4 | The photo is cut out, given a new background and relit into a commercial product image | Pado Playbook (target; legacy code uses Manus) |
| 5 | It opens in an Instagram-like editor for optional tweaks | app (the plugin only serves the image and accepts the edit back) |
| 6 | The seller describes the product **by typing or by voice**, and sets the price, stock and category | `POST /intake/description` |
| 7 | The assistant writes the listing and creates the WooCommerce product, translating it if the store is multilingual | `POST /intake/publish` |
| 8 | The product and its code go to the Instagram assistant | automatic |
| 9 | Image post or video post? | `POST /intake/post-kind` |
| 10 | For video: the seller's brief (typed or dictated) becomes a video, which they approve | `POST /intake/video` |
| 11 | The code is stamped onto the media, the caption tells viewers to comment it, hashtags are chosen | `POST /intake/compose` |
| 12–13 | The approved post is queued through Zernio; the comment-to-DM rule resolves the WooCommerce link in the IGBZ backend | `POST /intake/schedule` (target; migration pending) |

Three things hold it together:

* **There are two codes, and they are not the same string.** The *customer code* (`0047`) is the
  WooCommerce product id, left-padded to at least four digits. It is what gets burned onto the post,
  what the caption asks for, and what the target Zernio comment-to-DM funnel matches. It is digits-only because the
  shopper types it into an Instagram comment on a Persian keyboard, where reaching the Latin letters
  of a SKU means switching layouts mid-comment. Four digits is a floor rather than a format: a
  one- or two-digit code would be typed under a post by accident and fire the funnel for someone who
  never asked. The *warehouse SKU* (`IGBZ-4F2K`) stays the WooCommerce `sku` field for invoices and
  stock control, and a shopper never sees it. `intake.code_digits` widens the customer code; the
  padding only ever grows, so a store passing 9999 products keeps every code it already printed.
* **The AI never invents commerce fields.** Price, stock and category are the seller's, and the
  prompt explicitly forbids the model from stating or implying a price. It writes words, not numbers.
* **Voice input is vendor-neutral.** `SpeechToTextInterface` covers any service that takes a
  multipart upload and returns JSON — Whisper, a self-hosted model, an Iranian provider — configured
  by endpoint and API key. No provider is an assumed fallback: Persian speech-to-text must pass a live acceptance gate, otherwise the user receives an explicit unavailable state.

Multilingual stores get **real translated products, linked** when Polylang or WPML is installed.
Without one the translations are stored on the product and can be turned into real products later.

Every long step is asynchronous and must run through a durable, tenant-scoped job. The app polls `GET /intake/{id}`, which reports
`status`, `waiting` and the `next` call to make, so the client never reimplements the state machine.
A webhook settles finished tasks immediately; a five-minute cron sweep is the guarantee for the ones
whose callback never arrived.

## Try it in the browser (WordPress Playground)

No server needed — this boots a throwaway WordPress with WooCommerce and the plugin already
activated, in about 30 seconds:

**[▶ Launch the demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/paymanshafayan/IGBZ-WP/refs/heads/main/_playground/blueprint.json)**

The blueprint lives in [`_playground/blueprint.json`](_playground/blueprint.json).

Caveats, because Playground is not a normal host:

* It runs on **SQLite**, not MySQL. The plugin supports both, but Playground is not a substitute
  for testing on a real MySQL host before going live.
* Outbound HTTP is proxied, so payment gateways and all legacy or target provider calls will not complete
  against real endpoints. Use it to review the admin screens, the database schema and the
  storefront pages.
* Everything is wiped when you close the tab.

## Install

Copy `igbz-suite/` into `wp-content/plugins/` and activate. Requires WordPress 6.3+,
WooCommerce 8.0+, PHP 8.1+ with `openssl`. Full instructions, including the required
`IGBZ_ENCRYPTION_KEY` constant and the real-cron setup, are in
[`igbz-suite/README.md`](igbz-suite/README.md).

## Tests

```bash
php igbz-suite/tests/run.php     # 875 assertions in 19 cases
bash _devenv/test.sh             # the same suite plus a syntax check over 158 files
```

No Composer and no PHPUnit — the runner is dependency-free.
