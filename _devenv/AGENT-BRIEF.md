# Agent brief — read this first

Hand-off notes for an agent picking this repository up in a fresh session. Everything here was
learned the hard way; following it will save hours.

> **Start with [`PROJECT-STATE.md`](../PROJECT-STATE.md) in the repository root.** That document is
> the single source of truth for what is built, what is decided, and what is still waiting on the
> client. This brief is the deep technical companion to it: subsystem internals, environment
> quirks, and the traps that cost real time. Where the two disagree, the code wins and both get
> corrected.

---

## 1. What this project is

`igbz-suite/` is a **single WordPress plugin containing five toggleable modules**, a faithful port
of the IGBZ product from nopCommerce to WordPress + WooCommerce.

The one intentional functional difference from the nopCommerce original: the **Instagram Graph API
is not used**. It is replaced by two services:

- **Manus** — automated Instagram workflow: niche/trend research, graphic design,
  reels and short video, caption writing, hashtag selection, and auto-publishing/scheduling of
  posts, stories and reels at peak-engagement hours.
- **ManyChat** — DM funnels of the "comment X and I'll DM you the link" kind. Two integration
  paths, both implemented: a **webhook** (real-time, preferred) and the **ManyChat API** (`GET`
  subscriber profile, recent messages, custom fields).

The Instagram gateway sits behind an adapter interface so Graph API can be added back later, but
**no direct Graph calls should be implemented now**.

### Standing constraints

- **The user writes in Persian. Reply in Persian.**
- **A plan, then the literal words «شروع کن» ("start"), before any change.** Nothing in this
  repository — code, docs, settings, however small — may be changed until you have presented a
  plan, the client has approved it, and the client has said **«شروع کن»**. Implicit approval,
  "sounds good", silence, or your own reading of what the client wants is **not** enough. Work
  that changes nothing (reading, checking, testing, analysing, writing the plan itself) is free.
  **Committing, pushing and opening PRs after an approved change is free** and needs no separate
  approval (client's clarification, ۱۴۰۵/۰۵/۲۷; the original ۱۴۰۵/۰۵/۲۴ wording also gated
  commits and pushes; the explicit "شروع کن" keyword was made mandatory on ۱۴۰۵/۰۵/۲۸).
- **No progress reports. One final report only.** Until the plan is completely finished, send
  the client nothing — no status updates, no "step one done", no interim findings.
- **Keep testing light, especially in `vira/`.** For a small change, a few normal tests that
  show the change actually took effect are enough. **The client does the full testing himself.**
  Do not burn time on exhaustive suites (client's rule, ۱۴۰۵/۰۵/۲۹, reaffirmed ۱۴۰۵/۰۵/۲۸).
- **Never modify the `IGBZ-NopCommerce` project.** It was a read-only review, and that review is
  finished (`REVIEW-IGBZ-NopCommerce.md`).
- Tenancy is **single-site with `tenant_id` columns**. Not WordPress Multisite.
- One plugin, five modules — **not** four separate plugins.
- The deliverable is **complete and installable**, never a skeleton.
- **Paid content is delivered by our own customer app**, not by a DM vendor and not by Instagram.
  This was settled after a long detour through DM vendors; ManyChat cannot send video to Instagram
  at all. See §7, "The VIP channel".
- **Two security tiers.** Heavy anti-capture (`FLAG_SECURE`, `isCaptured`, emulator detection,
  device-bound links, watermarking) is for the **LMS/courses** only. VIP gets Close-Friends-level
  protection and no more.

---

## 2. Getting a working environment (start here)

```bash
bash _devenv/setup.sh     # build (~30s if npm is warm)
bash _devenv/run.sh       # site on http://127.0.0.1:9400, auto-logged-in as admin
bash _devenv/test.sh      # 1209 assertions + syntax check on 235 files
bash _devenv/makepot.sh   # rebuild languages/igbz-suite.pot (--check to only report staleness)
```

`_devenv/` contains committed WordPress and WooCommerce zips precisely because **`/tmp` is wiped
between sessions** (this has happened three times) and **wordpress.org is unreachable** from the
sandbox. Do not try to download WordPress or WooCommerce from wordpress.org; it will fail.

Health check while the site runs:

```bash
curl -sL -c /tmp/j.txt -b /tmp/j.txt "http://127.0.0.1:9400/?igbz_health=1"
```

Admin pages need a shared cookie jar and `-L` (the auto-login issues a 302):

```bash
curl -sL -b /tmp/j.txt -c /tmp/j.txt "http://127.0.0.1:9400/wp-admin/admin.php?page=igbz"
```

Boot takes roughly 30–90 s. The debug log lives at
`<vfsroot>/wordpress/wp-content/debug.log`, where `<vfsroot>` is the
`/tmp/node-playground-cli-site-*` directory named in the startup output.

---

## 3. Network reality in this sandbox

| Reachable | Blocked |
| --- | --- |
| `github.com`, `api.github.com`, `codeload.github.com` | **all of `wordpress.org`** (downloads, api, plugins.svn, plugins.trac, playground) |
| `registry.npmjs.org` | `raw.githubusercontent.com`, `release-assets.githubusercontent.com` |
| | jsdelivr, unpkg, esm.sh, statically, ghproxy, raw.githack |
| | gitlab.com, bitbucket.org, packagist.org, deb.debian.org |

Consequences worth internalising:

- **No container runtime, and none installable** (`apt-get` cannot reach Debian; no `/dev/kvm`).
  `@wordpress/env` is a Docker wrapper, so `wp-env` can **never** work here. Do not retry.
- **`php` is not installed as a CLI.** PHP runs through `@php-wasm/cli` under node — that is what
  `_devenv/test.sh` uses.
- `gh release download` fails because the release-asset CDN is blocked, even though `gh api` works.
- If you ever need WooCommerce again from the network, the **only** working route is the
  wordpress.org zip mirror on GitHub, via codeload:
  ```
  gh api "repos/WordPressBugBounty/plugins-woocommerce/commits?path=woocommerce/woocommerce.php&per_page=100" --paginate
  curl -sL -o woo.tar.gz "https://codeload.github.com/WordPressBugBounty/plugins-woocommerce/tar.gz/<sha>"
  ```
  That mirror has no tags; versions live in commit messages. 9.4.2 = `87199f6dbb5e9e477689192fb045a2b9f39fcde6`.
  Prefer the committed zip in `_devenv/`.

---

## 4. Architecture cheat-sheet

Bootstrap `igbz-suite/igbz-suite.php` → `igbz()` → `\IGBZ\Suite\Support\Plugin`. PSR-4 autoloader,
**no Composer**.

**Boot flow.** `boot()` binds core services and hooks `plugins_loaded`@5 → `on_plugins_loaded()`,
which returns early with an admin notice if WooCommerce is absent, then runs
`Activator::maybe_upgrade()`, registers enabled modules, then settings/status pages and cron.

> **Ordering trap:** anything needed *during activation* must be registered at file-load time, not
> inside `on_plugins_loaded()`. This is why `Cron::register_schedules()` is called at load time.

**Modules** (`Modules::all()`): `multitenant`, `instagram`, `hub`, `rest_api`, `fx`. Default
enabled: `multitenant`. Option: `igbz_enabled_modules`.

**Container ids** (the authoritative per-module table is in `igbz-suite/README.md` → *Architecture*)
- core: `settings, logger, db, http, tenancy`
- multitenant: `tenants, wallet, plans, bnpl.providers, bnpl, affiliate, lms, lms.vod, payments,
  otp, legal.nid, marketplace, marketplace.sync, marketplace.mappings, marketplace.basalam,
  logistics, logistics.courier, logistics.labels, master.payment, domain, webpresence, seo,
  translation, translation.adapter, i18n, gamification, gamification.carts`
- instagram: `ig.prompts, ig.credentials, ig.manus_client, ig.manus, ig.scheduler, ig.insights, ig.manychat, ig.subscribers, ig.funnels`,
  plus the DM provider switch (`dm.provider = manychat|chatplace`): `ig.dm, ig.dm_manychat, ig.dm_custom`,
  plus the registration flow: `ig.skus, ig.translations, ig.manychat_bridge, ig.intake, ig.publisher, ig.intake_worker, ig.stt, ig.stt_http, ig.stt_manus`,
  plus the VIP channel: `vip.access, vip.media, vip.posts, vip.social, vip.messages, vip.billing`,
  plus phases 8/14: `ai.studio, ai.credits, giveaways`
- hub: `hub.stats, hub.directory, hub.vip, hub.domains, hub.blocks, hub.signup`
- rest_api: `api.tokens, api.auth, api.devices, api.google_auth, api.push, api.notifications`
- fx: `fx.wallet, fx.accounts, fx.rates, fx.meter, fx.topup, fx.billing, fx.payouts, fx.ramp, fx.reports`

**Admin screens** — 28 of them, all under the top-level `igbz`: `igbz`, `igbz-settings`,
`igbz-tenants`, `igbz-wallet`, `igbz-plans`, `igbz-bnpl`, `igbz-affiliate`, `igbz-courses`,
`igbz-payments`, `igbz-master-payment`, `igbz-logistics`, `igbz-marketplaces`, `igbz-seo`,
`igbz-translator`, `igbz-gamification`, `igbz-domains`, `igbz-fx`, `igbz-ig-accounts`,
`igbz-ig-content`, `igbz-ig-funnels`, `igbz-ig-subscribers`, `igbz-ig-insights`, `igbz-ig-intake`,
`igbz-vip`, `igbz-ai-studio`, `igbz-giveaways`, `igbz-hub`, `igbz-mobile-api`.

**REST**: 199 routes across `igbz/v1` (incl. 14 `/intake/*`, 29 `/vip/*`, `/fx/*`, `/courier/*`,
`/domains/*`, `/master-payment/*`, `/ai/*`) and `igbz-hub/v1`.

**Schema**: 70 tables in `src/Support/Schema.php` (DB version 17; the ladder: v8 `ig_intake`,
v10 nine `vip_*` tables, v11 LMS wiring, v12 `ig_content.ig_shortcode` + funnel reward relabel,
v13 dropped the never-used `jobs` queue, v14 six `fx_*` tables, v15 phases 6–14 tables, v16 the
master-payment/courier/domain/label/legal completion pass, v17 `vip_post_saves` plus the ratified
VIP expiry policy). All carry `tenant_id` except
`tenants`, `tenant_domains`, `tenant_members`, `plans`, `logs`, `lesson_progress`,
`vip_post_likes`, `vip_post_saves`, `vip_post_views`, `fx_rates`, `fx_prices`,
`ig_label_group_items`, `ig_courier_tracking`, `ig_courier_chat` (whitelist in
`tests/SchemaTest.php`). Product/order
tenant scoping uses the meta key `_igbz_tenant_id`.

**Payments**: WooCommerce payment methods `igbz_wallet`, `igbz_bnpl`, and adapter ids
`zarinpal`, `idpay`, `nextpay`, `payir`, `httppsp`, `sadad`, `asanpardakht`, `parsian`,
`irankish`, `mellat`, `saman`, `pasargad`, `sepehr`, `balepay`, `nowpayments` (the bank-gated set
is the `bank_gateway_allowed()` list in `PaymentService`). `Money::to_rial/from_rial` handles the
Toman/Rial factor (`payments.currency_multiplier`, default 10).

---

## 5. Rules that must not be regressed

1. **Always write MySQL SQL.** `$wpdb` speaks MySQL on both engines and the SQLite drop-in
   translates. Only `SELECT … FOR UPDATE` and `GET_LOCK`/`RELEASE_LOCK` need `Db::is_sqlite()`
   branching.
2. **Always pass an explicit `$format` to `$wpdb->insert/update/delete`.** Otherwise core guesses
   from the *column name* via `wpdb::$field_types` and silently forces `post_id`, `user_id`, `ID`,
   `count`, `parent`, `active`, `public`, `deleted`, `spam` to `%d` **on any table**. `Db::formats()`
   derives formats from PHP types; `SchemaTest::assert_no_unsafe_core_column_names()` guards new
   columns. This is a real bug that was already fixed once — `ig_funnels.post_id` holds Instagram
   media ids, which are strings.
3. **dbDelta needs two spaces** in `PRIMARY KEY  (`.
4. Secrets are encrypted at rest. `Settings::set_many()` skips values equal to `Crypto::MASK` or
   `''`, so a masked field round-trips without wiping the stored secret.
5. **Never list-destructure an associative return value.** `AbstractIpgGateway::post_json()` and
   `post_raw()` return `['ok'=>…, 'body'=>…, 'raw'=>…, 'error'=>…]`. Writing
   `[ $ok, $body ] = $this->post_json(…)` asks for keys `0` and `1`, which do not exist: both
   variables become `null`, every successful bank response reads as a failure, and PHP emits
   "Undefined array key". Four gateways (Sadad, Asan Pardakht, Mellat, Parsian) shipped with
   exactly this bug and it was fixed on ۱۴۰۵/۰۵/۳۰. Read by key, and give any method returning an
   associative array a `@return array{…}` shape in its PHPDoc.

---

## 6. Traps that cost time before

- **A 403 "Sorry, you are not allowed to access this page" from `admin.php?page=…` usually means
  the page was never registered** — check module gating before suspecting capabilities.
- The local `.git` has silently lost commits twice when `/tmp` was wiped. **`origin` is the source
  of truth.** Recover with `git fetch origin <branch>` then `git reset --soft <sha>`, and if the
  index looks wrong afterwards, plain `git reset` rebuilds it from HEAD without touching files.
  Verify remote state with `git ls-remote origin <branch>` — `git fetch` does not create a
  remote-tracking ref here.
- Anonymous requests need their **own** cookie jar; the auto-login 302s the first cookieless request.
- `Db::wpdb()` is typed `: \wpdb`, so a test double must literally be `class wpdb`.
- `TestCase::assert_contains` is `(needle, haystack, message)`.
- The lint harness must use `token_get_all($src, TOKEN_PARSE)` — never `eval`.
- `ReflectionMethod::setAccessible()` is deprecated in PHP 8.5; just call `invoke()`.
- Services read settings through `igbz()->settings()`, so tests must call
  `igbz_test_reset_settings()` and double any new WP functions in `tests/bootstrap.php`.
- Heredocs containing non-ASCII can corrupt generated files; write such files with the file tools.
- One error in the debug log during order payment is **WooCommerce core's own** HPOS refund lookup
  (`OrdersTableQuery`, `LIMIT 0, 18446744073709551615`) which the SQLite translator cannot parse.
  It is not caused by this plugin and does not occur on MySQL.

---

`tests/bootstrap.php`'s `do_action()` really dispatches now (callbacks registered via `add_action()`
run in registration order, all args passed, priority not modelled), and the wpdb double records
every write in `$wpdb->writes`, not just `$wpdb->last_write`. Asserting on `last_write` is unsafe
whenever the code under test also logs, because the logger's own insert lands last — search
`writes` for the table you care about.

## 7. Verified behaviour (regression baseline)

Confirmed live on **WP 6.5.5 / WC 9.4.2 / PHP 8.2.32** *and re-confirmed on* **WP 7.0.4 / WC 11.0.1
/ PHP 8.3.32** (SQLite in both cases). Moving between the two is purely a matter of swapping the
zips in `_devenv/` and re-running `setup.sh --force`; no plugin code differs between them.

- 1209 assertions in 23 test cases; 235 files lint clean.
- 28/28 admin screens return 200 with no notices; 70/70 tables (`/?igbz_health=1`); 3 cron hooks
  scheduled.
- All 14+ payment gateways register with WooCommerce and their settings screens render; the
  direct banks show a Test connection button.
- Paying a real order with the wallet gateway debits exactly the order total, moves the order to
  `processing`, sets the transaction id, and credits 2% cashback
  (`wallet.order_cashback_percent`), with a correct running balance in `wallet_ledger`.
- WooCommerce's own admin screens (Home, Settings, Status, Products, Orders) stay clean.
- ManyChat funnel, end to end: wrong/missing token → 401; valid token → 200 with the v2 envelope;
  idempotent per `comment_id`; `per_user_limit` enforced (a capped user receives only the
  "already received" message).
- **Product registration, end to end against real WooCommerce**: photo → refusal with reasons →
  accepted photo → prepared image → description with the seller's own price/stock/category →
  written listing → a real `WC_Product_Simple` carrying the warehouse SKU in its `sku` field, both
  specs as attributes, three tags and the chosen category → an exact-match funnel keyed on the
  *customer code* whose `resolve_link()` reaches the product page → a content row carrying **both
  `product_id` and `funnel_id`** and the keyword in its brief. The last of those is the loop the
  port had left open.
- Registration REST surface: anonymous → 401, a capability-less user → 403, an owner → 200; a real
  multipart upload returns 201 with the file in the media library under a public URL (Manus fetches
  assets over HTTP, so a private directory would not work); missing price, missing category,
  missing description, composing before the product exists, scheduling before composing and an
  unknown post kind are each refused with their own error code.
- **VIP channel, end to end**: create → publish → share link; anonymous `/vip/feed` returns the blur
  with `locked: true` and no source URL; a forged media link is a 403; an owner's signed link 302s to
  storage and 403s for anybody else; the pretty permalink 301s then 200s, an unknown code is a 404
  and an expired one a **410**; the five-minute sweep reported `0 published, 1 expired`; the tip
  floor, the missing-gateway message and a stale nonce each produce their own error; an anonymous
  subscribe 302s to `wp-login.php` carrying `redirect_to`.
- Translation bridge: with no multilingual plugin nothing is published but the copy is kept in
  `_igbz_translations`; with WPML's hooks satisfied two real translated products are created,
  linked by trid, and each carries the original's price, sale price and stock with a
  language-suffixed SKU (WooCommerce enforces SKU uniqueness, so the suffix is not optional).

### Publishing is confirmed, not guaranteed

The Graph API answered a publish call synchronously with a media id: the post either existed or it
did not. Manus publishes through an async task, and a task can stop with status `finished` while
never handing back the post URL. That leaves a row saying `published` with nothing to link to, and
nothing on our side can prove whether the post is live.

The rule: **such a row stays `published`.** Demoting it to `failed` would offer the operator a retry
button on a post that is probably already live, and republishing an Instagram post creates a
duplicate that has to be deleted by hand. Instead the ambiguity is surfaced in three places:

- `ManusService::mark_published()` logs a `warning` on the `manus` channel and fires
  `igbz_ig_content_published_unverified` with the content id.
- The content list renders "No link returned — unverified" under the publish time, and the detail
  screen shows a warning notice telling the operator to check the account before republishing.
- The dashboard's *Content pipeline* card turns WARN and counts the affected rows.

`ManusService::unverified_publish_count( int $account_id = 0 )` derives the count from
`status = 'published' AND permalink = ''`. **Do not add a flag column for this.** A permalink can be
filled in later, by hand or by a retried confirmation, and a stored flag would then be a stale lie
that nobody clears. Held in place by `PublishVerificationTest`.

### "Delivered" means the DM was sent (DB v7)

The same honesty rule as publishing, applied to funnels. The ManyChat External Request action times
out after ~10 s, so `handle_event_async()` computes the reply, answers immediately, and schedules
`igbz_ig_funnel_followup` (+5 s) to do the outbound work. The bug that shipped before v7: the
webhook *also* set `delivered = 1` and incremented `conversions` right there. An account with a
missing or revoked ManyChat key therefore reported a **100% conversion rate while sending nothing**,
and because the row looked delivered the hourly retry skipped it forever.

**`followup()` is the single writer of the outcome.** `handle_event_async()` only records an
attempt. Everything that decides success — `delivered`, `conversions`, the wallet credit, the
`igbz_ig_funnel_delivered` action — goes through `FunnelService::settle()`, whose UPDATE is
conditional on `delivered = 0`. A zero row count means another worker settled it first, so a race
between the scheduled follow-up and the hourly retry cannot double-count a conversion or pay a
reward twice.

`delivery_error` carries the state (no new column, no migration for the shape):

| delivered | delivery_error | meaning |
|---|---|---|
| 0 | `pending` | recorded, nothing attempted yet |
| 0 | `pending_inline` | reply returned in the webhook response for ManyChat to render; the follow-up must **not** send the text again |
| 0 | `per_user_limit` | over the per-subscriber cap — not a fault, never retried |
| 0 | *(message)* | a real failure; retried hourly |
| 1 | `''` | confirmed by a ManyChat API call that succeeded |
| 1 | `unconfirmed` | rendered inline, no API call could prove it arrived |

Consequences worth keeping:

- **A blocked hit returns no link.** Returning one made the cap decorative — the caller put it
  straight in the DM, so the capped person got the URL anyway.
- **`hits` increments for every recorded attempt**, including blocked ones. It used to skip exactly
  the events that did not convert, which flattered the rate.
- **The cap counts in-flight hits too** (`delivered = 1 OR delivery_error IN (pending, pending_inline)`),
  minus the row being inserted. Counting only settled hits left a five-second window in which one
  person could claim two links, or two single-use coupons. A *failed* hit deliberately does not
  count — they received nothing, so commenting again must work.
- **`retry_failed()` also picks up `pending*` rows older than `FunnelService::FOLLOWUP_GRACE`
  (300 s)**, because WP-Cron only fires on traffic and the +5 s event can simply never run on a
  quiet site. The grace period stops it racing a follow-up that is merely late. It calls
  `followup()`, not `deliver()`, so an inline reply is settled rather than DMed twice.
- **`Admin/HitStatus::cell()` is the one renderer** for this column; the funnels and subscribers
  screens both use it. It never prints a raw marker like `per_user_limit` at the operator, and an
  in-flight hit is WARN "waiting to send", not a red failure.
- `FunnelService::delivery_backlog()` splits the last 24 h into pending / failed / blocked /
  unconfirmed and feeds the *Comment funnels* health card, which now warns only on failures and
  unconfirmed sends.

Migration `Activator::migrate_to_v7()` relabels legacy rows: `delivered = 1, error = ''` becomes
`unconfirmed` (they cannot be re-sent honestly — the subscriber may already have the reply, and DMs
are not idempotent), and `delivered = 0, error = ''` becomes `pending` so the retry can see it.
Held in place by `FunnelDeliveryTest`.

**Trap found while verifying this:** `Http::request()` called `wp_remote_retrieve_headers( $r )->getAll()`.
That object only exists when WP_Http built the response — any `pre_http_request` short-circuit
(caching plugins, request mockers, offline harnesses) returns a plain array and core does not
normalise it, so the call was a fatal error. Use `Http::headers_of()`.

### Product registration from the app (DB v9)

The rule that shapes everything here: **a product is never created through the WooCommerce admin.**
The app plus the AI pipeline is the only entry point, which means every failure has to degrade into
something a shopkeeper can act on rather than a dead end.

**One row per registration**, `ig_intake`, is the state machine. Thirteen steps spread over minutes
and many REST round-trips cannot live in a call stack, so each REST call and each webhook moves one
row from one status to the next; a request that dies, an app that is closed or a task that returns
twenty minutes later all resume from where they stopped.

```
uploaded → grading → rejected                    (reasons on the row, seller retries)
                  └→ graded → processing → ready_to_edit → edited
                                                              │
                        transcribing ◄──── describing ◄───────┘
                               └──→ writing → product_created
                                                    │
                                  awaiting_kind ◄───┘
                                        ├─ video → producing_video → video_review ─┐
                                        └─ image ──────────────────────────────────┤
                                                                        composing ─┘
                                                                             │
                                                            published ◄─ scheduled
```

**Services.** `ProductIntakeService` owns the row and the transitions. `ProductPublisher` turns an
approved row into a `WC_Product_Simple`, its funnel and its content row, and `hand_off()` does steps
12–13. `IntakeWorker` is the cron sweep. `SkuGenerator` mints both codes. `TranslationBridge` handles
Polylang / WPML / meta. `ManyChatBridge` primes the page before the first comment arrives.

**`ProductIntakeService` depends on `IntakeAgentInterface`, not `ManusService`.** It uses six of that
class's methods; naming the slice keeps the dependency honest and makes the state machine testable
without the network. `ManusService` implements it.

**Things that will look wrong until you know why:**

- **A refused photo is a success, not an error.** The row goes to `rejected` carrying reasons; only
  an unreadable grader answer is a fault, and even then the photo is *accepted* — refusing somebody's
  photograph because our grader malfunctioned is blaming them for our bug.
- **A score below `intake.quality_threshold` overrules an `accept` verdict.** The setting is the
  store's contract with itself; if the model could ignore it, it would mean nothing.
- **The image step falls back to the seller's original photo** and reuses the existing attachment
  rather than sideloading it again. The photo already passed the quality gate, so the listing is
  worse-looking, not wrong. The *video* step has no such fallback — a video post with no video is
  not a degraded result, it is nothing — so that one fails loudly.
- **Every Manus asset is pulled into the media library immediately.** Attachment URLs are signed and
  expire; a product image that 404s a fortnight later is worse than no automation.
- **Uploads go to the media library, not a protected directory.** Manus fetches assets over HTTP and
  cannot read a path on the server's disk.
- **Two codes, deliberately.** `sku` (`IGBZ-4F2K`) is the warehouse code in WooCommerce's `sku`
  field; `public_code` (`0047`) is the padded product id and is the *only* one a shopper ever sees —
  the overlay, the caption, the funnel keyword and the ManyChat field. Digits, because the shopper
  types it into a comment on a Persian keyboard and a Latin SKU means switching layouts. The floor
  of four digits is a safety margin, not cosmetics: a two-digit code gets typed by accident.
  `FunnelService::canonical()` already folds `۰۰۴۷` to `0047`, so a Persian-digit comment matches.
- **`public_code` is empty until step 8 and that is correct.** It *is* the product id, so it cannot
  exist before the product does. Anything reading it earlier — a caption, an overlay, a funnel — is
  a bug in the caller, and `create_funnel()` refuses to build a funnel from an empty code rather
  than creating one nobody can trigger. Every consumer runs after `publish()`, which is why the
  ordering worked out.
- **Nothing renumbers an existing funnel.** `migrate_to_v9()` back-fills `public_code` on rows that
  already have a product, but leaves old funnels answering their old SKU keyword forever: a post
  already out in the world tells people to comment that string.
- **Match mode is `exact`, not `contains`**: with `contains`, a comment quoting the caption would
  trigger a delivery.
- **A funnel that fails to save is a warning, not a failure.** The product exists and can be sold;
  stranding a good listing over the automation would be the wrong trade.

**The loop the port had left open is now closed.** `queue_post()` passes `product_id` and `funnel_id`
into `save_content()`, which is what makes the previously-dead keyword-injection branch in
`ManusService::generate()` reachable and lets a comment resolve to the right product.

**Speech to text** is `SpeechToTextInterface`. `HttpSpeechToText` covers any vendor that accepts a
multipart upload and answers with JSON — endpoint, key, model, file field, auth header/scheme and a
dotted response path are all settings, so switching provider is configuration. `ManusSpeechToText`
is the fallback and is *asynchronous*, hence `TranscriptionResult::pending()`: three states, because
collapsing pending into failure would tell the seller to retype while the transcript was in flight.
A transcript is **appended** to any typed text, never substituted — a seller who typed the size and
dictated the rest must not lose either half.

**The webhook is the fast path; the cron sweep is the guarantee.** Both call
`IntakeWorker::settle()`, so they cannot drift. A row parked over an hour is failed by
`TASK_TIMEOUT` rather than polling two API calls every five minutes forever.

**ManyChat webhook contract**: `POST /?rest_route=/igbz/v1/manychat/comment`, auth via
`Authorization: Bearer <token>`, `?token=`, or `X-IGBZ-Token`. Body keys:
`text|comment_text|last_input_text`, `subscriber_id|id`, `comment_id`, `post_id|media_id`,
`username|ig_username`.

**The token is the identity (DB v6).** It is per account, stored in
`ig_accounts.manychat_webhook_token` / `manus_webhook_token`, and the tenant is read from the
matched row — a `tenant_id` in the request body is ignored. Before this, one global token plus a
body-supplied `tenant_id` let any authenticated caller fire another tenant's funnels and spend
their coupons and wallet credit.

**Credentials are per account, not per install.** `ig_accounts` carries `manus_api_key` /
`manychat_api_key` (encrypted with `Crypto`) and a `credential_mode`:

- `own` — the account's own keys, unlimited. Never falls back to the shared key.
- `trial` — borrows the operator's `manus.api_key` / `manychat.api_key`, metered by
  `trial.task_quota` (**default 1**, `0` = unlimited) and `trial.days` (default 14). A closed trial
  returns an empty key rather than falling through.

This is forced by the products themselves: a ManyChat API key is scoped by ManyChat to a *single
page*, so one shared key can only ever drive one Instagram account. `AccountCredentials` is the
only place that resolves a key or counts trial usage, so the quota cannot be bypassed.

**The trial is one request.** The quota defaults to a single task: the account sends one thing,
sees the result, and then must bring its own keys. Three consequences that are easy to break:

1. **Claim before calling, never count after.** With a quota of one, the gap between "is the trial
   open?" and "spend it" is exactly wide enough for two cron ticks to both pass. Quota is claimed
   by `AccountCredentials::claim_trial_task()`, whose `WHERE … AND trial_tasks_used < %d` lets the
   database pick the winner; the loser sees zero affected rows and gets `false`. There is no
   `consume_trial_task()` any more — do not reintroduce a check-then-increment pair.
2. **Spending the last task closes the trial immediately**, by stamping `trial_expires_at` with the
   current time, so every read path agrees without re-deriving "used up". Because of that,
   `trial_blocked_reason()` checks *exhausted before expired* — otherwise a used-up trial would
   claim it ran out of time.
3. **A refused provider call is refunded** via `release_trial_task()`, which decrements and reopens
   the window; a network error must not cost a tenant their only free request.

Fair-share scheduling lives in `ContentScheduler::fair_share()` — still needed, because `tick()`
runs once site-wide per cron with a shared `BATCH` ordered by id, so one tenant queueing hundreds
of drafts would otherwise own every tick regardless of whose API key pays for it.

`ContentScheduler::per_account_cap( $account )` is **per account, not global**. An `own` account
buys its own Manus capacity, so the operator has no business throttling it: its cap is
`manus.account_concurrency` only when that is set above 0, otherwise half the batch. A `trial`
account is capped by what is left of its quota. `manus.account_concurrency` now defaults to `0`
and the `igbz_ig_account_concurrency` filter receives `$account` as a second argument.

**Never call `__()` on a code path that can run before `init`.** WordPress 6.7+ answers with a
`_load_textdomain_just_in_time` doing-it-wrong notice. Two such paths existed and were fixed by
guarding on `did_action( 'init' )`: `Cron::add_schedules()` (the `cron_schedules` filter fires
during `plugins_loaded` whenever another plugin — Jetpack's `Nonce_Handler`, for one — schedules an
event) and `Activator::add_roles()` / `seed_defaults()` (reached from `maybe_upgrade()` on
`plugins_loaded`). Both persist their strings, so storing the English original is correct anyway.
`CronScheduleTest` guards the regression.

### The VIP channel (DB v10)

The rule that shapes everything here: **the customer app is the delivery mechanism, not Instagram.**
The public Instagram post is a teaser; the real media lives on storage we control and is only
viewable by an entitled user. Close Friends was the original plan and is dead for three independent
reasons — Instagram disables the share button on Close Friends content, oEmbed has been
public-media-only since April 2025, and list membership has no API. Do not revisit it. Full design,
in Persian, in `DESIGN-VIP.md` at the repo root.

**Two tiers of security, and they are not the same tier.** VIP is deliberately *light*: the most we
promise is what an Instagram Close Friends post gives you. Screenshots are possible and that is
accepted. The heavy machinery — `FLAG_SECURE`, `isCaptured`, emulator detection, device-bound
links, watermarking — belongs to the **LMS/courses** section only. Applying it to VIP would be
re-litigating a decision already made.

**Ten tables**, all `vip_*`: `plans`, `memberships`, `posts`, `post_likes`, `post_saves`,
`post_comments`, `post_views`, `entitlements`, `threads`, `messages`. `vip_post_likes`,
`vip_post_saves` and `vip_post_views` carry **no `tenant_id`** on purpose — they are pure join rows
and the tenant belongs to the post. They are whitelisted in `SchemaTest::$unscoped`; adding a tenant
column to them is a regression, not a fix.

**Expiry is platform policy (v17).** A post is deleted from the server after
`vip.default_expiry_days` (7), the window is the IGBZ senior admin's alone, and the store admin's
editor no longer offers `expires_at`/`expiry_action` — it states them. Both facts are printed to
the buyer *above* the buy buttons, because the whole risk is somebody paying on day six. The save
icon is the compensation, and it is real: `vip_post_saves` plus `GET /vip/posts/{id}/offline`, which
hands an entitled member a longer-lived signed link so the app can keep a private copy. `offline_at`
records that the bytes were actually fetched — a bookmark alone does not survive the purge. **This
offline path is VIP-only; the LMS must never get one.** Rationale: `DESIGN-VIP-EXPIRY.md`.

> `VipLandingPage::render()` ends in `exit`, so it cannot be called from the test process — doing so
> kills the whole suite (the buffered page then leaks into the runner's output, which is the
> symptom you will see). Test the pieces through reflection and check placement on the live site.

**Services** (`src/Modules/Instagram/Vip/`), wired in `InstagramModule::bind_services()` as
`vip.access`, `vip.media`, `vip.posts`, `vip.social`, `vip.messages`, `vip.billing`:

| Service | Owns |
| --- | --- |
| `VipAccessService` | who may see a post — author / member / purchased / free. Returns a `VipAccess` value object, never a bool. |
| `VipPostService` | CRUD, shortcodes, scheduling, expiry, feed assembly. |
| `VipMediaService` | short-lived HMAC-signed URLs, purge on expiry. |
| `VipSocialService` | likes, comments and replies, saves, view counts. |
| `VipMessageService` | in-post direct messages to the admin. |
| `VipBillingService` | subscribe, buy-one-post, tips. |
| `VipLandingPage` | the public `/vip/p/{shortcode}` share page. |

**Things that will look wrong until you know why:**

- **Access is re-checked when the media is served, not only when the feed is built.** A signed URL
  proves *who asked*; it does not prove they are still entitled. A membership that lapses inside the
  15-minute TTL must stop working, so `handle_request()` calls `check_row()` again before
  redirecting. The signature and the entitlement answer two different questions.
- **The signature binds the user id**, and `handle_request()` compares it to
  `get_current_user_id()`. A link pasted into a group chat is therefore useless to everyone else —
  verified live: the owner's URL 302s to storage, the same URL anonymously is a 403.
- **`purchase_post()` refuses to charge an active member.** Their membership already grants the
  post, so taking the money would be selling something they own. The guard honours
  `check_row(...)->allowed`, not just the entitlements table. A test caught this as a real bug.
- **Expiry has two behaviours and `hide` is the default.** `delete` purges the media, and
  `VipMediaService::purge()` refuses any path outside the uploads directory — an expiry job that can
  be pointed at `/etc` is a much worse bug than a file left behind.
- **The share page is public by design and leaks nothing.** It renders the blur, never the real URL.
  Anonymous `/vip/feed` behaves the same way: `locked: true`, blur present, source absent.
- **An expired share is 410, not 404.** The post existed; saying so is the honest answer and stops
  the app from treating it as a bad link.
- **Never run `wp_kses_post()` over admin markup containing form controls.** It strips every
  `<select>` and `<input>`, which is exactly how the VIP post editor once shipped as a column of
  bare labels. Escape the values, not the form.
- **`esc_url()` drops the app's custom scheme** unless you pass the allow-list, so the deep link
  `igbz://vip/p/{code}` becomes `href=""`. Use `VipLandingPage::allowed_schemes()`. A test pins it.
- **The starter plan is seeded from `activate()` as well as `migrate_to_v10()`.** Only migrating
  leaves a fresh install with no plan at all, which makes the landing page offer a subscription
  nobody can buy.
- **Tips are for public posts.** They are a third revenue path next to the subscription and the
  single-post purchase, not a fallback when the other two are unpriced — when nothing is priced the
  offers block is suppressed rather than rendered empty.

Billing settles on the existing **`igbz_payment_verified`** hook and does not touch
`PaymentService::settle()`, which still handles only `wallet_topup` and `order`. Scheduling and
expiry ride the five-minute cron (`publish_due()` / `expire_due()`), with a "Run now" button on the
admin screen for when you do not want to wait. `tests/VipChannelTest.php` covers 21 scenarios
against an in-memory `VipDb` double; as everywhere else in this suite, module container bindings are
not registered in the harness, so it builds the service graph by hand.

### Quizzes, certificates and refunds (DB v11)

Three things in the LMS were built but never connected. They are connected now, and the
connections are load-bearing — read this before touching `LmsService`.

**Quizzes had no delivery surface.** `submit_quiz()` existed, graded correctly, and had *no
caller anywhere*. Quizzes could be authored in the admin and no learner could ever reach one.
There are now two surfaces, and they share every rule because the rules live in the service:

- `[igbz_course]` renders each quiz under its lesson (`quizzes.lesson_id`), or after the lesson
  list as the final assessment when `lesson_id = 0`.
- `GET /account/courses/{id}/quizzes` and `POST /account/quizzes/{id}/submit` for the app.

The one invariant: **`questions_for_client()` is the only sanctioned way to get a quiz out of the
database and towards a client.** The raw `questions` column contains the answer key, so anything
that hands the column to a template or a REST response is a leak. It also normalises the two
question shapes the admin JSON box accepts (`q` or `question`) and flags list answers as
`multiple` so the form knows to draw checkboxes.

`submit_quiz()` checks enrollment itself rather than trusting its callers — three surfaces
applying the same rule is a rule applied in two. Attempt limits are a *ceiling*, not a default:
`lms.max_quiz_attempts` caps whatever the quiz asks for, so a course author cannot type 99 into
the form and hand themselves unlimited retries. A quiz may be stricter, never more generous.
`pass_score` falls back quiz → course → `lms.pass_score`, because a quiz saved with an empty box
stored 0, and `$score >= 0` passed a blank answer sheet.

**`lms.certificate_enabled` did nothing.** It was written by the settings screen and read by no
code, and `refresh_progress()` minted a certificate the moment progress hit 100% — so a student
who scrubbed to the end of every video and failed every quiz got one. A certificate now needs all
of: every lesson done, **every quiz on the course passed**, the course's own box ticked, and the
site setting on. `maybe_issue_certificate()` runs on every `refresh_progress()` *and* after a
passing submission, because passing the last quiz is frequently the event that earns it — and it
returns the existing code when there is one, so re-opening the page cannot mint a second.

Certificates are verifiable at `/{lms.certificate_slug}/{CODE}` (`Lms/CertificatePage.php`), built
on the same rewrite-rule pattern as `VipLandingPage` — `add_rewrite_rule` on `init`, a `query_vars`
filter, a `template_redirect` responder and a `?igbz_certificate=` fallback for plain permalinks.
An unknown code returns **404, not a friendly 200**: a verification tool that answers "no" with a
success status will eventually be scripted by somebody who only reads the status code.

**A refund left the enrollment behind** — buy, watch, refund, keep, for free. `on_order_reversed()`
now also calls `revoke_from_order()`, gated on `lms.revoke_on_refund`. Two details matter:

- Revocation matches on `enrollments.order_id`, so refunding one order never touches a manual
  enrollment or a second purchase of the same course.
- **Partial refunds do not change the order status**, so the status hooks never fire for one.
  `woocommerce_order_refunded` is hooked separately and checks
  `get_qty_refunded_for_item() < 0` per line, so refunding the shipping on an order that also
  contains a course does not cost the customer the course.

The row is **deleted, not flagged**: `enrollments` has UNIQUE `(course_id, user_id)` and `enroll()`
returns the existing id when it finds one, so a soft-deleted row would lock the customer out of
ever buying the course again. `lesson_progress` is deliberately kept.

The **v11 migration** withdraws certificates that the old rule issued but the new one would not —
the code is cleared, the completion is not — and anyone who has in fact passed everything gets
theirs back on their next visit. Withdrawing is the safe direction: a certificate wrongly issued
is a claim we cannot stand behind, one wrongly withheld returns by itself. It is written as a read
plus per-row updates rather than one correlated `UPDATE`, which has to survive the SQLite
translator.

`tests/LmsTest.php` covers 17 scenarios against an in-memory `LmsDb` double. Two of them are
regressions from bugs the unit tests could not have caught and a live click-through did:

- The post/redirect/get after grading used `wp_get_referer()`, which **returns `false` when the
  referer equals the current URL** — exactly the case for a form that posts to itself. Every
  submission landed the student on the home page. The destination is now passed in explicitly.
- `Logger::info()` is `(channel, message, context)`; it was called with an array as the message
  and fatalled the whole refund path. Nothing in the unit suite exercises the WooCommerce hook.

A third live find was pre-existing: `save_course()` read `$data['level']` twice
(`in_array( $data['level'] ?? 'beginner', … ) ? (string) $data['level'] : …`), so the coalesce
satisfied the test and the cast then read a missing key, warning on every save that omitted a
level. The harness gained a `wp_kses_post()` stand-in to test this — it models the one rule that
has actually bitten us, that KSES strips form controls.

---

## 7b. Post identity and funnel precedence (DB v12), and the dropped queue (v13)

**The reward reason.** A funnel's wallet reward used to post under `affiliate_commission`, the same
reason the affiliate programme uses, so the two were indistinguishable in the ledger. There is now
a dedicated `instagram_reward`. `migrate_to_v12()` relabels only rows whose reference matches
`ig_funnel:*`; real commissions use `commission:<id>` and are never touched. The rewrite is safe
against the ledger's `UNIQUE (tenant, user, reason, reference)` key because the reference is unique
per hit on its own, and it cannot cause a double payment because `settle()` claims the hit row
before crediting.

**`PostIdentity`.** One post can be written many ways: a bare shortcode, `/p/<code>/`,
`/reel/<code>/`, with or without the username segment, with or without a tracking query. The
operator typed one spelling into a free-text box and ManyChat sent another, so a funnel could
silently stop firing. `PostIdentity::from_permalink()` reduces every spelling to its shortcode;
`match()` compares on that. `''` means *unknown* and never acts as a wildcard. The funnel form now
offers a picker of posts published through this plugin, falling back to free text when nothing has
been published yet — and a pasted URL is normalised on save.

**A pre-existing precedence bug this uncovered.** The ordering read
`ORDER BY (post_id <> '') ASC`, which sorts catch-all funnels *first*, so one broad funnel shadowed
every per-post campaign on the account. It is now `(post_id = '') ASC`. This had been wrong for
months and no test saw it, because the in-memory `FunnelDb` double ignored both the `IN` filter and
the `ORDER BY` and simply returned every row in insertion order.

> **The lesson worth carrying:** a double that hard-codes the *intended* ordering agrees with the
> query whichever way round the comparison is written. `FunnelDb` now derives its sort key from the
> comparison the SQL actually makes, so inverting the expression fails the test. Verify this by
> mutation, not by reading.

**v13 drops `jobs`.** It was a general-purpose worker queue — handler, payload, attempts,
max_attempts, available_at, reserved_at. Nothing ever enqueued a row and no runner ever dequeued
one; the only code touching it was the daily sweep deleting completed rows that could not exist.
Every background job here is already durable through a queue that models its own work:
`ig_product_intake` for intake, `ig_content` for generation and publishing, `ig_funnel_hits` for
delivery — each with its own retry counter and `last_error`, driven by the cron ticks. It was
removed rather than given a runner: a runner is only worth its failure modes once something needs
to enqueue. The DDL is in the git history if a future subsystem wants it.

---

## 7c. FX (v14), phases 6–14 (v15), the completion pass (v16)

### FX gateway (module `fx`, DB v14 — six tables)

- **Dollar wallet, Rial top-up.** `fx.fee_percent` (10%) is charged *on the currency*, converted
  at a rate locked into `fx_rates` at top-up time, paid through the Iranian gateways with
  `purpose=fx_topup`, and only the net amount is credited. Credit happens on
  `igbz_payment_verified` with the same idempotency-key discipline as the wallet.
- **Metering is synchronous, not queued.** `ManusService::dispatch()` checks the tenant's own
  balance at dispatch: enough → task goes out; not enough → rejected immediately with a
  "top up" message; a task Manus refused refunds the amount (`release`). Same for ManyChat in
  `FunnelService::settle()` — a metered `ig_funnel_hits` row is claimed before the charge.
- **Bills.** Monthly bills per active `fx_account`, settled by a daily cron through the payout
  adapter; failure reverses the charge and leaves the bill `due`. All of it is ledger-based
  (`fx_*` ledger tables) — never stored balances.
- **Payout adapters.** `FxPayoutAdapterInterface` + `igbz_register_fx_payout_providers`:
  `PstNetPayoutAdapter` (primary; the card is held by the registered Cyprus company — this is the
  legal layer), `RedotPayPayoutAdapter` (pilot). `fx.payout_provider` switches. Manual settlement
  button and a webhook (`POST /igbz/v1/fx/payout-webhook/{provider}`) are the always-on fallbacks.
- **USDT on-ramp.** `HttpRampAdapter` defaults to Nobitex endpoints; every buy is an operator
  ledger entry (tenant 0, reason `ramp`). `fx.ramp_enabled` defaults to **false** on purpose —
  Iranian exchanges need a manual approval per withdrawal.

### Phases 6–14 (DB v15, then v16)

- **Gate the banks, not the wallets.** The direct-bank IPGs and the original four PSPs are hidden
  from checkout until the tenant has a DNS-verified standalone domain **and** `legal.enamad_active`
  is on. Wallet, BNPL, FX, BalePay and NowPayments are *not* gated — a store that has not yet
  passed legal onboarding must still be able to take money.
- **`AbstractIpgGateway`** is the base for all eight bank adapters; it type-hints
  `IGBZ\Suite\Support\Http`, so **every adapter file needs `use IGBZ\Suite\Support\Http;`** — an
  adapter that forgets it dies with a `TypeError` at payment time, not at save time (this actually
  happened and cost a round).
- **Sandbox-friendly by construction.** Bank gateways parse *malformed* responses without fatal
  errors, but the parse paths emit `Undefined array key` warnings when a probe response is
  incomplete — expected noise in tests (`IpgAdaptersTest`), not a bug to fix blindly. If you make
  these parsers stricter, the test suite is the place to prove it.
- **Master payment (escrow).** `ig_master_*` tables; a daily (release) cron moves held funds on
  delivery confirmation; disputes block release; a digital agreement row is written per
  transaction before funds move. Withdrawals reserve from the wallet first, then become
  withdrawable — REST: `POST /master-payment/withdraw`.
- **Courier app.** Sequential routing: the `arrived` button opens the *next* shipment in the
  route. Delivery requires the customer-held PIN (`random_int`, `hash_equals`); the PIN is the
  same one printed on the label (barcode + PIN on `ig_label_groups`). COD supports four forms
  (cash/gateway/card reader/in-app). Tracking rows and chat are append-only logs, not mutable
  state.
- **Domains.** `DomainService` search/register/subdomain/DNS-verify/transfer — all behind
  `domain.provider*` keys (no real registrar wired yet). `WebPresenceService` registers verified
  domains with Google/Bing webmaster tools. DNS verification must confirm via a real lookup in
  production; in tests it is stubbed.
- **Legal.** `NationalIdVerifier` (Shahkar) refuses to enable itself until the senior admin
  stores `legal.shahkar_api_key` — the lock is deliberate and must not be bypassed by defaulting
  the check to "pass".
- **ChatPlace.** `dm.provider = manychat|chatplace`; ManyChat stays implemented as the inactive
  fallback. ChatPlace is the chosen provider (flat price, built-in AI agent, VIRALE, MCP later).
- **i18n/SEO.** `I18nService` is a config endpoint, not a full translation memory.
  `SeoService` can write generated meta onto **real products** (the `igbz-seo` picker) — the nop
  gap. Feeds are served from the real catalog, never from a template string.

### DB v16 accounting

69 tables total. Unscoped (deliberately, keep them in the SchemaTest whitelist):
`plans`, `logs`, `tenants`, `tenant_domains`, `tenant_members`, `lesson_progress`,
`vip_post_likes`, `vip_post_views`, `fx_rates`, `fx_prices`, `ig_label_group_items`,
`ig_courier_tracking`, `ig_courier_chat`.

---

## 8. Translations (`.pot`)

`wp-cli i18n make-pot` is not installable here (no Composer, and wordpress.org is blocked), so the
template is rebuilt by **`bash _devenv/makepot.sh`** — `_devenv/makepot.php` run through the same
php-wasm CLI the tests use. `--check` reports staleness without writing, which is what you want in
a review pass.

Things to know before you touch it:

- It scans **`src/` only** and recognises the five gettext calls this plugin actually uses:
  `__`, `_e`, `esc_html__`, `esc_html_e`, `esc_attr__`, `esc_attr_e` and `_n`. If you introduce
  `_x`/`_nx`, add them to `FUNCTIONS` in the script *and* teach it to emit `msgctxt` — it does not
  today, because no string in this plugin has ever needed one.
- **Only literal strings are extracted.** A call whose text or domain is built from a variable or a
  concatenation is skipped with a warning on stderr, exactly as wp-cli would skip it. A warning
  there means the string is untranslatable in practice, not that the tool failed.
- The output format is pinned to the file that shipped before — sorted by msgid, references sorted
  one per line, no wrapping, no `msgctxt`, no translator comments — so a rebuild produces a
  readable diff instead of a whole-file rewrite. It was validated by regenerating the template at
  commit `b917913` and diffing: the only differences were the `FunnelsPage` strings that the
  shipped `.pot` had missed since `92df5da`.
- `POT-Creation-Date` is the one line that always changes; the staleness check ignores it, so an
  up-to-date template is left untouched rather than re-stamped.

---

## 9. Git

Work happens on the session branch; push only to that branch. Never rewrite `main`.
Keep generated artifacts out of git — `_devenv/.work/` is ignored, and the two zips in `_devenv/`
are the deliberate exception to the `*.zip` ignore rule.
