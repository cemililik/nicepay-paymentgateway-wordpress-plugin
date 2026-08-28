# Build, Release & Repository Hygiene

> [!WARNING]
> **Frozen historical snapshot.** This review describes baseline commit `5855db1` (2026-08-19), before the 2.0 remediation work. Its line references and present-tense security claims do not describe the current code. Use `docs/analysis/pr-review2/` and current tests for release decisions.

This document covers the eleventh dimension of the NicePay Payment Gateway review: how the plugin is built, versioned, tested in CI, packaged, published, and how the repository presents itself to contributors and security researchers. The pipeline itself is small and, in several respects, better engineered than comparable projects — Action SHAs are pinned, permissions are minimal, and the release zip's contents and folder name are correct for WordPress. But the surrounding release engineering has three structural holes that matter for a payment gateway: **there is no update mechanism of any kind**, so a merchant who installs 2.0.0 will never be offered a security patch; **the plugin never declares HPOS compatibility**, so WooCommerce's own admin UI lists it as incompatible with the default order storage even though the code is fully compliant; and **the shipped translation catalogues are stale by 73 strings**, silently regressing an advertised headline feature across all four locales. Twenty-one findings follow — 3 high, 7 medium, 11 low — with a version-bump touchpoint table, a CI matrix gap table, and a missing-files checklist.

---

## What this codebase does well

Before the findings, the parts of this pipeline that are genuinely above average and should not be "fixed":

| # | Strength |
|---|----------|
| 1 | **Actions are pinned to full commit SHAs** with version comments in both workflows — e.g. `actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2`. This is solid supply-chain hygiene against tag-mutation attacks that many far larger projects skip. |
| 2 | **`permissions: contents: write`** (release.yml:8-9) is scoped minimally at the workflow level rather than inheriting broad default token permissions. |
| 3 | **The release zip's contents are correct.** The build step (release.yml:47-55) excludes `tests/`, `.github/`, `composer.json`/`composer.lock`, `phpunit.xml` and docs source from the shipped plugin, and the top-level folder name (`nicepay-payment-gateway`) matches the plugin slug — satisfying WordPress's requirement for the uploaded-zip folder name. |
| 4 | **`composer.json` sets `"type": "wordpress-plugin"`** correctly (a common oversight) and cleanly separates a dependency-free `require` (`php: >=7.4` only) from `require-dev` testing libraries — so nothing a merchant installs carries a third-party PHP dependency. |
| 5 | **Language files are committed as complete build artifacts.** All four locales' compiled `.mo` files are checked into git (required at runtime — gettext needs `.mo`, not `.po`), alongside their source `.po` and the `.pot` template. Many plugins ship only `.po` and wonder why nothing translates. |
| 6 | **`.gitignore` correctly excludes** `vendor/`, `node_modules/`, and build/zip artifacts, and no `.env`, credentials, or private-key files are tracked anywhere in the repository. |
| 7 | **Security-disclosure guidance is correct where it exists.** CONTRIBUTING.md:277-288 directs researchers to GitHub Security Advisories rather than public issues, and line 262 explicitly warns bug reporters never to paste MID/Merchant Key into issues — solid awareness of the payment-plugin threat model. |
| 8 | **The one true third-party runtime dependency is guarded.** `assets/js/nicepay.js` detects a missing/failed `nicepayStart` global (the vendor CDN script), re-enables the pay button and surfaces a notice rather than hanging or throwing — good defensive frontend engineering. (The *message* it shows is wrong; see RELEASE-16.) |

---

## 1. Release-pipeline walkthrough, with the problems inline

The entire release process is `git tag vX.Y.Z && git push --tags`. Here is what actually happens, step by step, and where it breaks down.

### Step 0 — What triggers the release (and what doesn't)

```yaml
# .github/workflows/release.yml:3-6
on:
  push:
    tags:
      - 'v*'
```

```yaml
# .github/workflows/tests.yml:3-7
on:
  pull_request:
    branches: [ main ]
  push:
    branches: [ main, development ]
```

A tag push matches neither `pull_request` nor `push: branches`. The `release` job (release.yml:12) declares no `needs:`, no `workflow_run` gate, and runs no PHP at all. **Nothing verifies the code even parses before a public release is published.** → RELEASE-07.

### Step 1 — Version extraction

```yaml
# .github/workflows/release.yml:20-22
- name: Extract version from tag
  id: version
  run: echo "VERSION=${GITHUB_REF#refs/tags/v}" >> $GITHUB_OUTPUT
```

`steps.version.outputs.VERSION` is consumed only at line 42 (zip filename), line 27 (changelog awk key) and line 66 (release title). **No step ever reads `nicepay-payment-gateway.php`.** The tag can disagree with the `Version:` header (line 6) and with `NICEPAY_VERSION` (line 22) and the release will publish happily. → RELEASE-05.

### Step 2 — Changelog extraction, with a silent fallback

```yaml
# .github/workflows/release.yml:28-36
CHANGELOG=$(awk -v ver="$VERSION" '
  $0 ~ "^## \\[" ver "\\]" { found=1; next }
  /^## \[/ { if (found) exit }
  found { print }
' CHANGELOG.md)

if [ -z "$CHANGELOG" ]; then
  CHANGELOG="Release v${VERSION}"
fi
```

If `CHANGELOG.md` has no `## [x.y.z]` heading matching the tag, the release body silently degrades to the string `Release v2.0.1` with no warning and a green check. This should be a hard failure. → RELEASE-05, RELEASE-06.

### Step 3 — Build

```yaml
# .github/workflows/release.yml:45-58
mkdir -p build/${PLUGIN_SLUG}

cp -r admin/ build/${PLUGIN_SLUG}/
cp -r assets/ build/${PLUGIN_SLUG}/
cp -r includes/ build/${PLUGIN_SLUG}/
cp -r templates/ build/${PLUGIN_SLUG}/
cp -r languages/ build/${PLUGIN_SLUG}/ 2>/dev/null || mkdir -p build/${PLUGIN_SLUG}/languages
cp nicepay-payment-gateway.php build/${PLUGIN_SLUG}/
cp README.md build/${PLUGIN_SLUG}/
cp LICENSE build/${PLUGIN_SLUG}/
cp CHANGELOG.md build/${PLUGIN_SLUG}/

cd build
zip -r ../${PLUGIN_SLUG}-${VERSION}.zip ${PLUGIN_SLUG}/
```

This is *mostly right* (see strength #3). Three gaps: `docs/`, `CONTRIBUTING.md` and `CODE_OF_CONDUCT.md` are not copied, breaking seven relative links in the bundled README (RELEASE-11); `languages/` is copied verbatim from git rather than regenerated, so the stale `.pot`/`.mo` problem is baked into every release (RELEASE-03); and no `wp i18n make-pot`/`msgfmt` step exists to catch it.

### Step 4 — Publish

```yaml
# .github/workflows/release.yml:63-70
- name: Create GitHub Release
  uses: softprops/action-gh-release@da05d552573ad5aba039eaac05058a918a7bf631 # v2.2.2
  with:
    name: v${{ steps.version.outputs.VERSION }}
    body_path: /tmp/changelog.txt
    files: ${{ env.ZIP_FILE }}
```

One artifact, no `.sha256`, no build attestation (RELEASE-18). And this is where the pipeline ends — **there is no step, and no code anywhere in the plugin, that would ever tell an installed site that this release exists** (RELEASE-01).

### Step 5 — What the merchant experiences

There is no step 5. The merchant must revisit GitHub manually, download the zip, and re-upload it. And when they do, `register_activation_hook` does not fire on an in-place update, so `create_tables()` never re-runs and no schema migration is possible (RELEASE-08).

---

## 2. Findings

### HIGH

---

#### RELEASE-01 · No update mechanism and no `Update URI:` header — merchants never see security patches

`HIGH` · `distribution` · CONFIRMED · effort: medium

**Where:** [nicepay-payment-gateway.php:1-16](../../nicepay-payment-gateway.php#L1) (header) · [.github/workflows/release.yml:63-70](../../.github/workflows/release.yml#L63)

**Problem.** The plugin header (lines 1-16) contains Plugin Name/URI, Description, Version, Author, Text Domain, Domain Path, Requires at least, Requires PHP, WC requires at least, WC tested up to, License — and nothing else. A grep for `Update URI|update_plugins|site_transient|pre_set_site_transient|plugins_api|puc_|plugin-update-checker` over the whole tree returns **zero matches**. `release.yml`'s only output is a zip attached to a GitHub Release (lines 63-70). There is no `readme.txt`, and the wordpress.org API confirms the slug is not hosted there (`request[slug]=nicepay-payment-gateway` → `{"error":"Plugin not found."}`). **No channel of any kind delivers updates.**

**Evidence.** `grep -n "Tested up to|Requires at least|Requires Plugins|Update URI|Network:"` against the header matches only line 11 (`Requires at least: 5.0`). Zero matches for any update-checker plumbing across all PHP files.

**Impact.** Every merchant who follows README.md:41-45 ("Manual Upload": download the ZIP, Plugins → Add New → Upload) will never see an "Update available" notice in wp-admin, no matter how many signature-verification or SSRF fixes are tagged afterward. For a plugin that holds live merchant keys in the options table and performs SHA-256 payment signature verification, an indefinitely-unpatched, update-invisible install is a real long-tail security exposure across the whole installed base.

> **User scenario.** The maintainer tags v2.0.1 fixing a signature bypass. Every existing merchant stays on 2.0.0 forever unless they happen to revisit the GitHub repo.

**Recommendation.** Vendor `YahnisElsts/plugin-update-checker` (MIT, no server component) into `includes/lib/`, and wire it in `NicePay_Payment_Gateway::init_hooks()`:

```php
PucFactory::buildUpdateChecker(
    'https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin/',
    NICEPAY_PLUGIN_FILE,
    'nicepay-payment-gateway'
)->getVcsApi()->enableReleaseAssets();
```

wp-admin then resolves updates from the tagged GitHub Release assets `release.yml` already produces. No build change is needed — `cp -r includes/` already covers `includes/lib/`. Also add `Update URI:` to the header (see RELEASE-10).

*Severity note (from verification): downgraded critical → high. Every sub-claim was independently verified, but a "critical" must be money loss, an active compromise, or total feature failure. This is a distribution gap creating a latent security exposure; the plugin works fine today.*

---

#### RELEASE-02 · No HPOS (`custom_order_tables`) compatibility declaration — WooCommerce flags the gateway as incompatible

`HIGH` · `wc-compatibility` · CONFIRMED · effort: trivial

**Where:** [nicepay-payment-gateway.php:66-90](../../nicepay-payment-gateway.php#L66) (`init_hooks`) · [nicepay-payment-gateway.php:173-184](../../nicepay-payment-gateway.php#L173) (`init_woocommerce_gateway`)

**Problem.** `grep -rn "declare_compatibility|FeaturesUtil|before_woocommerce_init|custom_order_tables|cart_checkout_blocks" --include="*.php"` over the entire tree returns **zero matches**. Since WooCommerce 8.2, extensions must declare HPOS compatibility on `before_woocommerce_init`. Undeclared plugins are listed under "Incompatible plugins" in **WooCommerce → Settings → Advanced → Features → Order data storage**, and WooCommerce warns the merchant (and in some flows refuses) when enabling HPOS with them active.

The irony: **the plugin is already HPOS-safe.** Every order access uses CRUD APIs — `wc_get_order()` (class-nicepay-gateway.php:92, 108, 241, 419; class-nicepay-transactions.php:200, 226), `$order->update_meta_data()` (:153, 154, 363, 364, 367), `$order->get_meta()` (:425, 430). There is not a single `get_post_meta`/`update_post_meta` call or direct `shop_order` query in the codebase.

**Evidence.** `init_hooks()` (lines 66-90) registers `plugins_loaded`, `wp_enqueue_scripts`, `init`, `template_redirect`, the shortcode, four AJAX actions and one admin filter — and no WooCommerce feature declaration.

**Impact.** HPOS is the default order storage for new WooCommerce installs since 8.2 and the primary path in WooCommerce 11 (current release, verified live). A merchant evaluating this gateway sees it named in WooCommerce's own admin UI as incompatible with their order storage. For a payment gateway that is an adoption blocker — and it is purely a missing declaration for code that already complies.

**Recommendation.** Add at file scope, next to the activation hooks around line 478:

```php
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', NICEPAY_PLUGIN_FILE, true
        );
    }
} );
```

Separately evaluate `declare_compatibility( 'cart_checkout_blocks', … )` — **but only after** building a real `AbstractPaymentMethodType` Blocks integration. Cart/Checkout Blocks have been the default checkout since WC 8.3 and there is no Blocks registration anywhere here (`woocommerce_blocks_payment_method_type_registration` has zero matches). Declaring it without the integration would be a false claim.

---

#### RELEASE-03 · The shipped `.pot` and all four `.po`/`.mo` files are stale: 73 source strings have no entry, 22 entries are orphaned

`HIGH` · `i18n-build` · CONFIRMED · effort: medium

**Where:** [languages/nicepay-payment-gateway.pot](../../languages/nicepay-payment-gateway.pot) and all four `.po` (157 msgids each) vs. 208 extracted from source · [admin/class-nicepay-admin.php:363](../../admin/class-nicepay-admin.php#L363) · [templates/standalone-payment-form.php:111](../../templates/standalone-payment-form.php#L111) · [templates/standalone-payment-form.php:303](../../templates/standalone-payment-form.php#L303)

**Problem.** The catalogue was re-extracted with `xgettext -L PHP -k__ -k_e -kesc_html__ -kesc_html_e -kesc_attr__ -kesc_attr_e -k_n:1,2 -k_x:1,2c` over all non-vendor, non-test PHP and diffed against the committed `.pot`:

| Measure | Count |
|---|---|
| msgids extracted from source | 208 |
| msgids in committed `.pot` | 157 |
| **Missing from `.pot` (present in source)** | **73** |
| **Orphaned in `.pot` (gone from source)** | **22** |
| msgids in each of the four `.po` | 157 (identical stale set) |
| entries in each compiled `.mo` | 157 |

Three missing strings spot-verified, all using the correct text domain:

```php
// admin/class-nicepay-admin.php:363
esc_html_e( 'Create your first payment shortcode to get started.', 'nicepay-payment-gateway' )

// templates/standalone-payment-form.php:111
esc_attr_e( 'Enter your email', 'nicepay-payment-gateway' )

// templates/standalone-payment-form.php:303
esc_js( __( 'Connection error. Please check your internet.', 'nicepay-payment-gateway' ) )
```

`grep` for any of these three across `languages/*.po` and `languages/*.pot` returns nothing.

Other missing strings include the **entire Shortcode Manager admin UI** — `Create New`, `Delete Shortcode`, `Display Mode`, `Live Preview`, `Button Color`, `Generated Shortcode`, `Copied!`, `Copy to clipboard`, `Are you sure? This cannot be undone.` — plus error text (`An unexpected error occurred.`, `Invalid request.`). The 22 orphans are leftovers from the removed shortcode-reference table (`Available Parameters`, `Parameter`, `KRW or USD`, `KO, EN, or CN`, `Payment Button Shortcode`).

**Good news also verified:** the committed `.mo` files *are* correctly compiled from their `.po` — recompiling each with `msgfmt` produced byte-identical output. The break is entirely upstream, in the `.pot` regeneration step that never runs.

**Impact.** On a Korean, Turkish, or Chinese store, roughly **a third of the plugin's interface** — including the buyer-facing standalone payment form placeholders and its error messages — renders in English. README.md:18 advertises "Multi-language — Korean, English, Chinese, Turkish translations included" as a headline feature, and CHANGELOG.md and recent commits (`feat: UI/UX redesign and complete i18n translations`, `feat: enhance UI elements and improve Turkish translations`) present it as complete. It is not. This is a shipped regression of an advertised feature, caused directly by the missing `wp i18n make-pot` step in the pipeline.

**Recommendation.**

1. `wp i18n make-pot . languages/nicepay-payment-gateway.pot --exclude=tests,vendor,docs`
2. `msgmerge --update --backup=none languages/nicepay-payment-gateway-<locale>.po languages/nicepay-payment-gateway.pot` for all four locales, translate the 73 new strings, `msgfmt` each back to `.mo`.
3. Add the make-pot-and-diff CI gate from RELEASE-09 so this cannot silently recur.
4. Add a make-pot + msgfmt step to `release.yml` before the zip is built, so shipped `.mo` files can never lag source.

---

### MEDIUM

---

#### RELEASE-04 · `WC tested up to: 9.0` is two majors stale, and no CI job ever runs against real WooCommerce

`MEDIUM` · `ci-coverage` · CONFIRMED · effort: large

**Where:** [.github/workflows/tests.yml:9-58](../../.github/workflows/tests.yml#L9) · [tests/bootstrap/wp-stubs.php:13-44](../../tests/bootstrap/wp-stubs.php#L13) · [phpunit.xml:24-25](../../phpunit.xml#L24) · [nicepay-payment-gateway.php:13-14](../../nicepay-payment-gateway.php#L13)

**Problem.** The `test` job matrixes **only on PHP**:

```yaml
# tests.yml:14-17
strategy:
  fail-fast: false
  matrix:
    php: ['7.4', '8.0', '8.1', '8.2', '8.3']
```

It runs `composer install` then `composer test`, bootstrapped by `tests/bootstrap/bootstrap.php` → `tests/bootstrap/wp-stubs.php`, which is hand-written in-memory stand-ins (`get_option`/`update_option`/`add_option`/`delete_option` backed by a `global $wp_options` array, lines 9-44). There is no wp-env, no WordPress checkout, no WooCommerce install, and no WP/WC matrix dimension. `phpunit.xml` additionally **excludes the two files that actually touch WooCommerce** from coverage:

```xml
<!-- phpunit.xml:24-25 -->
<file>includes/class-nicepay-gateway.php</file>
<file>includes/class-nicepay-return-handler.php</file>
```

Meanwhile the header declares:

```php
 * WC requires at least: 5.0
 * WC tested up to: 9.0
```

A live query to api.wordpress.org on 2026-08-19 returns WooCommerce **11.0.1** as current (which itself requires WP 6.9).

**Impact.** The `WC tested up to: 9.0` claim appears in WooCommerce's System Status Report and wp-admin compatibility surfaces; two majors behind reads as an abandoned extension to a merchant evaluating a payment gateway. The claim is also unbacked — nothing in CI has ever executed `WC_Gateway_NicePay::process_payment()` or `process_refund()` against real WooCommerce code, so the money-handling paths have **zero automated verification**.

**Recommendation.**

1. Bump line 14 to `WC tested up to: 11.0` after a manual smoke test on WC 11, and raise `WC requires at least:` from 5.0 (a 2021 release WooCommerce no longer supports) to something you actually test — e.g. 8.2, which also aligns with the HPOS baseline in RELEASE-02.
2. Add an `integration` job using `wp-env` or `wp-cli` + `wp plugin install woocommerce --version=${{ matrix.wc }}` with `matrix.wc: ['8.2','latest']`, and remove the phpunit.xml:24-25 exclusions so the gateway and return handler get real coverage.
3. Add a scheduled run (`on: schedule: cron`) so the "tested up to" value is re-validated as WooCommerce ships.

*Severity note: downgraded high → medium. Confirmed factually against the live API, but stale metadata plus a coverage gap causes no breakage on its own — the plugin's actual WC usage is CRUD-based and version-tolerant. The HPOS declaration (RELEASE-02) is the part that actually bites.*

---

#### RELEASE-05 · Release workflow never cross-checks the pushed tag against the `Version:` header or `NICEPAY_VERSION`

`MEDIUM` · `release-integrity` · CONFIRMED · effort: trivial

**Where:** [.github/workflows/release.yml:20-22](../../.github/workflows/release.yml#L20) · [.github/workflows/release.yml:40-61](../../.github/workflows/release.yml#L40) · [nicepay-payment-gateway.php:6](../../nicepay-payment-gateway.php#L6) · [nicepay-payment-gateway.php:22](../../nicepay-payment-gateway.php#L22)

**Problem.** Verified verbatim:

```yaml
# release.yml:22 — the only place VERSION is derived
run: echo "VERSION=${GITHUB_REF#refs/tags/v}" >> $GITHUB_OUTPUT
```

`steps.version.outputs.VERSION` is consumed only at line 42 (zip filename), line 27 (changelog awk key) and line 66 (release title). **No step reads `nicepay-payment-gateway.php`.** The in-file values live at line 6 (` * Version: 2.0.0`) and line 22 (`define( 'NICEPAY_VERSION', '2.0.0' );`).

**Impact.** Two concrete consequences, not just cosmetic mislabeling:

1. `get_plugin_data()` and the Plugins screen show the stale header version, so **support cannot tell what a merchant is running**.
2. `NICEPAY_VERSION` is the **cache-buster** passed as the `$ver` argument to `wp_enqueue_style`/`wp_enqueue_script` at nicepay-payment-gateway.php:278 and :293, and at admin/class-nicepay-admin.php:57, 64, 71. If it is not bumped, browsers and page caches keep serving the **previous** `nicepay.css`/`nicepay.js`/`nicepay-admin.js` after an upgrade — producing visibly broken or half-styled admin and checkout UI that the merchant cannot fix without a hard refresh.

**Recommendation.** Insert between *Extract version from tag* and *Build plugin ZIP*:

```yaml
- name: Verify version consistency
  run: |
    V="${{ steps.version.outputs.VERSION }}"
    H=$(sed -n 's/^ \* Version:[[:space:]]*//p' nicepay-payment-gateway.php | head -1 | tr -d '[:space:]')
    C=$(sed -n "s/.*NICEPAY_VERSION',[[:space:]]*'\([^']*\)'.*/\1/p" nicepay-payment-gateway.php | head -1)
    [ "$V" = "$H" ] || { echo "::error::tag $V != header $H"; exit 1; }
    [ "$V" = "$C" ] || { echo "::error::tag $V != NICEPAY_VERSION $C"; exit 1; }
    grep -q "^## \[$V\]" CHANGELOG.md || { echo "::error::CHANGELOG.md has no '## [$V]' heading"; exit 1; }
```

Note: `grep -oP` is GNU-only; the `sed` form above is portable. The CHANGELOG guard also fixes the silent `Release v$VERSION` fallback at release.yml:34-36.

*Severity note: downgraded high → medium; claim fully correct. The reviewer's line citation was off by one (`Version:` is line 6, not 5), and the asset cache-busting consequence — the part that actually hurts merchants — was added during verification.*

---

#### RELEASE-06 · Version string is hand-duplicated across nine files with no single source of truth

`MEDIUM` · `release-process` · CONFIRMED · effort: small

**Where:** see the touchpoint table in §3.

**Problem.** `grep -rn "2\.0\.0"` finds the literal `2.0.0` independently hardcoded in **nine** places: the plugin header (line 6), `define( 'NICEPAY_VERSION', '2.0.0' )` (line 22), **`tests/bootstrap/bootstrap.php:14`, which re-defines `NICEPAY_VERSION` for the test suite**, `CHANGELOG.md:5` (`## [2.0.0] - 2026-04-03`, which release.yml's awk at lines 28-32 keys on exactly), and `Project-Id-Version: NicePay Payment Gateway 2.0.0` in the `.pot` plus all four `.po` files — each carrying its own copy at pot:5, en_US.po:9, ko_KR.po:6, tr_TR.po:6, zh_CN.po:6. Confirmed: the `.po` headers are **not** inherited from the `.pot`.

**Impact.** Every release requires nine correct hand-edits with zero enforcement. Missing the CHANGELOG heading silently degrades the release body to `Release v${VERSION}` (release.yml:34-36) with no warning; missing the header/constant edits triggers the asset cache-busting failure in RELEASE-05; a stale `bootstrap.php` constant means the test suite asserts against a version the plugin no longer declares.

**Recommendation.** Add `scripts/bump-version.php` (invoked as a `composer bump` script) that takes the new version and rewrites in one pass: the ` * Version:` header, `NICEPAY_VERSION` in the plugin file, `NICEPAY_VERSION` in `tests/bootstrap/bootstrap.php`, and every `Project-Id-Version:` line under `languages/`; then inserts a `## [x.y.z] - YYYY-MM-DD` stub into CHANGELOG.md. Pair it with the CI gate from RELEASE-05.

Better still: **delete** the `NICEPAY_VERSION` define at `tests/bootstrap/bootstrap.php:14` and have the bootstrap parse the header from the real plugin file, and regenerate `.po` headers from the `.pot` via `msgmerge` at release time so the per-locale copies stop drifting.

*Severity note: downgraded high → medium (process risk, no direct breakage). Line numbers were corrected during verification — the `.po` files are not all at lines 5-6; `en_US` is at line 9 — and `tests/bootstrap/bootstrap.php:14` was added as the ninth, previously-missed location.*

---

#### RELEASE-07 · Release job is not gated on the test suite; tagging never runs `composer test` or `php -l`

`MEDIUM` · `release-gating` · CONFIRMED · effort: small

**Where:** [.github/workflows/release.yml:3-6](../../.github/workflows/release.yml#L3) · [.github/workflows/release.yml:11-22](../../.github/workflows/release.yml#L11) · [.github/workflows/tests.yml:3-7](../../.github/workflows/tests.yml#L3)

**Problem.** `release.yml` triggers on `push: tags: ['v*']` (lines 3-6) and its single `release` job has **no `needs:`, no `workflow_run` gate, and no step that runs PHP at all** — the steps are checkout, extract version, extract changelog, zip, publish:

```yaml
# release.yml:11-16
jobs:
  release:
    name: Build and Release
    runs-on: ubuntu-latest
    steps:
```

`tests.yml` triggers only on `pull_request: branches: [main]` and `push: branches: [main, development]` (lines 3-7) — a tag push matches neither.

**Impact.** A tag placed on a cherry-picked hotfix branch, or on a commit whose main-branch run was red, publishes a public GitHub Release with **zero verification that the code even parses**. The release job's green check only proves that `zip` and the release action succeeded.

**Recommendation.** Add `on: workflow_call:` to `tests.yml`, then in `release.yml`:

```yaml
jobs:
  verify:
    uses: ./.github/workflows/tests.yml
  release:
    needs: verify
```

This reuses the existing PHP matrix and lint jobs verbatim and aborts the release before the zip is built if anything fails.

*Severity note: downgraded high → medium. Mitigating context: the documented flow tags commits that already landed on `main` via PR, and `push: branches: [main]` does test those commits — so the gap bites only on cherry-picked/rebased hotfix tags. Real and cheap to fix, but it requires a compounding maintainer mistake to cause harm.*

---

#### RELEASE-08 · No database upgrade path: `nicepay_db_version` is written once and never read

`MEDIUM` · `release-process` · CONFIRMED · effort: small

**Where:** [nicepay-payment-gateway.php:92-98](../../nicepay-payment-gateway.php#L92) · [nicepay-payment-gateway.php:104-148](../../nicepay-payment-gateway.php#L104) · [nicepay-payment-gateway.php:161](../../nicepay-payment-gateway.php#L161) · [nicepay-payment-gateway.php:479-482](../../nicepay-payment-gateway.php#L479)

**Problem.** `create_tables()` (lines 104-148, which `dbDelta()`s the `wp_nicepay_transactions` schema) and `set_default_options()` (150-163) are called **only** from `activate()` (92-98), which is wired only via `register_activation_hook` at lines 479-482:

```php
// nicepay-payment-gateway.php:92-98
public function activate() {
    $this->create_tables();
    $this->set_default_options();
    $this->register_endpoints();
    flush_rewrite_rules();
}
```

WordPress **does not fire the activation hook when a plugin is updated in place** — neither by the built-in updater nor by re-uploading the zip over an active plugin. `set_default_options()` line 161 does `add_option( 'nicepay_db_version', NICEPAY_VERSION );`, but `grep -rn nicepay_db_version` across the whole tree returns that **single line**: the option is never read anywhere, and because it uses `add_option` (a no-op when the key exists) it is never even refreshed on reactivation.

The same applies to `flush_rewrite_rules()` at line 97, which is what persists the `^nicepay-return/?$` rewrite rule registered at lines 202-212 — **the entire payment-return endpoint depends on it.**

**Impact.** The moment a future release adds or alters a column on `wp_nicepay_transactions` — plausible, given the table has 24 columns tracking VBank, card and cancellation data — upgraded sites keep the old schema. Inserts naming the new column fail, so transaction records silently stop being written or the return handler errors out mid-payment. Likewise, if the return endpoint slug ever changes, upgraded sites keep the stale rewrite rule and payment returns 404. The unused `nicepay_db_version` option shows the migration was intended and never implemented.

**Recommendation.** Add to `init_hooks()`:

```php
add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 1 );
```

```php
public function maybe_upgrade() {
    if ( get_option( 'nicepay_db_version' ) === NICEPAY_VERSION ) {
        return;
    }
    $this->create_tables();          // dbDelta is idempotent
    $this->set_default_options();    // add_option is idempotent
    $this->register_endpoints();
    flush_rewrite_rules();
    update_option( 'nicepay_db_version', NICEPAY_VERSION );  // note: update_option, not add_option
}
```

Also change line 161 from `add_option` to `update_option` so activation refreshes a stale value. Separately: there is no `uninstall.php`, and `deactivate()` (lines 100-102) only flushes rewrite rules — decide and document whether the transactions table and the `nicepay_*` options (**including stored merchant keys**) survive uninstall.

---

#### RELEASE-09 · No PHPCS/PHPStan and no `.pot` freshness check in CI, despite WPCS being mandated by CONTRIBUTING.md

`MEDIUM` · `ci-coverage` · CONFIRMED · effort: medium

**Where:** [.github/workflows/tests.yml:60-79](../../.github/workflows/tests.yml#L60) · [composer.json:15-19](../../composer.json#L15) · [CONTRIBUTING.md:123-125](../../CONTRIBUTING.md#L123) · [CONTRIBUTING.md:142](../../CONTRIBUTING.md#L142)

**Problem.** The `lint` job runs exactly one check:

```yaml
# tests.yml:77-79
- name: Check syntax (PHP Lint)
  run: |
    find . -name "*.php" -not -path "./vendor/*" -not -path "./tests/*" -print0 | xargs -0 -n1 php -l
```

`composer.json` require-dev (lines 15-19) is `phpunit/phpunit ^9.6`, `brain/monkey ^2.6`, `mockery/mockery ^1.6` — **no `squizlabs/php_codesniffer`, no `wp-coding-standards/wpcs`, no `phpstan/phpstan`.** The `scripts` block (lines 25-29) has only `test`/`test-coverage`/`test-filter`. Yet CONTRIBUTING.md:142 mandates "Follow the [WordPress PHP Coding Standards]" and its PR checklist (lines 123-125) requires "Code follows WordPress coding standards" and "All strings are translatable". Neither is machine-checked, and there is no `wp i18n make-pot`-and-diff step.

**Impact.** Documented project policy is unenforceable — and the i18n gap is not theoretical: **73 translatable source strings are currently absent from the shipped catalogues** (RELEASE-03), so four locales silently fall back to English for a third of the UI. A CI check would have caught it on the commit that introduced it.

**Recommendation.**

```bash
composer require --dev squizlabs/php_codesniffer wp-coding-standards/wpcs \
  dealerdirect/phpcodesniffer-composer-installer
```

Add `phpcs.xml.dist` with `<rule ref="WordPress"/>` plus `<exclude name="WordPress.Files.FileName"/>` if you keep the current naming, add `"lint": "phpcs"` to composer scripts, and add `run: composer lint` to the `lint` job. Then add an i18n gate to the same job:

```yaml
- run: |
    curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    php wp-cli.phar i18n make-pot . /tmp/fresh.pot --exclude=tests,vendor,docs
    diff <(grep '^msgid' /tmp/fresh.pot | sort) <(grep '^msgid' languages/nicepay-payment-gateway.pot | sort) \
      || { echo '::error::languages/*.pot is out of date — run wp i18n make-pot'; exit 1; }
```

*Severity note: kept at medium as a tooling-absence finding. Its most damaging consequence is split out as RELEASE-03, because that is an actual shipped bug rather than a process gap.*

---

#### RELEASE-10 · No `Update URI:` header leaves the plugin's slug open to hijack by a same-named wordpress.org plugin

`MEDIUM` · `distribution` · CONFIRMED · effort: trivial

**Where:** [nicepay-payment-gateway.php:1-16](../../nicepay-payment-gateway.php#L1)

**Problem.** The header has no `Update URI:` field — a grep for `Tested up to|Requires at least|Requires Plugins|Update URI|Network:` matches only line 11 (` * Requires at least: 5.0`). WordPress's update check submits every installed plugin's **directory slug** to api.wordpress.org; if a plugin with a matching slug and a higher version is hosted there, core will offer — and on auto-update-enabled sites, silently install — that unrelated plugin over this one. The `Update URI:` header (WordPress 5.8+) is the documented opt-out.

The live API currently returns `{"error":"Plugin not found."}` for `request[slug]=nicepay-payment-gateway`, so the slug is unclaimed and the risk is **latent rather than active** — but it is claimable by anyone at any time, and README.md:35 instructs merchants to install into exactly that directory name (`git clone … nicepay-payment-gateway`).

**Impact.** If anyone ever publishes a plugin under the `nicepay-payment-gateway` slug on wordpress.org, WordPress will offer it as an "update" to every merchant running this gateway, and sites with plugin auto-updates enabled will install a **completely unrelated codebase over a live payment gateway**. Fixing it costs one line and closes the hole permanently.

**Recommendation.** Add to the header block:

```php
 * Update URI: https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin
```

(or ` * Update URI: false` to disable all update checks). Note this is honoured from WordPress 5.8 onward while the plugin declares `Requires at least: 5.0` — either raise that floor to 5.8+ (WordPress 5.0 is from 2018; current WordPress is 7.0.4) or accept that pre-5.8 sites remain exposed. Raising the floor is the right call and aligns with RELEASE-04 and RELEASE-14.

---

### LOW

---

#### RELEASE-11 · Released zip omits `docs/`, `CONTRIBUTING.md` and `CODE_OF_CONDUCT.md`, breaking seven links in the bundled README

`LOW` · `packaging` · CONFIRMED · effort: trivial

**Where:** [.github/workflows/release.yml:45-58](../../.github/workflows/release.yml#L45) · [README.md:196-200](../../README.md#L196) · [README.md:227](../../README.md#L227)

**Problem.** The build step copies exactly nine targets (release.yml:47-55): `admin/`, `assets/`, `includes/`, `templates/`, `languages/`, `nicepay-payment-gateway.php`, `README.md`, `LICENSE`, `CHANGELOG.md`. **`docs/` is never copied.** The bundled README then carries five relative links at lines 196-200 (`docs/USER-GUIDE.md`, `docs/CONFIGURATION.md`, `docs/ARCHITECTURE.md`, `docs/DEVELOPER-GUIDE.md`, `docs/API-REFERENCE.md`) **plus two more at line 227**:

> `Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to contribute, and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) for our community standards.`

Neither of those two files is copied either.

**Impact.** A merchant who downloads the release zip and opens the bundled README hits **seven dead relative links**, including the entire "Documentation" section pointing at the 695-line USER-GUIDE — the primary end-user documentation. Nothing breaks functionally, and the docs remain one click away on GitHub.

**Recommendation.** Cheapest correct fix: make those seven links **absolute** in the shipped README (`https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin/blob/main/docs/USER-GUIDE.md`, etc.) so they resolve from any context. If you prefer bundling, add after release.yml:55:

```bash
cp -r docs/ build/${PLUGIN_SLUG}/
cp CONTRIBUTING.md CODE_OF_CONDUCT.md build/${PLUGIN_SLUG}/
```

— but note WordPress parses nothing extra from this; it just adds ~2500 lines of markdown to the upload.

*Severity note: downgraded medium → low; extended from five broken links to seven during verification.*

---

#### RELEASE-12 · `composer.lock` is gitignored, so CI resolves dev dependencies fresh on every run

`LOW` · `supply-chain` · CONFIRMED · effort: trivial

**Where:** [.gitignore:25-26](../../.gitignore#L25) · [composer.json:12-19](../../composer.json#L12) · [.github/workflows/tests.yml:38-43](../../.github/workflows/tests.yml#L38) · [.github/workflows/tests.yml:75](../../.github/workflows/tests.yml#L75)

**Problem.**

```
# .gitignore:25-26
# Composer
composer.lock
```

No lockfile exists in the repo. Both the `test` job (line 43) and `lint` job (line 75) run `composer install --prefer-dist --no-progress`, which **with no lockfile behaves as `composer update`** and resolves `phpunit/phpunit ^9.6`, `brain/monkey ^2.6`, `mockery/mockery ^1.6` to whatever is newest at run time. The cache key at line 39, `hashFiles('**/composer.lock', '**/composer.json')`, was written assuming a lockfile — with none present it hashes `composer.json` alone.

**Impact.** A dev-dependency point release can turn CI red on an unchanged commit with no repo diff to explain it, and two runs of the same commit weeks apart can install different versions. **Blast radius is confined to CI and developer experience:** the plugin has zero runtime composer dependencies (`"require": { "php": ">=7.4" }`) and the release zip ships no `vendor/`, so nothing a merchant installs is affected.

**Recommendation.** Remove line 26 from `.gitignore`, commit `composer.lock`, and change both CI steps to `composer install --prefer-dist --no-progress --no-interaction` (which will then honour the lock). Bump the lock deliberately via PR. Note the PHP 7.4 matrix leg will need `--ignore-platform-req` handling or a lock generated with `--platform php=7.4` if a dependency later drops 7.4.

*Severity note: downgraded medium → low. All facts verified, but with no runtime deps and no `vendor/` in the shipped artifact this is purely a CI-flakiness concern, never a merchant-facing supply-chain risk.*

---

#### RELEASE-13 · No `SECURITY.md`; the disclosure policy is buried at the bottom of CONTRIBUTING.md

`LOW` · `repo-hygiene` · CONFIRMED · effort: trivial

**Where:** [CONTRIBUTING.md:277-287](../../CONTRIBUTING.md#L277)

**Problem.** `find .github -type f` returns only `workflows/release.yml` and `workflows/tests.yml` — **no `SECURITY.md` at root or under `.github/`.** A policy does exist, at CONTRIBUTING.md:277-287:

> `**Do NOT open a public issue for security vulnerabilities.**` … "Instead, please report security issues privately via GitHub's [Security Advisories](…/security/advisories) feature"

GitHub surfaces a repo's security policy from a top-level or `.github/` `SECURITY.md`; a section inside CONTRIBUTING.md does not populate that surface.

**Impact.** A researcher who finds an SSRF or signature bypass in `includes/class-nicepay-api.php` and looks at the repo's Security tab finds no policy, raising the odds of a public 0-day issue on a payment plugin. Mitigating: the policy **is** discoverable — README.md:227 links CONTRIBUTING.md — and GitHub's private-vulnerability-reporting button is enabled from repo settings independently of any file.

**Recommendation.** Create root `SECURITY.md` containing (a) a supported-versions table, (b) the verbatim reporting instructions currently at CONTRIBUTING.md:279-287, (c) a stated first-response SLA. Replace CONTRIBUTING.md:277-287 with a one-line cross-reference. Separately, enable **Private Vulnerability Reporting** in repo Settings → Security, which is what actually renders the "Report a vulnerability" button.

*Severity note: downgraded high → low. The file's absence is confirmed, but a documented, linked, correct disclosure process already exists and GitHub's private reporting is a settings toggle, not a file. Discoverability polish, not a security gap.*

---

#### RELEASE-14 · Plugin header declares no WordPress `Tested up to:` value, and `WC requires at least: 5.0` names a 2021 release

`LOW` · `packaging` · CONFIRMED · effort: trivial

**Where:** [nicepay-payment-gateway.php:11-14](../../nicepay-payment-gateway.php#L11) · [README.md:23-27](../../README.md#L23)

**Problem.** The header carries:

```php
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
```

— and **no `Tested up to:` field for WordPress whatsoever** (`grep -n "Tested up to"` matches only line 14, `WC tested up to`). README.md's Requirements table (lines 23-27) likewise states only minimums (PHP 7.4+, WordPress 5.0+, WooCommerce 5.0+) and no tested ceiling. Verified live on 2026-08-19: current WordPress is **7.0.4**, current WooCommerce is **11.0.1** (which itself requires WP 6.9 — so any merchant on current WooCommerce is necessarily far past the WP 5.0 floor advertised here).

**Impact.** With no `Tested up to:` value, neither wp-admin nor any future wordpress.org listing can tell a merchant whether this gateway has been exercised on their WordPress version — for a payment plugin that is exactly the signal a cautious store owner looks for. Advertising support back to WordPress 5.0 and WooCommerce 5.0 also implies a compatibility surface that CI never touches (the suite runs against hand-written stubs, not any real WP or WC).

**Recommendation.** Add ` * Tested up to: 7.0` after line 11, and raise the floors to versions you actually intend to support and test — realistically `Requires at least: 6.5` and `WC requires at least: 8.2` (the HPOS baseline). Mirror the same four numbers in README.md's Requirements table so the two never diverge, and fold them into the RELEASE-05 consistency check.

---

#### RELEASE-15 · No `.gitattributes` export-ignore, and README's primary install instruction clones the full repo into `wp-content/plugins`

`LOW` · `packaging` · CONFIRMED · effort: trivial

**Where:** [README.md:31-39](../../README.md#L31)

**Problem.** No `.gitattributes` exists (`ls .gitattributes` → not found). README.md:31-39, headed "### From GitHub" and listed **first** — ahead of "### Manual Upload" at lines 41-45 — instructs:

```
1. Download the latest release or clone the repository:
   git clone https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin.git nicepay-payment-gateway
2. Upload the `nicepay-payment-gateway` folder to `/wp-content/plugins/`
```

That folder contains `.git/` (full history), `tests/`, `docs/`, `composer.json`, `phpunit.xml`, `CONTRIBUTING.md`, `.github/` — none of which belong in a production webroot. Without `export-ignore` entries, GitHub's "Download ZIP" button produces the same over-broad payload.

**Impact.** Merchants following the first documented install path put a `.git` directory and the dev toolchain in a public webroot. On servers that serve dotfiles (a common misconfiguration), `.git/config` and the object store become fetchable. The repo is public so the source itself is not secret — this is bad hygiene rather than a secret leak, but it is exactly the kind of instruction a security-conscious merchant will flag when evaluating a payment plugin.

**Recommendation.**

1. Reorder README.md so "Download the latest release ZIP from the Releases page" is the **primary, first-listed** install method; demote the git-clone path to a "For developers" note with an explicit warning not to deploy a clone to a live site.
2. Add `.gitattributes` with `export-ignore` for `/tests`, `/docs`, `/.github`, `/composer.json`, `/phpunit.xml`, `/CONTRIBUTING.md`, `/CODE_OF_CONDUCT.md`, `/.gitignore`, `/.gitattributes`, so GitHub's Download-ZIP output matches what `release.yml` builds.

---

#### RELEASE-16 · Vendor CDN script has no SRI (unavoidable) and its load failure is reported as a generic retryable error

`LOW` · `supply-chain` · CONFIRMED · effort: small

**Where:** [nicepay-payment-gateway.php:29](../../nicepay-payment-gateway.php#L29) · [nicepay-payment-gateway.php:281-287](../../nicepay-payment-gateway.php#L281) · [nicepay-payment-gateway.php:301-305](../../nicepay-payment-gateway.php#L301) · [assets/js/nicepay.js:47-64](../../assets/js/nicepay.js#L47)

**Problem.** `NICEPAY_JS_URL` is hardcoded at line 29 to `https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js` and enqueued at lines 281-287 as `wp_enqueue_script( 'nicepay-pgweb', NICEPAY_JS_URL, array(), null, true )` with no `wp_script_add_data()` integrity attribute — **correct**, since SRI is not viable against a vendor script the PG updates server-side without notice.

The failure guard exists in structure (`nicepay.js:47` `if (typeof nicepayStart === 'function')`, else branch at 58-64) but shows the **wrong message**:

```js
// assets/js/nicepay.js:58-63
} else {
    this.hideLoading();
    this.showNotice(
        typeof nicepayParams !== 'undefined' ? nicepayParams.i18n.error : 'Payment system unavailable.',
```

```php
// nicepay-payment-gateway.php:303
'error' => __( 'Payment error occurred. Please try again.', 'nicepay-payment-gateway' ),
```

The branch resolves `nicepayParams.i18n.error` — i.e. the generic retryable message. The literal `'Payment system unavailable.'` is only the fallback for when `nicepayParams` itself is undefined, **which cannot happen**, since `wp_localize_script` attaches it to the very script containing that code. It is dead code.

**Impact.** When the vendor CDN is blocked or down, the buyer is told *"Payment error occurred. Please try again."* — advice that will never work, since retrying re-executes the same missing-global path. The merchant gets no server-side signal at all: nothing is written to the WooCommerce logger, so support cannot distinguish a CDN outage from a credential problem. The residual MITM risk from unverifiable third-party JS on the checkout page is real but not practically fixable given the vendor's versioning model.

**Recommendation.**

1. In `nicepay.js:58-64`, use a distinct message keyed off a new `nicepayParams.i18n.scriptUnavailable`, added to the localize array at nicepay-payment-gateway.php:301-305:
   ```php
   'scriptUnavailable' => __( 'The payment provider could not be reached. Please try again in a few minutes or contact the store.', 'nicepay-payment-gateway' ),
   ```
2. Fire a `wp_ajax_nopriv` beacon on that branch that writes through the existing WooCommerce logger, so admins see CDN outages server-side.
3. Document in `docs/CONFIGURATION.md` that CSP-enabled stores must allowlist `script-src https://pg-web.nicepay.co.kr`.

*Note: verification corrected the original claim, which credited the plugin with showing a translated "Payment system unavailable" notice. It does not — turning a claimed strength into a small concrete UX defect.*

---

#### RELEASE-17 · Missing repo scaffolding: issue/PR templates, Dependabot, CODEOWNERS, `.editorconfig`, README badges

`LOW` · `repo-hygiene` · CONFIRMED · effort: small

**Where:** [CONTRIBUTING.md:103-128](../../CONTRIBUTING.md#L103) · [CONTRIBUTING.md:130-134](../../CONTRIBUTING.md#L130) · [CONTRIBUTING.md:248-260](../../CONTRIBUTING.md#L248) · [README.md](../../README.md)

**Problem.** `.github/` contains **only** `workflows/release.yml` and `workflows/tests.yml` — no `ISSUE_TEMPLATE/`, no `PULL_REQUEST_TEMPLATE.md`, no `dependabot.yml`, no `CODEOWNERS`. Repo root has no `.editorconfig`, no `phpcs.xml`/`phpcs.xml.dist`, no `.gitattributes`.

Meanwhile CONTRIBUTING.md **already contains fully-written templates as inert prose**: a PR template in a fenced markdown block at lines 105-128, a 9-point bug report format at lines 250-260, and a stated review policy at lines 130-134 ("All PRs are reviewed by the project maintainer (@cemililik)"). README.md line 1 goes straight from the `#` heading to prose with zero badges.

**Impact.** Individually trivial; collectively these are the polish signals that separate a working repo from a first-class one. The already-authored bug and PR templates are never shown to anyone actually opening an issue or PR. With four pinned Action SHAs and three composer dev-deps, no Dependabot means they silently rot.

**Recommendation**, in priority order:

| # | Action | Payoff |
|---|--------|--------|
| 1 | Convert CONTRIBUTING.md:105-128 verbatim into `.github/PULL_REQUEST_TEMPLATE.md`, and :250-260 into `.github/ISSUE_TEMPLATE/bug_report.yml` | Pure copy-paste, immediate |
| 2 | `.github/dependabot.yml` with both `package-ecosystem: composer` and `github-actions` | Keeps the four pinned SHAs current |
| 3 | Badge row at README.md line 2: Tests workflow status, latest release, MIT licence | First-impression signal |
| 4 | `CODEOWNERS` with `* @cemililik` | Matches the stated review policy |
| 5 | `.editorconfig` — **but read RELEASE-21 first**, since CONTRIBUTING.md's stated tab rule contradicts the actual code | Avoids codifying the wrong rule |

---

#### RELEASE-18 · Release publishes no checksum or signature for the zip, which is the sole distribution channel

`LOW` · `supply-chain` · CONFIRMED · effort: trivial

**Where:** [.github/workflows/release.yml:40-70](../../.github/workflows/release.yml#L40)

**Problem.** The build step produces `${PLUGIN_SLUG}-${VERSION}.zip` (line 58) and the release step attaches `files: ${{ env.ZIP_FILE }}` (line 68) — a **single artifact**. No `sha256sum` file is generated, no artifact attestation (`actions/attest-build-provenance`) is produced, and nothing is signed. The workflow `permissions` block (lines 8-9) grants only `contents: write`. Because there is no update mechanism (RELEASE-01) and the plugin is not on wordpress.org, **this zip is the only way merchants ever obtain the plugin.**

**Impact.** A merchant who receives the zip through any channel other than a direct GitHub download — a forwarded file, a mirror, an agency's shared drive — has no way to verify it is the artifact the maintainer built. For a payment gateway that handles card authorisation and holds merchant signing keys, a verifiable artifact is a reasonable baseline expectation. Mitigating: GitHub Releases are served over HTTPS from a trusted host, so the direct-download path is already protected in transit.

**Recommendation.** After release.yml:58:

```bash
sha256sum ${PLUGIN_SLUG}-${VERSION}.zip > ${PLUGIN_SLUG}-${VERSION}.zip.sha256
```

then change the release step to `files: |` listing both the zip and the `.sha256`. Add `id-token: write` and `attestations: write` to the `permissions` block and an `actions/attest-build-provenance` step for signed SLSA provenance — both are free on public repos. Document the verification command in README's install section.

---

#### RELEASE-19 · `brain/monkey` and `mockery/mockery` are declared dev dependencies but no test uses them

`LOW` · `supply-chain` · CONFIRMED · effort: trivial

**Where:** [composer.json:17-18](../../composer.json#L17) · [tests/bootstrap/wp-stubs.php:82](../../tests/bootstrap/wp-stubs.php#L82)

**Problem.**

```json
"brain/monkey": "^2.6",
"mockery/mockery": "^1.6"
```

`grep -rn "Brain\\Monkey|Mockery|brain\\monkey" tests/` across all 995 lines of test code returns exactly **one** hit — a comment at `tests/bootstrap/wp-stubs.php:82` reading `// Override with Brain\Monkey in specific tests`. No test file imports, initialises (`Monkey\setUp()`), or calls either library; the suite relies entirely on the hand-written function stubs in `wp-stubs.php`.

**Impact.** Two unused packages — plus their transitive deps (hamcrest for mockery; antecedent/patchwork for brain/monkey) — are installed on all five PHP matrix legs of every CI run and on every contributor's machine, slowing `composer install` and enlarging the dependency surface, for zero benefit. The comment at wp-stubs.php:82 also documents a testing pattern that does not exist, misleading contributors.

**Recommendation.** Either remove both lines from `require-dev` and delete the stale comment at wp-stubs.php:82, or — the better option — **actually adopt Brain Monkey** for the two WooCommerce-touching classes currently excluded from coverage at phpunit.xml:24-25, where hand-written stubs are impractical and function mocking is exactly the right tool. Do not leave them declared-but-unused.

---

#### RELEASE-20 · No `readme.txt` blocks any wordpress.org path; MIT may block a WooCommerce.com Marketplace listing

`LOW` · `licensing` · **PLAUSIBLE — needs confirmation** · effort: small

**Where:** [LICENSE:1-3](../../LICENSE#L1) · [nicepay-payment-gateway.php:15](../../nicepay-payment-gateway.php#L15) · [composer.json:5](../../composer.json#L5)

**Problem.** Licensing is internally consistent: `LICENSE:1` `MIT License`, `nicepay-payment-gateway.php:15` ` * License: MIT`, `composer.json:5` `"license": "MIT",`. Verified absent: **no `readme.txt` anywhere in the repo.**

MIT is GPLv2-or-later-compatible, so wordpress.org hosting is **not** blocked by the licence — it is blocked by the missing `readme.txt` manifest (`Stable tag`, `Tested up to`, `Requires PHP`, `== Description ==`, etc.). The slug `nicepay-payment-gateway` is confirmed unregistered on wordpress.org, so the path is open.

> **Needs confirmation.** The separate claim that WooCommerce.com's Marketplace requires **GPLv3 specifically** (rather than merely GPL-compatible) could not be verified from the repo or from any authoritative source in this session. The repo-side facts — consistent MIT declarations, no `readme.txt` — are confirmed.

**Impact.** Purely conditional on distribution intent. If a wordpress.org listing is a goal, it is blocked today on `readme.txt` alone; a wordpress.org listing is also the only mechanism that would give merchants native update notifications, so this and RELEASE-01 are coupled. If no marketplace listing is planned, MIT is fine and nothing here matters.

**Recommendation.** Decide and document the distribution intent in CONTRIBUTING.md. If wordpress.org is the target, add `readme.txt` with `Stable tag: 2.0.0`, `Requires at least: 5.0`, `Tested up to: 7.0`, `Requires PHP: 7.4`, `License: MIT`, `License URI: https://opensource.org/licenses/MIT`, and add it to release.yml's copy list.

**Do NOT relicense** on the strength of the WooCommerce.com GPLv3 claim without confirming it against current WooCommerce.com vendor terms first — relicensing also conflicts with CONTRIBUTING.md:293, which binds contributions to MIT.

---

#### RELEASE-21 · CONTRIBUTING.md mandates tab indentation; the entire codebase uses four spaces

`LOW` · `repo-hygiene` · CONFIRMED · effort: trivial

**Where:** [CONTRIBUTING.md:144](../../CONTRIBUTING.md#L144) · [CONTRIBUTING.md:151-158](../../CONTRIBUTING.md#L151)

**Problem.** CONTRIBUTING.md:144 states `- Use tabs for indentation` as the **first** PHP coding rule — and its own "// Good" example at lines 151-158 is indented with **spaces**, contradicting the rule one line below it. Actual indentation counts (`grep -cP '^\t'` vs `grep -cP '^    '`):

| File | Tab-indented lines | 4-space-indented lines |
|------|-------------------:|-----------------------:|
| `nicepay-payment-gateway.php` | 0 | 382 |
| `includes/class-nicepay-api.php` | 0 | 390 |
| `admin/class-nicepay-admin.php` | 0 | 862 |

The codebase is 100% four-space, 0% tabs. There is no `.editorconfig` and no `phpcs.xml` to arbitrate.

**Impact.** Any contributor who follows the written standard introduces mixed indentation, producing noisy whitespace diffs in every subsequent PR of the affected files. It also undermines confidence in the rest of CONTRIBUTING.md's standards section, since the very first rule is demonstrably not the one the project follows.

**Recommendation.** Change CONTRIBUTING.md:144 to `- Use 4 spaces for indentation` to match reality (do **not** reindent 12.5k lines to tabs), and add `.editorconfig`:

```ini
[*.php]
indent_style = space
indent_size = 4
```

If you later adopt WPCS (which defaults to tabs), configure `phpcs.xml.dist` with `<arg name="tab-width" value="4"/>` and exclude `Generic.WhiteSpace.DisallowSpaceIndent` rather than churning the whole codebase.

---

## 3. Version-bump touchpoint table

Every location that must be hand-edited for a release today. **Nine touchpoints, zero enforcement** (RELEASE-06).

| # | File | Line | Literal | Consequence if missed |
|---|------|-----:|---------|-----------------------|
| 1 | `nicepay-payment-gateway.php` | 6 | ` * Version: 2.0.0` | Plugins screen / `get_plugin_data()` report the wrong version; support cannot identify the install |
| 2 | `nicepay-payment-gateway.php` | 22 | `define( 'NICEPAY_VERSION', '2.0.0' );` | **Asset cache-buster stays stale** — browsers keep serving the old CSS/JS after upgrade (checkout + admin UI visibly broken) |
| 3 | `tests/bootstrap/bootstrap.php` | 14 | re-defines `NICEPAY_VERSION` | Test suite asserts against a version the plugin no longer declares |
| 4 | `CHANGELOG.md` | 5 | `## [2.0.0] - 2026-04-03` | `release.yml` awk finds nothing → release body silently becomes `Release v2.0.1`, green check |
| 5 | `languages/nicepay-payment-gateway.pot` | 5 | `Project-Id-Version: … 2.0.0` | Cosmetic drift in the catalogue header |
| 6 | `languages/…-en_US.po` | 9 | `Project-Id-Version: … 2.0.0` | Cosmetic drift |
| 7 | `languages/…-ko_KR.po` | 6 | `Project-Id-Version: … 2.0.0` | Cosmetic drift |
| 8 | `languages/…-tr_TR.po` | 6 | `Project-Id-Version: … 2.0.0` | Cosmetic drift |
| 9 | `languages/…-zh_CN.po` | 6 | `Project-Id-Version: … 2.0.0` | Cosmetic drift |
| — | git tag `vX.Y.Z` | — | drives the whole workflow | Never cross-checked against #1 or #2 (RELEASE-05) |

Note the `.po` `Project-Id-Version` lines are **not** inherited from the `.pot` — each carries its own copy, and `en_US` is at a different line number from the other three.

---

## 4. CI matrix gap table

What the `Tests` workflow covers versus what the plugin claims to support.

| Dimension | Declared support | CI coverage | Gap |
|-----------|------------------|-------------|-----|
| PHP | `Requires PHP: 7.4` | `['7.4','8.0','8.1','8.2','8.3']` — full matrix | **None.** This dimension is well covered. |
| WordPress | `Requires at least: 5.0`, no `Tested up to:` | **None.** Hand-written stubs in `tests/bootstrap/wp-stubs.php:9-44`; no WP checkout, no wp-env | Total. No real WP core code is ever executed (RELEASE-04, RELEASE-14) |
| WooCommerce | `WC requires at least: 5.0`, `WC tested up to: 9.0` (current is 11.0.1) | **None.** No WooCommerce install; `class-nicepay-gateway.php` and `class-nicepay-return-handler.php` are *excluded* from coverage at phpunit.xml:24-25 | Total. `process_payment()` / `process_refund()` — the money paths — have zero automated verification (RELEASE-04) |
| HPOS / order storage | Not declared at all | None | Code is HPOS-safe but undeclared → WooCommerce lists it as incompatible (RELEASE-02) |
| Checkout Blocks | Not declared, not implemented | None | No `AbstractPaymentMethodType` integration anywhere; Blocks is the default checkout since WC 8.3 (RELEASE-02) |
| Coding standards | CONTRIBUTING.md:142 mandates WPCS | **`php -l` only** (tests.yml:77-79) | Policy is unenforceable; no PHPCS, no PHPStan (RELEASE-09) |
| i18n / `.pot` freshness | README:18 advertises 4 complete locales | **None** | 73 source strings missing from shipped catalogues (RELEASE-03, RELEASE-09) |
| Version consistency | 9 hand-edited touchpoints | **None** | Tag can disagree with header and constant (RELEASE-05, RELEASE-06) |
| Release gating | — | **None.** `release` job has no `needs:` | A tag on unverified code publishes a public release (RELEASE-07) |
| Dependency pinning | 3 dev deps | `composer install` with **no lockfile** → resolves fresh each run (RELEASE-12) | Non-reproducible CI |
| Scheduled / drift runs | — | None | "Tested up to" values silently rot |

---

## 5. Missing-files checklist

Verified absent via `find .github -type f` (returns exactly two files, both under `workflows/`) and `ls -la` of the repo root.

| File | Present? | Why it matters | Finding |
|------|:--------:|----------------|---------|
| `SECURITY.md` | ✗ | GitHub's Security tab shows no policy; a working one is buried in CONTRIBUTING.md:277-287 | RELEASE-13 |
| `readme.txt` | ✗ | Blocks any wordpress.org listing (the only native-update path) | RELEASE-20 |
| `uninstall.php` | ✗ | Merchant keys and the 24-column transactions table survive uninstall, undocumented | RELEASE-08 |
| `.gitattributes` | ✗ | GitHub "Download ZIP" ships `tests/`, `docs/`, `.github/`, `composer.json` | RELEASE-15 |
| `.editorconfig` | ✗ | No arbiter for the tabs-vs-spaces contradiction | RELEASE-17, RELEASE-21 |
| `phpcs.xml` / `phpcs.xml.dist` | ✗ | WPCS is mandated by CONTRIBUTING.md but unenforced | RELEASE-09 |
| `composer.lock` | ✗ (gitignored) | CI resolves dev deps fresh on every run | RELEASE-12 |
| `.github/ISSUE_TEMPLATE/` | ✗ | A 9-point bug format is already written at CONTRIBUTING.md:250-260 but never shown | RELEASE-17 |
| `.github/PULL_REQUEST_TEMPLATE.md` | ✗ | A full PR template is already written at CONTRIBUTING.md:105-128 but never shown | RELEASE-17 |
| `.github/dependabot.yml` | ✗ | 4 pinned Action SHAs + 3 dev deps rot silently | RELEASE-17 |
| `CODEOWNERS` | ✗ | Review policy stated at CONTRIBUTING.md:130-134 is not enforced | RELEASE-17 |
| README badges | ✗ | No build/release/licence signal above the fold | RELEASE-17 |
| `LICENSE` | ✓ | MIT, consistent with header and `composer.json` | — |
| `CHANGELOG.md` | ✓ | Correct `## [x.y.z]` format that `release.yml` parses | — |
| `CONTRIBUTING.md` | ✓ | Thorough — but not shipped in the zip and links break | RELEASE-11 |
| `CODE_OF_CONDUCT.md` | ✓ | Not shipped in the zip; README link breaks | RELEASE-11 |
| `.gitignore` | ✓ | Correctly excludes `vendor/`, `node_modules/`, build artifacts | — |
| `languages/*.mo` (×4) | ✓ | Correctly compiled and committed — but from stale `.po` | RELEASE-03 |

---

## 6. Recommendations in priority order

Ordered by (impact × merchant reach) ÷ effort. The first four are cheap and disproportionately valuable.

| Rank | Action | Effort | Findings closed |
|-----:|--------|--------|-----------------|
| 1 | **Declare HPOS compatibility** — one `before_woocommerce_init` hook. The code already complies; this is a nine-line fix that removes the gateway from WooCommerce's "Incompatible plugins" list. | trivial | RELEASE-02 |
| 2 | **Add `Update URI:` to the header.** One line; closes the slug-hijack hole permanently. | trivial | RELEASE-10 |
| 3 | **Regenerate the i18n catalogues** (`wp i18n make-pot` → `msgmerge` ×4 → translate 73 strings → `msgfmt`), and add the make-pot-diff CI gate so it cannot recur. Restores an advertised headline feature. | medium | RELEASE-03, part of RELEASE-09 |
| 4 | **Add the version-consistency + CHANGELOG gate to `release.yml`**, and gate `release` on `tests.yml` via `workflow_call`. ~15 lines of YAML; eliminates the stale-cache-buster and unverified-release classes of failure. | trivial→small | RELEASE-05, RELEASE-07 |
| 5 | **Ship an update mechanism** — vendor `plugin-update-checker` and point it at the GitHub Releases the pipeline already produces. Without this, every fix above only reaches new installs. | medium | RELEASE-01 |
| 6 | **Implement `maybe_upgrade()`** on `plugins_loaded` so schema and rewrite-rule migrations are possible before they are ever needed; switch line 161 to `update_option`. | small | RELEASE-08 |
| 7 | **Refresh version metadata**: `Tested up to: 7.0`, `WC tested up to: 11.0`, raise `Requires at least:`/`WC requires at least:` to versions actually tested; mirror in README's Requirements table. | trivial | RELEASE-14, part of RELEASE-04 |
| 8 | **Adopt PHPCS + WPCS** with `phpcs.xml.dist` and a `composer lint` CI step, so CONTRIBUTING.md's stated standard becomes enforceable. | medium | RELEASE-09 |
| 9 | **Add a WooCommerce integration CI job** (wp-env or wp-cli, `matrix.wc: ['8.2','latest']`), and remove the phpunit.xml:24-25 exclusions so `process_payment()`/`process_refund()` get real coverage. | large | RELEASE-04 |
| 10 | **Commit `composer.lock`**, remove it from `.gitignore`, add `--no-interaction` to both CI installs. | trivial | RELEASE-12 |
| 11 | **Convert the already-written templates** in CONTRIBUTING.md into `.github/PULL_REQUEST_TEMPLATE.md` and `.github/ISSUE_TEMPLATE/bug_report.yml`; add `dependabot.yml`, `CODEOWNERS`, README badges. Pure copy-paste. | small | RELEASE-17 |
| 12 | **Create `SECURITY.md`** from the existing CONTRIBUTING.md text and enable Private Vulnerability Reporting in repo settings. | trivial | RELEASE-13 |
| 13 | **Fix the CDN-failure message** — add `i18n.scriptUnavailable` and stop telling buyers to retry an unrecoverable state; log the failure server-side. | small | RELEASE-16 |
| 14 | **Publish a `.sha256` and build provenance** alongside the release zip. | trivial | RELEASE-18 |
| 15 | **Fix the seven broken README links** in the shipped zip (make them absolute), reorder README so the release ZIP is the primary install path, and add `.gitattributes` `export-ignore`. | trivial | RELEASE-11, RELEASE-15 |
| 16 | **Correct CONTRIBUTING.md:144** to "4 spaces" and add `.editorconfig` to match the actual codebase. | trivial | RELEASE-21 |
| 17 | **Resolve the unused dev deps** — either drop `brain/monkey` and `mockery/mockery`, or adopt Brain Monkey for the WooCommerce-touching classes in #9. | trivial | RELEASE-19 |
| 18 | **Decide and document distribution intent** (wordpress.org listing or not) before any licensing decision; confirm the WooCommerce.com GPLv3 claim independently before relicensing anything. | small | RELEASE-20 *(needs confirmation)* |

---

*21 findings in this dimension: 3 high, 7 medium, 11 low. One (RELEASE-20) is marked PLAUSIBLE and needs external confirmation before action. All other findings were verified against the working tree at branch `main`.*
