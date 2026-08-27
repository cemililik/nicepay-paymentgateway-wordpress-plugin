# User Experience — Merchant & Customer

> [!WARNING]
> **Frozen historical snapshot.** This review describes baseline commit `5855db1` (2026-08-19), before the 2.0 remediation work. Its line references and present-tense security claims do not describe the current code. Use `docs/analysis/pr-review2/` and current tests for release decisions.

This document covers the 126 review findings that touch what a human actually sees, reads and does: the merchant configuring and operating the plugin in `wp-admin`, and the customer moving from a payment button to a confirmed payment. The engineering underneath is in reasonable shape — signatures are verified fail-closed, net cancel is wired into every failure branch, AJAX endpoints pair capability checks with nonces, and the shortcode builder shows genuine product thinking. The experience layered on top of it is not yet at the same standard. Two findings are outright critical: a WooCommerce buyer who chooses Virtual Account is never shown the account number anywhere (UX-001), and the standalone shortcode signs whatever amount the browser sends it (UX-002). Beneath those sit a consistent pattern rather than a scattering of nits — **data is captured and never displayed** (result codes, card issuer, masked PAN, virtual-account details, buyer contact), **failures are announced in the vendor's Korean operator strings with no retry path**, **destructive actions are confirmed without naming what they destroy**, and **the merchant is never told what state the system is in** (test mode, missing credentials, unsupported currency, a gateway WooCommerce has not been told to enable). Fixing the display and copy layer over data that already exists in the database would move this product further, faster, than any other work available to it.

---

## What this codebase does well

Before the criticism, the parts that are genuinely above the bar for a WordPress payment plugin and should be protected during any refactor:

| Area | What is good |
|---|---|
| Shortcode builder | The live preview at [admin/class-nicepay-admin.php:610](../../admin/class-nicepay-admin.php#L610)–670 and [732](../../admin/class-nicepay-admin.php#L732)–798 renders an accurate mock of the front-end form as the merchant types — product name, formatted amount, the real enabled-method list with real icons, buyer-field placeholders that appear and disappear exactly as `$show_buyer_fields` behaves in the template, and the styled button in the chosen colour. Very few plugins ship a WYSIWYG shortcode builder at all. |
| Empty states | Both the transactions table ([admin/class-nicepay-transactions.php:95](../../admin/class-nicepay-transactions.php#L95)–106) and the shortcodes grid ([admin/class-nicepay-admin.php:359](../../admin/class-nicepay-admin.php#L359)–364) render a custom SVG illustration with a title and a supporting sentence. Treating blank space as a designed surface is well ahead of typical plugin quality. |
| Admin security | Both AJAX endpoints pair a capability check with nonce verification ([nicepay-payment-gateway.php:365](../../nicepay-payment-gateway.php#L365)–368, [444](../../nicepay-payment-gateway.php#L444)–447; [admin/class-nicepay-transactions.php:186](../../admin/class-nicepay-transactions.php#L186)); the cancel nonce is scoped per transaction (`wp_create_nonce( 'nicepay_cancel_' . $item->id )`); all output is escaped; the transactions query uses `$wpdb->prepare` with an `orderby` whitelist ([includes/nicepay-functions.php:195](../../includes/nicepay-functions.php#L195)–197). |
| Destructive-action guarding | The admin modal blocks overlay click, the cancel button and Escape while a request is in flight ([assets/js/nicepay-admin.js:97](../../assets/js/nicepay-admin.js#L97)–105), preventing the classic double-submit-and-close race. |
| Icon-only controls | The TID copy button carries `aria-label="Copy TID %s"` and the cancel button `aria-label="Cancel transaction %s"`, both interpolating the TID so screen-reader users can distinguish rows, with SVGs correctly `aria-hidden="true" focusable="false"` ([admin/class-nicepay-transactions.php:115](../../admin/class-nicepay-transactions.php#L115)–118, [144](../../admin/class-nicepay-transactions.php#L144)–150). |
| Decorative SVGs | Every method icon is wrapped in `<span class="nicepay-method-icon" aria-hidden="true">` with `stroke="currentColor"` ([includes/nicepay-icons.php:74](../../includes/nicepay-icons.php#L74)), so icons are hidden from AT and inherit colour; `nicepay_get_method_icon()` returns an empty string for an unknown method, so an unrecognised code degrades to text rather than breaking layout. |
| Responsive admin | The 782 px WordPress breakpoint is actually handled — filter bar stacks, the builder collapses two columns to one, the sticky preview un-sticks, the card grid becomes single-column, method chips shrink ([assets/css/nicepay-admin.css:1187](../../assets/css/nicepay-admin.css#L1187)–1255). Most plugin admin CSS stops at desktop. |
| Filter durability | `paginate_links` is built with `add_query_arg( 'paged', '%#%' )` over the current request URI so status/method/date/search survive pagination ([admin/class-nicepay-transactions.php:163](../../admin/class-nicepay-transactions.php#L163)–170), and the filter form omits `paged` so a new filter correctly resets to page 1. |
| Currency care in cancel | `ajax_cancel_transaction()` resolves currency from the linked WooCommerce order before formatting the cancel amount ([admin/class-nicepay-transactions.php:197](../../admin/class-nicepay-transactions.php#L197)–205) — a subtlety the transactions table's own amount column gets wrong (UX-027). |
| Translator context | Several `sprintf`/`_n` calls carry proper `/* translators: */` comments ([includes/class-nicepay-gateway.php:135](../../includes/class-nicepay-gateway.php#L135), 259, 373, 382, 455; [admin/class-nicepay-transactions.php:72](../../admin/class-nicepay-transactions.php#L72)). |
| Method picker semantics | Built on real `<input type="radio">` inside `<label>` with a shared `name`, in a `role="radiogroup"` with `aria-labelledby` ([templates/payment-form.php:34](../../templates/payment-form.php#L34)–44, [templates/standalone-payment-form.php:80](../../templates/standalone-payment-form.php#L80)–91). Native arrow-key navigation, grouping and AT semantics for free — most gateway plugins reimplement this with divs and get it wrong. |
| Handoff correctness (WC) | SignData/EdiDate/Moid are generated server-side in `generate_payment_form()` and `nicepayStart()` is invoked directly inside the click handler ([assets/js/nicepay.js:21](../../assets/js/nicepay.js#L21)–47), preserving the browser's user-activation context; the button is disabled and a spinner shown, preventing double submit. |
| Fail-closed verification | `verify_auth_signature()` is mandatory before any approval ([includes/class-nicepay-gateway.php:275](../../includes/class-nicepay-gateway.php#L275), [includes/class-nicepay-return-handler.php:70](../../includes/class-nicepay-return-handler.php#L70)), and `request_approval()` refuses a response with a missing or invalid Signature and issues a net cancel ([includes/class-nicepay-api.php:248](../../includes/class-nicepay-api.php#L248)–267). A buyer cannot be walked through a spoofed success. |
| Net cancel coverage | Wired into every failure branch — connection error, unparseable body, missing signature, bad signature ([includes/class-nicepay-api.php:225](../../includes/class-nicepay-api.php#L225), 238, 251, 262) — exactly the 망취소 behaviour the spec mandates. |
| Inline field validation | Well built for its size: clears prior errors, validates required/email/phone separately, marks the input `is-invalid`, injects a `role="alert"` message and moves focus to the first invalid field ([templates/standalone-payment-form.php:196](../../templates/standalone-payment-form.php#L196)–233). |
| Listener hygiene | The customer modal stores its keydown handler per instance and removes it on close rather than leaking listeners ([templates/standalone-payment-form.php:346](../../templates/standalone-payment-form.php#L346)–365). |
| Stylesheet architecture | 24 prefixed custom properties on `:root` ([assets/css/nicepay.css:8](../../assets/css/nicepay.css#L8)–35) with consistent radius/shadow/transition scales, `:focus-visible` outlines on the button and cancel link, and a `:has(input:checked)` selected state with an `.is-selected` class fallback. |
| Primary button | `#2563eb` on white computes to 5.17:1 (AA), `min-height: 48px`, and a 2px `:focus-visible` outline at 2px offset. Contrast and target size are both met. |
| Localisation coverage | All four locales are complete for the strings that *are* extracted (157 msgids, zero empty msgstr in en_US/ko_KR/tr_TR/zh_CN), and the Korean method names are idiomatic: 신용카드 / 계좌이체 / 가상계좌 / 휴대폰 결제. |
| Result page craft | For what it renders, the standalone result page is well designed — a focused card, a semantic `<h1>`, `<dl>` markup for detail pairs, a distinct VBANK treatment, single-column reflow under 480 px. |

---

## How to read this document

Severity badges: `CRITICAL` (money or total feature failure), `HIGH` (severe user-visible breakage or blocked workflow), `MEDIUM` (real defect or significant clarity gap), `LOW` (polish, narrow trigger), `ENHANCEMENT` (missing capability, not a defect).

Findings marked **needs confirmation** were verified as code facts but their stated consequence depends on something that could not be reproduced in this environment (browser behaviour, vendor script internals, a live WordPress install). Triage them by reproducing first.

| Section | Findings | Critical | High | Medium | Low | Enh. |
|---|---|---|---|---|---|---|
| §1 Merchant experience, screen by screen | 43 | 0 | 6 | 31 | 6 | 0 |
| §2 Customer journey, step by step | 47 | 1 | 6 | 20 | 17 | 3 |
| §3 Virtual Account (VBANK) | 5 | 1 | 3 | 1 | 0 | 0 |
| §4 Accessibility (WCAG 2.2 AA) | 21 | 0 | 0 | 16 | 4 | 1 |
| §5 Visual design system & CSS | 10 | 0 | 0 | 4 | 3 | 3 |
| **Total** | **126** | **2** | **15** | **72** | **30** | **7** |

Each finding is written up exactly once, under the ID given; other sections cross-reference it by ID. A handful of findings were recorded twice during review (UX-031/UX-088, UX-033/UX-086, UX-047/UX-087, UX-055/UX-083, UX-068/UX-080, UX-099/UX-117) — both records are kept because each contributes evidence or a different fix angle, and the duplication is flagged in place. §6 is the prioritised backlog and contains no new findings.

---

# §1 — The merchant experience, screen by screen

## 1.1 Activation and first run

The plugin currently has no first-run experience at all. `activate()` creates tables, sets options, registers endpoints, flushes rewrites, and returns. Everything below flows from that.

### UX-004 — `HIGH` — Activation immediately opens a payment method backed by NICEPAY's shared public demo account

[nicepay-payment-gateway.php:33](../../nicepay-payment-gateway.php#L33) · [includes/class-nicepay-gateway.php:41](../../includes/class-nicepay-gateway.php#L41) · [assets/css/nicepay-admin.css:28](../../assets/css/nicepay-admin.css#L28)

**Problem.** `activate()` seeds `nicepay_mode=test`, `nicepay_test_mid='nicepay00m'` and NICEPAY's publicly documented demo Merchant Key. The gateway's `enabled` form field defaults to `'yes'`, and `WC_Settings_API::init_settings()` applies form-field defaults whenever `woocommerce_nicepay_settings` does not yet exist — i.e. immediately after activation. `is_available()` passes because the seeded demo credentials are non-empty. The only test-mode signal in the entire product is an 11 px pill inside the `<h1>` of the NicePay settings pages plus a one-line `notice-info` on the API tab. A repo-wide grep for `admin_notices` returns zero hits. The badge colours are also inverted against risk.

```php
define( 'NICEPAY_TEST_MID', 'nicepay00m' );
define( 'NICEPAY_TEST_MERCHANT_KEY', 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==' );
```
```css
.nicepay-mode-badge-test { background: #fef3c7; color: #92400e; }   /* amber = warning */
.nicepay-mode-badge-live { background: #dcfce7; color: #166534; }   /* green = safe   */
```

**Impact.** An install done "just to check it renders" starts offering NicePay at checkout. In test mode orders reach `payment_complete()` and are marked paid while no money settles. Sandbox transactions also run on a MID shared with every other developer, which the spec limits (§12: VBANK testable only up to issuance).

**Fix.** (1) Change the gateway's `enabled` default to `'no'`. (2) Add an `admin_notices` callback, `notice-warning`, on every admin screen while `nicepay_mode === 'test'`. (3) Append a test marker to `method_title` so the WooCommerce Payments list shows it. (4) Swap the badge colours so Live reads as the consequential state. (5) Replace the API-tab notice with an explicit statement that these are NICEPAY's published shared demo credentials.

### UX-040 — `MEDIUM` — Nothing greets the merchant after activation: no onboarding, no checklist, no link back to WooCommerce Payments

[nicepay-payment-gateway.php:92](../../nicepay-payment-gateway.php#L92) · [nicepay-payment-gateway.php:470](../../nicepay-payment-gateway.php#L470) · [includes/class-nicepay-gateway.php:61](../../includes/class-nicepay-gateway.php#L61)

**Problem.** No activation redirect, no welcome notice, no setup checklist. `plugin_action_links` adds only "Settings". The WooCommerce gateway screen links *forward* to the NicePay pages, but no NicePay page links *back* to WooCommerce → Settings → Payments, never mentions that the gateway must be enabled there, and never states whether it currently is. Greps for `wc-settings`, `section=nicepay` and `plugin_row_meta` all return zero. The go-live checklist, permalink fix, firewall IP list and support address exist only in `docs/USER-GUIDE.md`, unreachable from wp-admin.

**Impact.** A first-time merchant has no idea what the steps are or which are done. The one piece of genuinely essential cross-navigation — this plugin is inert until the WooCommerce gateway is switched on — is missing in the direction merchants actually travel.

**Fix.** A Setup panel pinned to the top of the General tab computing live state, with five checked/unchecked items and a direct action link each: credentials entered for the current mode; at least one method enabled; NicePay enabled in WooCommerce (`admin.php?page=wc-settings&tab=checkout&section=nicepay`, showing current on/off); return endpoint responding; switch to Live when ready. Auto-collapse once all five pass. Add a one-time activation `notice-info`, extend `plugin_action_links` with "Transactions", and add `plugin_row_meta` entries for Documentation and Support.

### UX-051 — `MEDIUM` — Transactions carry no test/live marker, so sandbox and real payments are indistinguishable in the ledger

[nicepay-payment-gateway.php:110](../../nicepay-payment-gateway.php#L110)–144 · [admin/class-nicepay-transactions.php:83](../../admin/class-nicepay-transactions.php#L83)–91 · [includes/nicepay-functions.php:48](../../includes/nicepay-functions.php#L48)–73

**Problem.** The 26-column transactions schema records no `mode` and no `mid`; `nicepay_save_transaction()` has no mode key; the list offers no such column and no such filter. Because the plugin ships in test mode with working demo credentials and the gateway enabled by default (UX-004), essentially every install accumulates sandbox rows before go-live — and after switching to Live they sit in the same list, in the same date order, visually identical to real money.

**Impact.** After go-live the ledger silently mixes sandbox with real transactions. Any count, spot-check or export is wrong, and there is no way to purge test data.

**Fix.** Add `mode varchar(4) NOT NULL DEFAULT 'test'` (and ideally `mid varchar(10)`), populate from `NicePay_API::is_test_mode()` in both `generate_payment_form()` and `ajax_init_payment()`, render a small TEST pill beside the TID, add Live/Test/All to the filter bar defaulting to Live once live credentials exist, and offer a one-click "Delete all test transactions". Depends on UX-052.

### UX-052 — `MEDIUM` — There is no database migration path, so no schema fix can ever reach an existing install

[nicepay-payment-gateway.php:92](../../nicepay-payment-gateway.php#L92)–98 · [nicepay-payment-gateway.php:110](../../nicepay-payment-gateway.php#L110) · [nicepay-payment-gateway.php:161](../../nicepay-payment-gateway.php#L161)

**Problem.** `create_tables()` is called from exactly one place — `activate()`. `add_option( 'nicepay_db_version', NICEPAY_VERSION )` is written once and read nowhere (`grep -rn 'nicepay_db_version'` returns that single line). There is no `plugins_loaded`/`admin_init` version check. Compounding it, the statement is `CREATE TABLE IF NOT EXISTS`, the documented anti-pattern for `dbDelta()` — its table-name regex `|CREATE TABLE ([^ ]*)|` captures `IF` rather than the table name, so even when called it cannot diff and add columns.

**Impact.** Every schema-level improvement recommended in this document — `currency` (UX-027), `mode` (UX-051), `refunded_amount` (UX-050), `vbank_exp_time` (UX-096 area) — would ship to new installs only. Existing merchants would silently keep the old table, with no error.

**Fix.** Drop `IF NOT EXISTS` so `dbDelta()` can parse the table name; add `nicepay_maybe_upgrade_db()` on `admin_init` comparing `get_option( 'nicepay_db_version' )` against `NICEPAY_VERSION`, re-running `create_tables()` and updating the option. Verify with a fresh-install-vs-upgraded-install column comparison before shipping any schema change. **This is a prerequisite for four other findings and should be scheduled first.**

## 1.2 Settings — General, API Credentials, Payment Methods

### UX-003 — `HIGH` — Live mode saves with empty credentials and the gateway silently vanishes from checkout

[admin/class-nicepay-admin.php:108](../../admin/class-nicepay-admin.php#L108) · [admin/class-nicepay-admin.php:288](../../admin/class-nicepay-admin.php#L288) · [includes/class-nicepay-gateway.php:81](../../includes/class-nicepay-gateway.php#L81)

**Problem.** `register_setting( 'nicepay_api', 'nicepay_live_mid' )` and `nicepay_live_merchant_key` are two-argument calls with no `sanitize_callback` and no validation. The Mode selector lives on the **General** tab (line 197) while Live MID / Live Merchant Key live on the **API Credentials** tab (lines 288–301), so a merchant can flip Mode to Live and save without ever seeing that both live credentials are empty. `is_available()` then returns false and the payment method disappears from checkout, while WooCommerce still lists NicePay as Enabled. Nothing on any admin screen says why. The only mitigation is a `notice-warning` on the API tab reading "You are currently in Live mode. Payments will be processed with real money." — a different tab from the Mode control, and silent about missing credentials.

**Impact.** The store's only payment method silently disappears after a Mode change the UI accepted without complaint. The merchant's first signal is that orders stopped arriving.

**Fix.**
```php
register_setting( 'nicepay_api', 'nicepay_live_mid', array(
    'sanitize_callback' => function ( $value ) {
        $value = trim( (string) $value );
        if ( '' !== $value && ! preg_match( '/^[A-Za-z0-9]{1,10}$/', $value ) ) {
            add_settings_error( 'nicepay_api', 'nicepay_live_mid',
                __( 'Live MID must be 1-10 letters or digits, exactly as issued by NICEPAY (for example yourshop01m). Leading or trailing spaces are not allowed.', 'nicepay-payment-gateway' ) );
            return get_option( 'nicepay_live_mid', '' );
        }
        return $value;
    },
) );
```
Add a `sanitize_callback` to `nicepay_mode` that refuses `live` when either live credential is empty; add an `admin_notices` entry that fires on every admin screen while the active mode has no MID or key; and move the Mode control onto the API Credentials tab, directly above the credential pair it selects. **Note (1)–(2) are inert until UX-019 is fixed — the settings-error channel is currently dead.**

### UX-019 — `MEDIUM` — Saving any settings tab produces no confirmation; `settings_errors()` is never called

[admin/class-nicepay-admin.php:19](../../admin/class-nicepay-admin.php#L19) · [admin/class-nicepay-admin.php:132](../../admin/class-nicepay-admin.php#L132) · [admin/class-nicepay-admin.php:191](../../admin/class-nicepay-admin.php#L191)

**Problem.** All three tabs post to `options.php`, which redirects back with `settings-updated=true` and stores "Settings saved." in the `settings_errors` transient. WordPress prints that from `wp-admin/options-head.php`, which `admin-header.php` includes only when `$parent_file === 'options-general.php'`. This plugin registers a **top-level** menu (`add_menu_page( …, 'nicepay-settings', … )`), so options-head.php is never loaded — and `render_settings_page()` never calls `settings_errors()` itself (grep returns zero hits).

**Impact.** Every save on General, API Credentials and Payment Methods is completely silent; the page reloads looking identical. It also means the entire `add_settings_error()` channel is dead, so validation added by UX-003, UX-007 and UX-045 would be invisible.

**Fix.** Two lines, and it unblocks four other findings:
```php
</h1>
<hr class="wp-header-end">
<?php settings_errors( 'nicepay_' . $active_tab ); ?>
```
Then make the confirmation useful per tab — on the API tab: "Credentials saved. You are in Test mode, so these Live credentials are not in use yet."

### UX-018 — `MEDIUM` — No connection or configuration health check: credentials cannot be verified before real money moves

[admin/class-nicepay-admin.php:250](../../admin/class-nicepay-admin.php#L250)–306 · [includes/class-nicepay-api.php:130](../../includes/class-nicepay-api.php#L130)–135

**Problem.** Nothing in the admin ever contacts NICEPAY. `render_api_tab()` is `settings_fields()`, one notice, four inputs and `submit_button()`. The only registered admin AJAX handlers are `nicepay_save_shortcode`, `nicepay_delete_shortcode` and `nicepay_cancel_transaction`. The first time a merchant discovers a wrong MID/key pair or a closed outbound firewall is when a real customer's payment fails.

**Impact.** The two most common go-live failures — wrong key, blocked egress — are trivially detectable and left undetected. The firewall host/IP list and the `it@nicepay.co.kr` contact exist only in `docs/USER-GUIDE.md:642`–646, unreachable from wp-admin.

**Fix.** A "Run health check" button rendering a pass/warn/fail list in an `aria-live="polite"` region: (1) credential shape — MID matches `/^[A-Za-z0-9]{1,10}$/`, key non-empty and base64-decodable, naming the offending field; (2) outbound reachability — `wp_remote_get()` with `'timeout' => 5` against each host already whitelisted in `NicePay_API::$allowed_hosts`, quoting the spec IPs 121.133.126.56 / 211.44.32.56 on failure so the merchant can forward them to their host; (3) credential handshake — POST to `NICEPAY_CANCEL_URL` with the current MID, a deliberately non-existent TID and a correct SignData; any well-formed JSON ResultCode proves MID and signature parsed; (4) return endpoint — `wp_remote_get( home_url('/nicepay-return/') )` asserting 405 (the handler's own response) not 404; (5) store readiness — gateway enabled, ≥1 method enabled, currency in {KRW, USD}. Every row must state the fix, not just the state.

### UX-021 — `MEDIUM` — Plugin never declares HPOS compatibility, so WooCommerce flags it as incompatible

[nicepay-payment-gateway.php:66](../../nicepay-payment-gateway.php#L66)–90 · [nicepay-payment-gateway.php:478](../../nicepay-payment-gateway.php#L478)–495

**Problem.** No `FeaturesUtil::declare_compatibility( 'custom_order_tables', … )` on `before_woocommerce_init` anywhere; greps for `declare_compatibility` and `before_woocommerce_init` both return zero.

**Impact.** WooCommerce → Settings → Advanced → Features lists NicePay under incompatible plugins, WooCommerce warns by name on HPOS stores, and blocks switching a store to HPOS while an undeclared plugin is active. The merchant is told by WooCommerce itself that their payment plugin is unsafe, before any code runs.

**Fix.** Add the declaration at file scope — but only after fixing UX-020, so the claim is truthful:
```php
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NICEPAY_PLUGIN_FILE, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', NICEPAY_PLUGIN_FILE, false );
    }
} );
```

### UX-024 — `MEDIUM` — Merchant Key is printed into the `value` attribute on every page load and re-saved on every Save

[admin/class-nicepay-admin.php:297](../../admin/class-nicepay-admin.php#L297) · [docs/DEVELOPER-GUIDE.md:454](../../docs/DEVELOPER-GUIDE.md#L454)

**Problem.** Both key fields are `type="password"` but the real secret is echoed into `value`. `type=password` masks pixels only; the plaintext is in the DOM and in view-source, and there is no reveal toggle so a merchant verifying a pasted key cannot see it. Worse, `docs/DEVELOPER-GUIDE.md` recommends supplying credentials via an `option_nicepay_live_merchant_key` filter reading a wp-config constant — the form reads the *filtered* value via `get_option()` and posts it straight back, so the next Save writes the wp-config secret into the options table, defeating the documented pattern.

**Impact.** A credential that authorises refunds sits in the page source of a screen that gets screen-shared and screenshotted, and the documented secure-storage pattern is silently defeated by the form itself.

**Fix.** Never re-render the secret: empty field with `placeholder="••••••••"` plus a last-4 fingerprint when a key exists; treat an empty submission as "leave unchanged" in the sanitize callback; add a show/hide toggle with `aria-pressed` for the entry case. When `has_filter( 'option_nicepay_live_merchant_key' )` is true or a `NICEPAY_LIVE_MERCHANT_KEY` constant is defined, render the field disabled with "Set in wp-config.php — edit it there" and skip the option write entirely.

### UX-026 — `MEDIUM` — Transactions and settings require `manage_options`, locking out WooCommerce shop managers

[admin/class-nicepay-admin.php:22](../../admin/class-nicepay-admin.php#L22) · [admin/class-nicepay-transactions.php:17](../../admin/class-nicepay-transactions.php#L17) · [nicepay-payment-gateway.php:365](../../nicepay-payment-gateway.php#L365)

**Problem.** Both menu pages, both render guards and every AJAX endpoint check `manage_options`, an Administrator-only capability. WooCommerce's own order and refund screens use `manage_woocommerce`. Repo-wide grep for `manage_woocommerce` returns zero hits.

**Impact.** The people who actually process refunds and answer payment questions cannot open NicePay → Transactions at all — the menu is not there. The entire operational surface is reserved for site administrators, forcing role escalation or admin-only support handling.

**Fix.** Split as WooCommerce does: keep `manage_options` for Settings (credentials, mode); use `manage_woocommerce` with a `manage_options` fallback for the Transactions page and `nicepay_cancel_transaction`. Expose it through `apply_filters( 'nicepay_admin_capability', $cap, $context )`. The capability must be changed in `add_submenu_page()` **and** in the render guard together, or the page 404s.

### UX-038 — `MEDIUM` — Settings labels use Korean-PG jargon with no explanation a merchant could act on · *needs confirmation on severity, code state confirmed*

[admin/class-nicepay-admin.php:210](../../admin/class-nicepay-admin.php#L210)–243 · [admin/class-nicepay-admin.php:268](../../admin/class-nicepay-admin.php#L268)–302 · [includes/class-nicepay-gateway.php:149](../../includes/class-nicepay-gateway.php#L149)

**Problem.** Field by field, the settings screens assume the merchant already knows the NICEPAY protocol. **Charset** (utf-8 / euc-kr) — no description at all, yet the wrong value garbles Korean product names (and see UX-073). **Language** — labelled just "Language", one screen away from WordPress's own site-language setting; it actually sets `NpLang`, the language of the NICEPAY popup. **Currency** — no description; it does *not* control WooCommerce (the gateway sends `$order->get_currency()`) but does control shortcode payments and every amount in Transactions. **MID / Merchant Key** — the four most important fields in the product have zero help text: no format, no length, no source, no portal link. **Mode** — "Use Test mode for development. Switch to Live for production." says nothing about what changes. **Virtual Account Expiry (days)** — does not say what happens at expiry, or that it is inert unless VBANK is enabled.

**Impact.** The plugin cannot be configured correctly without the vendor PDF — the exact outcome the product exists to avoid. Two of these (Currency, Charset) are silently destructive when set wrong.

**Fix.** See the microcopy table in §1.7. Add a persistent sidebar card with the merchant portal link and `it@nicepay.co.kr`.

### UX-045 — `MEDIUM` — Settings are stored without whitelist validation, and a blank expiry field issues an already-expired virtual account

[admin/class-nicepay-admin.php:100](../../admin/class-nicepay-admin.php#L100)–103 · [admin/class-nicepay-admin.php:335](../../admin/class-nicepay-admin.php#L335) · [includes/class-nicepay-api.php:426](../../includes/class-nicepay-api.php#L426)

**Problem.** `nicepay_mode`, `nicepay_language`, `nicepay_currency` and `nicepay_charset` are registered with no `sanitize_callback` and no `type`, so POST is written verbatim. `nicepay_mode` is compared as `=== 'test'`, so any unexpected value silently selects **live** credentials. `nicepay_vbank_expiry_days` uses `absint`, which accepts 0 and 999 despite the input advertising `min="1" max="30"` — and because the input has no `required`, clearing the field and saving stores `absint('') === 0` through the normal UI. `get_vbank_exp_date()` then returns `date( 'YmdHi', strtotime( '+0 days' ) )` — an account that is already expired.

**Impact.** Out-of-range or unexpected values produce a wrong-but-silent configuration: live mode without the merchant choosing it, or a virtual account no customer can pay into, with no error and no visible symptom until a customer tries to deposit.

**Fix.** Whitelist every setting: mode ∈ {test, live}, language ∈ {KO, EN, CN}, currency ∈ {KRW, USD}, charset ∈ {utf-8, euc-kr}, expiry clamped with `min( 30, max( 1, absint( $v ) ) )`. Invert the mode check to `=== 'live'` so an unrecognised value fails safe into test. Add `required` to the expiry input. Register a settings error whenever a value is coerced.

### UX-042 — `MEDIUM` — Switching settings tabs silently discards unsaved changes

[admin/class-nicepay-admin.php:141](../../admin/class-nicepay-admin.php#L141)–160 · [admin/class-nicepay-admin.php:191](../../admin/class-nicepay-admin.php#L191)

**Problem.** Each tab is a separate `<form action="options.php">` and the tabs are plain `<a href>` links. No `beforeunload` handler exists anywhere (grep: zero hits). The Mode/credential split across tabs (UX-003) makes this near-certain: the merchant must cross tabs to complete one conceptual task.

**Impact.** Silent data loss during the most sensitive configuration in the product — re-typing an 88-character Merchant Key.

**Fix.** Track `input`/`change`, set a dirty flag, register `beforeunload`, and intercept tab links with Save / Discard / Stay. Better: consolidate General + API Credentials + Payment Methods into one scrollable page with section anchors and a single sticky Save bar — those three tabs contain ten fields between them and do not warrant separate forms.

### UX-048 — `MEDIUM` — No guard or warning when the store currency is one NICEPAY cannot process

[includes/class-nicepay-gateway.php:76](../../includes/class-nicepay-gateway.php#L76)–86 · [includes/class-nicepay-gateway.php:149](../../includes/class-nicepay-gateway.php#L149) · [admin/class-nicepay-admin.php:229](../../admin/class-nicepay-admin.php#L229)–232

**Problem.** The plugin knows NICEPAY supports only KRW and USD — the Currency dropdown offers exactly those two, matching the spec. But the gateway sends `$order->get_currency()` straight through as `CurrencyCode`, and `is_available()` checks only `enabled`, `get_mid()` and `get_merchant_key()`. A EUR or TRY store will offer NicePay, format amounts with two decimals, and send an unsupported CurrencyCode. The failure mode appears in `docs/USER-GUIDE.md:674` but nowhere in the product.

**Impact.** Silent checkout failure for any store outside the two supported currencies, diagnosable only from a customer's screenshot of the Korean payment popup.

**Fix.** Add a currency guard to `is_available()` returning false outside `array( 'KRW', 'USD' )`, paired with an unmissable `notice-error` on both the NicePay settings pages and the WooCommerce gateway screen: *"NicePay cannot accept EUR. Your store currency is EUR, but NICEPAY supports KRW and USD only, so NicePay is hidden at checkout. Change your store currency in WooCommerce → Settings → General, or contact NICEPAY about multi-currency support for your MID."* Hiding the gateway is safer than a doomed payment window — but only if the reason is stated loudly. See also UX-060.

## 1.3 Payment Methods tab

### UX-007 — `HIGH` — Saving zero enabled payment methods stores an empty array, dropping the required `PayMethod` and warning on a public page

[admin/class-nicepay-admin.php:114](../../admin/class-nicepay-admin.php#L114)–116 · [templates/payment-form.php:56](../../templates/payment-form.php#L56)–58 · [templates/standalone-payment-form.php:39](../../templates/standalone-payment-form.php#L39)

**Problem.** The checkbox group has no minimum. When every box is unchecked, `$_POST['nicepay_enabled_methods']` is absent, `options.php` passes `null`, `is_array(null)` is false, and the callback stores `array()`. Because the option now *exists*, the `array('CARD')` fallback in every `get_option()` call never applies. The receipt template then skips the `PayMethod` hidden input entirely; the standalone template evaluates `$enabled_methods[0]` on an empty array. The callback also does not validate against known method codes, so a hand-crafted POST can store arbitrary strings.

```php
'sanitize_callback' => function ( $value ) {
    return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
},
```

**Impact.** Checkout silently breaks (missing required `PayMethod`) and the shortcode form emits `Undefined array key 0` on a public page under PHP 8. No warning at save time, no indication afterwards. Customer-side consequences in UX-017.

**Fix.**
```php
'sanitize_callback' => function ( $value ) {
    $valid = array_keys( NicePay_API::get_available_methods() );
    $value = is_array( $value ) ? array_values( array_intersect( $value, $valid ) ) : array();
    if ( empty( $value ) ) {
        add_settings_error( 'nicepay_payment', 'nicepay_no_methods',
            __( 'Choose at least one payment method — NicePay cannot be offered at checkout with none selected.', 'nicepay-payment-gateway' ) );
        return get_option( 'nicepay_enabled_methods', array( 'CARD' ) );
    }
    return $value;
},
```
Mirror it client-side by disabling Save with an inline message, and independently harden both templates with `$enabled_methods = $enabled_methods ? $enabled_methods : array( 'CARD' );`.

### UX-039 — `MEDIUM` — VBANK expiry is always visible while the spec-required CELLPHONE goods class is hardcoded and not exposed

[admin/class-nicepay-admin.php:332](../../admin/class-nicepay-admin.php#L332)–341 · [includes/class-nicepay-gateway.php:201](../../includes/class-nicepay-gateway.php#L201)–203 · [templates/payment-form.php:62](../../templates/payment-form.php#L62)

**Problem.** Two symmetric conditional-disclosure faults. "Virtual Account Expiry (days)" renders unconditionally even when VBANK is unchecked, so merchants configure something inert. Meanwhile `GoodsCl` — spec-required for CELLPHONE, distinguishing physical goods (`1`) from digital contents (`0`) — is hardcoded to `'1'` in all three emission points with no setting anywhere. The same applies to `MallUserID` for GIFT_CULT, which silently sends the billing email with no disclosure.

**Impact.** Merchants configure a field that does nothing, and merchants selling content have mobile payments mis-categorised with no control and no visibility. Carriers apply different limits per goods class, so this is not cosmetic. Customer-side in UX-062.

**Fix.** Progressive disclosure per method — a nested block under each checked method, toggled by JS and gated server-side. Under Virtual Account: the expiry field with *"How long the customer has to deposit. After this the account number stops working and the order stays unpaid."* Under Mobile Payment: a new `nicepay_cellphone_goods_cl` select — *"What are you selling? · Physical goods (shipped items) · Digital content (downloads, subscriptions)"* with *"Mobile carriers apply different limits to each."* Under Culture Cash: a note that the buyer's email is sent as MallUserID. Add to every method: *"Available methods depend on your MID contract with NICEPAY — enabling one your MID does not support causes an error in the payment window."*

## 1.4 Transactions — the plugin's primary operational surface

This is the screen a merchant lives in, and it is the largest concentration of missed opportunity in the product. The database row for a failed payment contains the result code, the vendor's message, the full JSON approval response, the card issuer, the masked PAN, the instalment count and the virtual-account number. The screen renders none of it.

| # | Column rendered | Data available in the same row but not shown |
|---|---|---|
| 1 | ID | — |
| 2 | TID | `moid`, `auth_token` |
| 3 | Order | order number, order status |
| 4 | Amount | `currency` (not even stored), `refunded_amount` (not stored) |
| 5 | Method | `card_name`, `card_no`, `card_quota`, `bank_name`, `vbank_num`, `vbank_exp_date` |
| 6 | Status | `result_code`, `result_msg`, `payment_data` |
| 7 | Buyer | `buyer_email`, `buyer_tel` |
| 8 | Date | timezone |
| 9 | Actions | — |

### UX-005 — `HIGH` — ResultCode, ResultMsg and the raw approval payload are captured and never rendered anywhere in wp-admin

[admin/class-nicepay-transactions.php:83](../../admin/class-nicepay-transactions.php#L83)–91 · [nicepay-payment-gateway.php:120](../../nicepay-payment-gateway.php#L120)–135 · [includes/nicepay-functions.php:17](../../includes/nicepay-functions.php#L17)–19

**Problem.** The table stores `result_code`, `result_msg`, `payment_data` (the full JSON approval response), `auth_token`, card and bank fields and `moid`, all populated on every return. The admin renders nine columns and none of the diagnostic ones. A failed payment shows a red "Failed" badge and nothing else — no ResultCode, no ResultMsg, no Moid, no AuthDate. There is no detail view, no expandable row, no payload viewer, no log viewer. `nicepay_log()` is a no-op unless the merchant hand-edits `wp-config.php` (`if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) { return; }`), and there is no ResultCode→explanation map anywhere in product code.

**Impact.** Failed-payment triage — a core merchant workflow — is impossible from the admin even though the evidence is one column away in the same row. Support tickets to `it@nicepay.co.kr` need MID/TID/Moid/EdiDate; the screen surfaces only TID.

**Fix — the single highest-value addition to this product.**
1. Make the TID cell and a new "Details" row action open a slide-over showing Status, ResultCode with a plain-English explanation, ResultMsg verbatim (it arrives in Korean — label it "NICEPAY message"), and a Reference block (MID, Moid, TID, AuthCode, AuthDate, PayMethod, Amount, Currency).
2. Add "Copy diagnostic report" plus a `mailto:it@nicepay.co.kr` link pre-filled with subject `[MID xxx] Payment failure TID xxx`.
3. Add a collapsed `<details>` "Raw response from NICEPAY" rendering `payment_data` as pretty JSON, reusing the redaction list already in `NicePay_API::redact_for_log()` ([includes/class-nicepay-api.php:162](../../includes/class-nicepay-api.php#L162)–165) so CardNo/VbankNum are masked.
4. Add `nicepay_get_result_explanation( $code, $method )` covering at minimum `0000`, `3001`, `4000`, `4100`, `A000`, `2001`, `2211` and the plugin's own synthetic codes — `SIG_FAIL` → *"Signature check failed — your Merchant Key does not match the MID that processed this payment. Check API Credentials."*; `NET_ERROR` → *"Could not reach NICEPAY. Check outbound HTTPS to pg-api / dc1-api / dc2-api.nicepay.co.kr on port 443."*
5. Add a "Debug logging" checkbox on the General tab; have `nicepay_log()` honour `WP_DEBUG || get_option('nicepay_debug_log')`, with a link to WooCommerce → Status → Logs.

### UX-006 — `HIGH` — Cancelling from the Transactions page writes no WooCommerce refund and silently restocks the order

[admin/class-nicepay-transactions.php:225](../../admin/class-nicepay-transactions.php#L225)–230 · [admin/class-nicepay-transactions.php:208](../../admin/class-nicepay-transactions.php#L208) · [includes/class-nicepay-gateway.php:453](../../includes/class-nicepay-gateway.php#L453)–469

**Problem.** `ajax_cancel_transaction()` calls the PG and then does `$order->update_status( 'cancelled', … )`. It never calls `wc_create_refund()`. WooCommerce records no refund line, `get_total_refunded()` stays 0, the order shows Cancelled rather than Refunded, and the `woocommerce_order_status_cancelled` transition fires `wc_maybe_increase_stock_levels`, restocking items that may already have shipped. The same operation through WooCommerce → Orders → Refund goes via `process_refund()` and is bookkept correctly. Two buttons, same API call, divergent ledgers. The screen also always sends a **full** cancel — `request_cancel( $tid, $cancel_amount, $reason, $transaction->moid )` leaves `$partial` at its `false` default — so partial refunds are impossible from here.

**Impact.** Money is genuinely returned but WooCommerce has no refund record; the order still shows the full amount captured, so a merchant can attempt a second refund through the WooCommerce UI. Stock is silently restored on a cancel-after-fulfilment.

**Fix.** For any transaction with a non-null `wc_order_id`, do not cancel from this screen — replace the button with a "Refund in WooCommerce →" link to `$order->get_edit_order_url()`, so there is exactly one code path and WooCommerce owns the ledger. Keep in-place Cancel only for standalone (shortcode) transactions where `wc_order_id` is null. If in-place cancel must stay for WC orders, route it through `wc_create_refund( array( 'order_id' => …, 'amount' => …, 'reason' => …, 'refund_payment' => true ) )`.

### UX-020 — `MEDIUM` — The Order column hardcodes the legacy `post.php` edit URL · *needs confirmation*

[admin/class-nicepay-transactions.php:126](../../admin/class-nicepay-transactions.php#L126)–128

**Problem.** The cell builds `admin_url( 'post.php?post=' . $item->wc_order_id . '&action=edit' )`. Under High-Performance Order Storage — default for new WooCommerce installs since 8.2 — orders live in `wp_wc_orders` and the storage-agnostic URL is `$order->get_edit_order_url()`. The cell also prints the raw post ID rather than the order number and has no handling for a deleted order. Grep for `get_edit_order_url` returns zero hits. *Needs confirmation: whether the installed WooCommerce version redirects legacy `post.php?post=<order>` URLs to the HPOS editor could not be verified here, and behaviour differs between HPOS-with-sync and HPOS-authoritative.*

**Impact.** On HPOS stores without compatibility-mode post syncing, the only navigational bridge from the NicePay ledger to the actual order leads to an error screen, severing the merchant's core workflow.

**Fix.** Correct in every storage mode, and it also fixes the raw-ID display and the deleted-order case:
```php
$wc_order = $item->wc_order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $item->wc_order_id ) : false;
if ( $wc_order ) : ?>
    <a href="<?php echo esc_url( $wc_order->get_edit_order_url() ); ?>">#<?php echo esc_html( $wc_order->get_order_number() ); ?></a>
    <span class="nicepay-order-status"><?php echo esc_html( wc_get_order_status_name( $wc_order->get_status() ) ); ?></span>
<?php elseif ( $item->wc_order_id ) : ?>
    #<?php echo esc_html( $item->wc_order_id ); ?> <em><?php esc_html_e( '(order deleted)', 'nicepay-payment-gateway' ); ?></em>
<?php else : ?>
    <code><?php echo esc_html( $item->moid ); ?></code>
<?php endif;
```

### UX-022 — `MEDIUM` — The cancel dialog names neither the transaction nor the amount, and its two buttons read "Cancel" and "Cancel Transaction"

[admin/class-nicepay-admin.php:79](../../admin/class-nicepay-admin.php#L79)–84 · [assets/js/nicepay-admin.js:144](../../assets/js/nicepay-admin.js#L144)–153 · [admin/class-nicepay-transactions.php:183](../../admin/class-nicepay-transactions.php#L183)

**Problem.** The confirmation modal for reversing a real payment contains title "Cancel Transaction", the sentence "This action cannot be undone. The payment will be reversed.", a reason box, a dismiss button labelled **Cancel** and a confirm button labelled **Cancel Transaction**. It never states which transaction, how much money, which buyer or which method — the handler reads `btn.data('tid')` and `btn.data('id')` and passes neither into the copy. The confirm is styled `nicepay-modal-btn-danger`; the dismiss is neutral. The reason box has no `maxlength`, and the reason flows through `sanitize_text_field()` straight into `CancelMsg`, which the spec caps at 100 bytes — a longer reason is rejected by the PG with an opaque error. *Mitigation: an empty reason is rejected client-side ([assets/js/nicepay-admin.js:155](../../assets/js/nicepay-admin.js#L155)–158), so a single misclick plus Enter cannot fire the refund.*

**Impact.** A destructive money-moving action is confirmed with no statement of what is being reversed, and the labelling actively invites the wrong choice: a merchant who wants to back out reads "Cancel Transaction" as "cancel this dialog".

**Fix.** Rewrite the dialog — see the before/after table in §1.7. Enforce `mb_strcut( $reason, 0, 100 )` server-side and add `maxlength="100"` with a live counter. Pass tid/amount/buyer/method through `data-` attributes on the row button, which already carries `data-tid` and `data-id`.

### UX-023 — `MEDIUM` — Card issuer, masked PAN, instalment and virtual-account details are stored on every approval and never displayed

[admin/class-nicepay-transactions.php:134](../../admin/class-nicepay-transactions.php#L134) · [includes/class-nicepay-return-handler.php:128](../../includes/class-nicepay-return-handler.php#L128)–140 · [includes/nicepay-functions.php:285](../../includes/nicepay-functions.php#L285)

**Problem.** `card_name`, `card_no` (masked by NICEPAY), `card_quota`, `bank_name`, `vbank_num`, `vbank_exp_date`, `buyer_email` and `buyer_tel` are written on every approval by both return paths and none appears in the admin. The Method column renders only `pay_method_name ?: payment_method`; the Buyer column only `buyer_name`. `nicepay_get_card_name()` and `nicepay_get_bank_name()` exist and are called from nowhere in the entire codebase.

**Impact.** The commonest merchant question — *"which card was this, and does the last four match what the customer is telling me?"* — cannot be answered from the payments screen even though the answer is in the same database row. Two ready-made lookup helpers sit unused.

**Fix.** Enrich the two existing columns rather than adding new ones. Method → `Credit Card · Shinhan · ••••1234`, with `3-month instalment` appended when `card_quota` is not `"00"`; for VBANK → `Virtual Account · KB 12345678901 · expires 12 Mar`. Buyer → name on line 1, `mailto:` email on line 2. Everything else in the UX-005 detail panel. Use the two unused helpers as the fallback when the PG returns a code without a name. Also give the Method cell an em-dash placeholder — for a `pending` row both fields are empty and the cell renders blank.

### UX-027 — `MEDIUM` — Amounts are formatted with one global currency option, so mixed-currency stores display wrong money

[admin/class-nicepay-transactions.php:133](../../admin/class-nicepay-transactions.php#L133) · [includes/nicepay-functions.php:230](../../includes/nicepay-functions.php#L230)–238 · [nicepay-payment-gateway.php:110](../../nicepay-payment-gateway.php#L110)–144

**Problem.** No currency column exists, and the list calls `nicepay_format_amount( $item->amount )` with no currency argument, so it falls back to `get_option( 'nicepay_currency', 'KRW' )` — a global setting unrelated to the currency the transaction was taken in. A USD-configured store renders a 10,000 KRW order as "10,000.00 USD"; a KRW-configured store renders a 10.99 USD order as "11 KRW" because the KRW branch casts to `int`. The codebase already knows this is wrong: `ajax_cancel_transaction()` deliberately resolves currency from the WooCommerce order before computing the cancel amount ([lines 197](../../admin/class-nicepay-transactions.php#L197)–205).

**Impact.** Money is displayed with the wrong symbol and decimals on the one screen whose entire job is showing money. Reconciliation against NICEPAY's dashboard finds mismatched figures.

**Fix.** Add `currency varchar(3) NOT NULL DEFAULT 'KRW'`, populate from `$order->get_currency()` in `generate_payment_form()` and from the shortcode `currency` attribute in `ajax_init_payment()`, pass `$item->currency` into `nicepay_format_amount()`, and show the currency code in the header when the result set contains more than one. Requires UX-052 first.

### UX-028 — `MEDIUM` — Dates are printed raw from MySQL with no timezone conversion or locale formatting

[admin/class-nicepay-transactions.php:141](../../admin/class-nicepay-transactions.php#L141) · [nicepay-payment-gateway.php:136](../../nicepay-payment-gateway.php#L136) · [includes/nicepay-functions.php:174](../../includes/nicepay-functions.php#L174)–181

**Problem.** The Date column echoes `$item->created_at` verbatim. That column is written by MySQL's `DEFAULT CURRENT_TIMESTAMP` — the database session's clock, not the site timezone and not necessarily UTC. Rendering ignores the site's `date_format`/`time_format` entirely and shows no timezone. The date filters compare against the same ambiguous column.

**Impact.** A Korean merchant on a UTC database sees timestamps nine hours behind NICEPAY's dashboard, with nothing indicating the offset. Filtering by a single day can silently include or exclude the wrong rows.

**Fix.** Write `current_time( 'mysql', true )` explicitly in `nicepay_save_transaction()` instead of relying on the DB default; render with `wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->created_at . ' UTC' ) )`. Show relative time ("2 hours ago") as primary with the absolute timestamp in `title`, matching WooCommerce's order list. Convert filter bounds from site time to UTC before querying.

### UX-029 — `MEDIUM` — The transactions screen is a hand-rolled table, not a `WP_List_Table` — no sorting, bulk actions, per-page or export

[admin/class-nicepay-transactions.php:21](../../admin/class-nicepay-transactions.php#L21) · [admin/class-nicepay-transactions.php:83](../../admin/class-nicepay-transactions.php#L83)–91 · [includes/nicepay-functions.php:195](../../includes/nicepay-functions.php#L195)–197

**Problem.** Despite the `wp-list-table widefat fixed striped` classes this is a bespoke `<table>`. Missing versus every other WordPress list screen: sortable column headers, a top tablenav, per-page Screen Options (`$per_page = 20` is hardcoded with no `get_user_option`/`set_screen_option`), bulk actions, hover row actions, status-count links ("All (120) | Paid (98) | Failed (14)"), a `<caption>`, and any form of export. Greps for `WP_List_Table`, `screen_option`, `export` and `csv` all return zero. The query layer *already* whitelists `created_at`, `amount`, `status`, `payment_method` for `orderby` — and `render()` never passes `orderby`/`order` into `$args` at all. (A bottom tablenav with `paginate_links()` does exist at lines 159–175.)

**Impact.** A payments ledger that cannot be sorted by amount, cannot show more than 20 rows per page, and cannot be exported is unusable for reconciliation, accounting handover, or investigating a spike in failures.

**Fix.** Extend `WP_List_Table` — sortable headers, per-page Screen Options, bulk actions, row actions and the standard tablenav come essentially free and make the screen feel native. Wire the existing `orderby`/`order` whitelist to the sortable columns. Add status-count links above the table. Add "Export CSV" streaming the current filtered result set including `result_code`, `result_msg`, `card_name`, `card_no`, `vbank_num` and currency — the single most-requested feature on any payments screen, and the data is already queryable.

### UX-032 — `MEDIUM` — A failed cancel closes the dialog before the result is checked, discarding the typed reason

[assets/js/nicepay-admin.js:168](../../assets/js/nicepay-admin.js#L168)–183 · [admin/class-nicepay-transactions.php:234](../../admin/class-nicepay-transactions.php#L234)–235

**Problem.** `modal.close()` is called unconditionally at the top of the success callback, before `if (response.success)` is evaluated, and again in `.fail()`. On any error the dialog vanishes, the typed reason is destroyed, and the outcome arrives as a transient toast carrying the PG's raw `ResultMsg` (Korean) with no ResultCode, self-destructing after 4 seconds. The ResultCode *is* read one line earlier at [:215](../../admin/class-nicepay-transactions.php#L215) and then dropped from the error response.

**Impact.** Retrying a failed refund means re-typing the reason every time, and the merchant sees an untranslated vendor string with no code to quote in a support ticket, in a message that disappears.

**Fix.** Move `modal.close()` inside the `if (response.success)` branch. On failure, clear the loading state, preserve the input, and render an inline `role="alert"` block inside the modal body: *"NICEPAY refused this refund. Code {ResultCode} — {ResultMsg}"* plus a plain-English hint from the UX-005 result map and a "Copy details" button. Return `result_code` alongside `message`.

### UX-046 — `MEDIUM` — The cancel dialog gives no progress feedback during a request that can take 30 seconds

[assets/js/nicepay-admin.js:122](../../assets/js/nicepay-admin.js#L122)–126 · [includes/class-nicepay-api.php:360](../../includes/class-nicepay-api.php#L360)–361

**Problem.** `setLoading(true)` only sets `disabled` on the two modal buttons — no spinner, no label change, no explanation. The underlying `wp_remote_post` to `cancel_process.jsp` is configured with `'timeout' => 30`, matching the spec's read timeout, so the merchant can face a frozen dialog with two greyed-out buttons for half a minute after a destructive click.

**Impact.** The merchant assumes the click failed and reloads or clicks elsewhere, with no idea whether a real refund is in flight. For a money-moving operation that ambiguity is the worst possible state.

**Fix.** Swap the confirm label to "Refunding…" with core's `<span class="spinner is-active">`, set `aria-busy="true"`, add an `aria-live="polite"` line: *"Contacting NICEPAY — this can take up to 30 seconds. Do not close this window."* After 10 seconds update it to *"Still waiting for NICEPAY…"*. Escape and overlay-click are already correctly suppressed via `isLoading`.

### UX-049 — `MEDIUM` — Every load of the order-pay page inserts a new `pending` row, so the ledger fills with duplicate abandoned attempts

[includes/class-nicepay-gateway.php:115](../../includes/class-nicepay-gateway.php#L115) · [includes/class-nicepay-gateway.php:158](../../includes/class-nicepay-gateway.php#L158)–168 · [admin/class-nicepay-transactions.php:24](../../admin/class-nicepay-transactions.php#L24)–32

**Problem.** `receipt_page()` calls `generate_payment_form()` on every render, which unconditionally generates a fresh Moid, overwrites `_nicepay_moid`, and calls `nicepay_save_transaction()` with `'status' => 'pending'`. There is no check for an existing pending row for the same order. A customer who lands, hits back, returns or refreshes creates one row per view, and every abandoned checkout leaves a permanent row. There is no cleanup, no bulk delete, no "hide abandoned" filter, and the list is sorted `created_at DESC` with no default status filter.

**Impact.** The plugin's primary operational surface progressively fills with noise the merchant cannot clear or filter out, burying the paid and failed rows that matter. On a busy store the default view becomes useless within weeks.

**Fix.** Before inserting, look for an existing `pending` row for this `wc_order_id` and update it in place (Moid and EdiDate must still be regenerated per attempt — they can overwrite the same row). Additionally default the list to hiding `pending` rows older than 24 hours behind a "Show abandoned attempts" toggle, and add a bulk delete for `pending` rows. Both are needed; dedupe alone still leaves one stale row per abandoned checkout.

### UX-050 — `MEDIUM` — A partial refund flips the whole row to "Refunded" with no amount, and removes the only cancel action

[includes/class-nicepay-gateway.php:439](../../includes/class-nicepay-gateway.php#L439) · [includes/class-nicepay-gateway.php:464](../../includes/class-nicepay-gateway.php#L464)–466 · [admin/class-nicepay-transactions.php:143](../../admin/class-nicepay-transactions.php#L143)

**Problem.** `process_refund()` computes `$is_partial` then writes `'status' => $is_partial ? 'refunded' : 'cancelled'` with no record of how much was refunded and no update to `amount`. A 1,000 KRW refund against a 100,000 KRW payment marks the row "Refunded" while Amount still shows 100,000 KRW. Because the Actions column gates on `in_array( $item->status, array( 'paid', 'waiting' ), true )`, the row also loses its Cancel button, so the remaining 99,000 KRW can no longer be cancelled from this screen. There is no `refunded_amount` column in the schema.

**Impact.** The merchant-facing ledger materially misrepresents the state of the money, then blocks any further action on it.

**Fix.** Add `refunded_amount decimal(12,2) NOT NULL DEFAULT 0`, accumulate into it, render `100,000 KRW · 1,000 refunded` with a `partially-refunded` badge distinct from `refunded`, and keep the row actionable while `refunded_amount < amount` (routing that action to the WooCommerce refund screen per UX-006). Requires UX-052.

### UX-090 — `LOW` — The empty state does not distinguish "no transactions yet" from "no matches", and filters cannot be cleared

[admin/class-nicepay-transactions.php:95](../../admin/class-nicepay-transactions.php#L95)–106 · [admin/class-nicepay-transactions.php:42](../../admin/class-nicepay-transactions.php#L42)–68

**Problem.** Whatever the merchant filtered on, an empty result renders the same illustration and copy: *"No transactions found"* / *"Transactions will appear here once payments are made."* The second sentence is actively false when the store has thousands of transactions and the merchant filtered a date range with none. There is no Reset/Clear control, no summary of active filters, and no route back to the unfiltered list except editing the URL. The same state appears when a bookmarked `paged=5` URL is opened after the result set shrank.

**Impact.** The merchant concludes there are no transactions at all and stops looking, or gets stuck in a filtered state they cannot escape without understanding query strings.

**Fix.** Branch on whether any of `filter_status`, `filter_method`, `date_from`, `date_to`, `s` is non-empty. Filtered: *"No transactions match these filters. Try widening the date range, or clear the filters to see everything."* with a prominent **Clear filters** button linking to `admin.php?page=nicepay-transactions`. Always render Clear filters beside Filter while any filter is active, and change the count line to *"12 of 4,013 transactions match your filters"*.

### UX-112 — `LOW` — Truncated TIDs have no hover or focus affordance to reveal the full value

[assets/css/nicepay-admin.css:133](../../assets/css/nicepay-admin.css#L133)–144 · [admin/class-nicepay-transactions.php:114](../../admin/class-nicepay-transactions.php#L114)

**Problem.** `.nicepay-tid-cell code` caps at `max-width: 160px` with `text-overflow: ellipsis`, and the `<code>` carries no `title`. The copy button one line below *does* carry a full-value `aria-label`, so screen-reader users get the whole TID while sighted users do not.

**Impact.** An admin reconciling against the NICEPAY dashboard must click Copy and paste elsewhere just to read a value the screen is already displaying half of.

**Fix.** `title="<?php echo esc_attr( $item->tid ); ?>"` on the `<code>`. One line, zero risk.

## 1.5 Shortcodes and the Shortcode Generator

The builder is the best-designed part of the plugin (see §What this codebase does well) and also the one with the most self-inflicted traps.

### UX-025 — `MEDIUM` — Copied shortcodes bake in every value, so editing a saved shortcode never updates the pages using it

[admin/class-nicepay-admin.php:370](../../admin/class-nicepay-admin.php#L370)–380 · [admin/class-nicepay-admin.php:712](../../admin/class-nicepay-admin.php#L712)–714 · [nicepay-payment-gateway.php:248](../../nicepay-payment-gateway.php#L248)–259

**Problem.** The point of saving a named shortcode and referencing it by `id` is central management, but both the card grid and the generator emit `id="…"` **plus every attribute inline**, and `render_payment_shortcode()` applies the saved config only as *defaults* which explicit attributes then override (`shortcode_atts( $defaults, $raw_atts, … )` gives `$raw_atts` priority). A pasted shortcode is therefore fully self-contained and ignores every later edit. A comment in the builder acknowledges the intent — *"show id-based shortcode as a short form but also show full shortcode below"* — but no short form is ever rendered.

**Impact.** A merchant changes the price on the "Subscription" preset, sees the card and live preview update, and the published page keeps charging the old amount indefinitely. The `id=` in the pasted output implies a link that does not exist.

**Fix.** Default the copied output to the linked form `[nicepay_payment id="subscription"]` and add a segmented control above the output box — **Linked (recommended)** / **Self-contained** — with helper text: *"Linked: edit this shortcode here and every page updates. Self-contained: values are baked in and will not change."* If both an id and overrides coexist, name which attributes are overriding.

### UX-030 — `MEDIUM` — The builder saves a shortcode it has already flagged as invalid, publishing an error message to visitors

[admin/class-nicepay-admin.php:788](../../admin/class-nicepay-admin.php#L788)–796 · [admin/class-nicepay-admin.php:853](../../admin/class-nicepay-admin.php#L853)–857 · [templates/standalone-payment-form.php:27](../../templates/standalone-payment-form.php#L27)–29

**Problem.** The builder marks Amount and Product name with a red `*` and shows "Amount is required, Product name is required" in the preview column, but that validity flag only disables the **Copy** button. The **Save Shortcode** button validates the Name field alone, and `ajax_save_shortcode()` server-side likewise checks only `name`. A shortcode with no amount saves cleanly, shows a success toast, appears as a normal card, and on a page the front-end template bails out with a red *"Invalid payment amount."* box shown to the visitor.

**Impact.** A broken payment button ships to a public page while the UI signalled success. The merchant learns about it from a customer.

**Fix.** Disable `#sc-save-btn` with `aria-disabled="true"` while `valid === false`, and mirror the check server-side (reject when the amount is empty or `(float) $amount <= 0`). Attach messages to the fields rather than only to the sticky preview column. *Precision:* only a missing/zero amount is fatal on the front end — a missing Product name renders an empty `<h3>` (UX-074), so the server guard should key on amount and Product name should stay a UI-level requirement.

### UX-031 — `MEDIUM` — The generator's Copy button silently does nothing outside a secure context

[admin/class-nicepay-admin.php:832](../../admin/class-nicepay-admin.php#L832)–841 · [assets/js/nicepay-admin.js:200](../../assets/js/nicepay-admin.js#L200)–205

**Problem.** The inline copy handler is guarded by `if (navigator.clipboard && navigator.clipboard.writeText)` with no `else` and no `.catch()`. `navigator.clipboard` is undefined in non-secure contexts — any admin served over plain http on a non-localhost host, including the `*.local` / `*.test` hostnames most local WordPress tooling generates. The click then does nothing: no copy, no error, no toast. The card-grid copy handler in the same product *does* implement an `execCommand` fallback, so the two copy buttons behave differently. (`http://localhost` and `http://127.0.0.1` **are** secure contexts; the exposure is other hostnames on plain http.)

**Impact.** The primary action of the flagship feature fails silently and inconsistently, with no signal that the click registered.

**Fix.** Extract one shared `nicepayCopy( text )` into `nicepay-admin.js` that tries `navigator.clipboard`, falls back to `execCommand`, and on total failure selects the code element's text and shows *"Press Ctrl+C (⌘C) to copy."* Use it from all three call sites (generator, card grid, TID button) and add the missing `.catch()`. Duplicate report: UX-088.

### UX-043 — `MEDIUM` — Seeded demo shortcodes are indistinguishable from real ones, and deletion is permanent with no undo

[includes/nicepay-functions.php:328](../../includes/nicepay-functions.php#L328)–346 · [admin/class-nicepay-admin.php:382](../../admin/class-nicepay-admin.php#L382)–423 · [nicepay-payment-gateway.php:452](../../nicepay-payment-gateway.php#L452)–459

**Problem.** Activation seeds four ready-to-use payment buttons with real amounts — Quick Payment ₩10,000, Donation ₩5,000, Product Purchase ₩50,000, Subscription ₩29,900. Each carries `'is_preset' => true`, but that flag is **written in five places and read in none**: the cards render identically to merchant-created ones, with no "Example" badge and no warning. Deletion is a one-way door — a generic "Are you sure? This cannot be undone.", then permanent removal with no undo and no way to restore the defaults (once the option holds an empty array, `nicepay_get_all_shortcodes()` will not re-seed, because it only seeds when the option is `null`). `ajax_delete_shortcode()` filters blindly and always reports success, even for an id that never existed. At scale the grid has no search, sort or pagination.

**Impact.** A merchant can paste a seeded sample onto a live page and start charging customers ₩10,000 for a thing called "Quick Payment". Conversely, an accidental delete of a carefully-built shortcode is unrecoverable, and any page already referencing its id breaks silently.

**Fix.** (1) Badge preset cards "Example" with a hint at the top of the tab. (2) Replace the destructive confirm with an optimistic delete plus a 10-second **Undo** in the toast. (3) Warn before deleting a shortcode whose `id` appears in published post content — one `$wpdb->get_col()` LIKE query on `post_content` finds it: *"This shortcode is used on 3 pages. Deleting it will break the payment buttons there."* (4) Return an error when the id was not found. (5) Add search and name/date sort, paginate above ~24 cards. (6) Add "Restore example shortcodes" to the empty state.

### UX-081 — `MEDIUM` — The builder's free-form colour picker has no contrast guardrail against the `!important` white button text

[assets/css/nicepay.css:186](../../assets/css/nicepay.css#L186)–192 · [admin/class-nicepay-admin.php:578](../../admin/class-nicepay-admin.php#L578) · [admin/class-nicepay-admin.php:783](../../admin/class-nicepay-admin.php#L783)

**Problem.** `.nicepay-pay-button` hardcodes `color: #fff !important` (and repeats it on `:hover`), so no inline style can override it. The builder exposes an unconstrained `<input type="color" id="sc-button-color">`; the six presets are all dark enough for white text, but the picker is not constrained to them and no contrast check exists anywhere. The live preview does update the background, so a pale choice is visible — but nothing labels it as a problem.

**Impact.** An admin picking a pale brand colour ships an unreadable pay button (white on `#ffff00` is ~1.07:1) onto a live customer-facing payment page — the single most important element in the standalone flow. Same applies to the saved-card preview and the rendered button.

**Fix.** In `updatePreview()`, compute relative luminance of `btnColor` and set the preview text colour to `#fff` or `#111827`, then emit the chosen text colour into the shortcode — which requires replacing `color: #fff !important` with `var(--nicepay-button-text, #fff)` the template can set inline. Minimum viable: show the existing `.nicepay-sc-validation` strip when contrast against white drops below 4.5:1.

### UX-088 — `MEDIUM` — Builder Copy button has no `execCommand` fallback and no error path *(duplicate of UX-031, recorded separately by the verifier)*

[admin/class-nicepay-admin.php:829](../../admin/class-nicepay-admin.php#L829)–842

**Problem / Impact.** As UX-031. On a plain-HTTP admin the single primary output control of the Shortcode Generator is inert with zero feedback — no toast, no label change, no console error. The generated code is selectable text, so a manual workaround exists, but the feature reads as broken.

**Fix.** Better than patching the inline handler: delete it and let the shared `.nicepay-copy-btn` handler at [assets/js/nicepay-admin.js:247](../../assets/js/nicepay-admin.js#L247)–271 own it, by adding that class and a `data-copy` attribute to the button at [admin/class-nicepay-admin.php:655](../../admin/class-nicepay-admin.php#L655).

### UX-089 — `MEDIUM` — `buildShortcode()` does not escape double quotes, so a product name containing `"` emits a broken shortcode

[admin/class-nicepay-admin.php:718](../../admin/class-nicepay-admin.php#L718) · [admin/class-nicepay-admin.php:374](../../admin/class-nicepay-admin.php#L374)

**Problem.** Every attribute is assembled by raw concatenation into a double-quoted value: `parts.push('goods_name="' + goodsName + '"')`. A name such as `12" Vinyl` produces `goods_name="12" Vinyl"`, which WordPress's shortcode attribute parser terminates at the first inner quote. The server-side builder used on the Shortcodes tab has the same shape but is protected by `esc_attr()`, which converts `"` to `&quot;` — so the two builders disagree, and only the live one is broken.

**Impact.** The builder's headline output — the shortcode string the admin copies and pastes — is silently malformed for any legitimate name containing a quote. The live preview shows the *correct* name, so the failure is invisible until the page is published.

**Fix.** Entity-encode `"` in `buildShortcode()` before concatenation (`&quot;` round-trips correctly through `shortcode_atts`), or reject the character at input time with the `.nicepay-sc-validation` strip that already exists.

### UX-091 — `LOW` — A stale `edit=` id puts the builder into a state that can never save

[admin/class-nicepay-admin.php:435](../../admin/class-nicepay-admin.php#L435)–438 · [admin/class-nicepay-admin.php:695](../../admin/class-nicepay-admin.php#L695) · [nicepay-payment-gateway.php:411](../../nicepay-payment-gateway.php#L411)–413

**Problem.** When the id no longer exists, `$edit_data` is null so the form renders empty and the button reads "Save Shortcode" — but `data-edit-id` is still populated from the raw `$edit_id`, the JS still posts `edit_id`, and the server takes the update branch and responds "Shortcode not found." There is no route out except noticing the `edit=` parameter in the URL. (Form values *do* persist — the button is re-enabled — so data is lost only if the merchant navigates away.)

**Impact.** The merchant fills in a form, presses Save, and gets a red toast saying the thing they are creating does not exist. Every retry fails identically.

**Fix.** Emit `data-edit-id` only when `$edit_data` is truthy — one line, and the page then behaves exactly like the create form. When `$edit_id` was supplied but not found, render an inline `notice-warning`: *"That shortcode no longer exists — it may have been deleted. You are creating a new one."*

### UX-094 — `LOW` — The amount field has no currency-aware `step` and accepts KRW values the backend truncates

[admin/class-nicepay-admin.php:493](../../admin/class-nicepay-admin.php#L493) · [admin/class-nicepay-admin.php:746](../../admin/class-nicepay-admin.php#L746)–748 · [includes/nicepay-functions.php:253](../../includes/nicepay-functions.php#L253)–255

**Problem.** `<input type="number" id="sc-amount" min="1" placeholder="10000">` has no `step` and no `inputmode`, so the spinner behaves as integer-only even when USD is selected, while the live preview formats `parseFloat(amount).toFixed(2)`. Conversely a decimal entered for KRW is saved verbatim and then silently truncated by `(string) (int) $amount` at render time — 10000.50 KRW charges 10000. There is also no maximum, though the spec caps `Amt` at 12 bytes.

**Impact.** USD shortcodes fight the browser's number control, and KRW shortcodes can be saved with a decimal the merchant believes is honoured.

**Fix.** Drive `step`/`inputmode` from the currency select on change (`step="1" inputmode="numeric"` for KRW, `step="0.01" inputmode="decimal"` for USD), add `max="999999999999"`, and show a live hint when a KRW value contains a decimal: *"KRW amounts must be whole won — this will be charged as ₩10,000."*

### UX-095 — `LOW` — "CSS Class" replaces the button's entire class attribute rather than adding to it, and the preview never shows the result

[admin/class-nicepay-admin.php:590](../../admin/class-nicepay-admin.php#L590)–591 · [templates/standalone-payment-form.php:157](../../templates/standalone-payment-form.php#L157) · [nicepay-payment-gateway.php:241](../../nicepay-payment-gateway.php#L241)

**Problem.** The field is labelled "CSS Class" with placeholder `nicepay-pay-button`, which reads as "add an extra class". In fact the value becomes the button's *entire* `class` attribute, so entering `my-brand-btn` strips `nicepay-pay-button` and the button loses all plugin styling. There is no help text, and the preview button's only styling update is text and background colour, so the breakage is invisible until the shortcode is on a page.

**Impact.** A merchant customising the button ends up with a naked `<button>` on their payment page and no explanation. It also silently disables the loading spinner (UX-116) and the loading reset (UX-076).

**Fix.** Emit `class="nicepay-pay-button <?php echo esc_attr( $atts['button_class'] ); ?>"` in both templates, relabel the field **Extra CSS class** with *"Added alongside the default styling, for targeting the button from your theme's CSS"*, drop `button_class` from the shortcode defaults so it is genuinely additive, and apply the class to `#sc-preview-btn` so the preview stops lying.

## 1.6 Localisation and WooCommerce integration

### UX-009 — `HIGH` — 72 translatable strings are missing from the `.pot`, leaving the entire Shortcode UI untranslatable in all four locales

[languages/nicepay-payment-gateway.pot:581](../../languages/nicepay-payment-gateway.pot#L581)–584 · [admin/class-nicepay-admin.php:431](../../admin/class-nicepay-admin.php#L431)–931 · [admin/class-nicepay-transactions.php:116](../../admin/class-nicepay-transactions.php#L116)

**Problem.** Extracting every translatable literal across the plugin and diffing against the `.pot` yields 205 unique literals, of which **72 are absent**, roughly 50 of them from `admin/*.php` and the bootstrap. They include every string on the Shortcodes and Shortcode Generator tabs ("Shortcode Generator", "Save Shortcode", "Live Preview", "Generated Shortcode", "Display Mode", "Inline", "Modal", "Buyer Information", "Appearance", "Payment Amount", "Product / Service Name", "No shortcodes yet", "Create New", "Edit", "Copy", "Delete", "CSS Class", "Button Text", "Button Color"), every modal and toast string ("Delete Shortcode", "Are you sure? This cannot be undone.", "Copied!", "Saving...", "Shortcode saved!", "Shortcode updated!", "Shortcode deleted.", "Shortcode not found."), the accessible label "Copy TID %s", every front-end validation string in the standalone template, and the four seeded preset names. All four `.po` files carry an identical 157-msgid set. Separately the `_n()` call at [admin/class-nicepay-transactions.php:74](../../admin/class-nicepay-transactions.php#L74) is registered in the `.pot` as a plain `msgid "Total: %d transactions"` with **no `msgid_plural`** — and there is no `msgid_plural` anywhere in any `.pot` or `.po` file, so the plural machinery is dead in every locale.

**Impact.** For a Korean payment gateway, the Korean merchant sees roughly a third of the admin — the entire shortcode builder and every confirmation dialog — in English. The `.pot` is hand-maintained (`X-Generator: NicePay Payment Gateway`), so this will silently rot further with every change.

**Fix.** `wp i18n make-pot . languages/nicepay-payment-gateway.pot --domain=nicepay-payment-gateway`, `msgmerge` the four `.po` files, recompile `.mo`, and **add a CI step to `.github/workflows/tests.yml` that regenerates the `.pot` and fails when `git diff --exit-code languages/` is non-empty** — without that gate this recurs. Add `/* translators: %s: NicePay transaction ID */` above the two `sprintf( __( 'Copy TID %s' … ) )` calls. See UX-015 for the customer-facing half of the same drift.

### UX-044 — `MEDIUM` — The WooCommerce order screen shows nothing about the NicePay payment

[includes/class-nicepay-gateway.php:363](../../includes/class-nicepay-gateway.php#L363)–367 · [includes/class-nicepay-gateway.php:153](../../includes/class-nicepay-gateway.php#L153)–154

**Problem.** The gateway writes `_nicepay_tid`, `_nicepay_moid`, `_nicepay_edi_date` and `_nicepay_auth_code` to order meta and adds an order note, but registers **no meta box** and no `woocommerce_admin_order_data_after_billing_address` hook — greps for `add_meta_box` and `woocommerce_admin_order_data` both return zero. On the order edit screen, where a merchant investigating a payment actually goes, there is no TID, no card details, no virtual-account number, no result code, and no link to the NicePay transaction. The cross-link exists only in the other direction, and it is the broken `post.php` link (UX-020).

**Impact.** The merchant must already know a separate NicePay → Transactions screen exists, then find the order there by ID. The most-visited screen in WooCommerce says nothing about how the customer paid beyond a free-text note.

**Fix.** `add_meta_box( 'nicepay-payment', __( 'NicePay payment' ), …, wc_get_page_screen_id( 'shop-order' ), 'side' )` — use `wc_get_page_screen_id()` so it works under both HPOS and legacy storage. Show: status badge, TID with copy button, method with card issuer and masked PAN (or bank/number/expiry for VBANK), AuthCode, Moid, amount and currency, ResultCode with its plain-English explanation, and a "View in NicePay transactions" link filtered to that TID. That single panel removes most of the reason to visit the transactions screen at all.

### UX-092 — `LOW` — Four user-facing strings in the admin JS are hardcoded English, and the cancelled-status label is registered twice

[assets/js/nicepay-admin.js:198](../../assets/js/nicepay-admin.js#L198) · [assets/js/nicepay-admin.js:234](../../assets/js/nicepay-admin.js#L234) · [admin/class-nicepay-admin.php:88](../../admin/class-nicepay-admin.php#L88) · [includes/nicepay-functions.php:271](../../includes/nicepay-functions.php#L271)

**Problem.** `'Copy failed'` (lines 198, 257, 268) and `'Error'` (line 234) never pass through `nicepayAdmin.i18n` — and `'Error'` is the fallback shown when a save or delete fails without a message, the least useful error string it is possible to display. Separately, the same conceptual state is registered twice: the JS writes `__( 'CANCELLED' )` into the badge after a successful cancel while the server renders `nicepay_get_status_label('cancelled')` → `__( 'Cancelled' )` on the next load. *Verifier note: no visible case flip occurs (`.nicepay-status { text-transform: uppercase }`), and ko_KR translates both to 취소됨; only tr_TR actually diverges — "IPTAL EDILDI" vs "İptal Edildi" — on the Turkish dotted/dotless I.*

**Fix.** Drop `statusCancelled` and have `ajax_cancel_transaction()` return `status_label` from `nicepay_get_status_label( 'cancelled' )` so client and server cannot disagree. Add `copyFailed` → *"Couldn't copy — select the text and press Ctrl+C."* and `genericError` → *"Something went wrong and the change was not saved. Try again."* to the localised map.

## 1.7 Merchant microcopy — before and after

Every "before" below is the literal string in the codebase today.

### Settings labels and help text

| Field | Before | After |
|---|---|---|
| Mode | "Mode" — *"Use Test mode for development. Switch to Live for production."* | **Environment** — *"Test uses NICEPAY's sandbox: customers can complete checkout but no money moves. Live takes real payments using your Live MID."* |
| Language | "Language" — *(no description)* | **Payment window language** — *"The language customers see inside the NICEPAY payment popup. This does not change your WordPress admin language."* |
| Currency | "Currency" — *(no description)* | **Currency for shortcode payments** — *"Used by [nicepay_payment] shortcodes and for displaying amounts in Transactions. WooCommerce orders always use the order's own currency. NICEPAY supports KRW and USD only."* |
| Charset | "Charset" — *(no description)* | *(move under an Advanced disclosure)* **Character set** — *"Leave as UTF-8. Change to EUC-KR only if NICEPAY support tells you your MID requires it — the wrong value garbles Korean product names."* |
| Test MID | "Test MID" — *(no description)* | *"Provided by NICEPAY. The default `nicepay00m` is NICEPAY's shared public demo account — its credentials are published in NICEPAY's manual and used by every developer."* |
| Live MID | "Live MID" — *(no description)* | *"Up to 10 letters or digits, issued by your NICEPAY sales representative."* |
| Live Merchant Key | "Live Merchant Key" — *(no description)* | *"Copy it exactly — a single trailing space makes every payment fail signature verification."* |
| VBANK expiry | "Virtual Account Expiry (days)" — *"Number of days before virtual account expires."* | **How long customers have to deposit** — *"After this the account number stops working and the order stays unpaid. Between 1 and 30 days."* |
| Enabled methods | *(bare checkbox group)* | Add per method: *"Available methods depend on your MID contract with NICEPAY — enabling one your MID does not support causes an error in the payment window."* |

### The refund confirmation dialog (UX-022)

| Element | Before | After |
|---|---|---|
| Title | "Cancel Transaction" | "Refund this payment?" |
| Body | "This action cannot be undone. The payment will be reversed." | "You are about to return **{amount}** to **{buyer}** for transaction **{tid}** ({method}, {date}). NICEPAY cannot undo this." |
| Reason label | "Cancellation Reason" | "Reason (sent to NICEPAY and stored on the order)" — `maxlength="100"` with a live counter |
| Dismiss button | "Cancel" | "Keep payment" |
| Confirm button | "Cancel Transaction" | "Refund {amount}" |
| In-flight state | *(both buttons greyed, no text change)* | "Refunding…" + spinner + live region: "Contacting NICEPAY — this can take up to 30 seconds. Do not close this window." |
| Failure | toast: raw Korean `ResultMsg`, gone in 4 s | inline `role="alert"` in the dialog: "NICEPAY refused this refund. Code {ResultCode} — {ResultMsg}" + plain-English hint + Copy details |

### Empty and error states

| Where | Before | After |
|---|---|---|
| Transactions, no rows at all | "No transactions found" / "Transactions will appear here once payments are made." | *(unchanged, plus a primary action linking to the setup checklist)* |
| Transactions, filtered to nothing | *(same copy — actively false)* | "No transactions match these filters. Try widening the date range, or clear the filters to see everything." + **Clear filters** |
| Save shortcode with no name | *(red border only, no text)* | "Give this shortcode a name so you can find it later." |
| Cancel with no reason | *(red border only, no text)* | "Enter a reason — NICEPAY requires one and it is stored on the order." |
| Generic AJAX failure | "Error" | "Something went wrong and the change was not saved. Try again." |
| Copy failure | "Copy failed" | "Couldn't copy — select the text and press Ctrl+C." |
| Test mode banner | *(none; only an 11 px pill in the `<h1>`)* | Site-wide `notice-warning`: "NicePay is in Test mode — no real money is being taken. Checkout still shows NicePay and orders will be marked as paid, but nothing is charged." + **Go live** |
| Live mode, no credentials | *(nothing — gateway silently disappears)* | `notice-error`: "NicePay is not being offered at checkout. Live mode is selected but no Live MID / Merchant Key is saved." + **Add credentials** |
| Unsupported store currency | *(nothing)* | `notice-error`: "NicePay cannot accept EUR. NICEPAY supports KRW and USD only, so NicePay is hidden at checkout." |

---

# §2 — The customer journey, step by step

Two flows exist and they behave differently at almost every step. Read this table first: many findings are "one flow does it right and the other does not".

| Step | WooCommerce flow | Standalone shortcode flow |
|---|---|---|
| Choose NicePay | Bare radio, no icon, no method choice, `has_fields = false` | Card with method radios and icons |
| Sign the request | Server-side at render, in `generate_payment_form()` | AJAX round-trip to `admin-ajax.php` at click time |
| Launch window | `nicepayStart()` synchronously in the click handler ✅ | `nicepayStart()` inside `xhr.onload` ⚠️ |
| Loading state | Full-screen overlay with a live region | Button spinner only |
| Window closed | Navigates the buyer away to `/checkout/` ⚠️ | Re-enables the button ✅ |
| Return URL | `WC()->api_request_url()` — permalink-safe ✅ | Hardcoded `/nicepay-return/` ⚠️ |
| Success page | WooCommerce thank-you page (themed) | Bespoke unbranded HTML document |
| Receipt email | WooCommerce order emails | **None — the plugin never calls `wp_mail`** |
| VBANK details | **Shown nowhere** (UX-001) | Deposit card on the result page ✅ |

## 2.1 Choosing NicePay at checkout

### UX-060 — `MEDIUM` — The gateway is offered for unsupported currencies and explains nothing at checkout (no `payment_fields`, no `get_icon`)

[includes/class-nicepay-gateway.php:17](../../includes/class-nicepay-gateway.php#L17)–37 · [includes/class-nicepay-gateway.php:76](../../includes/class-nicepay-gateway.php#L76)–86

**Problem.** `WC_Gateway_NicePay` defines exactly eight methods and overrides neither `payment_fields()` nor `get_icon()`; `$this->has_fields = false` and `$this->icon` is never set. `is_available()` checks only `enabled === 'yes'` plus MID and key presence — no currency gate, even though the plugin's own settings screen offers exactly KRW and USD. The order currency is passed straight through as `CurrencyCode`.

**Impact.** (1) A EUR/JPY store still shows NicePay; the buyer selects it, places the order, and the payment window fails on `CurrencyCode` with an untranslated PG error. (2) The buyer sees a bare radio and a paragraph — no icons, no brand mark, and no way to choose CARD / BANK / VBANK / CELLPHONE, which is deferred to a second page load after "Place order". For Korean buyers that ordering is backwards.

**Fix.** Add to `is_available()`: `if ( function_exists( 'get_woocommerce_currency' ) && ! in_array( get_woocommerce_currency(), array( 'KRW', 'USD' ), true ) ) { return false; }` — paired with the admin notice from UX-048, because a silently hidden gateway is only safe if the merchant is told. Implement `get_icon()` (UX-126). Implement `payment_fields()` printing the description plus the same accessible radio group used in `templates/payment-form.php`, persist the choice to order meta in `process_payment()`, and pre-select it on the receipt page. Set `$this->order_button_text = __( 'Continue to payment' )` so the checkout button does not promise "Place order" when a further step follows.

### UX-126 — `ENHANCEMENT` — The gateway ships no checkout icon despite the plugin containing a full SVG icon set

[includes/class-nicepay-gateway.php:17](../../includes/class-nicepay-gateway.php#L17)–37 · [includes/nicepay-icons.php:74](../../includes/nicepay-icons.php#L74)

**Problem.** No `$this->icon` assignment and no `get_icon()` override anywhere in the 475-line class, while `nicepay_get_method_icon()` provides six hand-drawn SVGs already used on the payment form, the standalone form, the settings tab, the method chips and the live preview.

**Impact.** On the checkout — where the gateway competes for the shopper's choice against other options — NicePay appears as bare text with no visual identity, while the same plugin renders a polished icon row one step later. It is the most-seen and least-designed touchpoint of the WooCommerce flow.

**Fix.** Override `get_icon()` to return the enabled methods' inline SVGs (from `get_option('nicepay_enabled_methods')`) wrapped in a small flex row, filtered through `apply_filters( 'woocommerce_gateway_icon', $icon, $this->id )`. Reuses existing code; no new assets.

## 2.2 The payment page — what the buyer reads before committing

### UX-002 — `CRITICAL` — The standalone payment amount is taken from the client and signed with no server-side authority

[nicepay-payment-gateway.php:322](../../nicepay-payment-gateway.php#L322) · [nicepay-payment-gateway.php:336](../../nicepay-payment-gateway.php#L336) · [templates/standalone-payment-form.php:130](../../templates/standalone-payment-form.php#L130)

**Problem.** `ajax_init_payment()` reads `$_POST['amount']`, validates only `(float) $amount > 0`, and signs it into `SignData`. It never cross-checks the amount against the shortcode configuration in `nicepay_saved_shortcodes` and never receives a shortcode id at all. The `Amt` hidden input is likewise client-editable. An attacker calls `admin-ajax.php` directly with the page's own nonce and `amount=100`, receives `edi_date`/`moid`/`sign_data` valid for 100, sets `Amt` to 100, and calls `nicepayStart()`. Because **SignData was minted by the server for the attacker's amount**, NICEPAY accepts it. Both return handlers then forward `$_POST['Amt']` into `request_approval()` without ever comparing it to `$transaction->amount`.

```php
$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
if ( empty( $amount ) || (float) $amount <= 0 ) { … }
$sign_data = $api->create_auth_sign_data( $edi_date, $amount );
```

**Impact.** Direct merchant money loss with trivial exploitation: any visitor can pay ₩100 for a ₩50,000 item, and the plugin records the transaction as successfully paid at the manipulated amount. Nothing downstream can catch it, because the signature is valid. This is the one place in the flow where the merchant must be the sole authority on price, and it is delegated to the buyer's browser.

**Fix.** Never accept the amount from the client.
- Pass only the saved-shortcode `id` (plus the nonce) to `ajax_init_payment`, look the amount up server-side via `nicepay_get_saved_shortcode( $id )`, and reject unknown ids.
- For ad-hoc `amount="…"` shortcodes, mint a short-lived transient at render time keyed to a random token — `set_transient( 'nicepay_amt_' . $token, $amount, 30 * MINUTE_IN_SECONDS )` — and resolve the amount from the token.
- Independently, in **both** return handlers, reject the approval when `(string) $_POST['Amt'] !== (string) $transaction->amount`.

Note this must be fixed **before** UX-121 (buyer-entered amounts), which would otherwise widen the hole.

### UX-017 — `HIGH` — Un-checking every payment method breaks the customer-facing form with a PHP warning

[admin/class-nicepay-admin.php:112](../../admin/class-nicepay-admin.php#L112)–117 · [templates/standalone-payment-form.php:38](../../templates/standalone-payment-form.php#L38)–39 · [templates/payment-form.php:56](../../templates/payment-form.php#L56)–58

**Problem.** Customer-side consequence of UX-007. With `nicepay_enabled_methods` stored as `array()`, the standalone template dereferences `$enabled_methods[0]` with no guard — and lines 34–36 force `$pay_method = ''` when it is not in `$enabled_methods`, so the unguarded branch is *always* reached.

**Impact.** On PHP 8 the buyer sees `Warning: Undefined array key 0` printed into the payment card, `$default_method` becomes null, and the form posts an empty `PayMethod` — a REQUIRED parameter. *The WooCommerce template is guarded* (both `$enabled_methods[0]` uses sit inside `count() > 1` and `! empty()` checks), so that path degrades to a dead button showing "Please select a payment method." Only the standalone form warns on a public page.

**Fix.** Fix the sanitize callback per UX-007, and independently harden the template after line 32:
```php
$enabled_methods = array_values( (array) $enabled_methods );
if ( empty( $enabled_methods ) ) {
    echo '<div class="nicepay-notice nicepay-notice-error" role="alert"><span>'
       . esc_html__( 'Online payment is temporarily unavailable. Please contact us to complete your order.', 'nicepay-payment-gateway' )
       . '</span></div>';
    nicepay_log( 'No payment methods enabled' );
    return;
}
```

### UX-015 — `HIGH` — Every label, placeholder and validation message in the standalone buyer form is missing from the POT and all four locales

[templates/standalone-payment-form.php:100](../../templates/standalone-payment-form.php#L100)–119 · [templates/standalone-payment-form.php:211](../../templates/standalone-payment-form.php#L211)–215 · [languages/nicepay-payment-gateway.pot:47](../../languages/nicepay-payment-gateway.pot#L47)–78

**Problem.** The POT was generated from an older revision of the template and carries only five references to it. Checking each user-visible string by exact msgid match, **all thirteen** of "Name", "Email", "Phone", "Enter your name", "Enter your email", "Enter your phone number", "This field is required.", "Please enter a valid email address.", "Please enter a valid phone number.", "An unexpected error occurred.", "Connection error. Please check your internet.", "Payment initialization failed." and "Close" are absent — and therefore absent from all four `.po`/`.mo` files. The POT still carries stale entries that no longer match the template ("Payment initialization failed. Please try again.", a "Cancel" reference where the template now says "Close"). Each `.po` has exactly one empty msgstr (the header), so the locales are otherwise fully translated: **this is extraction drift, not translator neglect.**

**Impact.** The one form the customer actually types into is English-only in every locale, while the WooCommerce path is fully localised. A Korean buyer on a Korean site sees a localised WooCommerce form but "Name / Email / Phone / This field is required." from the shortcode form. It reads as a half-finished bolt-on at the moment of payment.

**Fix.** Regenerate and re-merge as in UX-009, with the same CI gate. Korean copy: 이름 / 이메일 / 휴대폰 번호, "필수 입력 항목입니다.", "올바른 이메일 주소를 입력해 주세요.", "올바른 휴대폰 번호를 입력해 주세요."

### UX-063 — `MEDIUM` — The buyer is never told a payment window will open, never told it is NICEPAY, and gets no security or brand affordance

[templates/payment-form.php:21](../../templates/payment-form.php#L21)–29 · [includes/class-nicepay-gateway.php:179](../../includes/class-nicepay-gateway.php#L179)–208 · [templates/standalone-payment-form.php:68](../../templates/standalone-payment-form.php#L68)–75

**Problem.** The pay page renders a heading, the total, a method picker and a button. Nothing says that a separate NICEPAY window will open, that card details go to NICEPAY and never touch this site, or which card brands are accepted. No lock glyph, no "Secured by NICEPAY" mark. The spec's `LogoImage` (a 60×60 merchant logo shown *inside* the NICEPAY window) and `SkinType` are never sent — grep returns zero for both — so the buyer sees an unbranded PG window with no visual continuity from the shop.

**Impact.** Two known conversion leaks. When a new window opens unannounced, a meaningful share of buyers close it as a suspected pop-up ad — and on the WooCommerce path the close handler then throws them off the page entirely (UX-053). And handing card details to a window bearing no sign of the shop raises exactly the doubt that kills a first-time purchase.

**Fix.** One line of reassurance above the button in both templates — *"A secure NICEPAY window will open to complete your payment. Your card details are handled by NICEPAY and are never stored on this site."* — with a lock glyph, a "Secured by NICEPAY" lockup, and for CARD an accepted-brand row. Send `LogoImage` (full URL to a 60×60 image, settable in admin, defaulting to the site icon) in `$form_data`, and expose `SkinType` (`default`/`black` only) so the window can match a dark shop.

### UX-070 — `MEDIUM` — "Bank Transfer" and "Virtual Account" are indistinguishable to a non-Korean buyer, and neither is explained

[includes/class-nicepay-api.php:434](../../includes/class-nicepay-api.php#L434)–445 · [templates/payment-form.php:40](../../templates/payment-form.php#L40)–42 · [includes/nicepay-icons.php:27](../../includes/nicepay-icons.php#L27)–45

**Problem.** The method picker renders a single line of text per option — "Credit Card", "Bank Transfer", "Virtual Account", "Mobile Payment", "SSG Bank Account", "Culture Cash" — with no supporting description in any locale. To an English speaker "Bank Transfer" and "Virtual Account" name the same concept, and "Virtual Account" conveys nothing of what it actually is: a one-time account number you transfer to later. The BANK and VBANK icons are near-identical (VBANK drops two columns and adds a clock circle), so at 22 px they do not disambiguate either.

**Impact.** A buyer who picks the wrong one lands in an unexpected flow: choosing VBANK when she wanted to pay now means she "completes" payment and then discovers her order is on hold pending a manual bank transfer.

**Fix.** Add a `<span class="nicepay-method-desc">` per option fed by a new `NicePay_API::get_payment_method_description()`:

| Method | English | Korean |
|---|---|---|
| CARD | Pay now with a credit or check card | 신용·체크카드로 즉시 결제 |
| BANK | Pay now directly from your bank account (real-time transfer) | 계좌에서 실시간 이체 |
| VBANK | We issue you a bank account number — transfer within N days. Your order is processed after the deposit arrives. | 전용 계좌번호를 발급해 드립니다. 입금 확인 후 처리됩니다. |
| CELLPHONE | Charge to your mobile phone bill | 휴대폰 요금에 합산 청구 |

Redraw the VBANK icon as a receipt/document with a number rather than a bank, and show the expiry window inline so the commitment is visible before selection.

### UX-071 — `MEDIUM` — When only one method is enabled the buyer is never told which one she is about to use

[templates/payment-form.php:31](../../templates/payment-form.php#L31) · [templates/standalone-payment-form.php:38](../../templates/standalone-payment-form.php#L38)

**Problem.** Both templates gate the entire method block on `count( $enabled_methods ) > 1`. A shop that enables only VBANK, or a shortcode that pins `pay_method="VBANK"`, renders a card showing only a title, an amount and a generic button — with no statement anywhere of what payment method will be used. The hidden `PayMethod` input is still populated.

**Impact.** The buyer commits blind. With VBANK this is severe: she presses "Pay Now" and is issued a bank account number instead of being charged. With CELLPHONE the charge lands on her phone bill.

**Fix.** When exactly one method is active, render a static non-interactive row with the same icon, label and description: *"Payment method: 가상계좌 (Virtual Account) — we will issue you an account number to transfer to."* Make the button text method-aware: "Get account number" for VBANK, "Pay ₩50,000" for CARD/BANK, "Charge to my phone bill" for CELLPHONE — the amount is already available in both templates.

### UX-074 — `MEDIUM` — An empty `goods_name` renders a blank heading and posts an empty required `GoodsName`

[templates/standalone-payment-form.php:47](../../templates/standalone-payment-form.php#L47) · [templates/standalone-payment-form.php:129](../../templates/standalone-payment-form.php#L129) · [admin/class-nicepay-admin.php:853](../../admin/class-nicepay-admin.php#L853)–857

**Problem.** `goods_name` defaults to `''`. The template runs it through `mb_strcut( sanitize_text_field( … ), 0, 40, 'UTF-8' )` with no fallback, then renders `<h3></h3>` and `<input type="hidden" name="GoodsName" value="">`. `GoodsName` is a REQUIRED 40-byte auth parameter. The builder marks the field required with a `*` but **neither the client-side save handler nor the AJAX endpoint enforces it** — both validate only `name` — so an empty goods_name is savable through the plugin's own UI. The WooCommerce path handles this well, building a name from the order items with an "and %d more items" suffix.

**Impact.** A card showing an empty heading above a bare price — the buyer has no idea what she is paying for — and the NICEPAY window is invoked without a required field.

**Fix.** In the template after line 47: `if ( '' === $goods_name ) { $goods_name = mb_strcut( get_bloginfo( 'name' ), 0, 40, 'UTF-8' ); }`, plus an editor-only notice when the attribute was empty and `current_user_can( 'edit_posts' )`. Enforce it where it is authored: a client-side check beside the existing `name` check, and the matching server-side check in `ajax_save_shortcode()`.

### UX-078 — `MEDIUM` — Required buyer fields are validated only in the browser and are never used server-side

[nicepay-payment-gateway.php:323](../../nicepay-payment-gateway.php#L323)–331 · [templates/standalone-payment-form.php:210](../../templates/standalone-payment-form.php#L210)–216

**Problem.** The form forces the buyer to supply name, email and phone (each `required`, gated by the JS validator). `ajax_init_payment()` accepts all three unconditionally — `sanitize_text_field()`/`sanitize_email()` then straight into the transaction row, with no emptiness check, no format check and no rejection path; only `amount` is validated. `sanitize_email()` returns an empty string for a malformed address, so an invalid entry is silently stored blank. The values actually posted to NICEPAY come from separate hidden inputs the client populates, so the browser is the only gate.

**Impact.** The plugin imposes three mandatory fields — real friction on a mobile payment form — then neither validates nor uses them: no confirmation email is ever sent (`wp_mail` is never called anywhere), and a trivially bypassed check means transaction records can carry blank or malformed contact details for real payments.

**Fix.** Validate server-side alongside the amount check: reject with a field-specific message when `$buyer_name` is empty or exceeds 30 bytes, when `! is_email( $buyer_email )`, or when the phone fails `preg_match( '/^[\d\-+() ]{7,20}$/', $buyer_tel )` — mirroring the JS rules and the spec's byte limits — and have `showStandaloneError()` render returned field errors against the right inputs. Then make the requirement earn its place by sending the receipt email in UX-075. If the merchant does not need contact details, make the fields optional.

### UX-057 — `MEDIUM` — The AJAX nonce is baked into cacheable HTML; after cache expiry the button dies with "Invalid request."

[templates/standalone-payment-form.php:305](../../templates/standalone-payment-form.php#L305) · [nicepay-payment-gateway.php:314](../../nicepay-payment-gateway.php#L314)–320

**Problem.** The init nonce is printed inline into the page body inside the XHR payload string. Any full-page cache serves that HTML to anonymous visitors after the nonce's validity window (for logged-out visitors the nonce depends only on the 12-hour tick and two ticks are accepted, so up to 24 hours). `ajax_init_payment` then returns `wp_send_json_error( array( 'message' => __( 'Invalid request.' ) ) )`, rendered verbatim to the buyer.

**Impact.** On a site whose cache TTL exceeds 24 hours the payment button stops working silently, and the only feedback is two words that name no cause and suggest no remedy. To the merchant it presents as "the button randomly stops working".

**Fix.** Do not embed the nonce in cacheable HTML — fetch a fresh one from a REST route at click time, or mark the container `data-no-cache` and use the standard nonce-refresh pattern. **Regardless**, make the failure recoverable: return a distinct error code on nonce failure and have the client show *"This page has expired. Refreshing…"* and call `location.reload()`.

### UX-058 — `MEDIUM` — No buyer-facing test-mode indicator, while the plugin ships defaulted to test mode with working sandbox credentials

[nicepay-payment-gateway.php:151](../../nicepay-payment-gateway.php#L151)–153 · [admin/class-nicepay-admin.php:131](../../admin/class-nicepay-admin.php#L131)–138

**Problem.** A freshly activated plugin is fully functional in test mode. No customer-facing surface references the mode — a grep for `nicepay_mode` and `is_test_mode` across `templates/`, `assets/` and the return handler returns zero hits. *(The admin is reasonably warned: a TEST/LIVE badge renders inside the `<h1>` of every settings tab, not only the API tab.)*

**Impact.** During QA nobody can tell a test transaction from a real one from the front end, and if a merchant switches credentials but not the mode dropdown, customers "pay" against the sandbox and see a green "Payment Successful" page while no money moves.

**Fix.** When `nicepay_mode === 'test'`, render a persistent amber bar at the top of both payment templates and the result page: *"TEST MODE — this is a sandbox payment. No money will be taken."* Additionally gate live mode server-side per UX-003 and promote the badge to an `admin_notices` entry per UX-004.

### UX-105 — `LOW` — Logged-in WordPress users must retype name, email and phone on every standalone payment

[templates/standalone-payment-form.php:44](../../templates/standalone-payment-form.php#L44)–48 · [nicepay-payment-gateway.php:236](../../nicepay-payment-gateway.php#L236)–238

**Problem.** The buyer-field block shows whenever any of the three `buyer_*` shortcode attributes is empty, and those can only be filled at authoring time. Nothing consults the current user — greps for `wp_get_current_user`, `is_user_logged_in` and `get_userdata` across all non-test PHP return zero.

**Impact.** Three avoidable fields at the payment moment for exactly the buyers the merchant already knows, on a form that also has no `autocomplete` attributes to fall back on (UX-056). The cheapest conversion win available in the shortcode flow, simply not taken.

**Fix.** After the `$preset_*` assignments, fall back to the current user before deciding what to render:
```php
if ( is_user_logged_in() ) {
    $u = wp_get_current_user();
    if ( '' === $preset_name )  { $preset_name  = $u->display_name; }
    if ( '' === $preset_email ) { $preset_email = $u->user_email; }
}
```
so `$show_buyer_fields` collapses to the phone field alone. Render prefilled values as visible, editable inputs rather than hidden ones, and where WooCommerce is active prefer the billing profile.

### UX-103 — `LOW` — Amounts render as "10,000 KRW" rather than Korean convention, and the result page ignores the transaction currency

[includes/nicepay-functions.php:234](../../includes/nicepay-functions.php#L234)–238 · [includes/class-nicepay-return-handler.php:232](../../includes/class-nicepay-return-handler.php#L232)

**Problem.** `nicepay_format_amount()` always emits `number_format(...) . ' ' . $currency`, never `₩10,000` or `10,000원`, and never consults the site locale. On the result page it is called with no currency argument, so it falls back to the global `nicepay_currency` option rather than the currency of the transaction; the table has no currency column, so the real currency is not even recorded.

**Impact.** Cosmetically foreign to the primary audience, and functionally wrong when a merchant runs USD shortcodes on a KRW-default site — the buyer is shown a USD amount labelled KRW at the exact moment she is confirming what she paid.

**Fix.** Symbol-first with locale awareness: KRW → `number_format( round( $amount ) ) . '원'` for ko_KR, `'₩' . number_format( round( $amount ) )` otherwise; USD → `'$' . number_format( (float) $amount, 2 )`. Delegate to `wc_price()` where WooCommerce is present. Add the `currency` column (UX-027, UX-052) and pass it explicitly.

### UX-096 — `LOW` — KRW amounts are truncated with `(int)` rather than rounded, so the charged amount can differ from the displayed total

[includes/nicepay-functions.php:253](../../includes/nicepay-functions.php#L253)–255 · [includes/class-nicepay-gateway.php:124](../../includes/class-nicepay-gateway.php#L124) · [templates/payment-form.php:26](../../templates/payment-form.php#L26)

**Problem.** `nicepay_get_amount()` returns `(string) (int) $amount` for KRW — truncation, not rounding — while the order total on the same page is rendered from `$order->get_formatted_order_total()`, which rounds. On a KRW store left at WooCommerce's default two price decimals, a total of 10999.99 displays as ₩11,000 and posts `Amt=10999`. The same truncated value feeds `create_auth_sign_data()` and the cancel amount.

**Impact.** The buyer reads one number and is charged another, and `payment_complete()` then marks the order fully paid, so the discrepancy is invisible until settlement reconciliation. Bounded below 1 KRW, but amount fidelity is the one thing a payment UI must never get wrong.

**Fix.** `(string) (int) round( (float) $amount )` for KRW (keep `number_format( round( …, 2 ), 2, '.', '' )` for USD), or delegate to `wc_format_decimal( $amount, 0 )`. Then close the loop: render the buyer-facing total from the **same** `nicepay_get_amount()` value that is signed and posted, so display and charge cannot diverge by construction.

### UX-104 — `LOW` — The shortcode `currency` attribute is never validated against the two values NICEPAY accepts

[templates/standalone-payment-form.php:17](../../templates/standalone-payment-form.php#L17) · [templates/standalone-payment-form.php:141](../../templates/standalone-payment-form.php#L141)

**Problem.** `$currency = sanitize_text_field( $atts['currency'] );` goes straight into the `CurrencyCode` hidden input with no allowlist, and also selects the amount-normalisation and display branches. `[nicepay_payment amount="20" currency="EUR"]` renders "20.00 EUR" and posts an unsupported CurrencyCode. The admin builder restricts the control to KRW/USD, so only hand-authored shortcodes are exposed.

**Fix.** Immediately after line 17: `$currency = in_array( strtoupper( $currency ), array( 'KRW', 'USD' ), true ) ? strtoupper( $currency ) : 'KRW';`, plus an editor-only notice when the supplied value was invalid: *"NicePay: currency \"EUR\" is not supported. NICEPAY accepts KRW and USD only — falling back to KRW."*

### UX-115 — `LOW` — `button_color` is interpolated into an inline `style` without hex validation, allowing CSS injection by any post author

[templates/standalone-payment-form.php:157](../../templates/standalone-payment-form.php#L157)–160 · [nicepay-payment-gateway.php:259](../../nicepay-payment-gateway.php#L259) · [nicepay-payment-gateway.php:394](../../nicepay-payment-gateway.php#L394)

**Problem.** The shortcode default is an empty string and `shortcode_atts()` applies no sanitisation, so a raw attribute flows into `style="background:<value>"` through `esc_attr()` only. `esc_attr()` blocks attribute break-out but not additional declarations: `button_color="red;position:fixed;inset:0;width:100vw;height:100vh;z-index:99999"` produces a full-viewport overlay. The AJAX save path *does* validate with `sanitize_hex_color()`, so only the direct-attribute path is unguarded — an inconsistency, not a design decision.

**Impact.** Not XSS, but a defacement/clickjacking primitive available to any user who can author post content — Author and Contributor in a default install, not just administrators.

**Fix.** In `render_payment_shortcode()`, post-process the merged atts: `$atts['button_color'] = sanitize_hex_color( $atts['button_color'] );` — the same function already used on the save path, so both paths enforce the same contract.

## 2.3 Launching the payment window

### UX-010 — `HIGH` — The standalone flow calls `nicepayStart()` from an XHR callback; if the window does not open the button is permanently dead · *needs confirmation*

[templates/standalone-payment-form.php:258](../../templates/standalone-payment-form.php#L258)–306 · [templates/standalone-payment-form.php:323](../../templates/standalone-payment-form.php#L323)–329

**Problem.** `nicepayStartStandalone()` sets `is-loading` + `disabled`, fires an `XMLHttpRequest` to `admin-ajax.php`, and calls `nicepayStart()` only inside `xhr.onload` — outside the click's synchronous execution. There is **no watchdog**: the only code path that clears the loading state after a successful XHR is `window.nicepayClose`, which the vendor script invokes when the payment window is *closed*. If the window never opens, that callback never fires and the button stays disabled with a spinner forever, with no message. The WooCommerce path signs server-side at render and calls `nicepayStart()` synchronously in the click handler, so the two flows genuinely differ. *Needs confirmation: `pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js` was not fetched, so whether `nicepayStart()` uses `window.open` on PC is unverified. Popup-blocker behaviour also varies — Chrome grants transient activation for ~5 s after a click and Firefox ~1 s, so Safari is the strict case.*

**Impact.** Any failure in which `nicepayStart()` returns without opening a window and without later invoking `nicepayClose` produces a dead button: one click, no window, permanent spinner, no error, no retry.

**Fix.** Preserve the user gesture — fire the `nicepay_init_payment` XHR on page ready (or on first focus of a buyer field), stash `edi_date`/`moid`/`sign_data`, and have the click handler populate the hidden inputs and call `nicepayStart()` synchronously; re-issue the XHR every ~10 minutes to keep EdiDate fresh. **Regardless of that**, add an unconditional watchdog:
```js
var wd = setTimeout(function () {
    btn.classList.remove('is-loading');
    btn.disabled = false;
    showStandaloneError(wrapper, form, /* localised */ 'The payment window did not open. Your browser may be blocking pop-ups for this site — please allow them and try again.');
}, 20000);
```
cleared from both `nicepaySubmit` and `nicepayClose`.

### UX-061 — `MEDIUM` — A blocked payment window leaves the full-screen overlay up forever, and its copy describes the wrong state · *needs confirmation*

[assets/js/nicepay.js:44](../../assets/js/nicepay.js#L44)–45 · [assets/js/nicepay.js:67](../../assets/js/nicepay.js#L67)–70 · [templates/payment-form.php:16](../../templates/payment-form.php#L16)–19

**Problem.** `startPayment()` adds `is-loading` + `disabled` to every pay button and `is-active` to a `position: fixed; inset: 0; z-index: 99999` white sheet reading "Processing payment...", then calls `nicepayStart()`. The overlay is removed only by `hideLoading()`, reachable from the `catch` (a *synchronous* throw only) and from `window.nicepayClose`. No timeout, no watchdog, no dismiss control; not `inert`, no focus management. And the copy is wrong: nothing is being processed while the buyer is typing her card number in the NICEPAY window. *Verifier note: the "vendor host unreachable" case is already handled — `nicepay.js:47` and `:58`–64 guard with `if (typeof nicepayStart === 'function')`. The only unhandled case is `nicepayStart()` returning normally without opening a window, which depends on unverified vendor internals.*

**Impact.** The buyer sees a white sheet over the whole viewport with no way out but reloading — and reloading an order-pay page mid-flow is exactly the input that triggers the non-idempotent re-approval in UX-013. Keyboard users can also tab into the invisible page behind the overlay.

**Fix.** (1) A 20-second watchdog in `startPayment()` mirroring UX-010. (2) A visible Cancel control, `aria-modal`, and `inert` on the background. (3) Change the copy to *"Complete your payment in the NICEPAY window"* with a "Reopen payment window" action, and reserve "Processing payment…" for the post-return interstitial (UX-054).

### UX-076 — `MEDIUM` — A custom `button_class` breaks the loading-state reset, leaving the button permanently disabled

[templates/standalone-payment-form.php:157](../../templates/standalone-payment-form.php#L157) · [templates/standalone-payment-form.php:325](../../templates/standalone-payment-form.php#L325) · [admin/class-nicepay-admin.php:724](../../admin/class-nicepay-admin.php#L724)

**Problem.** The shortcode applies `button_class` verbatim as the button's *only* class. The loading state is set on that element **by reference**, but the recovery path in `window.nicepayClose` queries **by class**: `document.querySelectorAll('.nicepay-pay-button.is-loading')`. A merchant who sets `button_class="wp-block-button__link"` to match their theme therefore has a button that can be disabled but can never be re-enabled. This is not a theoretical hand-authored case — the builder exposes an editable CSS Class field and emits `button_class="…"` into the generated shortcode whenever it differs from the default.

**Impact.** For any theme-styled button, closing the NICEPAY window leaves a dead greyed-out button and the buyer must reload the page to try again. Invisible to the merchant because the default class works fine in testing.

**Fix.** Track the in-flight button on a data attribute rather than a class — set `btn.setAttribute('data-nicepay-busy','1')` and have `nicepayClose` query `[data-nicepay-busy]` — or keep a module-level reference. Independently, always append the plugin class to whatever the merchant supplies (UX-095).

### UX-116 — `LOW` — A custom `button_class` also silently disables the loading spinner the JS still tries to show

[assets/css/nicepay.css:229](../../assets/css/nicepay.css#L229)–243 · [templates/standalone-payment-form.php:258](../../templates/standalone-payment-form.php#L258)–260 · [admin/class-nicepay-admin.php:591](../../admin/class-nicepay-admin.php#L591)

**Problem.** The spinner is defined only under `.nicepay-pay-button.is-loading` and its `::after`. With a theme class instead, `is-loading` matches nothing.

**Impact.** An admin who follows the builder's own affordance loses all progress feedback: the button goes disabled with unchanged text during the AJAX round-trip and popup launch — exactly the multi-second window where the buyer is most likely to click again or abandon.

**Fix.** Scope the loading styles on a *state* class rather than the component class — `.is-loading { color: transparent !important; pointer-events: none; position: relative; }` plus its `::after` — so the spinner follows the state, not the skin.

### UX-118 — `LOW` — The shortcode flow has no full-screen loading state or live region, unlike the WooCommerce flow it otherwise mirrors

[templates/payment-form.php:16](../../templates/payment-form.php#L16)–19 · [templates/standalone-payment-form.php:258](../../templates/standalone-payment-form.php#L258)–260

**Problem.** The WooCommerce template opens with a `.nicepay-loading-overlay` carrying `role="alert" aria-live="assertive"` and a "Processing payment..." label. The standalone template emits no overlay at all — its only feedback is `btn.classList.add('is-loading')`. Yet the standalone path is the *slower* of the two, because it makes an `admin-ajax.php` round trip before it can even call `nicepayStart()`.

**Impact.** During the AJAX call plus popup launch the buyer sees only a small button spinner with nothing announced to assistive tech; in modal display mode the modal stays fully interactive behind it. The inconsistency also means the translated "Processing payment..." string is never shown on the shortcode surface.

**Fix.** Emit the same overlay markup (the CSS is already loaded) and toggle `is-active` alongside the existing `is-loading`, at the one set site and all five reset sites. Reuse the existing translated string — and fix its wording per UX-061.

### UX-106 — `LOW` — `NicePayHandler`'s loading toggles use page-global selectors, so a WooCommerce pay button locks every shortcode pay button on the page

[assets/js/nicepay.js:43](../../assets/js/nicepay.js#L43)–45 · [nicepay-payment-gateway.php:241](../../nicepay-payment-gateway.php#L241)

**Problem.** `startPayment()` and `hideLoading()` operate on `$('.nicepay-pay-button')` and `$('.nicepay-loading-overlay')` with no scoping — under a comment that claims the opposite ("class-based for multi-instance support") — and the shortcode default `button_class` is the same class. `startPayment()` is only reachable from the single WooCommerce order-pay button, so cross-talk fires in one direction only: clicking the WC pay button also disables every shortcode button on that page. *Overlay stacking is impossible: `.nicepay-loading-overlay` is emitted in exactly one place in the codebase.*

**Fix.** Resolve the wrapper from the clicked button (`$(e.currentTarget).closest('.nicepay-payment-wrapper')`) and use `wrapper.find(…)`. Keep the global reset in `hideLoading()` only if it is intentionally the panic-reset used by `window.nicepayClose`; otherwise scope it identically. Fix the misleading comment either way.

### UX-107 — `LOW` — The `document.payForm` named-form global is shared by every NicePay form · *needs confirmation*

[templates/payment-form.php:49](../../templates/payment-form.php#L49) · [templates/standalone-payment-form.php:276](../../templates/standalone-payment-form.php#L276) · [assets/js/nicepay.js:74](../../assets/js/nicepay.js#L74)

**Problem.** Both templates emit `name="payForm"`, so with two or more NicePay forms on a page `document.payForm` resolves to an HTMLCollection until something assigns over it. `nicepayStartStandalone` patches this (`document.payForm = form;`) immediately before launching, but `nicepay.js:74` reads the ambient global with no such guard, and the WooCommerce path never assigns it at all.

**Impact.** (1) `$(document.payForm).closest('.nicepay-payment-wrapper')` can wrap a collection, so the error notice anchors to whichever wrapper is first in document order. (2) More consequentially, because the standalone flow permanently reassigns `document.payForm`, a buyer who interacts with a shortcode form and then uses the WC pay button on the same page would have `payForm.submit()` submit the **standalone** form. *A real page with that interaction ordering could not be constructed, so this stays a design fragility rather than a demonstrated bug.*

**Fix.** Drop the legacy named-form global. Give each form a unique `name` derived from the already-unique `$form_id`, and resolve the active form from a module-scoped variable set at click time (or `event.target.closest('form')`).

### UX-100 — `LOW` — Inline `onclick` handlers and un-nonced inline `<script>` blocks break the payment button under a strict CSP

[templates/standalone-payment-form.php:56](../../templates/standalone-payment-form.php#L56) · [templates/standalone-payment-form.php:174](../../templates/standalone-payment-form.php#L174)–367 · [templates/payment-form.php:77](../../templates/payment-form.php#L77)–88

**Problem.** Every interactive entry point in the shortcode is an inline attribute handler, and 193 lines of behaviour live in an un-nonced inline `<script>` — emitted once per shortcode instance. The WooCommerce template likewise defines `nicepaySubmit`/`nicepayClose` inline.

**Impact.** On a site with a CSP lacking `'unsafe-inline'`, the payment button becomes inert with a console error the buyer never sees. *In practice such sites are already broken by WordPress core, the block editor and WooCommerce, which all emit un-nonced inline scripts — so the deployment is rare.*

**Fix.** Move it all into an enqueued `assets/js/nicepay-standalone.js` driven by data attributes (`data-nicepay-action="start" data-nicepay-form="…"`) with a single delegated listener, passing per-instance values via `wp_localize_script` or a `data-config` JSON attribute. Worth doing for the per-instance duplication alone.

### UX-072 — `MEDIUM` — The NICEPAY window language is a fixed global option, decoupled from the site or buyer locale

[includes/class-nicepay-api.php:464](../../includes/class-nicepay-api.php#L464)–479 · [includes/class-nicepay-gateway.php:148](../../includes/class-nicepay-gateway.php#L148) · [nicepay-payment-gateway.php:157](../../nicepay-payment-gateway.php#L157)

**Problem.** `NpLang` is resolved from `get_option( 'nicepay_language', 'KO' )` — a single site-wide value defaulting to KO, seeded at activation — while everything the plugin renders is translated through the WordPress locale. The two are never reconciled, and `get_nicepay_lang()` falls back to `'KO'` for any unrecognised value (a behaviour pinned by `tests/unit/NicePayApiTest.php:388`, which asserts `get_nicepay_lang( 'TR' ) === 'KO'`).

**Impact.** On an English store the buyer reads a fully English payment card, presses the button, and the NICEPAY window opens in Korean — she cannot read the field she must type her card number into. The plugin ships four locales and then hands the buyer to a window in a fifth language.

**Fix.** Add an `AUTO` branch — note the spec offers only EN/CN/KO, so a Turkish site must fall back to **EN**, not KO:
```php
if ( 'AUTO' === $lang ) {
    $l = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
    return 0 === strpos( $l, 'ko' ) ? 'KO' : ( 0 === strpos( $l, 'zh' ) ? 'CN' : 'EN' );
}
```
Make the admin dropdown a four-way choice with **Follow site language (recommended)** as the default, and seed `nicepay_language` to `AUTO`.

### UX-062 — `MEDIUM` — `GoodsCl` is hardcoded to `'1'` (physical goods) at all three CELLPHONE call sites

[includes/class-nicepay-gateway.php:200](../../includes/class-nicepay-gateway.php#L200)–203 · [templates/payment-form.php:61](../../templates/payment-form.php#L61)–63 · [templates/standalone-payment-form.php:148](../../templates/standalone-payment-form.php#L148)–150

**Problem.** All three sites emit `GoodsCl = '1'` unconditionally whenever CELLPHONE is enabled, annotated `// Physical goods`. The spec makes `GoodsCl` a REQUIRED CELLPHONE parameter with two distinct values — `0` = contents (digital), `1` = physical goods — and Korean carrier billing applies different rules and limits to each. The standalone shortcode, used precisely for digital and donation payments, is the most exposed.

**Impact.** Carrier-billing declines or compliance exposure for digital-goods merchants, surfacing to the buyer as an unexplained failure inside the NICEPAY window with no plugin-side message. *(The spec non-conformance is certain; whether carriers reject outright or merely mis-apply limits is inferred.)*

**Fix.** Derive it. WooCommerce:
```php
$virtual = true;
foreach ( $order->get_items() as $item ) {
    $p = $item->get_product();
    if ( $p && ! $p->is_virtual() ) { $virtual = false; break; }
}
$form_data['GoodsCl'] = $virtual ? '0' : '1';
```
Standalone: a `goods_class="digital|physical"` attribute defaulting from the new global setting in UX-039, exposed in the builder with *"Mobile carrier billing treats digital content and physical goods differently — pick the one that matches what you are selling."*

### UX-064 — `MEDIUM` — `WapUrl` / `IspCancelUrl` are never sent, so buyers in native-app webviews are stranded in the card app

[includes/class-nicepay-gateway.php:179](../../includes/class-nicepay-gateway.php#L179)–208 · [templates/standalone-payment-form.php:129](../../templates/standalone-payment-form.php#L129)–154

**Problem.** Korean card authentication routinely hands off to an external app (ISP/모바일ISP, issuer apps, simple-pay apps). The spec provides `WapUrl` (the app scheme to return to) and `IspCancelUrl` (where to land if the buyer cancels inside that app) precisely so the buyer can be deep-linked back. Neither appears anywhere in the plugin — grep returns zero for both.

**Impact.** When the shop is opened inside a native app's webview (KakaoTalk, Naver, a merchant's own app), the card app takes over and there is no return route: the buyer completes authentication and is left sitting in the card app while the webview never receives the result. This disproportionately affects the Korean mobile buyers who are the plugin's core audience.

**Fix.** Two optional settings — "Mobile app return scheme (WapUrl)" and "Mobile app cancel URL (IspCancelUrl)" — documented as *"Only needed if your shop is displayed inside a native app webview; enter the custom URL scheme that reopens your app, e.g. `myshop://payment-return`."* Emit them into `$form_data` and as hidden inputs when set, plus matching shortcode attributes.

### UX-098 — `LOW` — The third-party `nicepay-pgweb.js` loads on the checkout form and the thank-you page, where it is never used

[nicepay-payment-gateway.php:186](../../nicepay-payment-gateway.php#L186)–199 · [nicepay-payment-gateway.php:281](../../nicepay-payment-gateway.php#L281)–287

**Problem.** Assets load when `is_payment_page() || is_checkout() || is_checkout_pay_page()`. WooCommerce's `is_checkout()` is true for the checkout page *including its endpoints*, so it matches both the checkout form and the order-received page — neither of which ever calls `nicepayStart()`. Only the order-pay page and shortcode pages need it; it is enqueued with `null` as the version from a Korean host for every visitor.

**Impact.** An extra DNS lookup, TLS handshake and third-party request on the two highest-intent pages in the funnel, paid by every buyer including those paying by another gateway; for buyers outside Korea the latency is material. It also places a third-party script on the thank-you page — the page most likely to be governed by consent policy.

**Fix.** Narrow to `is_payment_page() || ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() )` and drop the bare `is_checkout()` branch. Better: load the vendor script lazily on first pointerdown/focus within `#nicepay-payment-wrapper`, with `<link rel="preconnect" href="https://pg-web.nicepay.co.kr">`.

### UX-099 / UX-117 — `LOW` — The shortcode enqueues its stylesheet during content rendering, so styles land in the footer and unsized SVGs blow up first

[nicepay-payment-gateway.php:223](../../nicepay-payment-gateway.php#L223)–226 · [nicepay-payment-gateway.php:462](../../nicepay-payment-gateway.php#L462)–468 · [includes/nicepay-icons.php:21](../../includes/nicepay-icons.php#L21)

**Problem.** `is_payment_page()` inspects only `$post->post_content`, so a shortcode in a widget, block-theme template part, page-builder module or term description is not detected during `wp_enqueue_scripts`. The only enqueue then happens inside `render_payment_shortcode()`, which runs while `the_content` is filtered — after `wp_head` has printed styles — so WordPress emits the stylesheet late, in the footer. Compounding it, none of the six method-icon SVGs declares `width`/`height`; they rely entirely on `.nicepay-method-icon svg { width: 22px; height: 22px; }`. (Recorded twice during review: UX-099 and UX-117 are the same defect.)

**Impact.** The buyer sees the payment form unstyled for the duration of the load — and because an inline `<svg>` with a viewBox and no width/height resolves to the full width of its container, the flash is a screenful of oversized line drawings above the payment button, followed by a large layout shift on the most trust-sensitive component on the page.

**Fix.** Register the handles on `wp_enqueue_scripts` and broaden detection (scan widget content, use `has_block`/a `render_block` filter for block themes), or hook `wp`/`template_redirect` and enqueue there so the `<link>` lands in `<head>`, keeping the in-shortcode call as a fallback for programmatic `do_shortcode()`. Independently add `width="22" height="22"` to every SVG and give `.nicepay-method-icon` a fixed 22×22 box to reserve space.

### UX-120 — `ENHANCEMENT` — No instalment (할부) configuration — a core Korean card-conversion lever is entirely absent

[includes/class-nicepay-gateway.php:179](../../includes/class-nicepay-gateway.php#L179)–208 · [templates/standalone-payment-form.php:129](../../templates/standalone-payment-form.php#L129)–154

**Problem.** The spec provides `SelectQuota` (offerable instalment months), `SelectCardCode` (card allowlist), `ShopInterest` (merchant-funded interest-free) and `QuotaInterest` (per-issuer interest-free month lists) for exactly the promotion Korean shops run constantly — 무이자 할부 6개월. Grep returns zero for all four.

**Impact.** For any basket above the spec's 50,000 KRW instalment threshold, the absence of a visible 무이자 할부 가능 signal is a measurable conversion loss in the Korean market, and it is the feature merchants will request immediately after going live.

**Fix.** Admin settings for "Offer instalments" (multi-select → `SelectQuota`, e.g. `00,02,03,06`), "Merchant-funded interest-free" (`ShopInterest`) and "Interest-free months by card" (`QuotaInterest`), emitted only when the total is ≥ 50,000 KRW. Surface it under the CARD option: *"최대 6개월 무이자 할부 / Interest-free instalments up to 6 months."* Enforce the spec's mobile constraint in the settings UI: on mobile, `SelectQuota` must be accompanied by `SelectCardCode`.

### UX-121 — `ENHANCEMENT` — The shortcode cannot accept a buyer-entered amount, so the shipped "Donation" preset is a fixed ₩5,000

[includes/nicepay-functions.php:347](../../includes/nicepay-functions.php#L347)–365 · [templates/standalone-payment-form.php:24](../../templates/standalone-payment-form.php#L24)–25

**Problem.** The amount is always fixed at authoring time: the template reads `$atts['amount']`, normalises it, and renders it as read-only text. There is no `allow_custom_amount` path and no amount input anywhere in the 367-line template. Yet the plugin ships a Donation preset with `'amount' => '5000'` and `'button_text' => __( 'Donate' )` — and a donation form that cannot accept an arbitrary amount is not a donation form.

**Fix.** Add `allow_custom_amount="yes" min_amount="1000" max_amount="1000000"` and, when enabled, a real amount field with a ₩ prefix, live thousands grouping, integer-only enforcement for KRW, and quick-pick chips (₩10,000 / ₩30,000 / ₩50,000 / 직접 입력). Mirror the chosen amount into the button label ("Donate ₩30,000"). **Build this on top of the server-side amount authority from UX-002, never on a client-supplied number.**

## 2.4 The return trip — approval and the moment of maximum anxiety

### UX-012 — `HIGH` — `/nicepay-return/` depends on pretty permalinks; with plain permalinks the standalone buyer lands on a 404 and approval never runs

[nicepay-payment-gateway.php:203](../../nicepay-payment-gateway.php#L203)–211 · [templates/standalone-payment-form.php:127](../../templates/standalone-payment-form.php#L127) · [templates/standalone-payment-form.php:136](../../templates/standalone-payment-form.php#L136)

**Problem.** The standalone return URL is hardcoded as `home_url( '/nicepay-return/' )` in both the form `action` and the `ReturnURL` field, but the only routing is `add_rewrite_rule( '^nicepay-return/?$', … )`. `WP_Rewrite::rewrite_rules()` returns an empty array immediately when `permalink_structure` is empty, so on a "Plain"-permalink site the rule is never merged and the URL resolves to nothing. `handle_payment_return()` gates on `get_query_var( 'nicepay_return' )`, which is never set. The query var *is* registered, so `home_url( '/?nicepay_return=1' )` would work — but nothing generates that form. **The WooCommerce path is immune**, because `WC()->api_request_url()` already falls back to `add_query_arg( 'wc-api', … )` when `permalink_structure` is empty — which is also the fix idiom.

**Impact.** On a plain-permalink site the buyer authenticates, is POSTed to a 404, and the server-side approval is never requested. The authorisation hangs with no net cancel, and the buyer sees a "Nothing found" page after handing over card details. Total silent failure of the shortcode flow on affected sites.

**Fix.**
```php
function nicepay_return_url() {
    return get_option( 'permalink_structure' )
        ? home_url( '/nicepay-return/' )
        : add_query_arg( 'nicepay_return', '1', trailingslashit( home_url() ) );
}
```
Use it at both template sites and for `nicepayParams.returnUrl`. Add a health-check row (UX-018) flagging plain permalinks when any shortcode exists.

### UX-013 — `HIGH` — The return handlers are not idempotent — a re-POST flips a paid order to "failed" and can fire a net cancel against an approved payment

[includes/class-nicepay-gateway.php:234](../../includes/class-nicepay-gateway.php#L234)–241 · [includes/class-nicepay-gateway.php:396](../../includes/class-nicepay-gateway.php#L396)–409 · [includes/class-nicepay-api.php:248](../../includes/class-nicepay-api.php#L248)–267

**Problem.** Both handlers load the transaction by Moid and fall straight through to `request_approval()` with **no** check of `$transaction->status`, `$order->needs_payment()` or `$order->get_meta('_nicepay_tid')`, and no guard against the same POST arriving twice — a back-then-resubmit, a mobile retry, a double-tap. NICEPAY rejects the second approval for a consumed AuthToken; the handler then takes the failure branch and executes `$order->update_status( 'failed', … )` plus `wc_add_notice( 'Payment failed.' … )` on an order successfully paid moments earlier.

**Impact.** A charged buyer is told her payment failed and is bounced to checkout, where she is likely to pay again. Worse: if the rejection response omits `Signature` or `TID`, `request_approval()` calls `request_net_cancel()` with the original AuthToken/TID — an attempt to reverse an already-approved payment.

**Fix.** At the top of both handlers, immediately after loading `$transaction`:
```php
if ( $transaction && in_array( $transaction->status, array( 'paid', 'waiting', 'refunded', 'cancelled' ), true ) ) {
    // WC: wp_safe_redirect( $this->get_return_url( $order ) ); exit;
    // standalone: re-render the stored payment_data as the success page
}
```
Additionally never downgrade an order that already carries `_nicepay_tid`, and take a short transient lock keyed on the Moid (`set_transient( 'nicepay_lock_' . md5( $moid ), 1, 60 )`) around the approval call so two concurrent POSTs cannot both enter it.

### UX-054 — `MEDIUM` — No interstitial while the synchronous approval call runs — the buyer stares at a blank viewport

[includes/class-nicepay-api.php:213](../../includes/class-nicepay-api.php#L213)–220 · [includes/class-nicepay-gateway.php:303](../../includes/class-nicepay-gateway.php#L303) · [includes/class-nicepay-return-handler.php:97](../../includes/class-nicepay-return-handler.php#L97)

**Problem.** Both return handlers execute a blocking `wp_remote_post` to NextAppURL (`'timeout' => 30`) before emitting a single byte. The buyer's browser is mid-navigation, so the viewport is blank for the duration. Neither handler flushes an interstitial, sets `nocache_headers()` (zero occurrences repo-wide), or produces any progress affordance.

**Impact.** At the moment the buyer is most anxious — authorised but unconfirmed — there is nothing on screen. On a slow mobile connection this reads as a crash and invites a reload or back, which is exactly the input that triggers UX-013.

**Fix.** Split the return into two hops. Hop 1: persist the raw auth payload against the Moid, call `nocache_headers()`, render a small interstitial (spinner, `role="status" aria-live="polite"`, *"Confirming your payment…"*, *"Do not close this window or press back"*, plus the order reference and amount), then auto-POST a hidden form to hop 2, which performs the approval and redirects. If single-hop is kept, at minimum `nocache_headers(); echo $interstitial; flush();` before `request_approval()` and finish with a JS redirect. **Do not add a `connect_timeout` argument** — WordPress's HTTP API has no such parameter; set `CURLOPT_CONNECTTIMEOUT` via the `http_api_curl` action if the spec's 5-second connect budget is needed.

### UX-053 — `MEDIUM` — Closing the NICEPAY window throws the WooCommerce buyer off the pay page back to `/checkout/`

[templates/payment-form.php:83](../../templates/payment-form.php#L83)–86 · [templates/standalone-payment-form.php:323](../../templates/standalone-payment-form.php#L323)–329

**Problem.** `window.nicepayClose` — the callback fired when the buyer *closes* the payment window — performs `window.location.href = '<checkout url>'`. Closing is a benign, common action (fetching a card, an ISP app stealing focus, wanting to change method). Instead of re-arming the button, the buyer is navigated away from the order-pay URL entirely. The standalone template handles the identical callback correctly, so the two flows are inconsistent and the WooCommerce one is worse.

**Impact.** The buyer loses her place. Recovery is possible but unguided: WooCommerce keeps `order_awaiting_payment` in the session and does not empty the cart until the thank-you page, so re-running checkout reuses the same pending order — but the buyer does not know that, and the pay page she was on is gone.

**Fix.** Make it symmetrical and do not navigate:
```js
window.nicepayClose = function () {
    if (window.NicePayHandler) {
        window.NicePayHandler.hideLoading();
        window.NicePayHandler.showNotice(/* localised */ 'Payment window closed. Nothing has been charged — press Proceed to Payment when you are ready.', 'info');
    }
};
```
Keep an explicit link as the only way to leave, pointing at `$order->get_checkout_payment_url()` semantics rather than `wc_get_checkout_url()`.

### UX-073 — `MEDIUM` — Selecting EUC-KR in settings sends the charset but never converts, so approvals fail with a raw parse error · *needs confirmation*

[admin/class-nicepay-admin.php:238](../../admin/class-nicepay-admin.php#L238)–241 · [includes/class-nicepay-api.php:204](../../includes/class-nicepay-api.php#L204) · [includes/class-nicepay-api.php:232](../../includes/class-nicepay-api.php#L232)–243

**Problem.** The settings screen exposes a CharSet dropdown with UTF-8 and EUC-KR. When EUC-KR is chosen the plugin sets `'CharSet' => 'euc-kr'` and labels the Content-Type accordingly, but the request body is still UTF-8 bytes and the response body is handed straight to `json_decode()` with **no conversion** — grep for `mb_convert_encoding` returns zero. `json_decode()` requires valid UTF-8 and returns `null` for EUC-KR Korean text, so `if ( ! $result )` fires, a net cancel is issued, and the buyer receives *"Failed to parse approval response."* *Needs confirmation: this depends on NICEPAY actually returning EUC-KR-encoded Korean when `CharSet=euc-kr`. If the response is ASCII-only the parse succeeds and the failure degrades to mojibake in stored `ResultMsg`/`CardName`/`VbankBankName`.*

**Impact.** A merchant who flips this setting — plausibly, since the vendor manual documents euc-kr as the default — silently breaks every payment, and the buyer sees an internal parse-error string. The net cancel at least prevents a charge.

**Fix.** Drop the EUC-KR option entirely and always send `utf-8`, or handle it properly: after `wp_remote_retrieve_body()` in all three request methods, run `$body = mb_convert_encoding( $body, 'UTF-8', 'EUC-KR' );` before `json_decode()` when the configured charset is euc-kr, and convert outbound Korean values the other way. If the option stays, relabel per UX-038. Replace the buyer-visible parse error with human copy per UX-014.

## 2.5 The result page — where the journey most often ends badly

### UX-014 — `HIGH` — Buyers are shown raw PG operator strings, and a deliberate cancellation renders a red "Payment Failed"

[includes/class-nicepay-return-handler.php:164](../../includes/class-nicepay-return-handler.php#L164)–167 · [includes/class-nicepay-return-handler.php:215](../../includes/class-nicepay-return-handler.php#L215)–216 · [includes/class-nicepay-gateway.php:265](../../includes/class-nicepay-gateway.php#L265)–268

**Problem.** There is no result-code → buyer-message mapping anywhere (`is_success_code()` is a boolean test; `nicepay_get_status_label()` maps internal row statuses, not PG codes). The buyer is shown `AuthResultMsg`/`ResultMsg` verbatim — NICEPAY's Korean operator strings — and the page title is chosen purely by a success boolean, so a **user cancellation renders `<h1>Payment Failed</h1>`** with a red ✕. Internal diagnostics are surfaced to the buyer verbatim as the page message: *"Payment verification failed."*, and via `$result->get_error_message()` the strings *"Invalid approval URL."*, *"Failed to parse approval response."*, *"Missing signature in approval response."* and *"Signature verification failed."*

```php
$page_title = $success ? __( 'Payment Successful' ) : __( 'Payment Failed' );
…
<p class="result-message"><?php echo esc_html( $message ); ?></p>
```

**Impact.** Three failures at once: an English- or Chinese-locale buyer gets untranslated Korean; a buyer who consciously backed out is told she FAILED, which reads as a decline and destroys willingness to retry; and internal diagnostics leak plugin internals while meaning nothing to a customer.

**Fix.** Add `nicepay_get_buyer_message( $code, $pay_method )` returning translated, actionable copy, with the PG string demoted to a collapsed "Technical details" block:

| Situation | Title | Message |
|---|---|---|
| User cancelled | Payment cancelled *(neutral grey icon)* | "You cancelled the payment. Nothing has been charged." + primary **Try again** |
| Card declined | Payment not completed | "Your card was declined. Please try a different card or contact your issuer." |
| Signature / parse / URL error | We could not confirm your payment | "If money has left your account it will be returned automatically. Reference: {Moid}." + support link |

Never print "Signature verification failed" to a buyer. Apply the same mapping to the two `wc_add_notice()` calls.

### UX-055 — `MEDIUM` — Failure offers no retry path: standalone shows only "Return to Home", WooCommerce dumps the buyer at `/checkout/`

[includes/class-nicepay-return-handler.php:259](../../includes/class-nicepay-return-handler.php#L259)–263 · [includes/class-nicepay-gateway.php:399](../../includes/class-nicepay-gateway.php#L399)–412

**Problem.** The standalone result page renders exactly one action for every outcome — `<a href="<?php echo esc_url( home_url() ); ?>" class="result-btn">Return to Home</a>` — so a declined buyer must find the product page again from the site root. In WooCommerce every failure branch calls `update_status( 'failed' )` then `wp_safe_redirect( wc_get_checkout_url() )`, discarding the reusable order-pay URL. Marking a *user cancellation* as `failed` also corrupts failed-payment metrics. *(Correction to a common assumption: WooCommerce reuses the order in the `order_awaiting_payment` session key, so a retry does not accumulate new orders.)*

**Impact.** The buyer has already demonstrated intent to pay; a decline is the highest-value retry moment in the funnel. The standalone case is the severe one — she has literally no route back to what she was buying.

**Fix.** Standalone: carry the originating page URL through `ReqReserved` (500 bytes, spec-allowed and currently unused) or a short-lived transient keyed on the Moid, and render a primary **Try again** button to it with "Return to Home" demoted. WooCommerce: redirect to `$order->get_checkout_payment_url()` so the buyer resumes the same order on the same page, keep the order `pending` for user cancellations, and word the notice *"Your payment did not go through. Your order is saved — try again below."*

### UX-083 — `MEDIUM` — The failed-payment result page is a dead end *(companion to UX-055, recorded separately)*

[includes/class-nicepay-return-handler.php:65](../../includes/class-nicepay-return-handler.php#L65) · [includes/class-nicepay-return-handler.php:216](../../includes/class-nicepay-return-handler.php#L216) · [includes/class-nicepay-return-handler.php:259](../../includes/class-nicepay-return-handler.php#L259)–263

**Problem.** Every failure branch calls `render_result_page( false, <vendor message> )` and that value is printed verbatim; the page's only action is a single "Return to Home" link. There is no visual distinction between "you cancelled" and "your card was declined" — both render the identical hard failure treatment — and when the PG returns no `AuthResultMsg` the page renders an **empty paragraph**.

**Fix.** As UX-055, plus: reuse the already-defined-but-unused `.result-btn-secondary` for the secondary action, map known result codes through a helper so the buyer sees a localised message (falling back to the vendor string only for unmapped codes), and never render an empty `.result-message`.

### UX-077 — `MEDIUM` — The standalone result is an unbranded, single-use page that a refresh destroys

[includes/class-nicepay-return-handler.php:26](../../includes/class-nicepay-return-handler.php#L26)–29 · [includes/class-nicepay-return-handler.php:171](../../includes/class-nicepay-return-handler.php#L171)–176 · [includes/class-nicepay-return-handler.php:259](../../includes/class-nicepay-return-handler.php#L259)–263

**Problem.** `render_result_page()` emits a complete standalone HTML document with its own inline CSS — never `get_header()`/`get_footer()`, never the site stylesheet, never the site name or logo. The only site reference on the page is a bare `home_url()` in the button; `get_bloginfo( 'name' )` appears nowhere in the file. The page is also POST-only: `process()` opens with `if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) { wp_die( 'Invalid request method.', …, 405 ); }`, so the confirmation exists solely as the response body of a single POST. Bookmarking, sharing or returning later yields WordPress's generic `wp_die` error screen.

**Impact.** At the moment of highest trust sensitivity — immediately after handing over card details — the buyer is dropped onto a page carrying no evidence of the shop she just bought from. It reads as a third-party interstitial. And because the plugin sends no email either (UX-075), that unbranded page is her only record, and it is destroyed by a refresh.

**Fix.** Render inside the site's chrome — a filterable `nicepay/payment-result.php` template, or at minimum inject `get_site_icon_url( 64 )` and `bloginfo( 'name' )` above `.result-title` and reuse the theme's link colours. Then give the result a durable address: persist the outcome against the Moid (already stored in `payment_data`), redirect after the POST to `?nicepay_receipt=<signed-token>`, and render the same page for that GET. Replace the 405 `wp_die` with a friendly page offering a link home. See also UX-082.

### UX-075 — `MEDIUM` — The standalone success page gives the buyer no order reference, no receipt and no email — the plugin never sends mail

[includes/class-nicepay-return-handler.php:240](../../includes/class-nicepay-return-handler.php#L240)–263 · [includes/class-nicepay-return-handler.php:218](../../includes/class-nicepay-return-handler.php#L218)–239 · [nicepay-payment-gateway.php:313](../../nicepay-payment-gateway.php#L313)–358

**Problem.** On a successful non-VBANK standalone payment the buyer sees TID, amount and method, then a single "Return to Home" link. She is not shown the goods name she just paid for, not shown the Moid (the merchant's own reference, which is what support will ask for), and not told whether a receipt is coming. **`wp_mail` is never called anywhere in the repository** — no confirmation email is ever sent for a shortcode payment, even though the buyer's email is a required field (UX-078). The VBANK branch is worse: it shows the deposit card but **no TID and no Moid at all**.

**Impact.** The buyer walks away from a payment with nothing durable. If she closes the tab she has no record and no reference. The email address she was required to supply is used for nothing, which makes requiring it feel gratuitous.

**Fix.** On the success page show goods name, amount, method (with masked CardNo when present), Moid, date, and *"A receipt has been sent to j\*\*\*@example.com."* Add a "Print / save receipt" action. Implement that email with `wp_mail()` carrying the same fields for CARD/BANK/CELLPHONE and — critically — for VBANK with full deposit instructions, account number and localised deadline. Make the template filterable so merchants can brand it.

### UX-101 — `LOW` — The modal close button overlaps long product names, and `100vh` sizing can push the pay button below the fold on iOS · *needs confirmation*

[assets/css/nicepay.css:344](../../assets/css/nicepay.css#L344)–388 · [assets/css/nicepay.css:529](../../assets/css/nicepay.css#L529)–534 · [templates/standalone-payment-form.php:71](../../templates/standalone-payment-form.php#L71)

**Problem.** `.nicepay-payment-modal-close` is `position: absolute; top: 12px; right: 12px; width/height: 32px`, occupying 12–44 px from the modal's right edge. The content column runs to 24 px from that edge (8 px modal-body padding + 16 px wrapper padding at ≤640 px), giving ~20 px of overlap, and the goods-name `<h3>` has no `padding-right`. Separately `max-height: calc(100vh - 32px)` uses `vh`, which on iOS Safari resolves against the *largest* viewport, so with the URL bar visible the bottom of the modal — including the pay button — can sit below the fold while `document.body.style.overflow = 'hidden'` blocks page scroll. *The iOS half is unverified on a device, and the fixed-position overlay may confine the modal in practice.*

**Fix.** `.nicepay-payment-modal-body .nicepay-payment-info h3 { padding-right: 44px; }`; declare `max-height: calc(100vh - 32px)` followed by `calc(100dvh - 32px)` so the dynamic unit wins where supported; keep `overflow-y: auto` and add `overscroll-behavior: contain`; bump the close button to 44×44.

### UX-102 — `LOW` — The "Cancel" link on the WooCommerce pay page is ambiguous and its touch target is under-sized on mobile

[templates/payment-form.php:70](../../templates/payment-form.php#L70)–72 · [assets/css/nicepay.css:245](../../assets/css/nicepay.css#L245)–260 · [assets/css/nicepay.css:540](../../assets/css/nicepay.css#L540)–553

**Problem.** The only escape hatch beside the pay button is a text link reading "Cancel" pointing at `wc_get_checkout_url()`. Next to a payment button, "Cancel" reads as *cancel my order*, but it merely navigates back to checkout leaving a pending order behind. At ≤640 px the link is blockified with `padding: 8px 0` and 0.875 rem type, giving a ~33 px hit area directly below a 48 px primary button — above the 24 px AA minimum, below the 44 px comfort target.

**Fix.** Rename to "← Back to checkout" or "Choose a different payment method"; if a true cancel is wanted, add a separate link to `$order->get_cancel_order_url()` labelled "Cancel this order". Give the link `min-height: 44px; display: inline-flex; align-items: center; padding: 12px 8px;` inside the ≤640 px block.

### UX-108 — `LOW` — The modal's Escape-handler registry is last-write-wins, orphaning a listener if open is called twice · *needs confirmation*

[templates/standalone-payment-form.php:332](../../templates/standalone-payment-form.php#L332) · [templates/standalone-payment-form.php:350](../../templates/standalone-payment-form.php#L350)–351 · [templates/standalone-payment-form.php:362](../../templates/standalone-payment-form.php#L362)–365

**Problem.** `_nicepayModalEscHandlers[formId] = escHandler;` overwrites any existing entry, and close removes only the currently stored handler, so a second `nicepayOpenPaymentModal(formId)` before a close permanently strands the first closure on `document`. *Hard to reach through the UI — after the first click the fixed overlay covers the trigger — so the realistic path is a programmatic caller, since the function is a bare global exposed via inline `onclick`. Consequence is bounded and harmless; the leak is unbounded per repeat.*

**Fix.** Make open idempotent: `if (_nicepayModalEscHandlers[formId]) { document.removeEventListener('keydown', _nicepayModalEscHandlers[formId]); }` before registering.

### UX-109 — `LOW` — `showStandaloneError` concatenates the message into `innerHTML` — the only unescaped message sink in the codebase

[templates/standalone-payment-form.php:314](../../templates/standalone-payment-form.php#L314) · [templates/standalone-payment-form.php:287](../../templates/standalone-payment-form.php#L287) · [templates/standalone-payment-form.php:224](../../templates/standalone-payment-form.php#L224)

**Problem.** Line 314 builds the notice with string concatenation into `.innerHTML` and line 287 feeds it `resp.data.message` straight from the AJAX response. Every other message renderer escapes first with the `$('<span>').text(x).html()` idiom.

**Impact.** **Not exploitable today** — all three `wp_send_json_error` messages reachable from `nicepay_init_payment` are static `__()` literals. The defect is that the unsafe sink is pre-wired: any future change surfacing a dynamic string (a NICEPAY `ResultMsg`, or a validation message quoting buyer input) turns it into reflected XSS with no further code change. The same file already does it correctly 90 lines earlier (`err.textContent = errorMsg;`).

**Fix.** Build the icon `<span>` via innerHTML (static SVG), then append a second `<span>` whose content is set with `textContent`. One line, matching the pattern already in the file.

---

# §3 — Virtual Account (VBANK): the highest-impact customer-facing gap

For a Korean gateway, 가상계좌 is not an edge case — it is the method a buyer without a credit card uses, and the one the plugin enables **by default on activation**. It is also the only method where the payment is not finished when the buyer leaves the site: she must go to her bank, transfer an exact amount to a specific number, before a deadline. Everything about that hand-off is either missing or wrong.

Trace the whole journey against the code:

| Step | What should happen | What actually happens |
|---|---|---|
| 1. Buyer picks 가상계좌 | Told what it is and what she is committing to | Label only; no description (UX-070); if it is the sole method, no label at all (UX-071) |
| 2. NICEPAY issues the account | Bank, number, exact amount, deadline captured | Captured into the transactions table ✅ |
| 3. Buyer is shown the details | Prominent card, copyable number, localised deadline | **WooCommerce: shown nowhere at all** (UX-001). Standalone: shown, but the number is inert text (UX-059) and the deadline is a raw digit string (UX-016) |
| 4. Buyer receives an email | Deposit instructions in her inbox | Stock WooCommerce on-hold email containing none of it; standalone sends no email at all (UX-075) |
| 5. Buyer deposits | Bank confirms | — |
| 6. NICEPAY notifies the shop | Order → processing/completed | **No endpoint exists** (UX-011); the order stays On-hold forever |
| 7. Merchant resolves it | "Mark deposit received" | No such action; the only button on a `waiting` row is Cancel (UX-008) |

### UX-001 — `CRITICAL` — The VBANK account number is never shown to the WooCommerce buyer; it is written only to a private order note

[includes/class-nicepay-gateway.php:370](../../includes/class-nicepay-gateway.php#L370)–378 · [includes/class-nicepay-gateway.php:363](../../includes/class-nicepay-gateway.php#L363)–368 · [includes/class-nicepay-gateway.php:389](../../includes/class-nicepay-gateway.php#L389)–392

**Problem.** On a successful VBANK issuance the bank name / account number / expiry are passed as the **second argument** of `$order->update_status( 'on-hold', … )`. `WC_Order::update_status( $status, $note, $manual )` forwards that to `add_order_note( $note, 0, $manual )` — `$is_customer_note = 0`, i.e. a **private staff-only note**. The VBANK fields are also not persisted to order meta: only `_nicepay_moid`, `_nicepay_edi_date`, `_nicepay_tid`, `_nicepay_pay_method` and `_nicepay_auth_code` are written (verified by `grep -rn '_nicepay_'`). The buyer is then redirected to the stock order-received page. A full hook inventory — 20 `add_action`/`add_filter` calls across all non-test PHP — contains **no** `woocommerce_thankyou*`, **no** `woocommerce_email_*`, and **no** `woocommerce_order_details_*` / `woocommerce_view_order` hook.

```php
if ( $result_method === 'VBANK' ) {
    // Virtual account - waiting for deposit
    $order->update_status( 'on-hold', sprintf(
        __( 'Awaiting virtual account deposit. Bank: %1$s, Account: %2$s, Expires: %3$s', 'nicepay-payment-gateway' ),
        isset( $result['VbankBankName'] ) ? $result['VbankBankName'] : '',
        isset( $result['VbankNum'] )      ? $result['VbankNum']      : '',
        isset( $result['VbankExpDate'] )  ? $result['VbankExpDate']  : ''
    ) );
}
```

**Impact.** WooCommerce **does** send the stock "Order on-hold" customer email on the pending→on-hold transition, so the buyer receives an email — containing no account number. There is no customer surface anywhere (thank-you page, email, My Account → View order) that carries the number, so she cannot complete the transfer. **VBANK is unusable end to end in the WooCommerce path.** *(The standalone shortcode path is not affected — it renders a deposit card.)*

**Fix.**
1. In the VBANK branch, persist before `$order->save()`:
   `$order->update_meta_data( '_nicepay_vbank_bank_name' | '_nicepay_vbank_num' | '_nicepay_vbank_exp_date' | '_nicepay_vbank_exp_time', … )`
2. Add a **customer** note: `$order->add_order_note( $text, 1 )`.
3. Render the same deposit card from three new hooks registered in the gateway constructor:
   - `add_action( 'woocommerce_thankyou_nicepay', … )`
   - `add_action( 'woocommerce_email_before_order_table', … )`, gated on `$order->get_payment_method() === 'nicepay'` and a non-empty `_nicepay_vbank_num`
   - `add_action( 'woocommerce_order_details_after_order_table', … )`
4. Card content: bank name, account number (with a copy button, UX-059), the exact amount, and the deadline formatted from `VbankExpDate` + `VbankExpTime` in Asia/Seoul (UX-016).

### UX-011 — `HIGH` — No deposit-notification (입금통보) endpoint: VBANK orders never transition after the buyer deposits

[nicepay-payment-gateway.php:203](../../nicepay-payment-gateway.php#L203)–207 · [includes/class-nicepay-gateway.php:359](../../includes/class-nicepay-gateway.php#L359) · [docs/USER-GUIDE.md:585](../../docs/USER-GUIDE.md#L585)–587

**Problem.** The plugin registers exactly one public endpoint (`^nicepay-return/?$`) plus WooCommerce's `wc-api/nicepay_return`. There is no inbound handler for NICEPAY's deposit notification anywhere. Both return handlers set the transaction row to `waiting` and the order to `on-hold`, and **nothing in the codebase ever transitions those to paid/processing**. The documentation is self-contradictory: `DEVELOPER-GUIDE.md:432` states plainly that the deposit-notification handler must be configured with NicePay separately, while two mermaid diagrams (`USER-GUIDE.md:585`–587, `DEVELOPER-GUIDE.md:419`–421) promise automatic completion.

**Impact.** After the buyer transfers the money the order stays On-hold indefinitely: no processing/completed email, no downloadable release, no fulfilment trigger. The merchant must reconcile manually against the NICEPAY console.

**Fix.** Add `add_action( 'woocommerce_api_nicepay_vbank_noti', … )` (permalink-independent, mirroring the existing return endpoint) plus a non-WC equivalent. Restrict to the documented inbound IPs **121.133.126.10, 121.133.126.11, 211.33.136.39**, verify the notification signature, match on Moid/TID, then `$order->payment_complete( $tid )` and set the row to `paid`; reply with the acknowledgement body NICEPAY expects. Surface the notification URL read-only on the Payment settings tab next to the VBANK expiry field so the merchant can hand it to NICEPAY, and show an admin notice when VBANK is enabled but no notification has ever been received. Fix the two misleading diagrams.

### UX-008 — `HIGH` — VBANK is enabled by default but a waiting transaction can never be resolved from the admin

[nicepay-payment-gateway.php:156](../../nicepay-payment-gateway.php#L156) · [includes/class-nicepay-gateway.php:359](../../includes/class-nicepay-gateway.php#L359) · [admin/class-nicepay-transactions.php:143](../../admin/class-nicepay-transactions.php#L143)

**Problem.** `set_default_options()` enables VBANK on activation. A VBANK approval sets the transaction to `waiting`, and with no notification endpoint (UX-011) it can never leave that state by itself. The Transactions screen offers no "re-check status", no "mark as deposited", no explanation of the pulsing WAITING badge, and no display of the stored `vbank_num` / `bank_name` / `vbank_exp_date`. The only action on a `waiting` row is **Cancel**. *(For WooCommerce-linked orders the merchant can work around it by setting the order to Completed on the WooCommerce Orders screen; the NicePay row still stays `waiting` forever, and standalone shortcode transactions have no workaround at all.)*

**Impact.** Merchants on default settings accumulate transactions stuck at `waiting`. The customer has paid; the ledger never reflects it; the admin offers no path forward and no clue that a NICEPAY-side notification URL must be requested — a requirement that appears only in `docs/USER-GUIDE.md:671`.

**Fix.**
1. **Do not enable VBANK by default** — ship CARD only and let the merchant opt in.
2. When VBANK is checked, reveal an inline warning: *"Virtual Account needs one more step at NICEPAY. Deposits are confirmed by a notification NICEPAY sends to your server. Email `it@nicepay.co.kr` with your MID and this URL to have it enabled: `{home_url}/nicepay-deposit/`. Until then, virtual-account orders stay On hold until you mark them paid manually."* Include the inbound IPs as copyable text.
3. Add a **Mark deposit received** row action on `waiting` rows that sets the transaction to `paid` and calls `$order->payment_complete( $tid )`, behind a confirm dialog naming the amount.
4. Render `vbank_num`, `bank_name` and `vbank_exp_date` in the row or the UX-005 detail panel — the data is already in the table and never displayed, so the merchant cannot even answer *"which account should I have paid into?"*

### UX-016 — `HIGH` — The VBANK deadline is computed in UTC, shown as a raw digit string, and `VbankExpTime` is discarded

[includes/class-nicepay-api.php:426](../../includes/class-nicepay-api.php#L426)–429 · [includes/class-nicepay-return-handler.php:234](../../includes/class-nicepay-return-handler.php#L234)–237 · [includes/class-nicepay-gateway.php:354](../../includes/class-nicepay-gateway.php#L354)

**Problem.** Three compounding defects.
1. `get_vbank_exp_date()` returns `date( 'YmdHi', strtotime( '+N days' ) )`. WordPress forces PHP's default timezone to UTC via `wp_timezone_init()`, so a request made at 10:00 KST renders as 01:00 — and NICEPAY interprets the value in KST. **The requested expiry is nine hours earlier than the merchant intends.**
2. The result page prints the response value raw, so the buyer literally reads `20260822`.
3. `VbankExpTime` (spec 6.4.3, `HHmmss`) is **never read anywhere** — a repo-wide grep returns zero hits — so the buyer is never told the hour by which the transfer must clear, and the transactions table has no column for it.

```php
public function get_vbank_exp_date() {
    $days = (int) get_option( 'nicepay_vbank_expiry_days', 3 );
    return date( 'YmdHi', strtotime( '+' . $days . ' days' ) );
}
```
```php
<dt><?php esc_html_e( 'Deadline', 'nicepay-payment-gateway' ); ?></dt>
<dd><?php echo esc_html( $data['VbankExpDate'] ); ?></dd>
```

**Impact.** A buyer told `20260822` reasonably assumes she has all of 22 August; the account may in fact close at 09:30 KST that morning because of the UTC skew. She transfers, the account is closed, the money bounces or lands unallocated, and the order dies. For a method whose whole value proposition is "pay later by bank transfer", an unreadable and wrong deadline is fatal.

**Fix.** Replace the computation with an explicit Seoul one:
```php
$tz = new DateTimeZone( 'Asia/Seoul' );
$d  = new DateTimeImmutable( 'now', $tz );
return $d->modify( '+' . $days . ' days' )->format( 'YmdHi' );
```
Capture `VbankExpTime` alongside `vbank_exp_date` (add the column — UX-052) in both return paths. Render via `DateTime::createFromFormat( 'YmdHis', $date . $time, new DateTimeZone( 'Asia/Seoul' ) )` and `date_i18n()`: *"2026년 8월 22일 (토) 오후 6:30까지"* / *"by Sat 22 Aug 2026, 6:30 PM (KST)"* — and repeat it in the email and on the thank-you card. Also add `required` and range clamping to the expiry-days setting (UX-045), which today can be saved as 0 and issue an already-expired account.

### UX-059 — `MEDIUM` — No copy-to-clipboard on the account number the buyer must retype into a banking app

[includes/class-nicepay-return-handler.php:226](../../includes/class-nicepay-return-handler.php#L226)–229 · [includes/class-nicepay-return-handler.php:195](../../includes/class-nicepay-return-handler.php#L195)

**Problem.** The deposit block renders a 14–20 digit account number as inert text inside a `<dd>` styled at 15 px, with no digit grouping and no `font-variant-numeric: tabular-nums`. There is no copy affordance anywhere on the customer's path, even though this is the highest-friction step of the entire VBANK journey. *Note: the admin clipboard helpers cannot be reused — `assets/js/nicepay-admin.js` is enqueued only on admin screens, and the result page loads no external stylesheet or script at all.*

**Impact.** The buyer must hand-transcribe a long digit string into her banking app on a phone, switching between two apps. A single transposed digit sends the money nowhere recoverable, and NICEPAY cannot match an unallocated deposit to the order.

**Fix.** Add a copy button beside the number on the result page — and on the new WooCommerce thank-you/email surfaces from UX-001:
```html
<button type="button" class="nicepay-copy" data-copy="<?php echo esc_attr( $data['VbankNum'] ); ?>"
        aria-label="<?php esc_attr_e( 'Copy account number', 'nicepay-payment-gateway' ); ?>">복사 / Copy</button>
```
with a small self-contained handler (`navigator.clipboard.writeText` + `execCommand` fallback) and an `aria-live="polite"` confirmation. Render the number at ≥18 px with `font-variant-numeric: tabular-nums; letter-spacing: .02em`, and add a second copy control for the exact amount in digits.

---

# §4 — Accessibility (WCAG 2.2 AA)

Twenty-one findings. Nothing here is exotic: the recurring causes are (a) visually-hidden radios with no `:focus-within` partner, (b) errors signalled by border colour with no text, (c) dialogs marked `aria-modal` that implement none of what that promises, and (d) `#9ca3af` used as body text.

| ID | SC | Level | Surface | Status |
|---|---|---|---|---|
| UX-036 | 2.4.7 Focus Visible, 1.4.11 Non-text Contrast | AA | Shortcode builder — all custom controls | Confirmed |
| UX-085 | 2.4.7 Focus Visible | AA | Builder display-mode + method chips (hidden radios) | Confirmed |
| UX-119 | 2.4.3 Focus Order | A | Decorative preview buttons in the tab order | Confirmed |
| UX-034 | 2.4.3 Focus Order, 2.4.7 Focus Visible | AA | Admin modal — no trap, no restore, no inert | Confirmed |
| UX-068 | 2.4.3 Focus Order, 4.1.2 Name Role Value | A | Customer payment modal — same, plus no accessible name | Confirmed |
| UX-080 | 4.1.2 Name Role Value | A | Customer payment modal (duplicate record of UX-068) | Confirmed |
| UX-033 | 3.3.2 Labels or Instructions, 4.1.2 | A | Delete dialog renders an unlabelled input and focuses it | Confirmed |
| UX-086 | 3.3.2, 4.1.2 | A | Same defect, recorded separately | Confirmed |
| UX-047 | 1.4.1 Use of Color, 3.3.1 Error Identification, 4.1.3 | A/AA | Builder + cancel dialog — colour-only errors | Confirmed |
| UX-087 | 1.4.1, 3.3.1 | A | Same defect, recorded separately | Confirmed |
| UX-035 | 2.2.1 Timing Adjustable, 4.1.3 Status Messages | A/AA | Toasts: 4 s auto-dismiss, `role="status"` for errors | Confirmed |
| UX-066 | 1.4.3 Contrast, 2.2.1, 3.3.1 | AA | Frontend error notice — 4.41:1 and auto-dismiss | Confirmed |
| UX-065 | 1.4.11 Non-text Contrast, 1.4.3 | AA | Unselected radio ring / input border at 1.24:1 | Confirmed |
| UX-079 | 1.4.3 Contrast, 1.4.11 | AA | `--nicepay-text-muted` as body text across ~11 admin sites | Confirmed |
| UX-037 | 1.3.1 Info and Relationships, 3.3.2 | A | Transactions table — no `scope`, no caption, unlabelled filters | Confirmed |
| UX-093 | 4.1.2, plus core notice placement | A | Tab nav — no `aria-label`, no `aria-current`, no `wp-header-end` | Confirmed |
| UX-056 | 1.3.5 Identify Input Purpose, 3.3.1 | AA | Buyer fields outside the form, no `autocomplete`, no error association | Confirmed |
| UX-067 | 4.1.3 Status Messages | AA | Loading overlay uses `role="alert"` on a pre-existing hidden node | **Needs confirmation** |
| UX-097 | 2.3.3 Animation from Interactions | AAA* | No `prefers-reduced-motion` on the customer surfaces | Confirmed |
| UX-114 | 2.3.3 / user preference | AAA* | No `prefers-reduced-motion` across 11 keyframes in both sheets | Confirmed |
| UX-125 | — (best practice) | — | Copy-button success state is colour-only | Confirmed |

\* Honouring `prefers-reduced-motion` is not itself an AA success criterion, but it is the recognised remedy for 2.3.3 and is an OS-level accessibility preference the product currently ignores everywhere.

## Detail

### UX-033 — `MEDIUM` — The Delete Shortcode dialog renders a stray, unlabelled text input that steals initial focus

[assets/js/nicepay-admin.js:79](../../assets/js/nicepay-admin.js#L79)–80 · [assets/js/nicepay-admin.js:92](../../assets/js/nicepay-admin.js#L92)–94 · [assets/js/nicepay-admin.js:216](../../assets/js/nicepay-admin.js#L216)–221

**Problem.** `NicePayModal.open()` renders the message and label *conditionally* but renders `<input type="text" id="nicepay-modal-input" …>` **unconditionally**. The delete flow passes `inputPlaceholder: ''` and no `inputLabel`, so the confirmation dialog for deleting a shortcode shows an empty, unexplained, unlabelled text box that does nothing — and focus is then moved into it. Even in the cancel flow where a label *is* rendered, it is a bare styled `<label>` with **no `for` attribute**, so it is never programmatically associated.

**Impact.** The dialog looks broken. For assistive technology it is an unlabelled control receiving initial focus in a destructive confirmation. Enter is wired to confirm, so the user can fire a delete from a field they had no reason to be in.

**Fix.** Wrap the input in the same `opts.inputLabel ?` guard (or add an explicit `opts.requireInput` flag), bind it with a real `<label for="nicepay-modal-input">`, and when there is no input move initial focus to the **dismiss** button — the correct default for a destructive dialog.

### UX-086 — `MEDIUM` — Same defect, recorded independently

[assets/js/nicepay-admin.js:77](../../assets/js/nicepay-admin.js#L77)–81 · [assets/js/nicepay-admin.js:211](../../assets/js/nicepay-admin.js#L211)–242

The input's value is passed to `onConfirm` as `val` and discarded. Fix as UX-033; focus `#nicepay-modal-cancel` when no input is shown.

### UX-034 — `MEDIUM` — The admin modal has no focus trap, does not inert the background, and never restores focus

[assets/js/nicepay-admin.js:72](../../assets/js/nicepay-admin.js#L72)–137

**Problem.** The dialog is marked `role="dialog" aria-modal="true"` but nothing implements what that markup promises. There is no `keydown` handler for Tab anywhere in the file — only Escape and Enter-inside-the-input — so Tab moves straight out of the dialog into the page behind it; the background is never made inert; and `close()` removes the overlay without returning focus to the trigger, dropping focus to `<body>`. There is also no `aria-describedby` pointing at the message.

**Impact.** Keyboard and screen-reader users can tab into the obscured page while a dialog claims to be modal, and after dismissing lose their place in the transactions table entirely.

**Fix.** Store the invoking element on open (`self.trigger = document.activeElement`), implement a Tab/Shift+Tab cycle over the dialog's focusable children, set `inert` (or `aria-hidden`) on `#wpwrap` while open, call `self.trigger.focus()` in `close()`, and add `aria-describedby`.

### UX-068 — `MEDIUM` — The customer payment modal has no focus trap, no initial focus, no focus restore and no accessible name

[templates/standalone-payment-form.php:62](../../templates/standalone-payment-form.php#L62) · [templates/standalone-payment-form.php:334](../../templates/standalone-payment-form.php#L334)–352 · [templates/standalone-payment-form.php:354](../../templates/standalone-payment-form.php#L354)–366

**Problem.** `nicepayOpenPaymentModal()` sets `display: flex`, locks body scroll, and wires backdrop-click and Escape — but never moves focus into the dialog, never constrains Tab, and never returns focus on close. The container carries `role="dialog" aria-modal="true"` with no `aria-labelledby` or `aria-label`, and the goods-name `<h3>` has no `id` to point one at. The background page is neither `inert` nor `aria-hidden`.

**Impact.** A keyboard or screen-reader user who opens the payment modal is left with focus on the now-obscured trigger; tabbing walks invisibly through the page underneath while the visible dialog is unreachable. On close focus is lost to the document root. For a dialog whose purpose is taking money, this is a hard blocker.

**Fix.** On open: store `document.activeElement`, add `id="<form_id>-title"` to the `<h3>` and `aria-labelledby` to the overlay, move focus to the close button, apply `inert` to the rest of the document, and cycle Tab between the first and last focusable descendants. On close: remove `inert` and restore focus. ~25 lines; Escape and backdrop handling are already correct.

### UX-080 — `MEDIUM` — Same defect, recorded independently (with the admin modal contrast)

[templates/standalone-payment-form.php:62](../../templates/standalone-payment-form.php#L62) · [assets/js/nicepay-admin.js:73](../../assets/js/nicepay-admin.js#L73)

The admin modal is the better of the two — it focuses the input and wires `aria-labelledby="nicepay-modal-title"` — but it too has no Tab guard and no focus restoration. Fix both together; apply the restoration to `NicePayModal.close()` as well.

### UX-035 — `MEDIUM` — Toasts are the only feedback channel yet are time-limited, undismissable, and announced as low-priority status

[assets/js/nicepay-admin.js:25](../../assets/js/nicepay-admin.js#L25) · [assets/js/nicepay-admin.js:33](../../assets/js/nicepay-admin.js#L33)–41 · [assets/css/nicepay-admin.css:339](../../assets/css/nicepay-admin.css#L339)–341 · [assets/css/nicepay-admin.css:1207](../../assets/css/nicepay-admin.css#L1207)–1210

**Problem.** Four defects in one component. (1) Every toast auto-removes after 4000 ms with no dismiss button and no pause on hover or focus — including error toasts carrying the PG's failure message, the single most important string in the product. (2) All types use `role="status"` + `aria-live="polite"`, so a failed refund may not be announced until the user is idle; errors need `role="alert"`. (3) The live region is the toast element itself, created already populated and only then injected — the pattern known to be unreliable for announcement. (4) `.nicepay-toast-container { top: 40px }` clears the 32 px desktop admin bar, but the ≤782 px media query adjusts only `left`/`right`, so on mobile (46 px admin bar) toasts sit under it.

**Impact.** A merchant who looks away for five seconds permanently loses the only report of why a refund failed — there is no history and no log to fall back on (UX-005). AT users may never hear it.

**Fix.** Create the container once at page load with `aria-live="polite"` and inject only text. Give errors `role="alert"` and **never** auto-dismiss them; add a visible close button with `aria-label="Dismiss"`. Pause the timer on hover and focus-within for other types. Set `top: calc(var(--wp-admin--admin-bar--height, 32px) + 8px)` and override it inside the 782 px query. Mirror every error into a persistent inline `notice notice-error` so the message survives.

### UX-036 — `MEDIUM` — Keyboard focus is invisible across every custom control on the shortcode builder

[assets/css/nicepay-admin.css:76](../../assets/css/nicepay-admin.css#L76)–81 · [assets/css/nicepay-admin.css:917](../../assets/css/nicepay-admin.css#L917)–922 · [assets/css/nicepay-admin.css:624](../../assets/css/nicepay-admin.css#L624)–629

**Problem.** The 1255-line stylesheet contains five focus rules across six selectors. Four of them (`.nicepay-filters select/input:focus`, `.nicepay-modal-body input/textarea:focus`, `.nicepay-sc-input:focus`, `.nicepay-sc-select-sm:focus`) remove the native ring with `outline: none` and substitute a `box-shadow`, which disappears entirely in forced-colors mode. The radio-backed custom controls — `.nicepay-sc-method-chip` and `.nicepay-sc-display-mode` — hide their real `<input>` with `position:absolute; opacity:0; width:0; height:0` and define **no** `:focus-within` style at all. Colour swatches, card action buttons, modal buttons and the output copy button have hover styles and no focus styles. The one correct rule in the file is `.nicepay-copy-btn:focus-visible` — use it as the model.

**Impact.** The Shortcode Generator is effectively unusable by keyboard: a sighted keyboard user cannot tell which chip or card is focused, so cannot tell which payment method they are about to select.

**Fix.**
```css
.nicepay-sc-method-chip:focus-within,
.nicepay-sc-display-mode:focus-within,
.nicepay-sc-color-swatch:focus-visible,
.nicepay-sc-card-btn:focus-visible,
.nicepay-sc-copy-btn:focus-visible,
.nicepay-modal-btn:focus-visible {
    outline: 2px solid var(--wp-admin-theme-color, #2271b1);
    outline-offset: 2px;
}
```
Use `outline`, never `box-shadow`, so the indicator survives forced-colors. Stop using `outline: none` on text inputs — keep the ring and layer the shadow on top. Add `aria-pressed` to the colour swatches and translate their `title` attributes, which are hardcoded English (`title="Blue"`, `"Black"`, `"Green"`, `"Red"`, `"Purple"`, `"Orange"`).

### UX-085 — `MEDIUM` — The builder's visually-hidden radios have no `:focus-within` styling at all

[assets/css/nicepay-admin.css:624](../../assets/css/nicepay-admin.css#L624)–629 · [assets/css/nicepay-admin.css:917](../../assets/css/nicepay-admin.css#L917)–922 · [assets/css/nicepay.css:104](../../assets/css/nicepay.css#L104)–107

**Problem.** Both `.nicepay-sc-display-mode input` and `.nicepay-sc-method-chip input` apply `position: absolute; opacity: 0; width: 0; height: 0` — exactly as the frontend does. But the frontend pairs that with `.nicepay-method-option:focus-within { outline: 2px solid var(--nicepay-primary); outline-offset: 2px; }`, and `nicepay-admin.css` has no `:focus-within` rule anywhere. Secondary: neither label sets `position: relative`, so those absolutely-positioned radios escape to the nearest positioned ancestor and can cause a scroll jump on focus — contrast `nicepay.css:96`, which gets it right.

**Fix.** Add the `:focus-within` rules above and `position: relative` to both label rules. The correct pattern already exists in the sibling stylesheet.

### UX-119 — `LOW` — Decorative preview buttons are real focusable `<button>` elements neutralised only by `pointer-events: none`

[admin/class-nicepay-admin.php:391](../../admin/class-nicepay-admin.php#L391)–393 · [admin/class-nicepay-admin.php:645](../../admin/class-nicepay-admin.php#L645)–647 · [assets/css/nicepay-admin.css:1090](../../assets/css/nicepay-admin.css#L1090)–1096

**Problem.** `pointer-events: none` blocks the mouse but does not remove an element from the tab order and does not stop Enter/Space activation once focused.

**Impact.** A keyboard user on the Shortcodes tab tabs through one dead button per saved shortcode card, plus one in the generator's preview panel, each announced as a real button with no effect.

**Fix.** Add `disabled`, `tabindex="-1"` and `aria-hidden="true"` (they are illustrations, not controls), or render them as `<span>` with the button classes. `disabled` alone also removes the tab stop and lets you drop the `pointer-events` hack.

### UX-047 — `MEDIUM` — Required-field errors in the builder and the cancel dialog are signalled by colour alone

[admin/class-nicepay-admin.php:854](../../admin/class-nicepay-admin.php#L854)–856 · [assets/js/nicepay-admin.js:155](../../assets/js/nicepay-admin.js#L155)–157 · [assets/css/nicepay-admin.css:701](../../assets/css/nicepay-admin.css#L701)–704

**Problem.** Three separate colour-only signals. (1) Saving a shortcode without a name does `$(fields.name).addClass('is-invalid').focus()`, and `.nicepay-sc-input.is-invalid` is nothing but `border-color: #dc2626` plus a red box-shadow — no message, no `aria-invalid`, no announcement. (2) Submitting the cancel dialog without a reason does `$('#nicepay-modal-input').css('border-color', '#dc2626').focus()` — again a red border and silence, applied as an inline style that is never cleared on subsequent valid input. (3) The builder's `#sc-validation` panel is toggled with `.show()`/`.hide()` and carries no `aria-live` or `role`, so its contents are never announced, and it sits in the sticky right-hand column far from the fields it describes.

**Impact.** Colour-blind and screen-reader users get no indication of what is wrong, or that anything is wrong at all — the form simply does nothing, which reads as a broken button.

**Fix.** For each: set `aria-invalid="true"`, render a visible text message adjacent to the field, and link it with `aria-describedby`. Copy the pattern the **frontend already implements correctly** at [templates/standalone-payment-form.php:221](../../templates/standalone-payment-form.php#L221)–225 (`role="alert"` + `textContent`); `.nicepay-field-error` is already loaded in the admin via the `nicepay-shared-css` dependency. Add `role="alert"` to `#sc-validation` and keep it permanently in the DOM (toggle a class, not `display`). Clear `aria-invalid`, the inline style and the message on the next `input` event, not on the next submit.

### UX-087 — `MEDIUM` — Same defect, recorded independently

[assets/js/nicepay-admin.js:155](../../assets/js/nicepay-admin.js#L155)–158 · [admin/class-nicepay-admin.php:853](../../admin/class-nicepay-admin.php#L853)–858 · [assets/css/nicepay-admin.css:701](../../assets/css/nicepay-admin.css#L701)–704

Fix as UX-047. Suggested copy: Name → *"Give this shortcode a name so you can find it later."*; Reason → *"Enter a reason — NICEPAY requires one and it is stored on the order."*

### UX-037 — `MEDIUM` — The transactions table lacks header scope, a caption and labelled filters, and loses table semantics on mobile

[admin/class-nicepay-transactions.php:83](../../admin/class-nicepay-transactions.php#L83)–91 · [admin/class-nicepay-transactions.php:63](../../admin/class-nicepay-transactions.php#L63)–65 · [assets/css/nicepay-admin.css:1191](../../assets/css/nicepay-admin.css#L1191)–1195

**Problem.** Four defects on one screen. (1) Every `<th>` in `<thead>` omits `scope="col"`, which WordPress core list tables include. (2) There is no `<caption>`. (3) All five filter controls — two selects, two date inputs and the search box — have **no `<label>`**; the search relies on `placeholder="Search..."` alone, and the two date inputs carry `placeholder` attributes that browsers ignore entirely on `type="date"`. (4) The 782 px breakpoint sets `display: block` on the `<table>` element itself, which strips the implicit table role in several browser/AT combinations.

**Impact.** On a nine-column financial table a screen-reader user gets no column association when moving through cells, cannot identify any filter control, and on a phone loses the structure entirely.

**Fix.** Add `scope="col"` to all nine headers and `<caption class="screen-reader-text">NicePay transactions</caption>`. Give every filter a `<label class="screen-reader-text">` (Status, Payment method, From date, To date, Search transactions). Change the search placeholder to something actionable — *"Search TID, order no., buyer or product"* — matching what `nicepay_get_transactions()` actually searches (tid, moid, buyer_name, goods_name). Replace the mobile `display: block` with a wrapper: `<div class="nicepay-table-scroll" tabindex="0" role="region" aria-label="Transactions">` with `overflow-x: auto` on the wrapper, which keeps table semantics and makes the scroll region keyboard reachable.

### UX-093 — `LOW` — Tab navigation is missing `aria-current`, an accessible name, and the `wp-header-end` marker

[admin/class-nicepay-admin.php:132](../../admin/class-nicepay-admin.php#L132)–161 · [admin/class-nicepay-transactions.php:40](../../admin/class-nicepay-transactions.php#L40)

**Problem.** The `<nav class="nav-tab-wrapper">` has no `aria-label`, so screen-reader users hear an unnamed navigation landmark. The active tab is marked only by the `nav-tab-active` class with no `aria-current="page"`. There is no `<hr class="wp-header-end">` after the `<h1>` — the marker WordPress core JS uses to position admin notices — so any notice lands in an arbitrary spot. The mode badge is inside the `<h1>`, so the heading is announced as "NicePay Settings TEST".

**Fix.** Add `aria-label` and `wp-clearfix` to the nav to match core; emit `aria-current="page"` on the active tab; insert `<hr class="wp-header-end">` between the `<h1>` and the nav (also the anchor point UX-019 needs); move the mode badge out of the heading or prefix it with `<span class="screen-reader-text">Current mode: </span>`. The Transactions page `<h1>` needs the same marker.

### UX-056 — `MEDIUM` — Buyer input fields sit outside the `<form>` and carry no `autocomplete`, `inputmode`, `maxlength` or error association

[templates/standalone-payment-form.php:97](../../templates/standalone-payment-form.php#L97)–123 · [templates/standalone-payment-form.php:126](../../templates/standalone-payment-form.php#L126) · [templates/standalone-payment-form.php:221](../../templates/standalone-payment-form.php#L221)–225

**Problem.** `.nicepay-buyer-fields` (name/email/phone, each carrying `required`) is a **sibling** of the `<form>`, not a child. With no form owner the `required` attribute is inert for constraint validation, the browser cannot group the fields for autofill, and Enter cannot submit. Values reach the payload only because JS copies them into hidden inputs. Separately, a repo-wide grep for `autocomplete`, `inputmode`, `maxlength`, `aria-describedby`, `aria-invalid` and `novalidate` across `templates/` and `assets/js/nicepay.js` returns **zero hits**, and the injected error node is `role="alert"` only — never associated with the input it describes.

**Impact.** Buyers lose one-tap autofill of name/email/phone, the single biggest friction reducer on a mobile payment form. Screen-reader users who tab back to a field after an error hear nothing about it. Over-length values silently violate NICEPAY's byte limits (BuyerName 30, BuyerTel 20, BuyerEmail 60).

**Fix.** Move `.nicepay-buyer-fields` inside the `<form>` (or add `form="<?php echo esc_attr( $form_id ); ?>"` to each input) and give each field real attributes — name → `autocomplete="name" maxlength="30"`; email → `autocomplete="email" inputmode="email" maxlength="60"`; phone → `autocomplete="tel" inputmode="tel" maxlength="20"`. On validation failure set `aria-invalid="true"`, give the error div a stable id and set `aria-describedby`; clear both on the next attempt. Add an error summary above the form linked to each field, keeping the existing focus-to-first-invalid behaviour. *(Note the pay button is `type="button"`, so implicit submission never fires regardless — moving the fields is about autofill and semantics, not submission.)*

### UX-065 — `MEDIUM` — Unselected radio rings and input borders sit at 1.24:1 — the unselected state is effectively invisible

[assets/css/nicepay.css:20](../../assets/css/nicepay.css#L20)–25 · [assets/css/nicepay.css:109](../../assets/css/nicepay.css#L109)–141 · [assets/css/nicepay.css:284](../../assets/css/nicepay.css#L284)–305

**Problem.** `--nicepay-border: #e5e7eb` has L = 0.7981; against `#ffffff` that is **1.24:1**, far below the 3:1 SC 1.4.11 requires for the visual boundary of a control. That colour is the 2 px `.nicepay-method-option` card border, the 2 px `::before` radio ring, and the 1.5 px `.nicepay-field-input` border — and because the real radio is hidden with `opacity: 0; width: 0; height: 0`, no native indicator remains. Separately `--nicepay-text-muted: #9ca3af` computes to **2.54:1** on white and is used for `::placeholder` text (fails SC 1.4.3) and unselected method icons. *(The **selected** state is fine and was refuted as a finding: it carries a `#2563eb` 2 px border at 5.17:1, a 1 px ring, a 5 px-wide blue `::before`, a blue icon and a 500→600 weight change — shape and weight cues alongside hue, so SC 1.4.1 is not violated.)*

**Impact.** A low-vision buyer, or anyone on a phone in daylight, cannot see where the unselected options and the input boxes are — the controls read as flat white space until one is chosen.

**Fix.** Introduce `--nicepay-control-border: #6b7280` (L = 0.1672 → **4.83:1** on white) for the radio ring and input borders, keeping `#e5e7eb` only for decorative dividers. Darken `--nicepay-text-muted` to `#6b7280` for placeholders and unselected icons. Re-verify against the dark palette if UX-069 is actioned.

### UX-066 — `MEDIUM` — Error notice text fails contrast at 4.41:1 and both implementations auto-dismiss on a timer

[assets/css/nicepay.css:15](../../assets/css/nicepay.css#L15)–16 · [assets/css/nicepay.css:444](../../assets/css/nicepay.css#L444)–459 · [assets/js/nicepay.js:91](../../assets/js/nicepay.js#L91)–93 · [templates/standalone-payment-form.php:316](../../templates/standalone-payment-form.php#L316)

**Problem.** `.nicepay-notice-error` sets `color: #dc2626` (L = 0.1675) on `background: #fef2f2` (L = 0.9099) at 14 px — recomputed as **4.41:1**, below the 4.5:1 required under 18.66 px. And both notice implementations remove the message on a timer — 6000 ms in `nicepay.js`, 8000 ms in the standalone template — with no pause, no manual dismiss, and no way to bring it back.

**Impact.** The error text is marginally unreadable for low-vision buyers, and any buyer who looks away — or who uses a screen reader and needs longer to parse it — loses the only explanation of why their payment did not start.

**Fix.** Use a darker token for notice **text** while keeping `#dc2626` for icons and borders: `#b91c1c` computes to **5.91:1** on `#fef2f2`. Remove both auto-dismiss timers for error-severity notices and add an explicit close button with `aria-label="Dismiss"`; keep auto-dismiss only for transient success toasts, cancelled on hover/focus.

### UX-079 — `MEDIUM` — `--nicepay-text-muted` (#9ca3af) is used as real text and icon colour in ~11 admin locations at 2.31–2.54:1

[assets/css/nicepay.css:22](../../assets/css/nicepay.css#L22) · [assets/css/nicepay-admin.css:152](../../assets/css/nicepay-admin.css#L152) · [assets/css/nicepay-admin.css:754](../../assets/css/nicepay-admin.css#L754) · [assets/css/nicepay-admin.css:1100](../../assets/css/nicepay-admin.css#L1100)

**Problem.** `#9ca3af` has L = 0.3636 → **2.54:1** on `#ffffff`, **2.31:1** on `#f3f4f6`. Every use site is genuine informational text at 11–13 px, not placeholder or disabled text: `.nicepay-empty-desc`, `.nicepay-method-code`, `.nicepay-shortcodes-empty`, `.nicepay-sc-display-mode-text span`, `.nicepay-sc-optional` (on `#f3f4f6`), `.nicepay-sc-preview-label`, `.nicepay-sc-pv-section-label`, `.nicepay-sc-hint`. `.nicepay-copy-btn` is a functional icon control at 2.54:1, under the 3:1 SC 1.4.11 floor. Additionally `.nicepay-sc-output-label { color: #64748b }` on the `#0f172a` output card computes to 3.75:1, also short.

**Impact.** Systemic SC 1.4.3 (and SC 1.4.11 for the copy button) failure at roughly half the required ratio, across empty-state guidance, field hints, section eyebrows and an interactive icon button — all fixable with one token change.

**Fix.** Use `#6b7280` (already declared as `--nicepay-text-secondary`, **4.83:1** on white) for informational text; also darken `.nicepay-sc-optional`'s background to `#fff` or its text to `#4b5563` since 4.83:1 drops to ~4.39:1 on `#f3f4f6`. Keep `#9ca3af` only for `::placeholder` and the disabled-button background (exempt as an inactive control). Raise `.nicepay-sc-output-label` to `#94a3b8` (5.7:1 on `#0f172a`).

### UX-067 — `MEDIUM` — The loading overlay uses `role="alert"` on a permanently-present hidden node, so no status is announced · *needs confirmation*

[templates/payment-form.php:16](../../templates/payment-form.php#L16)–19 · [assets/js/nicepay.js:44](../../assets/js/nicepay.js#L44)–45 · [assets/css/nicepay.css:403](../../assets/css/nicepay.css#L403)–419

**Problem.** The overlay markup — `role="alert" aria-live="assertive"` with the text "Processing payment..." — is rendered into the DOM on page load and merely toggled between `display: none` and `display: flex`. Live-region announcements are driven by *content changes* within an observed region; toggling the visibility of unchanged text is unreliable across NVDA/JAWS/VoiceOver. Nothing else announces state — the method radios have no live companion and there is no `aria-busy` on the form. *Some screen readers do announce content entering an existing live region when a hidden ancestor becomes visible, so "will not announce" is stronger than can be verified without AT testing; the pattern is unreliable and the fix is correct regardless.*

**Impact.** A screen-reader user presses "Proceed to Payment", the button goes disabled and silent, and they receive no confirmation that anything is happening. They may press repeatedly or conclude the site is broken.

**Fix.** Render an empty `<div id="nicepay-status" role="status" aria-live="polite" class="screen-reader-text"></div>` on load and **write text into it** at each transition ("Opening the secure payment window…", "Payment window closed. Nothing has been charged.", "Confirming your payment…"). Make the visual overlay decorative with `aria-hidden="true"`, set `aria-busy="true"` on `#nicepay-payment-wrapper` while in flight, and give the submit button `aria-describedby="nicepay-status"`.

### UX-097 — `LOW` — No `prefers-reduced-motion` support in the customer-facing CSS

[assets/css/nicepay.css:529](../../assets/css/nicepay.css#L529) · [assets/css/nicepay.css:242](../../assets/css/nicepay.css#L242) · [includes/class-nicepay-return-handler.php:201](../../includes/class-nicepay-return-handler.php#L201)–202

**Problem.** `assets/css/nicepay.css` contains exactly one `@media` rule — `@media (max-width: 640px)` — and no `prefers-reduced-motion` block; the result page's inline CSS has none either. The buyer-facing frontend runs two infinite spinners, modal backdrop and content entrance animations, a notice `slide-down`, a field-error `slide-down`, and on the result page a `box-appear` translate+scale plus a bouncing `icon-pop`. *(One reviewer claim was refuted: the pulsing `.nicepay-status-waiting` dot is admin-only and never renders on a customer surface, so SC 2.2.2 does not apply to a buyer.)*

**Fix.** Append to `nicepay.css` and to the result page's inline `<style>`:
```css
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
```
Then re-express the spinner under reduced motion as a static ring plus its text label so the loading state is still conveyed.

### UX-114 — `LOW` — No `prefers-reduced-motion` handling for eleven keyframe animations across both stylesheets and the result page

[assets/css/nicepay.css:390](../../assets/css/nicepay.css#L390) · [assets/css/nicepay-admin.css:326](../../assets/css/nicepay-admin.css#L326) · [includes/class-nicepay-return-handler.php:201](../../includes/class-nicepay-return-handler.php#L201)–202

**Problem.** `grep -rn 'prefers-reduced-motion|prefers-color-scheme|forced-colors|@media print'` across `assets/`, `includes/`, `templates/` and `admin/` returns **nothing**. There are 9 `@keyframes` across the two stylesheets plus 2 inlined in the result page, and `nicepay.css:519` runs `nicepay-pulse 2s ease-in-out infinite`. *(The forced-colors half of the original claim was refuted for the fake radio: it signals selection through `border-width: 2px → 5px`, which survives Windows High Contrast Mode intact.)*

**Fix.** As UX-097, plus the same block scoped to `.nicepay-admin` in `nicepay-admin.css`.

### UX-125 — `ENHANCEMENT` — The copy-button success state changes colour only, without an icon swap

[assets/css/nicepay-admin.css:172](../../assets/css/nicepay-admin.css#L172)–174 · [assets/js/nicepay-admin.js:28](../../assets/js/nicepay-admin.js#L28)

**Problem.** `.nicepay-copy-btn.is-copied { color: var(--nicepay-success); }` is the entire state change, and the SVG is not swapped. *(Not a WCAG failure — the accompanying `NicePayToast.show(...)` carries `role="status" aria-live="polite"` with text, which is the real feedback channel. The shortcode copy button also swaps its label text, so it is not colour-only.)*

**Impact.** A colourblind user gets no glanceable confirmation on the 14×14 px icon itself.

**Fix.** Swap the button's SVG children for the checkmark path already present in the icon map (`M20 6L9 17l-5-5`) while `is-copied` is active, then restore after 2 s.

---

# §5 — Visual design system and CSS

There are **three** stylesheets in this product, not two: `assets/css/nicepay.css` (554 lines, tokenised), `assets/css/nicepay-admin.css` (1255 lines, partially tokenised) and a hand-written inline `<style>` block in `includes/class-nicepay-return-handler.php` that uses no tokens at all. The third one styles the terminal screen of the customer payment flow.

### UX-041 — `MEDIUM` — 1255 lines of custom admin CSS re-implement WordPress components, ignore admin colour schemes, and honour no motion preference · *needs confirmation on framing*

[assets/css/nicepay-admin.css:8](../../assets/css/nicepay-admin.css#L8)–10 · [assets/css/nicepay-admin.css:100](../../assets/css/nicepay-admin.css#L100)–109 · [assets/css/nicepay-admin.css:292](../../assets/css/nicepay-admin.css#L292)–324 · [admin/class-nicepay-admin.php:53](../../admin/class-nicepay-admin.php#L53)–58

**Problem.** The stylesheet rebuilds components WordPress already ships — a modal, a toast system, buttons (`.nicepay-modal-btn` instead of `.button`/`.button-primary`), form fields (`.nicepay-sc-input` instead of `.regular-text`) — on a private palette of hardcoded hex values. **Nothing reads `--wp-admin-theme-color`**, so on any of the eight non-default admin colour schemes the plugin's blue clashes with the surrounding chrome. `.nicepay-admin { font-family: var(--nicepay-font) }` overrides the admin font stack. `.nicepay-admin .wp-list-table thead th` overrides core list-table headers with uppercase small caps and a custom background, so the NicePay table looks unlike every other table in wp-admin. There is no `prefers-reduced-motion` guard despite four keyframe animations and a hover `transform: scale(1.15)` on the colour swatches, and no forced-colors handling — greps for `prefers-reduced-motion`, `prefers-color-scheme`, `forced-colors` and `wp-admin-theme` all return zero. The admin also loads the entire 554-line **frontend** stylesheet purely for the `:root` tokens and the `.nicepay-status*` badges. *(The "re-implements WP components is wrong" framing is a design judgement; the colour-scheme and reduced-motion gaps are objective.)*

**Impact.** The plugin reads as a foreign application bolted into wp-admin rather than part of it, will drift further as WordPress evolves its components, and every hand-rolled control is a fresh accessibility surface — see UX-036, UX-034, UX-035.

**Fix.** (1) Replace hardcoded blues with `var(--wp-admin-theme-color, #2271b1)` / `var(--wp-admin-theme-color-darker-20, #135e96)`. (2) Drop `.nicepay-modal-btn*` and `.nicepay-sc-input` for core `.button`, `.button-primary`, `.button-link-delete`, `.regular-text` — this deletes roughly 200 lines and inherits core's focus, disabled and high-contrast handling for free. (3) Remove the `font-family` override. (4) Delete the `.wp-list-table thead th` override. (5) Extract the `:root` tokens and `.nicepay-status*` badges into a small `nicepay-tokens.css` so the admin stops loading 554 lines of payment-form CSS. (6) Add a `prefers-reduced-motion: reduce` block scoped to `.nicepay-admin`.

### UX-069 — `MEDIUM` — The frontend CSS hardcodes a light palette with no dark-mode handling

[assets/css/nicepay.css:8](../../assets/css/nicepay.css#L8)–35 · [assets/css/nicepay.css:192](../../assets/css/nicepay.css#L192) · [includes/class-nicepay-return-handler.php:179](../../includes/class-nicepay-return-handler.php#L179)–180

**Problem.** All 24 tokens are declared once on bare `:root` with no `@media (prefers-color-scheme: dark)` override (grep: zero hits in either stylesheet). `.nicepay-payment-wrapper` paints `#ffffff` with `--nicepay-text: #111827`, and the pay button forces `color: #fff !important` **twice**, overriding any theme button colour. The result page hardcodes `background: #f0f2f5` on `body` and `#fff` on `.result-box` with no dark handling at all.

**Impact.** On a dark WordPress theme the payment form is a glaring white rectangle dropped into a dark page — it reads as a third-party ad or an injected iframe rather than part of the shop, which is corrosive at precisely the moment trust matters. The `!important` also breaks merchants combining `button_color` with a theme button class (UX-081, UX-095).

**Fix.** Keep the light palette on bare `:root`, then redefine only the tokens inside a dark query:
```css
@media (prefers-color-scheme: dark) {
    :root {
        --nicepay-bg-white: #111827;
        --nicepay-text: #f9fafb;
        --nicepay-text-secondary: #9ca3af;
        --nicepay-border: #374151;
        --nicepay-primary-light: #1e293b;
        --nicepay-primary-border: #3b82f6;
    }
}
```
Re-verify contrast for the dark values against UX-065 and UX-066. Mirror the same tokens into the result page's inline CSS. Replace `color: #fff !important` with a `--nicepay-on-primary` token.

### UX-082 — `MEDIUM` — The payment-result page reimplements the entire design system inline — 26 lines of duplicated CSS, zero tokens, a third radius scale

[includes/class-nicepay-return-handler.php:164](../../includes/class-nicepay-return-handler.php#L164)–268 · [includes/class-nicepay-return-handler.php:179](../../includes/class-nicepay-return-handler.php#L179) · [includes/class-nicepay-return-handler.php:199](../../includes/class-nicepay-return-handler.php#L199)–200

**Problem.** `render_result_page()` emits a complete standalone HTML document with its own inline `<style>`, re-declaring by hand every value that already exists as a token: `#2563eb` / `#1d4ed8`, `#dcfce7` / `#16a34a`, `#fee2e2` / `#dc2626`, `#111827`, `#6b7280`, `#374151`, `#e5e7eb`, `#eff6ff` / `#bfdbfe`, plus a byte-identical copy of the `--nicepay-font` stack. Its radii (16 px, 12 px, 10 px) match none of the declared scale (6/10/14 px), its two keyframes duplicate the modal-entrance motion in `nicepay.css`, and `.result-btn-secondary` is dead — no markup emits it.

**Impact.** This is the terminal, highest-stakes customer-facing screen of the shortcode flow — the confirmation/failure page — and it is the one surface completely outside the design system. Any brand change (primary colour, corner rounding, font) silently skips it, so the confirmation page drifts away from the payment form it follows. It is also the third stylesheet with no reduced-motion handling.

**Fix.** Either (a) inline `nicepay.css`'s `:root` token block into the `<head>` and rewrite the 26 rules to consume `var(--nicepay-*)`, or (b) move the result page to a real template under `templates/` rendering inside the theme via `get_header()`/`get_footer()` and reusing `.nicepay-payment-wrapper` / `.nicepay-notice` / `.nicepay-status` — which also resolves UX-077. Delete the dead `.result-btn-secondary` rules either way, or wire them up per UX-083.

### UX-084 — `MEDIUM` — Every `:has()` rule is grouped with its own `.is-selected` fallback, so unsupporting browsers drop **both**

[assets/css/nicepay.css:109](../../assets/css/nicepay.css#L109)–114 · [assets/css/nicepay.css:116](../../assets/css/nicepay.css#L116)–121 · [assets/css/nicepay.css:137](../../assets/css/nicepay.css#L137)–141

**Problem.** All four `:has()` usages sit in comma-separated selector lists **alongside** the `.is-selected` class that exists precisely as the JS fallback. Per CSS selector-list semantics, one unsupported selector invalidates the whole rule — so in a browser without `:has()`, the `.is-selected` half is discarded too. Meanwhile the radio input itself is visually erased with `position: absolute; opacity: 0; width: 0; height: 0`.

```css
.nicepay-method-option:has(input:checked),
.nicepay-method-option.is-selected {
    border-color: var(--nicepay-primary);
    background: var(--nicepay-primary-light);
    box-shadow: 0 0 0 1px var(--nicepay-primary);
}
```

**Impact.** On Firefox before 121 (ESR 115 was supported into late 2024 and is still common in managed corporate fleets), Safari before 15.4 and Chrome before 105, the payment-method selector renders with **zero** indication of which method is chosen: no border highlight, no radio dot, no icon or label colour change, and no native radio to fall back on. The plugin targets WP 5.0+/PHP 7.4+, i.e. deliberately old environments.

**Fix.** Split each of the four rules into two — `.nicepay-method-option.is-selected { … }` and `.nicepay-method-option:has(input:checked) { … }` separately. Purely mechanical, no behaviour change in modern browsers, and it restores the fallback the JS is already maintaining (`NicePayHandler.updateMethodSelection` and the inline handler both keep `.is-selected` accurate).

### UX-110 — `LOW` — Three orphaned CSS blocks (~26 lines) style markup that no PHP or JS file emits

[assets/css/nicepay-admin.css:432](../../assets/css/nicepay-admin.css#L432)–450 · [assets/css/nicepay-admin.css:983](../../assets/css/nicepay-admin.css#L983)–992

**Problem.** Cross-referencing all 131 `.nicepay-*` selectors in both stylesheets against every `nicepay-*` token emitted anywhere in PHP/JS, exactly three selector families have no markup: `.nicepay-shortcode-help`, `.nicepay-code-block` / `.nicepay-code-block code`, and `.nicepay-sc-preview-area` / `.nicepay-sc-preview-area .nicepay-pay-button`. (The live-preview markup uses `.nicepay-sc-preview-live`.) All other apparent orphans are false positives from string interpolation.

**Impact.** Dead weight plus a signal that a Shortcode Help panel — a full dark code-block component — was designed and dropped without cleanup.

**Fix.** Delete lines 432–450 and 983–992, **or** wire up the missing panel: the admin Shortcodes tab currently offers no in-product explanation of the shortcode attribute syntax, and the styling for exactly that panel is sitting unused.

### UX-111 — `LOW` — The token system is only half-adopted: three pill radii bypass `--nicepay-radius-full`, seven transitions bypass `--nicepay-transition`

[assets/css/nicepay-admin.css:517](../../assets/css/nicepay-admin.css#L517) · [assets/css/nicepay-admin.css:757](../../assets/css/nicepay-admin.css#L757) · [assets/css/nicepay-admin.css:907](../../assets/css/nicepay-admin.css#L907) · [assets/css/nicepay.css:34](../../assets/css/nicepay.css#L34)

**Problem.** `border-radius: 99px` appears three times alongside `var(--nicepay-radius-full)` for visually equivalent pills. `transition:` appears 13 times in `nicepay-admin.css`: 6 use the token and 7 hardcode a literal — four at `0.15s`, three at `0.2s`. Raw `border-radius: <px>` count is 22 (5×4 px, 4×6 px, 10×8 px, 3×99 px) — and 8 px, the most-used value, matches **none** of the three declared radius tokens (6/10/14 px).

**Impact.** The two brand surfaces meant to share one token vocabulary have drifted into a second undeclared radius scale and a second transition duration. A rebrand requires hunting literals instead of editing four token lines.

**Fix.** Add `--nicepay-radius-xs: 4px` and `--nicepay-radius-input: 8px` (or fold 8 px into `--nicepay-radius-md`), then mechanically replace the 22 raw values; replace `99px` with `var(--nicepay-radius-full)`; settle on one micro-transition duration or add `--nicepay-transition-fast: 0.15s ease`.

### UX-113 — `LOW` — Plugin CSS adds no `overflow-wrap` to fixed-layout transaction table cells · *needs confirmation*

[admin/class-nicepay-transactions.php:80](../../admin/class-nicepay-transactions.php#L80) · [assets/css/nicepay-admin.css:111](../../assets/css/nicepay-admin.css#L111)–116 · [assets/css/nicepay-admin.css:97](../../assets/css/nicepay-admin.css#L97)

**Problem.** The table carries WP's `fixed` class and `.nicepay-admin .wp-list-table td` defines only padding, vertical-align, border-bottom and font-size — no `word-break`/`overflow-wrap` anywhere in the plugin's stylesheets. *Two mitigations mean real breakage is unlikely: WordPress core's list-tables stylesheet applies a global `word-wrap: break-word` under `.widefat` (unverifiable here — no WordPress install present), and the plugin itself sets `overflow: hidden` on the table box for its 8 px radius, so overflowing content is clipped rather than spilling.*

**Fix.** Cheap insurance: add `overflow-wrap: anywhere;` to `.nicepay-admin .wp-list-table td`. Do not treat it as a fix for an observed bug — confirm against a real install first.

### UX-122 — `ENHANCEMENT` — No font-size token scale; the two stylesheets use near-arbitrary and near-duplicate sizes · *needs confirmation*

[assets/css/nicepay.css:58](../../assets/css/nicepay.css#L58) · [assets/css/nicepay.css:289](../../assets/css/nicepay.css#L289) · [assets/css/nicepay.css:365](../../assets/css/nicepay.css#L365)

**Problem.** There is no `--nicepay-font-size-*` token anywhere, unlike colour/radius/shadow/transition which all have tokens. The clearest artefact: `nicepay.css` defines both `0.9375rem` (15 px) and `0.95rem` (15.2 px) — two visually indistinguishable sizes 0.2 px apart. It is also not purely rem (`font-size: 18px` on the modal close button), so the "clean rem/px split between the two sheets" story does not hold.

**Impact.** Craft-level, not user-facing. rem sizing tracks a user's browser font-size preference and px does not, so the admin surface is less adaptable; and there is no single source of truth.

**Fix.** Introduce `--nicepay-font-size-xs/sm/md/lg/xl` in rem on `:root` in `nicepay.css` (which the admin sheet already depends on via the `nicepay-shared-css` enqueue dependency), collapse `0.95rem` into `0.9375rem`, and migrate both files.

### UX-123 — `ENHANCEMENT` — No RTL support: all directional spacing uses physical properties

[assets/css/nicepay.css:131](../../assets/css/nicepay.css#L131) · [assets/css/nicepay.css:359](../../assets/css/nicepay.css#L359) · [assets/css/nicepay-admin.css:342](../../assets/css/nicepay-admin.css#L342) · [assets/css/nicepay-admin.css:595](../../assets/css/nicepay-admin.css#L595)

**Problem.** `grep -E 'margin-inline|padding-inline|inset-inline|\[dir='` across both stylesheets returns zero matches; all directional declarations are physical (`margin-right`, `right`, `margin-left: auto`, `border-left`).

**Impact.** No active bug — none of en_US / ko_KR / tr_TR / zh_CN is RTL. Purely forward-looking: an Arabic or Hebrew localisation would need a parallel override pass for every icon gap, badge position and close-button placement.

**Fix.** When RTL is on the roadmap, do a mechanical migration: `margin-left/right` → `margin-inline-start/end`, `left/right` → `inset-inline-start/end`, `border-left` → `border-inline-start`. That alone makes the layout RTL-correct with no `[dir="rtl"]` blocks.

### UX-124 — `ENHANCEMENT` — No print stylesheet for the transactions screen

*(absence — `grep -n '@media print'` across both stylesheets and the result page returns no matches)*

**Impact.** Printing or print-to-PDF'ing the transactions log for reconciliation carries the filter bar, the Cancel buttons, the copy icons, and any open toast or modal overlay.

**Fix.**
```css
@media print {
    .nicepay-filters, .tablenav, .nicepay-copy-btn, .nicepay-cancel-btn, .nicepay-toast-container { display: none !important; }
    .nicepay-admin .wp-list-table { border: none; }
    .nicepay-tid-cell code { max-width: none; overflow: visible; }   /* so printed TIDs are not ellipsised */
}
```

---

# §6 — Prioritised UX improvement backlog

Ordered by value delivered per unit of work, not by severity alone. Each item names the findings it closes.

## Wave 0 — Stop the bleeding (days, not weeks)

| # | Work | Closes | Why first |
|---|---|---|---|
| 0.1 | Resolve the standalone payment amount server-side from a shortcode id or a render-time transient; compare `$_POST['Amt']` against `$transaction->amount` in both return handlers | **UX-002** | Direct, trivially exploitable money loss |
| 0.2 | Persist the VBANK fields to order meta; add a customer order note; render a deposit card on `woocommerce_thankyou_nicepay`, `woocommerce_email_before_order_table` and `woocommerce_order_details_after_order_table` | **UX-001** | A payment method that is completely unusable end to end |
| 0.3 | Compute the VBANK deadline in `Asia/Seoul`; capture `VbankExpTime`; render both with `date_i18n()`; clamp the expiry-days setting | **UX-016**, UX-045 (partial) | Buyers are being given a deadline that is nine hours wrong and unreadable |
| 0.4 | Add an idempotency guard at the top of both return handlers plus a Moid transient lock | **UX-013** | A re-POST currently tells a charged buyer she failed, and can net-cancel a live approval |
| 0.5 | Add `nicepay_return_url()` with the plain-permalink fallback | **UX-012** | Total silent failure of the shortcode flow on affected sites |
| 0.6 | Change the gateway's `enabled` default to `'no'`; add a persistent `admin_notices` test-mode warning; swap the badge colours | **UX-004**, UX-058 (partial) | Stops shipping a live-looking checkout on a shared demo MID |

## Wave 1 — Unblock the merchant (1–2 weeks)

| # | Work | Closes | Why |
|---|---|---|---|
| 1.1 | Add `<hr class="wp-header-end">` + `settings_errors()` to the settings pages | **UX-019**, UX-093 | Two lines; the error channel four other fixes depend on |
| 1.2 | Add the DB migration routine and drop `IF NOT EXISTS` | **UX-052** | Prerequisite for `currency`, `mode`, `refunded_amount`, `vbank_exp_time` |
| 1.3 | Validate every setting: mode/language/currency/charset whitelists, MID pattern, expiry clamp, ≥1 payment method | **UX-003**, **UX-007**, UX-045, UX-017 | Removes the whole class of silently-broken configurations |
| 1.4 | Build the transaction detail panel: ResultCode + explanation, ResultMsg, reference block, raw payload behind `<details>`, copy-diagnostics, debug-log toggle | **UX-005** | The single highest-value addition in this document |
| 1.5 | Replace in-place cancel with a link to the WooCommerce refund screen for WC-linked rows; keep it only for standalone rows | **UX-006**, UX-050 (partial) | Ends the divergent-ledger and silent-restock defect |
| 1.6 | Rewrite the refund dialog per the §1.7 table; keep it open on failure; add progress feedback; cap `CancelMsg` at 100 bytes | **UX-022**, **UX-032**, **UX-046** | Destructive action, currently confirmed blind |
| 1.7 | Regenerate the `.pot`, re-merge all four locales, add the CI drift gate | **UX-009**, **UX-015** | A third of the admin and the whole buyer form are untranslatable |
| 1.8 | Add the setup checklist panel and the WooCommerce → Payments back-link; declare HPOS compatibility; fix the order edit link | **UX-040**, **UX-021**, **UX-020** | First-run orientation and the broken ledger↔order bridge |

## Wave 2 — Make failure survivable for the buyer (1–2 weeks)

| # | Work | Closes |
|---|---|---|
| 2.1 | `nicepay_get_buyer_message( $code, $method )`; distinguish cancellation from decline; never print internal diagnostics; demote the vendor string to "Technical details" | **UX-014**, UX-083 |
| 2.2 | Add a **Try again** action on every failure surface; redirect WC failures to `get_checkout_payment_url()`; keep user cancellations `pending` | **UX-055**, UX-083 |
| 2.3 | Watchdog timers on both launch paths + correct overlay copy + a dismiss control | **UX-010**, **UX-061**, UX-118 |
| 2.4 | Stop `nicepayClose` navigating the WC buyer away | **UX-053** |
| 2.5 | Interstitial (or two-hop return) with `nocache_headers()` during approval | **UX-054** |
| 2.6 | Send a receipt email with `wp_mail()` (VBANK deposit instructions included); show goods name + Moid on the success page; give the result a durable GET address inside the site's chrome | **UX-075**, **UX-077**, UX-082 |
| 2.7 | Server-side validation of the buyer fields, then make them earn their place | **UX-078**, UX-056 |

## Wave 3 — Accessibility conformance (1 week, mostly mechanical)

| # | Work | Closes |
|---|---|---|
| 3.1 | One token change: `#9ca3af` → `#6b7280` for text; `--nicepay-control-border: #6b7280`; notice text `#b91c1c` | **UX-079**, **UX-065**, **UX-066** |
| 3.2 | `:focus-within` / `:focus-visible` outlines on every custom control in both stylesheets; stop `outline: none` | **UX-036**, **UX-085** |
| 3.3 | Focus trap + initial focus + focus restore + accessible name on both modals; guard the stray input | **UX-034**, **UX-068**, UX-080, **UX-033**, UX-086 |
| 3.4 | Text errors with `aria-invalid` + `aria-describedby` everywhere, copying the pattern the frontend already gets right | **UX-047**, UX-087 |
| 3.5 | Toast rework: persistent live region, `role="alert"` for errors, no auto-dismiss on errors, mobile `top` fix | **UX-035** |
| 3.6 | Table `scope`/`caption`/labelled filters; scroll wrapper instead of `display: block` | **UX-037** |
| 3.7 | `prefers-reduced-motion` block in all three stylesheets; `autocomplete`/`inputmode`/`maxlength` on buyer fields; drop dead preview buttons from the tab order | UX-097, UX-114, UX-056, UX-119 |

## Wave 4 — Operational maturity

| # | Work | Closes |
|---|---|---|
| 4.1 | Health check on the API tab (credential shape, egress, handshake, return endpoint, store readiness) | **UX-018** |
| 4.2 | Convert Transactions to `WP_List_Table`; wire the existing `orderby` whitelist; add status counts, per-page, bulk actions and CSV export | **UX-029** |
| 4.3 | Enrich the Method and Buyer columns; add the WooCommerce order meta box | **UX-023**, **UX-044** |
| 4.4 | Currency + mode + `refunded_amount` columns, correct date rendering, pending-row dedupe and abandoned-attempt filtering | **UX-027**, **UX-051**, **UX-050**, **UX-028**, **UX-049** |
| 4.5 | `manage_woocommerce` capability split | **UX-026** |
| 4.6 | VBANK operations: do not enable by default, disclosure panel with the notification URL and inbound IPs, "Mark deposit received" action, deposit-notification endpoint | **UX-008**, **UX-011** |

## Wave 5 — Polish and craft

| # | Work | Closes |
|---|---|---|
| 5.1 | Shortcode builder: linked-vs-self-contained output, Save obeys the validity rule, shared copy helper with fallback, quote escaping, preset badges + undo, stale-`edit` guard, additive CSS class, currency-aware amount input, colour-contrast guardrail | UX-025, UX-030, UX-031, UX-088, UX-089, UX-043, UX-091, UX-095, UX-094, UX-081 |
| 5.2 | Buyer-facing clarity: method descriptions, single-method disclosure, reassurance copy + `LogoImage`/`SkinType`, `AUTO` language, currency guard, `payment_fields()` + `get_icon()` | UX-070, UX-071, UX-063, UX-072, UX-048, UX-060, UX-126 |
| 5.3 | CSS system: split the `:has()` rules, dark-mode tokens, admin colour-scheme adoption, token the result page, delete orphaned blocks, radius/transition/font-size scales | UX-084, UX-069, UX-041, UX-082, UX-110, UX-111, UX-122 |
| 5.4 | Front-end robustness: scoped selectors, drop `document.payForm`, idempotent modal open, `textContent` instead of `innerHTML`, `sanitize_hex_color` on `button_color`, state-class spinner, early enqueue + sized SVGs, narrow the vendor-script load | UX-106, UX-107, UX-108, UX-109, UX-115, UX-116, UX-076, UX-099, UX-117, UX-098 |
| 5.5 | Remaining copy and formatting: empty-state branching, Korean currency formatting, KRW rounding, shortcode currency validation, admin JS strings, logged-in prefill, ambiguous "Cancel" link, modal overlap, print stylesheet, RTL migration, copy-button icon swap, TID `title` | UX-090, UX-103, UX-096, UX-104, UX-092, UX-105, UX-102, UX-101, UX-124, UX-123, UX-125, UX-112 |

## The one-sentence version

If only three things get done: **make the merchant able to see why a payment failed** (UX-005), **make the buyer able to see her virtual account number** (UX-001), and **stop trusting the browser for the amount** (UX-002).
