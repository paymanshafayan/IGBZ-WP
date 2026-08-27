# `_devenv/` — offline test environment

This directory exists for one reason: **the sandbox the agent runs in cannot reach
wordpress.org**, and its `/tmp` is wiped between sessions. Everything needed to boot a real
WordPress + WooCommerce site is therefore kept here, in the repository, where it survives.

Nothing in this directory ships with the plugin. It is developer tooling only.

## What goes here

Two zip files, committed to git:

| File | What it is | Where to get it |
| --- | --- | --- |
| `wordpress-7.0.4.zip` | WordPress core | <https://wordpress.org/latest.zip> |
| `woocommerce-11.0.1.zip` | WooCommerce plugin | <https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip> |

No page builder or third-party theme is bundled or mounted. Elementor and Hello Elementor were
removed from the default environment; the demo deliberately uses WordPress core's Twenty
Twenty-Five theme. The two hidden version-marker files left from the former harness are historical
metadata only and are not read by `setup.sh` or `run.sh`.

The exact WP/WC filenames do not matter. `setup.sh` globs for `wordpress-*.zip` and
`woocommerce-*.zip` and picks the newest match, so `wordpress-7.1.zip` or
`woocommerce-11.0.1-fa_IR.zip` work just as well.

### Zip layout

Prefer the **official wordpress.org zips**, unmodified:

- WordPress: `setup.sh` accepts either layout — files at the root of the zip (`wp-includes/`,
  `wp-admin/`, `index.php`, …) or everything nested inside a `wordpress/` folder, which is what
  the official core zip does. Validation looks for `wp-includes/version.php` either way.
- WooCommerce: everything under a single top-level `woocommerce/` folder.

`setup.sh` validates both and fails loudly with a clear message if the layout is wrong, so a
bad upload is caught immediately instead of producing a confusing boot failure.

## Why these versions

These are the current stable releases, and the plugin header declares `Tested up to: 7.0` /
`WC tested up to: 11.0` to match. WooCommerce 11.x requires WordPress 6.9 or newer, so the two
must be upgraded together.

Upgrading is only ever a matter of dropping in a newer pair of zips and re-running
`bash _devenv/setup.sh --force`; no plugin code changes are involved. The suite has been verified
unchanged on both WP 6.5.5 / WC 9.4.2 / PHP 8.2 and WP 7.0.4 / WC 11.0.1 / PHP 8.3.

## Usage

```bash
bash _devenv/setup.sh          # build the environment (~1-2 min the first time)
bash _devenv/run.sh            # start the site on http://127.0.0.1:9400
bash _devenv/test.sh           # unit tests + lint
bash _devenv/makepot.sh        # rebuild igbz-suite/languages/igbz-suite.pot
bash _devenv/makepot.sh --check  # report whether that template is stale, write nothing
```

`setup.sh` is idempotent and safe to re-run. It never writes inside the repository except to
this directory, and the build output it creates (`_devenv/.work/`) is git-ignored.

If the zips are missing, `setup.sh` says exactly which file it wanted and where to put it, then
falls back to downloading from the network if that happens to be reachable.

## How it works

The Playground CLI normally downloads WordPress from wordpress.org, which is blocked here.
However, its `--wp` flag accepts an **http(s) URL**, and that branch is evaluated *before* the
blocked `api.wordpress.org` version lookup. So `setup.sh` starts a tiny local HTTP server on
port 8799 that serves `wordpress-*.zip`, and points `--wp` at it.

This replaces the previous approach, which monkey-patched `fetch` inside the CLI. No patching
is needed any more.

WooCommerce needs no server: it is simply extracted and bind-mounted into the site as
`wp-content/plugins/woocommerce`. IGBZ Suite is bind-mounted from the repository, and the active
demo theme is the bundled WordPress core theme Twenty Twenty-Five. No Elementor files are staged
or mounted.

## Sample seed data

On first boot, a mu-plugin seeder (`_devenv/.work/mu/100-seed-sample-shop.php`) populates the
site with a cosmetics demo storefront:

- 8 product categories (skin care, hair care, face/eye/lip makeup, perfume, body care, tools).
- 25 simple WooCommerce products with Persian names, prices in IRR, stock counts and a
  locally-generated gradient placeholder image (drawn via PHP GD, no network access).
- 100 customer accounts with Persian first/last names, dummy mobile numbers and Tehran/Karaj
  addresses. The default password for every demo customer is **`Customer123!`**.
- 8 sample orders distributed across customers (processing / completed / on-hold).
- Site title/tagline set to «شاپ بیوتی — فروشگاه نمونه آرایشی».
- Permalink structure set to `/%postname%/`, WooCommerce currency to IRR (right-to-left,
  thousand-separator `,`, no decimals), country default IR, and all four WC pages (shop,
  cart, checkout, my-account) auto-created if missing.

The seed is guarded by the `igbz_seeded_v1` option so it runs exactly once per Playground
instance; wiping `_devenv/.work/` and re-running `setup.sh` will reseed from scratch.

## Translation template

`makepot.sh` rebuilds `igbz-suite/languages/igbz-suite.pot` by running `makepot.php` through the
php-wasm CLI, because `wp-cli i18n make-pot` needs Composer and wordpress.org, and neither is
reachable here. It scans `src/`, collects the literal `__`/`_e`/`esc_html__`/`esc_html_e`/
`esc_attr__`/`esc_attr_e`/`_n` calls carrying the `igbz-suite` domain, and writes the template in
the same format as before (sorted by msgid, one reference per line, no wrapping) so rebuilds stay
diffable. Non-literal calls are reported on stderr and skipped, which is what wp-cli does too.
