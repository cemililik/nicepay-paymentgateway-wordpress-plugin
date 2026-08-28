# WordPress & WooCommerce Platform Conformance

> [!WARNING]
> **Frozen historical snapshot.** This review describes baseline commit `5855db1` (2026-08-19), before the 2.0 remediation work. Its line references and present-tense security claims do not describe the current code. Use `docs/analysis/pr-review2/` and current tests for release decisions.

This document covers how NicePay Payment Gateway v2.0.0 behaves as a *WordPress plugin* and as a *WooCommerce payment gateway* — the platform contracts it must honour (Blocks, HPOS, multisite, activation/uninstall, capabilities, i18n, settings API, rewrite rules, asset enqueueing, directory policy) rather than the correctness of the NICEPAY protocol itself. The headline result is a plugin whose *internals* are unusually disciplined — HPOS-correct order meta, parameterised SQL, uniformly sanitised superglobals, nonce+capability pairs on every AJAX endpoint — sitting inside a *platform shell* that is missing several of the declarations and registrations modern WooCommerce requires. Two findings are payment-critical: the gateway does not render at all in the Cart/Checkout blocks (the default checkout since WooCommerce 8.3), and the standalone shortcode signs a client-supplied amount, letting any visitor pay whatever they like. 42 findings are reported here: 2 critical, 5 high, 26 medium, 9 low. Two are marked **PLAUSIBLE — needs confirmation** because their core assertion is a design judgement rather than a verified defect, and one more carries a residual-uncertainty note.

---

## What this codebase does well

These are not throwaway compliments — each was verified against the source, and several of them are the reason the fixes below are cheap rather than structural.

- **Order data access is genuinely HPOS-correct throughout.** Every order meta operation uses the CRUD API — `$order->update_meta_data()` / `$order->get_meta()` / `$order->save()` ([includes/class-nicepay-gateway.php:153](../../includes/class-nicepay-gateway.php#L153), [:363](../../includes/class-nicepay-gateway.php#L363), [:425](../../includes/class-nicepay-gateway.php#L425)) — and a repo-wide grep for `get_post_meta`, `update_post_meta`, `WP_Query` and `shop_order` returns nothing. The plugin is one three-line declaration away from real HPOS compatibility, which is a much better position than most gateways in this size class.
- **Superglobal handling is disciplined and consistent.** Every `$_POST`/`$_GET` read in the return handlers, AJAX endpoints and admin pages goes through `isset()` → `wp_unslash()` → `sanitize_text_field()`/`sanitize_email()`/`esc_url_raw()` ([class-nicepay-gateway.php:220-231](../../includes/class-nicepay-gateway.php#L220), [class-nicepay-return-handler.php:32-42](../../includes/class-nicepay-return-handler.php#L32), [nicepay-payment-gateway.php:322-326](../../nicepay-payment-gateway.php#L322)). This is done uniformly rather than sporadically.
- **Every gettext call uses a literal text domain.** All `__`/`_e`/`_n`/`_x`/`esc_html__`/`esc_attr__`/`esc_html_e`/`esc_attr_e` occurrences were grepped for a non-literal domain: zero. Nine translator comments are present in the source. The catalogue has msgid drift (PLATFORM-16), but the calling code itself is correctly written for i18n.
- **SQL is parameterised properly.** `nicepay_get_transactions()` ([includes/nicepay-functions.php:161-214](../../includes/nicepay-functions.php#L161)) builds a placeholder array and passes it to `$wpdb->prepare()`, uses `$wpdb->esc_like()` for the search term, and gates `orderby` through an explicit allowlist rather than interpolating user input into the ORDER BY clause.
- **Every AJAX endpoint pairs a capability check with a nonce check in the same conditional** ([nicepay-payment-gateway.php:365](../../nicepay-payment-gateway.php#L365), [:444](../../nicepay-payment-gateway.php#L444), [class-nicepay-transactions.php:186](../../admin/class-nicepay-transactions.php#L186)), and the cancel nonce is scoped per-transaction (`'nicepay_cancel_' . $item->id`) rather than a single global action — a level of care above the norm.
- **Output escaping in the admin templates is thorough and correct:** `esc_attr()` on every attribute, `esc_url()` on every href, `esc_html()`/`esc_html_e()` on every text node, `esc_js()` inside inline script strings, and `wp_kses_post()` on the `paginate_links()` output. The transactions and settings screens are clean in this respect.
- **The admin UI has real product thinking behind it:** a live-preview shortcode builder with validation feedback, a mode badge in the settings header, purpose-built empty states with illustrations, toast notifications, a proper confirm modal with a loading-guarded state machine, and copy-to-clipboard with a graceful `execCommand` fallback. This is well above the standard for a self-published gateway.
- **Both stylesheets are fully namespaced.** No bare `body`/`html`/`*`/element selectors in either file, and only 4 `!important` declarations across 1,800 lines. Admin assets are correctly scoped to the plugin's own screens via the `$hook` check, so nothing leaks into the wider WordPress admin.
- **The API layer takes protocol security seriously:** an explicit SSRF allowlist validating scheme and host against the four documented NICEPAY endpoints before any `wp_remote_post` ([includes/class-nicepay-api.php:128-152](../../includes/class-nicepay-api.php#L128)), `hash_equals()` for every signature comparison, mandatory signature verification on auth, approval and cancel responses, and a log-redaction helper masking AuthToken/SignData/Signature/CardNo/VbankNum ([:157-174](../../includes/class-nicepay-api.php#L157)).
- **Net-cancel (망취소) is wired into every approval failure path** — transport error, unparseable body, missing signature and signature mismatch all trigger `request_net_cancel()` ([includes/class-nicepay-api.php:225-267](../../includes/class-nicepay-api.php#L225)). Getting the rollback path right on all four branches, rather than just the obvious timeout case, reflects a careful reading of the spec.
- **CI is meaningfully set up:** a five-version PHP matrix (7.4 through 8.3), pinned action SHAs rather than floating tags, Composer caching, coverage collection and artifact upload, plus a separate lint job. The release workflow extracts per-version changelog entries automatically. The foundations for adding WPCS and a `.pot`-drift gate are already in place.

---

## 1. Compatibility matrix

| Platform contract | Status | Consequence in one line |
|---|---|---|
| **HPOS (custom order tables)** | ⚠️ Works, never declared | Listed under "Incompatible plugins"; one order-edit link is hardcoded to `post.php` and 404s under HPOS (PLATFORM-08, PLATFORM-11). |
| **Cart / Checkout Blocks** | ❌ Not supported | Gateway does not render at all in the block checkout — the default for stores since WC 8.3 — with no error or admin warning (PLATFORM-01). |
| **Multisite** | ❌ Broken on network activate | Only the network admin's current site gets the transactions table; all other sites fail every payment with "Payment initialization failed" (PLATFORM-06). |
| **PHP 8.x** | ⚠️ Mostly fine, one known warning | CI covers 7.4–8.3; an empty `nicepay_enabled_methods` triggers "Undefined array key 0" and submits an empty `PayMethod` (PLATFORM-21). `ext-mbstring` is used but undeclared (PLATFORM-37). |
| **WP 6.x** | ⚠️ Works, forward-risk | Runs on current WP; explicit `load_plugin_textdomain()` on `plugins_loaded` is redundant post-6.7 and is one careless line from a `_doing_it_wrong` notice (PLATFORM-42). |
| **WC 9.x / 10.x** | ⚠️ Stale declaration | `WC tested up to: 9.0` makes WooCommerce print "has not been tested with your version of WooCommerce" on the plugins screen, which reads as abandonment (PLATFORM-31). |
| **Uninstall** | ❌ Absent | No `uninstall.php`, no `register_uninstall_hook()`; the transactions table and the plaintext **live merchant key** survive plugin deletion indefinitely (PLATFORM-14). |
| **i18n plumbing** | ⚠️ Code correct, catalogue drifted | Calling code is textbook-correct; three msgids do not match the source and there are zero `msgid_plural` entries in any of the five catalogue files, so those strings render in English in all four locales (PLATFORM-16). |
| *(bonus)* **Permalinks** | ⚠️ Conditional break | The shortcode return URL hardcodes the pretty-permalink path; on a Plain-permalink site the buyer authenticates and lands on a 404 with a dangling authorisation (PLATFORM-05). |
| *(bonus)* **Extensibility** | ❌ None | Zero `apply_filters`/`do_action` in the entire plugin, and the developer guide documents a filter that was never implemented (PLATFORM-15, PLATFORM-28). |

---

## 2. Findings

### 🔴 CRITICAL

---

#### PLATFORM-01 · No WooCommerce Blocks payment method registration — gateway is absent from the Checkout block

**Severity:** 🔴 Critical · **Category:** spec-conformance · **Effort:** large · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-gateway.php:12-37](../../includes/class-nicepay-gateway.php#L12), [nicepay-payment-gateway.php:173-184](../../nicepay-payment-gateway.php#L173)

**Problem.** The plugin registers only a classic `WC_Payment_Gateway` subclass. Repo-wide greps for `AbstractPaymentMethodType`, `PaymentMethodRegistry`, `woocommerce_blocks_payment_method_type_registration`, `woocommerce_blocks_loaded`, `registerPaymentMethod` and `wcBlocksRegistry` all return zero hits. There is no blocks JS bundle under `assets/js/` (only `nicepay.js` and `nicepay-admin.js`). WooCommerce Blocks renders only payment methods registered against its own registry with a matching client-side `registerPaymentMethod()` call; the classic gateway list is not consulted.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:17-36 — classic registration only
$this->id = 'nicepay';
// hooks: woocommerce_update_options_payment_gateways_nicepay,
//        woocommerce_receipt_nicepay, woocommerce_api_nicepay_return

// nicepay-payment-gateway.php:180-183 — the ONLY registration
add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
    $gateways[] = 'WC_Gateway_NicePay';
    return $gateways;
} );
```

**Impact.** On any store using the Cart/Checkout blocks — the default checkout for new stores since WooCommerce 8.3 — NicePay does not render in the payment list. There is no error and no admin warning: the merchant configures credentials, enables the gateway, and it silently never appears. Total feature failure of the WooCommerce path for a growing majority of stores.

**Verifier note.** Early WooCommerce Blocks versions shipped a "legacy" shim that rendered classic gateways inside the block checkout; that shim was removed years ago, which is precisely why WooCommerce maintains a `cart_checkout_blocks` incompatibility list. Severity kept at critical: on an affected store this is total feature failure with zero diagnostics.

**Recommendation.** Ship `includes/blocks/class-nicepay-blocks-support.php` extending `Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType` with `initialize()`, `is_active()` (delegating to the gateway's `is_available()`), `get_payment_method_script_handles()` and `get_payment_method_data()` (title, description, supports, icon). Register it on `woocommerce_blocks_payment_method_type_registration`, guarded by `class_exists()`. Add the client script calling `wc.wcBlocksRegistry.registerPaymentMethod({ name: 'nicepay', ... })`. Because `process_payment()` returns a redirect to the receipt page, the block component only needs a label plus description — the blocks checkout honours the redirect.

---

#### PLATFORM-02 · Payment amount is taken from `$_POST` and signed by the server — shortcode buyers can pay any amount they choose

**Severity:** 🔴 Critical · **Category:** correctness · **Effort:** medium · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:313-359](../../nicepay-payment-gateway.php#L313), [templates/standalone-payment-form.php:130](../../templates/standalone-payment-form.php#L130), [:305](../../templates/standalone-payment-form.php#L305)

**Problem.** `ajax_init_payment()` reads `$_POST['amount']`, validates only `! empty()` and `(float) $amount > 0`, then signs it with the merchant key and returns the SignData to the browser. Nothing binds that amount to the server-side shortcode configuration, even though one exists (`nicepay_saved_shortcodes`, addressable by `id`, and the shortcode instance already knows its id). The nonce is no protection — it is a public nonce rendered into the page for every anonymous visitor. Both the hidden `Amt` field and the AJAX `amount` parameter are fully under the client's control, and the SignData the server returns will match whatever the attacker sent.

**Evidence.**

```php
// nicepay-payment-gateway.php:322
$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
// :328
if ( empty( $amount ) || (float) $amount <= 0 ) {
// :336
$sign_data = $api->create_auth_sign_data( $edi_date, $amount );

// includes/class-nicepay-api.php:74-77
// create_auth_sign_data = hash( 'sha256', $edi_date . $this->mid . $amt . $this->merchant_key )
```

```php
// templates/standalone-payment-form.php:305
xhr.send('action=nicepay_init_payment&nonce=…&amount=<?php echo esc_js( $amount ); ?>&goods_name=' + …)
```

**Impact.** Any visitor can obtain a valid, correctly-signed authorisation for an arbitrary amount and pay 100 KRW for a 50,000 KRW item. NICEPAY genuinely approves it, the response signature verifies, and the transaction row records the tampered amount as legitimate. No server-side record of the intended price exists, so nothing downstream can detect the discrepancy. Direct revenue loss.

**User scenario.** A visitor on a page with `[nicepay_payment amount="50000"]` opens devtools, edits the hidden `Amt` input and the XHR `amount` parameter to `100`, clicks Pay, and completes a genuinely approved 100 KRW payment. The merchant's transaction log shows a successful 100 KRW payment with no anomaly flag.

**Verifier note.** The WooCommerce path is **not** affected: [includes/class-nicepay-gateway.php:124](../../includes/class-nicepay-gateway.php#L124) and [:146](../../includes/class-nicepay-gateway.php#L146) derive the amount from `$order->get_total()` server-side and sign it there, so tampering with the receipt-page `Amt` field invalidates SignData and NICEPAY rejects the auth.

**Recommendation.** Make the AJAX endpoint authoritative. Send only the shortcode `id` (plus buyer fields); on the server load the config with `nicepay_get_saved_shortcode( $id )` and use **its** `amount`, `currency`, `goods_name` and `pay_method` for both the transaction row and the SignData. For inline shortcodes with no saved id, store the price context in a short-lived transient keyed by `$form_id` at render time ([templates/standalone-payment-form.php:41](../../templates/standalone-payment-form.php#L41) already generates `$form_id`) and have the handler read the transient:

```php
// at render time
set_transient( 'nicepay_form_' . $form_id, array(
    'amount'     => $amount,
    'goods_name' => $goods_name,
    'currency'   => $currency,
), 2 * HOUR_IN_SECONDS );
```

Never derive SignData from a client-supplied `Amt`.

---

### 🟠 HIGH

---

#### PLATFORM-03 · Gateway ships ENABLED by default and pre-seeded with NICEPAY's public sandbox MID

**Severity:** 🟠 High · **Category:** correctness · **Effort:** trivial · **Verdict:** CONFIRMED *(added during verification)*
**Location:** [includes/class-nicepay-gateway.php:29](../../includes/class-nicepay-gateway.php#L29), [:41-46](../../includes/class-nicepay-gateway.php#L41), [nicepay-payment-gateway.php:33-34](../../nicepay-payment-gateway.php#L33), [:151-153](../../nicepay-payment-gateway.php#L151), [includes/class-nicepay-api.php:22-33](../../includes/class-nicepay-api.php#L22)

**Problem.** Three defaults combine into a live-store hazard.

1. `init_form_fields()` sets `'enabled' => … 'default' => 'yes'` and the constructor reads `$this->enabled = $this->get_option( 'enabled', 'yes' )`. `WC_Settings_API::get_option()` falls back to the form-field default when the setting has never been saved, so the gateway is ON from the instant WooCommerce loads it, with no merchant action.
2. `set_default_options()` seeds `nicepay_test_mid` / `nicepay_test_merchant_key` with the vendor's shared public sandbox credentials, and `NicePay_API::__construct()` additionally falls back to the `NICEPAY_TEST_MID` / `NICEPAY_TEST_MERCHANT_KEY` constants — so `is_available()` always returns true in test mode.
3. `nicepay_mode` defaults to `test`, and nothing appends a test-mode marker to the checkout title or description.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:41-46
'enabled' => array(
    'title'   => __( 'Enable/Disable', 'nicepay-payment-gateway' ),
    'type'    => 'checkbox',
    'label'   => __( 'Enable NicePay Payment', 'nicepay-payment-gateway' ),
    'default' => 'yes',
),
// :29
$this->enabled = $this->get_option( 'enabled', 'yes' );
```

```php
// nicepay-payment-gateway.php:33-34
define( 'NICEPAY_TEST_MID', 'nicepay00m' );
define( 'NICEPAY_TEST_MERCHANT_KEY', 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==' );
// :151-153
add_option( 'nicepay_mode', 'test' );
add_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
add_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );
```

`grep -n "is_test_mode" includes/class-nicepay-gateway.php` → no hits: the gateway never consults the mode. [`:358-380`](../../includes/class-nicepay-gateway.php#L358) runs `is_success_code()` then `payment_complete( $tid )` with no test-mode branch.

**Impact.** Activating the plugin immediately adds a payment option labelled "NicePay Payment" — with the description "Pay securely via NicePay (Credit Card, Bank Transfer, Virtual Account, Mobile)" — to the checkout of any WooCommerce store, wired to NICEPAY's shared sandbox MID `nicepay00m`. A real customer can select it, complete a sandbox authorisation, and `handle_return()` will see ResultCode `3001` (a sandbox success), call `$order->payment_complete( $tid )`, mark the order processing and trigger fulfilment — with no money collected anywhere. Nothing at checkout, in the order, or in the confirmation email indicates the payment was a test. Goods ship against zero funds.

**User scenario.** A merchant installs and activates NicePay to evaluate it, without opening the WooCommerce Payments screen. A customer at checkout that afternoon sees "NicePay Payment", selects it, completes the sandbox flow, and receives an order-confirmation email. The order shows as Processing. The merchant ships. No funds ever arrive.

**Recommendation.**

```php
// includes/class-nicepay-gateway.php:44 and :29
'default' => 'no',
$this->enabled = $this->get_option( 'enabled', 'no' );

// in __construct(), after init_settings()
if ( $this->api->is_test_mode() ) {
    $this->description .= ' ' . __( 'TEST MODE ENABLED. Payments are simulated and no money will be taken.', 'nicepay-payment-gateway' );
}
```

Add a persistent `admin_notices` warning while the plugin is in test mode with the WooCommerce gateway enabled. Shipping `'default' => 'no'` is the WooCommerce convention and also makes the `needs_setup()` fix (PLATFORM-18) meaningful.

---

#### PLATFORM-04 · Admin "Cancel" refunds at the PG but sets the order to `cancelled` and creates no refund record

**Severity:** 🟠 High · **Category:** correctness · **Effort:** medium · **Verdict:** CONFIRMED *(added during verification)*
**Location:** [admin/class-nicepay-transactions.php:180-237](../../admin/class-nicepay-transactions.php#L180), [:225-230](../../admin/class-nicepay-transactions.php#L225)

**Problem.** `ajax_cancel_transaction()` sends a full cancel to NICEPAY and on success calls `$order->update_status( 'cancelled', … )`. It never calls `wc_create_refund()`, so WooCommerce records no `WC_Order_Refund` object: `get_total_refunded()` stays at 0, the Refunds row on the order screen stays empty, and WooCommerce Analytics still counts the order's full value as net revenue. Meanwhile `WC_Gateway_NicePay::process_refund()` remains fully reachable — WooCommerce renders refund controls on non-editable orders including `cancelled` — and it reads `_nicepay_tid` / `_nicepay_moid`, which the admin cancel never clears or annotates.

**Evidence.**

```php
// admin/class-nicepay-transactions.php:208 — $partial omitted, so PartialCancelCode = '0'
$result = $api->request_cancel( $tid, $cancel_amount, $reason, $transaction->moid );

// :217-230
if ( $api->is_cancel_success( $result_code ) ) {
    nicepay_update_transaction( $transaction->id, array( 'status' => 'cancelled', … ) );
    if ( $transaction->wc_order_id ) {
        $order = wc_get_order( $transaction->wc_order_id );
        if ( $order ) {
            $order->update_status( 'cancelled', __( 'Cancelled via NicePay admin.', … ) . ' ' . $reason );
        }
    }
}
```

`grep -rn "wc_create_refund" .` → no hits anywhere in the plugin. [includes/class-nicepay-gateway.php:425-445](../../includes/class-nicepay-gateway.php#L425) — `process_refund()` reads only `_nicepay_tid` and `_nicepay_moid` and has no already-cancelled guard.

**Impact.** Two concrete harms. (1) *Accounting divergence*: money is returned to the buyer at the PG while WooCommerce's own refund totals and reports show zero refunded, so revenue reconciliation and tax reporting are wrong for every transaction cancelled from this screen. (2) *Double-reversal exposure*: after the admin cancel, a merchant (or a second staff member) looking at the order sees an unrefunded total and can click Refund, firing a second `request_cancel()` for the same TID. `cancelled` is also semantically wrong for a captured-then-reversed payment — `refunded` is WooCommerce's status for that — and the `woocommerce_order_status_cancelled` transition restores stock, which may not be intended post-payment.

**User scenario.** A merchant cancels a 50,000 KRW transaction from NicePay → Transactions. The buyer is refunded. The WooCommerce order shows status Cancelled, Refunded: 0, and analytics still counts 50,000 KRW of net sales. A week later a colleague opens the order, sees nothing refunded, and clicks Refund — sending a second cancel for the same TID.

**Recommendation.**

```php
// on successful PG cancel, replace the bare update_status() with:
$refund = wc_create_refund( array(
    'amount'         => $transaction->amount,
    'reason'         => $reason,
    'order_id'       => $transaction->wc_order_id,
    'refund_payment' => false, // the PG reversal has already been performed here
) );
```

Let WooCommerce set the resulting status, and add an order note carrying the ResultCode and CancelNum. Guard the reverse direction too: have `process_refund()` return a `WP_Error` when the linked transaction row is already `cancelled` or `refunded`.

---

#### PLATFORM-05 · Standalone return URL hard-codes the pretty-permalink path — 404 after the buyer has already authenticated

**Severity:** 🟠 High · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:202-212](../../nicepay-payment-gateway.php#L202), [:299](../../nicepay-payment-gateway.php#L299), [templates/standalone-payment-form.php:127](../../templates/standalone-payment-form.php#L127), [:136](../../templates/standalone-payment-form.php#L136)

**Problem.** The shortcode flow posts the NICEPAY auth result to `home_url( '/nicepay-return/' )`, which resolves only through the rewrite rule `^nicepay-return/?$`. When `permalink_structure` is empty, `WP_Rewrite::using_permalinks()` is false, rewrite rules are never consulted in `parse_request()`, and the URL 404s. The `nicepay_return` query var **is** registered, so `home_url( '/?nicepay_return=1' )` would work — but that fallback URL is never generated anywhere in the codebase.

**Evidence.**

```php
// nicepay-payment-gateway.php:203-207
add_rewrite_rule( '^nicepay-return/?$', 'index.php?nicepay_return=1', 'top' );
// :299
'returnUrl'  => home_url( '/nicepay-return/' ),
```

```php
// templates/standalone-payment-form.php:127 and :136
action="<?php echo esc_url( home_url( '/nicepay-return/' ) ); ?>"
<input type="hidden" name="ReturnURL" value="<?php echo esc_url( home_url( '/nicepay-return/' ) ); ?>">
```

Contrast [includes/class-nicepay-gateway.php:125](../../includes/class-nicepay-gateway.php#L125) `$return_url = WC()->api_request_url( 'nicepay_return' );` — WooCommerce's helper branches on `get_option( 'permalink_structure' )` and falls back to `?wc-api=`, which is why only the shortcode flow breaks.

**Impact.** On an affected site the buyer completes card/bank authentication with the issuer, the auth token is issued, and the browser POSTs to a theme 404 page. `NicePay_Return_Handler::process()` never runs, so the server-side approval call to NextAppURL is never made — and neither is the net-cancel. Result: a dangling authorisation on the buyer's card, no captured funds, a transaction row stuck at `pending` forever, and a raw 404 shown to someone who just entered card details.

**Verifier note.** Two corrections to the original write-up: (1) plain permalinks are **not** the WordPress default on a fresh install — `wp_install()` has set a date/name structure for new non-multisite installs since WP 4.2, so this fires only on sites deliberately set to Plain or migrated that way. (2) Because it is configuration-conditional rather than universal, severity was downgraded critical → high; on an affected site the consequence is exactly as described.

**Recommendation.**

```php
function nicepay_return_url() {
    return get_option( 'permalink_structure' )
        ? home_url( '/nicepay-return/' )
        : add_query_arg( 'nicepay_return', '1', home_url( '/' ) );
}
```

Use it at `nicepay-payment-gateway.php:299` and `templates/standalone-payment-form.php:127` and `:136`. Additionally probe for the rule on the NicePay Settings page and show an admin notice when the endpoint is unreachable.

---

#### PLATFORM-06 · Network activation creates the transactions table on one site only

**Severity:** 🟠 High · **Category:** correctness · **Effort:** medium · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:104-148](../../nicepay-payment-gateway.php#L104), [:479-482](../../nicepay-payment-gateway.php#L479), [includes/nicepay-functions.php:46](../../includes/nicepay-functions.php#L46)

**Problem.** `create_tables()` uses `$wpdb->prefix` (per-site) but the activation closure declares no parameters, so the `$network_wide` argument WordPress passes to `register_activation_hook` callbacks is discarded. On network activation WordPress fires the hook exactly once, in the context of the site the network admin is on, so only that site's `{prefix}nicepay_transactions` is created. There is no `wp_initialize_site`/`wpmu_new_blog` handler either, so sites created afterwards never get a table. All five query helpers use `$wpdb->prefix`.

**Evidence.**

```php
// nicepay-payment-gateway.php:479-482 — closure takes no $network_wide
register_activation_hook( __FILE__, function () {
    $plugin = NicePay_Payment_Gateway::instance();
    $plugin->activate();
} );
// :107
$table_name = $wpdb->prefix . 'nicepay_transactions';
```

```php
// includes/nicepay-functions.php:81-86
$result = $wpdb->insert( $table, $data );
if ( $result === false ) { nicepay_log( … ); return false; }
```

**Impact.** On every other site in the network `nicepay_save_transaction()` hits a missing table, `$wpdb->insert` returns false, and both entry points treat that as fatal: the WooCommerce receipt page prints "Payment initialization failed. Please return to checkout." and marks the order failed ([includes/class-nicepay-gateway.php:170-177](../../includes/class-nicepay-gateway.php#L170)), and the shortcode AJAX returns the same error. The transactions screen shows an empty state rather than an error, and the only diagnostic is a `nicepay_log()` line that is itself a no-op unless `WP_DEBUG` is on (PLATFORM-26).

**Recommendation.**

```php
register_activation_hook( __FILE__, function ( $network_wide ) {
    $plugin = NicePay_Payment_Gateway::instance();
    if ( is_multisite() && $network_wide ) {
        foreach ( get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) {
            switch_to_blog( $blog_id );
            $plugin->activate();
            restore_current_blog();
        }
        return;
    }
    $plugin->activate();
} );
```

Add `add_action( 'wp_initialize_site', … )` for sites created later. Independently, add a cheap self-heal on `admin_init`: if `get_option( 'nicepay_db_version' )` is missing or `$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )` is null, run `create_tables()` — this also rescues installs where the activation hook was bypassed by deploy tooling.

---

#### PLATFORM-07 · Virtual-account deposit details never reach the buyer

**Severity:** 🟠 High · **Category:** ux-clarity · **Effort:** medium · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-gateway.php:357-390](../../includes/class-nicepay-gateway.php#L357)
**Spec:** NICEPAY §6.4.3 (VbankBankCode/VbankBankName/VbankNum/VbankExpDate/VbankExpTime); §2 inbound deposit-notification hosts.

**Problem.** On a successful VBANK approval the gateway calls `$order->update_status( 'on-hold', sprintf( 'Awaiting virtual account deposit. Bank: %1$s, Account: %2$s, Expires: %3$s', … ) )`. WooCommerce records status-transition notes via `add_order_note( $note, 0, … )` — the `0` makes it a **private** note visible only in wp-admin. The bank name, account number and expiry are never written to order meta either (lines 363-368 store only `_nicepay_tid`, `_nicepay_pay_method`, `_nicepay_auth_code`), and a repo-wide grep for `woocommerce_thankyou`, `woocommerce_email`, `woocommerce_admin_order_data`, `manage_edit-shop_order_columns` and `woocommerce_shop_order_search_fields` returns zero hits.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:370-378
if ( $result_method === 'VBANK' ) {
    $order->update_status( 'on-hold', sprintf(
        /* … */ 'Awaiting virtual account deposit. Bank: %1$s, Account: %2$s, Expires: %3$s' /* … */
    ) );
}
```

Note the standalone flow **does** render a deposit box ([includes/class-nicepay-return-handler.php:218-239](../../includes/class-nicepay-return-handler.php#L218), "Deposit Information" with Bank/Account/Amount/Deadline) — so the WooCommerce path is strictly worse than the shortcode path.

**Impact.** The customer chooses Virtual Account, is told their order is On hold, and is never shown the account number they must deposit into — not on the order-received page, not in the On-hold email, not in My Account. The only copies live in an admin-only note and the plugin's private transactions table. VBANK is one of four headline methods and is unusable without the merchant hand-emailing every buyer. Compounding it, there is no deposit-notification (입금통보) endpoint anywhere in the plugin, so even when the buyer deposits, the order never leaves On hold without manual intervention.

**User scenario.** A Korean buyer selects 가상계좌 at checkout. The thank-you page and confirmation email both say "On hold". Nowhere is the virtual account number they must transfer to. They email support; the merchant opens wp-admin, finds the private note, and copies the number by hand.

**Recommendation.**
1. Persist `_nicepay_vbank_bank_name`, `_nicepay_vbank_num`, `_nicepay_vbank_exp_date` and `_nicepay_vbank_exp_time` as order meta next to `_nicepay_tid`.
2. Add a **customer** note: `$order->add_order_note( $text, 1 )`.
3. Hook `woocommerce_thankyou_nicepay` and `woocommerce_email_before_order_table` to render a deposit panel with bank, account, amount and deadline.
4. Implement the deposit-notification endpoint (inbound from 121.133.126.10/.11 and 211.33.136.39 per spec §2) and call `$order->payment_complete()` on deposit.
5. Capture `VbankExpTime` — spec §6.4.3 returns it and the plugin drops it, so the buyer sees a date with no cut-off time.

---

### 🟡 MEDIUM

---

#### PLATFORM-08 · HPOS compatibility is never declared

**Severity:** 🟡 Medium · **Category:** spec-conformance · **Effort:** trivial · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:66-90](../../nicepay-payment-gateway.php#L66)

**Problem.** There is no `before_woocommerce_init` hook and no `FeaturesUtil::declare_compatibility()` call anywhere in the plugin (repo-wide grep for `FeaturesUtil`, `declare_compatibility`, `custom_order_tables`, `before_woocommerce_init`, `cart_checkout_blocks` → zero hits). WooCommerce treats any plugin that has not explicitly declared `custom_order_tables` compatibility as incompatible with High-Performance Order Storage. The order-data code is otherwise already HPOS-clean.

**Evidence.** `init_hooks()` at lines 66-90 registers `plugins_loaded` ×2, `wp_enqueue_scripts`, `init`, `template_redirect`, one shortcode, four AJAX actions and one admin filter — no `before_woocommerce_init`. Contrast:

```php
// includes/class-nicepay-gateway.php:153-155 — already the correct HPOS-safe CRUD API
$order->update_meta_data( '_nicepay_moid', $moid );
$order->update_meta_data( '_nicepay_edi_date', $edi_date );
$order->save();
```

**Impact.** In WooCommerce → Settings → Advanced → Features, NicePay is listed under "Incompatible plugins". A store that has not migrated cannot enable HPOS without deactivating NicePay or overriding the warning; a store already on HPOS sees a persistent warning against this plugin. Merchants and agencies read that screen as "unmaintained". Nothing functionally breaks — this is an operational blocker and a credibility problem, not a payment failure.

**Verifier note.** Severity downgraded critical → medium: a missing declaration causes no functional failure whatsoever — payments, refunds and meta all work identically with or without HPOS enabled.

**Recommendation.**

```php
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
    }
} );
```

Fix the hardcoded `post.php` order link (PLATFORM-11) first so the `custom_order_tables` declaration is actually truthful. Flip `cart_checkout_blocks` to `true` only once PLATFORM-01 ships.

---

#### PLATFORM-09 · `CREATE TABLE IF NOT EXISTS` defeats dbDelta's parser, so no schema migration can ever run

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:104-148](../../nicepay-payment-gateway.php#L104), [:161](../../nicepay-payment-gateway.php#L161)

**Problem.** `create_tables()` passes `CREATE TABLE IF NOT EXISTS {$table_name} (...)` to `dbDelta()`. dbDelta indexes queries by table name with `preg_match( '|CREATE TABLE ([^ ]*)|', $qry, $matches )`, so `$matches[1]` is the literal string `IF` and the query is filed under the table name `IF`. dbDelta then runs `DESCRIBE IF;`, gets nothing back, and `continue`s — the column/index diffing branch never executes for this table. The raw statement is then executed verbatim, which creates the table on first install and is a guaranteed MySQL-level no-op on every subsequent run. `nicepay_db_version` is written once at line 161 and is never read by any code path (its only other occurrence is a documentation row in [docs/ARCHITECTURE.md:441](../ARCHITECTURE.md)), so there is not even a version gate that could detect the drift.

**Evidence.**

```php
// nicepay-payment-gateway.php:110
$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
// :146-147
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( $sql );
// :161
add_option( 'nicepay_db_version', NICEPAY_VERSION );
```

**Impact.** Entirely latent today — the table is correct on a fresh install. The cost arrives the moment v2.1 needs a new column (a `currency` column for PLATFORM-23, an `otid` column for PLATFORM-29, a `cancel_tid`): calling `create_tables()` again will silently do nothing on every existing install, every write touching the new column will fail, `nicepay_save_transaction()` will return false, and the gateway will surface that to buyers as "Payment initialization failed". A routine schema change becomes a store-wide payment outage.

**Verifier note — one sub-claim REFUTED.** The original review also claimed that `PRIMARY KEY (id)` with one space instead of two "will cause repeated ALTER attempts". That is **false** for every WordPress version this plugin supports: dbDelta's index parser was rewritten in WP 4.2 to `'PRIMARY\s+KEY|(?:UNIQUE|FULLTEXT|SPATIAL)\s+(?:KEY|INDEX)|KEY|INDEX'` followed by `\s+` and an optional name group, so single-space `PRIMARY KEY (id)` parses correctly. The two-space rule is a pre-4.2 relic and the plugin requires WP 5.0+. **Do not make that change.**

**Recommendation.** Drop `IF NOT EXISTS` — dbDelta is already idempotent: `$sql = "CREATE TABLE {$table_name} (";`. Add a real migration gate: define `NICEPAY_DB_VERSION` separately from `NICEPAY_VERSION`, compare it against `get_option( 'nicepay_db_version' )` on `admin_init`, run `create_tables()` plus any version-specific ALTERs on mismatch, then `update_option()`. Version the schema independently of the plugin so a release without schema changes does not trigger a DESCRIBE on every install.

---

#### PLATFORM-10 · Every admin surface is gated on `manage_options` — shop managers are locked out

**Severity:** 🟡 Medium · **Category:** ux-clarity · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [admin/class-nicepay-admin.php:22](../../admin/class-nicepay-admin.php#L22), [:33](../../admin/class-nicepay-admin.php#L33), [:42](../../admin/class-nicepay-admin.php#L42), [:125](../../admin/class-nicepay-admin.php#L125), [admin/class-nicepay-transactions.php:17](../../admin/class-nicepay-transactions.php#L17), [:186](../../admin/class-nicepay-transactions.php#L186), [nicepay-payment-gateway.php:365](../../nicepay-payment-gateway.php#L365), [:444](../../nicepay-payment-gateway.php#L444)

**Problem.** All seven capability gates in the plugin use `manage_options`, an administrator-only capability. WooCommerce's `shop_manager` role deliberately does not have it — the capability for store operations is `manage_woocommerce`. The model contradicts the rest of the store: a shop manager can open an order and click Refund, triggering `process_refund()` (a real, irreversible money movement against the NICEPAY API), but cannot open NicePay → Transactions to see whether it worked, and cannot use the Cancel button.

**Evidence.**

```php
// admin/class-nicepay-admin.php:19-45 — menu + both submenus use 'manage_options'
add_menu_page( …, 'manage_options', 'nicepay-settings', … );
// :125
if ( ! current_user_can( 'manage_options' ) ) { return; }

// admin/class-nicepay-transactions.php:186
if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'nicepay_cancel_' . $id ) ) {
```

**Impact.** The people who run the shop are locked out of the plugin's entire admin experience — `add_menu_page()` is gated too, so the NicePay menu does not even render. Reconciliation, transaction lookup and cancellation all require escalating someone to full administrator, exactly the privilege creep WooCommerce's role model exists to prevent. Meanwhile the one genuinely dangerous operation they *can* perform is ungated by this plugin. Additionally, an under-privileged user reaching the page URL directly gets a completely blank admin page — both renderers use a bare `return`.

**Verifier note.** Severity downgraded high → medium: this is a usability and role-design defect, not a security hole (the gate is too strict, never too loose) and it has a clean workaround (grant administrator).

**Recommendation.**

```php
function nicepay_admin_capability() {
    return apply_filters(
        'nicepay_admin_capability',
        class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options'
    );
}
```

Use it for the menu registration, both page renderers, and the cancel-transaction AJAX handler. Keeping the API Credentials tab on `manage_options` is a defensible split, but make it a deliberate per-tab decision rather than a blanket lockout. Replace the bare `return` at `admin/class-nicepay-admin.php:125-127` and `admin/class-nicepay-transactions.php:17-19` with `wp_die( esc_html__( 'You do not have permission to access this page.', 'nicepay-payment-gateway' ), 403 )`.

---

#### PLATFORM-11 · Transactions list builds order edit links with `post.php?post=` instead of `get_edit_order_url()`

**Severity:** 🟡 Medium · **Category:** spec-conformance · **Effort:** trivial · **Verdict:** CONFIRMED
**Location:** [admin/class-nicepay-transactions.php:124-131](../../admin/class-nicepay-transactions.php#L124)

**Problem.** The Order column constructs the edit link by hand. Under HPOS orders are not posts and the canonical edit screen is `admin.php?page=wc-orders&action=edit&id={id}`. This is the one genuine HPOS incompatibility in the codebase — the meta handling is otherwise correct.

**Evidence.**

```php
// admin/class-nicepay-transactions.php:125-128
<?php if ( $item->wc_order_id ) : ?>
    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $item->wc_order_id . '&action=edit' ) ); ?>">
        #<?php echo esc_html( $item->wc_order_id ); ?>
    </a>
```

**Impact.** On an HPOS store, the transactions screen's single most useful affordance — jumping from a payment record to its order — is dead. The link resolves against a `shop_order_placehold` post (the ID-reservation record HPOS writes into `wp_posts`), which has no editable post-type UI, so the merchant gets a WordPress error page during reconciliation.

**Verifier note.** One impact claim corrected: the link does **not** "silently open an unrelated post that happens to share the ID" — HPOS reserves order IDs by writing `shop_order_placehold` rows into `wp_posts` precisely so IDs never collide with real posts, so the link errors out rather than opening someone else's content. Severity downgraded high → medium.

**Recommendation.**

```php
<?php
$linked_order = $item->wc_order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $item->wc_order_id ) : false;
if ( $linked_order ) : ?>
    <a href="<?php echo esc_url( $linked_order->get_edit_order_url() ); ?>">#<?php echo esc_html( $linked_order->get_order_number() ); ?></a>
<?php else : ?>
    <?php echo esc_html( $item->moid ); ?>
<?php endif; ?>
```

Rendering `get_order_number()` rather than the raw ID also matters because sequential-order-number plugins make the ID meaningless to merchants.

---

#### PLATFORM-12 · All protocol timestamps use `date()`, which WordPress forces to UTC — VbankExpDate is 9 hours short of the intended KST deadline

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** medium · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-api.php:59-61](../../includes/class-nicepay-api.php#L59), [:66-68](../../includes/class-nicepay-api.php#L66), [:426-429](../../includes/class-nicepay-api.php#L426), [admin/class-nicepay-transactions.php:141](../../admin/class-nicepay-transactions.php#L141), [nicepay-payment-gateway.php:136-137](../../nicepay-payment-gateway.php#L136)

**Problem.** `generate_edi_date()`, `generate_moid()` and `get_vbank_exp_date()` all call PHP's `date()`. Since WordPress 5.3, `wp-settings.php` executes `date_default_timezone_set( 'UTC' )`, so `date()` returns UTC — not the site timezone and not KST, the wall clock NICEPAY's platform operates in. The signature itself is unaffected (both sides hash the same literal string), but every other consumer is. Separately the DB columns use `DEFAULT CURRENT_TIMESTAMP` (the MySQL server's timezone — a third independent clock) and the admin prints that raw value with no conversion and no timezone label.

**Evidence.**

```php
// includes/class-nicepay-api.php:59-61
public function generate_edi_date() { return date( 'YmdHis' ); }
// :66-68
return $prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
// :426-429
$days = (int) get_option( 'nicepay_vbank_expiry_days', 3 );
return date( 'YmdHi', strtotime( '+' . $days . ' days' ) );
```

```sql
-- nicepay-payment-gateway.php:136-137
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

```php
// admin/class-nicepay-transactions.php:141
<td><?php echo esc_html( $item->created_at ); ?></td>
```

**Impact.** Two verified defects. (1) `get_vbank_exp_date()` computes `+3 days` in UTC and submits it as a KST wall-clock deadline, so a virtual account configured to expire in 3 days actually expires **9 hours early** from the buyer's and the bank's point of view — late deposits bounce and the merchant loses the sale. (2) The transactions screen prints `created_at` verbatim in an unstated timezone, so a merchant reconciling against a NICEPAY settlement report in KST is comparing clocks with no labels — disqualifying for a financial audit trail.

**User scenario.** A store enables Virtual Account with a 3-day expiry. A buyer at 18:00 KST is told the account expires in 3 days; the account NICEPAY actually issues expires at 09:00 KST on day 3. The buyer deposits on the morning of day 3 and the transfer is rejected.

**Verifier note.** A third sub-claim — that a 9-hour EdiDate skew "sits inside whatever freshness window NICEPAY applies and is a latent rejection risk" — could **not** be verified: nothing in the spec digest documents an EdiDate freshness window, and the plugin has evidently been transacting successfully with this skew. It has been removed from the impact rather than presented as fact. Severity downgraded high → medium accordingly.

**Recommendation.** Introduce one timezone authority:

```php
$now = new DateTimeImmutable( 'now', new DateTimeZone( apply_filters( 'nicepay_timezone', 'Asia/Seoul' ) ) );
$edi_date  = $now->format( 'YmdHis' );
$vbank_exp = $now->modify( "+{$days} days" )->format( 'YmdHi' );
```

Spec §5.2.2 accepts 8 or 12 characters, so the existing 12-char format is correct — only the clock is wrong. For storage, write `current_time( 'mysql', true )` yourself rather than relying on `CURRENT_TIMESTAMP`, and render with `wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->created_at . ' UTC' ) )`. Show the KST timestamp alongside — that is the number that matches NICEPAY's report.

---

#### PLATFORM-13 · No idempotency guard on the return handler — a replayed POST can demote a paid order to `failed`

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED *(residual uncertainty on the trigger — see note)*
**Location:** [includes/class-nicepay-gateway.php:216-249](../../includes/class-nicepay-gateway.php#L216), [:291-317](../../includes/class-nicepay-gateway.php#L291), [:395-412](../../includes/class-nicepay-gateway.php#L395), [includes/class-nicepay-return-handler.php:51-158](../../includes/class-nicepay-return-handler.php#L51)

**Problem.** `handle_return()` performs no idempotency check. It resolves the transaction by Moid and the order by `wc_order_id`, verifies the auth signature, and then unconditionally calls `request_approval()` — regardless of whether that Moid has already been approved, whether the transaction row is still `pending`, or whether the order is already paid. A repo-wide grep for `is_paid`, `has_status` and `needs_payment` returns zero hits: no status check exists anywhere in the plugin. If the second approval attempt returns any non-success ResultCode, control falls through to the failure branch which calls `$order->update_status( 'failed', … )` on an already-paid order and overwrites the transaction row's status to `failed`.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:303 — unconditional
$result = $this->api->request_approval( $auth_data );

// :396-404 — reached for ANY non-success ResultCode
$update_data['status'] = 'failed';
nicepay_update_transaction( $transaction->id, $update_data );
$order->update_status( 'failed', sprintf( /* … */ 'NicePay payment failed. Code: %1$s, Message: %2$s' /* … */ ) );
```

**Impact.** A successfully paid, `processing` order can be silently demoted to `failed`, destroying the record that it ever succeeded in both WooCommerce and the plugin's own table. WooCommerce then drops it from fulfilment queues and may email the customer that payment failed, while the money is captured at NICEPAY. The plausible triggers are a browser back-navigation onto the form-resubmission dialog on mobile, or a client-side retry.

**User scenario.** A customer pays on mobile, lands on the order-received page, then hits Back twice. Chrome offers to resubmit the form; they accept. The duplicate approval is rejected and their completed order becomes "failed".

**Verifier note.** The code defect is fully verified: there is genuinely no idempotency check and the failure branch genuinely downgrades unconditionally. **Residual uncertainty on the trigger** (hence medium, not high): the second POST reuses a one-time AuthToken against a one-time NextAppURL, and it could not be verified from this repo whether NICEPAY returns a non-success ResultCode (→ the described demotion), an HTTP error (→ the `is_wp_error` branch at line 305, also a demotion), or a repeat of the original success payload (harmless). Two of the three possible PG responses produce the harm.

**Recommendation.** Guard the entry point immediately after the order is resolved:

```php
if ( $order->is_paid()
    || $order->has_status( array( 'processing', 'completed', 'on-hold', 'refunded' ) )
    || ( $transaction && $transaction->status !== 'pending' ) ) {
    nicepay_log( 'Duplicate return POST ignored', array( 'moid' => $moid ) );
    wp_safe_redirect( $this->get_return_url( $order ) );
    exit;
}
```

Belt-and-braces: in the failure branch, only call `update_status( 'failed' )` when the order is still `pending` or `failed`. Apply the same guard to `NicePay_Return_Handler::process()`, which has the identical structure and additionally proceeds to call `request_approval()` even when `$transaction` is null (the line 51 result is only null-checked before DB writes, never before the approval call).

---

#### PLATFORM-14 · No uninstall routine — the transactions table and the live merchant key survive plugin deletion

**Severity:** 🟡 Medium · **Category:** spec-conformance · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:100-102](../../nicepay-payment-gateway.php#L100), [:150-163](../../nicepay-payment-gateway.php#L150)

**Problem.** There is no `uninstall.php` in the repo root and no `register_uninstall_hook()` call anywhere (both verified). `deactivate()` only flushes rewrite rules. Deleting the plugin through the WordPress admin therefore leaves behind `{$prefix}nicepay_transactions` and twelve options — including `nicepay_live_merchant_key`, the plaintext secret used to sign every payment.

**Evidence.**

```php
// nicepay-payment-gateway.php:100-102 — the entire teardown
public function deactivate() {
    flush_rewrite_rules();
}
// :151-162 — twelve add_option() calls including:
add_option( 'nicepay_live_merchant_key', '' );
```

The developer guide concedes it — [docs/DEVELOPER-GUIDE.md:52](../DEVELOPER-GUIDE.md): *"The database table and options are NOT removed on deactivation… To fully clean up, use an uninstall hook or manual deletion."*

**Impact.** Two harms. (1) The live merchant key remains in `wp_options` indefinitely after the merchant believes they have removed the integration — recoverable by anyone who later gains DB or admin access (a site sold to a new owner, a backup handed to a contractor, a compromised staging clone) and usable to sign valid NICEPAY requests. (2) Uninstall/reinstall never resets state, so a merchant troubleshooting by removing and re-adding the plugin gets the identical broken state back. WordPress plugin-directory review expects an uninstall routine.

**Verifier note.** Severity downgraded high → medium: the residual credential is a real hygiene and compliance problem, but exploiting it requires an attacker who already has database or administrator access.

**Recommendation.** Add `uninstall.php` guarded by `if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;`. Gate table/history destruction behind an opt-in option (`nicepay_remove_data_on_uninstall`, default off, exposed on the General tab) so transaction history is not destroyed by accident — but **always** delete `nicepay_live_merchant_key`, `nicepay_test_merchant_key`, `nicepay_live_mid` and `nicepay_test_mid` regardless of the toggle. Make it multisite-aware: iterate `get_sites()` with `switch_to_blog()`.

---

#### PLATFORM-15 · Zero extension points exist, and the developer guide documents a filter that was never implemented

**Severity:** 🟡 Medium · **Category:** dx · **Effort:** medium · **Verdict:** CONFIRMED
**Location:** [docs/DEVELOPER-GUIDE.md:343-364](../DEVELOPER-GUIDE.md), [includes/class-nicepay-gateway.php:179-210](../../includes/class-nicepay-gateway.php#L179), [includes/class-nicepay-api.php:193-220](../../includes/class-nicepay-api.php#L193)

**Problem.** `grep -rn "apply_filters\|do_action" --include='*.php' .` across the entire plugin (excluding tests) returns **nothing** — not one filter, not one action. A store developer cannot alter the auth request parameters, change the goods name, choose the order status after approval, react to a completed approval, override a template, or adjust the API timeout without forking. Meanwhile `docs/DEVELOPER-GUIDE.md:343-353` instructs developers to *"use the `nicepay_payment_form_template` filter"* and provides a working-looking `add_filter()` sample for a filter that does not exist, hedged only by a footnote reading *"This filter needs the gateway to apply it."*

**Evidence.**

```php
// docs/DEVELOPER-GUIDE.md:347
add_filter( 'nicepay_payment_form_template', function( $template_path ) {
// :356
> **Note:** This filter needs the gateway to apply it.

// includes/class-nicepay-gateway.php:210 — a bare include, no filter on the path
include NICEPAY_PLUGIN_DIR . 'templates/payment-form.php';
```

**Impact.** Every non-trivial customisation — the normal case for a payment integration — requires a fork, so the merchant loses updates permanently. The documented-but-absent filter is worse than no documentation: a developer copies the snippet, it silently does nothing, and they lose an afternoon before reading source. The fallback the docs then offer (`remove_action( 'woocommerce_receipt_nicepay', array( WC()->payment_gateways()->get_available_payment_gateways()['nicepay'], 'receipt_page' ) )`, DEVELOPER-GUIDE.md:362) fatals with an undefined-index if the gateway is unavailable, and runs on `init` when gateways may not be instantiated yet.

**Verifier note.** Severity downgraded high → medium: a developer-experience and documentation-accuracy gap, not a defect in the shipped product's behaviour.

**Recommendation.** Add a deliberate hook surface and then make the docs true. Minimum set:

| Hook | Site |
|---|---|
| `apply_filters( 'nicepay_auth_request_params', $form_data, $order )` | gateway.php:210, before the include |
| `apply_filters( 'nicepay_approval_request_params', $params, $auth_data )` | api.php:213, before the POST |
| `apply_filters( 'nicepay_goods_name', $goods_name, $order )` | gateway.php:144 |
| `apply_filters( 'nicepay_payment_complete_order_status', $status, $order, $result )` | gateway.php:358+ |
| `do_action( 'nicepay_payment_approved', $order, $result, $transaction_id )` | gateway.php success branch |
| `do_action( 'nicepay_payment_failed', $order, $result )` | gateway.php failure branch |
| `apply_filters( 'nicepay_http_timeout', 30, $context )` | api.php, all three calls |
| `apply_filters( 'nicepay_payment_form_template', $path, $order )` | gateway.php:210 |

Delete the broken `remove_action` snippet from the docs.

---

#### PLATFORM-16 · Three msgids do not match the source strings, so those strings render in English in all four locales

**Severity:** 🟡 Medium · **Category:** i18n · **Effort:** small · **Verdict:** CONFIRMED
**Location:** `languages/nicepay-payment-gateway.pot:189, 583, 623` (and 143/434/464 in each of tr_TR, ko_KR, zh_CN; 146/437/467 in en_US); [includes/class-nicepay-gateway.php:136-139](../../includes/class-nicepay-gateway.php#L136), [admin/class-nicepay-transactions.php:73-76](../../admin/class-nicepay-transactions.php#L73), [:116](../../admin/class-nicepay-transactions.php#L116)

**Problem.** The catalogue was not generated from the source. Three msgids are wrong, and `grep -c msgid_plural` across all five catalogue files returns **0** in every file — not a single plural entry, despite the code calling `_n()` twice. Gettext keys on the exact singular string, so a mismatched msgid resolves to nothing and falls back to English.

| Source string | Catalogue msgid | Result |
|---|---|---|
| `_n( 'Total: %d transaction', 'Total: %d transactions', … )` | `"Total: %d transactions"` (non-plural) | Never matches the singular key |
| `_n( ' and %d more item', ' and %d more items', … )` | `" and %d more"` | Never matches |
| `__( 'Copy TID %s' )` | `"Copy TID"` | Never matches |

**Evidence.**

```php
// includes/class-nicepay-gateway.php:136-139
$goods_name .= sprintf( _n( ' and %d more item', ' and %d more items', $extra, 'nicepay-payment-gateway' ), $extra );

// admin/class-nicepay-transactions.php:73-76
printf( esc_html( _n( 'Total: %d transaction', 'Total: %d transactions', $total, 'nicepay-payment-gateway' ) ), $total );

// :116
sprintf( __( 'Copy TID %s', 'nicepay-payment-gateway' ), $item->tid )
```

`strings languages/nicepay-payment-gateway-tr_TR.mo | grep …` confirms `' and %d more'`, `'Copy TID'` and `'Total: %d transactions'` are baked into the compiled binary as the wrong keys.

**Impact.** [README.md:17](../../README.md) advertises *"Multi-language — Korean, English, Chinese, Turkish translations included"*, but on a Korean store the transactions header renders as English "Total: 42 transactions" and the order line item as "Widget and 3 more items", while the translator's Korean text sits unused in the `.mo`. For a plugin whose primary market is Korea, English leaking into the admin is a credibility problem. The complete absence of plural forms also means `_n()` on `ko_KR` (nplurals=1) can never behave correctly even after the msgids are fixed. Because the catalogue is hand-maintained (`X-Generator: NicePay Payment Gateway`), this drift will recur on every string change.

**Recommendation.** Stop hand-writing the catalogue:

```bash
wp i18n make-pot . languages/nicepay-payment-gateway.pot --domain=nicepay-payment-gateway
for po in languages/*.po; do msgmerge -U "$po" languages/nicepay-payment-gateway.pot; done
wp i18n make-mo languages/
```

Retranslate the three orphaned entries. Add a CI step to `.github/workflows/tests.yml` that regenerates the `.pot` and fails if `git diff --exit-code languages/nicepay-payment-gateway.pot` is non-empty. Separately, drop the leading space from `' and %d more item'` and concatenate it in PHP — leading whitespace in translatable strings is a known translator trap.

---

#### PLATFORM-17 · `is_available()` ignores the store currency — NicePay is offered for currencies NICEPAY cannot process

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-gateway.php:76-86](../../includes/class-nicepay-gateway.php#L76), [:149](../../includes/class-nicepay-gateway.php#L149), [:191](../../includes/class-nicepay-gateway.php#L191), [includes/nicepay-functions.php:248-258](../../includes/nicepay-functions.php#L248)
**Spec:** §4 (table 5-2): `CurrencyCode(3: KRW default / USD)`.

**Problem.** `is_available()` checks only that the gateway is enabled and a MID and merchant key are present. The NICEPAY spec supports exactly two values for `CurrencyCode`. The gateway nonetheless passes `$order->get_currency()` straight through as `CurrencyCode`, so a store selling in EUR, GBP or JPY renders NicePay at checkout and submits an unsupported currency code. `nicepay_get_amount()` compounds it: anything not KRW is formatted with two decimals, wrong for zero-decimal currencies.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:76-86 — no currency check
public function is_available() {
    if ( $this->enabled !== 'yes' ) { return false; }
    if ( ! $this->api->get_mid() || ! $this->api->get_merchant_key() ) { return false; }
    return true;
}
// :149 / :191
$currency = $order->get_currency();
'CurrencyCode' => $currency,
```

```php
// includes/nicepay-functions.php:253-257
if ( $currency === 'KRW' ) { return (string) (int) $amount; }
return number_format( (float) $amount, 2, '.', '' );
```

**Impact.** The merchant sees the gateway working in admin, the buyer selects it, is redirected to the NICEPAY window, and the payment fails at the PG with an opaque error code — the worst place to discover a configuration mismatch. There is no admin warning, no checkout-time filtering, and no mention of the constraint in the settings UI. The General tab even offers a plugin-level KRW/USD dropdown that has no effect on WooCommerce orders (PLATFORM-30), reinforcing the false impression that currency is handled.

**Verifier note.** Severity downgraded high → medium: reaching the failure requires the merchant to have configured a Korean PG on a non-KRW/USD store, which is a self-evident mismatch.

**Recommendation.**

```php
if ( ! in_array( get_woocommerce_currency(), apply_filters( 'nicepay_supported_currencies', array( 'KRW', 'USD' ) ), true ) ) {
    return false;
}
```

Pair it with an admin notice on the WooCommerce Payments screen naming the store's current currency and the supported set — silently disappearing is not acceptable feedback. Make `nicepay_get_amount()` decide decimals from a zero-decimal-currency list rather than special-casing `'KRW'` by name.

---

#### PLATFORM-18 · No `needs_setup()` and no gateway icon — an unconfigured gateway vanishes from checkout with zero feedback

**Severity:** 🟡 Medium · **Category:** ux-clarity · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-gateway.php:39-71](../../includes/class-nicepay-gateway.php#L39), [:76-86](../../includes/class-nicepay-gateway.php#L76)

**Problem.** `is_available()` returns false when the MID or merchant key is empty, but the gateway does not implement `needs_setup()` (grep for `needs_setup` and `$this->icon` in the gateway file returns nothing). WooCommerce uses `needs_setup()` on the Payments settings screen to replace the enable/disable toggle with a "Set up" call to action and to stop a merchant enabling a gateway before it can work. Without it the merchant sees a normal toggle, flips it on, sees no error — and the gateway silently never renders.

**Evidence.** `grep -n "needs_setup\|\$this->icon\|payment_scripts" includes/class-nicepay-gateway.php` → no hits. [admin/class-nicepay-admin.php:135-137](../../admin/class-nicepay-admin.php#L135) renders `<span class="nicepay-mode-badge nicepay-mode-badge-…">` with the mode string and no validity indication.

**Impact.** The single most common first-run failure — credentials entered on the Test tab while the mode is Live, or live credentials never entered — produces zero diagnostics. The Payments list shows NicePay as enabled; the checkout shows nothing; the plugin's own settings page shows nothing wrong. The merchant's only recourse is guessing or reading source. The gateway also renders as bare text at checkout while the plugin ships a full SVG icon set (`includes/nicepay-icons.php`).

**Verifier note.** A mitigation worth recording: in TEST mode `NicePay_API::__construct()` falls back to the `NICEPAY_TEST_MID`/`NICEPAY_TEST_MERCHANT_KEY` constants and `set_default_options()` seeds them, so `is_available()` never fails in test mode — the silent-disappearance scenario is specific to **Live** mode with empty live credentials. (See PLATFORM-03 for the other half of that behaviour.)

**Recommendation.**

```php
public function needs_setup() {
    return ! $this->api->get_mid() || ! $this->api->get_merchant_key();
}
```

Add an `admin_notices` callback scoped to the WooCommerce settings and NicePay pages stating exactly what is missing and for which mode — *"NicePay is in Live mode but no Live MID is configured; payments are disabled."* Add the same status as a banner next to the existing mode badge, which today reports the mode but never whether that mode is usable. Set `$this->icon` from the icon set.

---

#### PLATFORM-19 · The third-party NICEPAY script loads on every checkout and thank-you page regardless of the gateway

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:186-200](../../nicepay-payment-gateway.php#L186), [:269-307](../../nicepay-payment-gateway.php#L269)

**Problem.** `enqueue_scripts()` loads the full asset bundle — including `https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js` — whenever `is_checkout()` is true. It never consults whether the NicePay gateway is enabled, whether it is available, or whether the customer selected it. `is_checkout()` is also true on the order-received endpoint, so the external script loads on the thank-you page too. WooCommerce's convention is a `payment_scripts()` method on the gateway that returns early unless the gateway is available and the page is the pay/checkout page; grep confirms no such method exists.

**Evidence.**

```php
// nicepay-payment-gateway.php:186-200 — no gateway-enabled check
public function enqueue_scripts() {
    $load = $this->is_payment_page();
    if ( ! $load && function_exists( 'is_checkout' ) ) { $load = is_checkout(); }
    if ( ! $load && function_exists( 'is_checkout_pay_page' ) ) { $load = is_checkout_pay_page(); }
    if ( $load ) { $this->enqueue_payment_assets(); }
}
// :281-287
wp_enqueue_script( 'nicepay-pgweb', NICEPAY_JS_URL, array(), null, true );
```

**Impact.** (1) *Privacy*: every visitor who reaches checkout — including on stores where NicePay is installed but disabled, or where the buyer chose another method — triggers a request to a Korean payment processor's domain before any consent, exposing IP and referrer. (2) *Performance*: an external script plus a 554-line stylesheet on the highest-value page of the store, unconditionally. (3) *Reliability*: if `pg-web.nicepay.co.kr` is slow or blocked (corporate networks, some regions), checkout degrades for customers who are not using NicePay at all.

**Verifier note.** Severity downgraded high → medium: privacy and performance costs are real, but no functionality breaks, and a "PCI scope" framing would overstate it — this is a redirect gateway that never touches card data on-site.

**Recommendation.** Move the enqueue onto the gateway as `payment_scripts()`, hooked from the gateway constructor: return early unless `is_checkout_pay_page()` (or the shortcode is present), unless `$this->is_available()`, and unless the order's payment method is `nicepay`. Better for privacy: do not load `nicepay-pgweb.js` at page load at all — append the script tag on demand when the buyer clicks Pay, which the standalone flow can do inside its existing AJAX round-trip. Keep `nicepay.css` local and conditional the same way.

---

#### PLATFORM-20 · Nonce is baked into cacheable shortcode HTML, and a second mismatched nonce is localized but structurally unusable

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:297-320](../../nicepay-payment-gateway.php#L297), [templates/standalone-payment-form.php:305](../../templates/standalone-payment-form.php#L305), [assets/js/nicepay.js](../../assets/js/nicepay.js)

**Problem.** Two defects in one mechanism.

**(a)** The shortcode template emits `wp_create_nonce( 'nicepay_init_payment' )` inline into the page HTML. Any full-page cache — WP Rocket, LiteSpeed, Cloudflare APO, a static host — serves that HTML past the nonce's 24-hour maximum lifetime, and `ajax_init_payment()` rejects it with the flat message "Invalid request." with no retry path.

**(b)** `enqueue_payment_assets()` localizes `'nonce' => wp_create_nonce( 'nicepay_payment' )` into `nicepayParams`, while `ajax_init_payment()` verifies against the action `'nicepay_init_payment'`. The two action strings differ, so that localized nonce could never validate — and nothing reads it: `assets/js/nicepay.js` references only `nicepayParams.i18n` (lines 37, 54, 61). `nicepayParams.returnUrl` is likewise dead.

**Evidence.**

```php
// nicepay-payment-gateway.php:297-306
wp_localize_script( 'nicepay-js', 'nicepayParams', array(
    'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
    'returnUrl' => home_url( '/nicepay-return/' ),
    'nonce'     => wp_create_nonce( 'nicepay_payment' ),   // <-- action A
    'i18n'      => …,
) );
// :314-317
if ( ! wp_verify_nonce( isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '', 'nicepay_init_payment' ) ) {   // <-- action B
```

**Impact.** On any cached site the standalone payment button is broken for anonymous visitors — exactly the audience shortcodes exist to serve — and the only feedback is "Invalid request.", which points the merchant at nothing. The dead mismatched nonce is a maintenance trap: the next developer will assume `nicepayParams.nonce` is the live credential, wire something to it, and get a silent auth failure. Shipping two nonces for one endpoint, one structurally incapable of validating, is a correctness smell in the most security-sensitive part of the plugin.

**Verifier note.** Severity downgraded high → medium: the mismatched nonce is inert dead code (no security consequence, since the endpoint verifies the correct action), and the caching breakage is conditional on a full-page cache being installed.

**Recommendation.** (1) Delete `nonce` and `returnUrl` from the localize payload, or make the localized nonce `wp_create_nonce( 'nicepay_init_payment' )` and have the template read `nicepayParams.nonce` so there is a single source. (2) Make the nonce cache-safe: on `wp_verify_nonce()` failure return a distinct error code and have the JS fetch a fresh nonce from a small uncached endpoint and retry once, rather than dead-ending. (3) Replace "Invalid request." with "This page has expired. Please refresh and try again." plus an automatic refresh affordance.

---

#### PLATFORM-21 · Unchecking every payment method stores an empty array, producing a PHP warning and an empty PayMethod

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** trivial · **Verdict:** CONFIRMED
**Location:** [templates/standalone-payment-form.php:32-39](../../templates/standalone-payment-form.php#L32), [admin/class-nicepay-admin.php:112-117](../../admin/class-nicepay-admin.php#L112)

**Problem.** When a merchant unchecks every box on the Payment Methods tab, no `nicepay_enabled_methods[]` field is submitted; `wp-admin/options.php` passes `null` to `update_option()` for any registered option absent from `$_POST`, and the registered sanitize callback converts null to `array()`. The option now **exists** with an empty-array value, so `get_option( 'nicepay_enabled_methods', array( 'CARD' ) )` returns `array()` — the default never applies. The template then indexes `[0]` with no guard.

**Evidence.**

```php
// templates/standalone-payment-form.php:32, 38-39
$enabled_methods = get_option( 'nicepay_enabled_methods', array( 'CARD' ) );
$show_method_selector = empty( $pay_method ) && count( $enabled_methods ) > 1;
$default_method = $pay_method ? $pay_method : $enabled_methods[0];
```

```php
// admin/class-nicepay-admin.php:112-117
register_setting( 'nicepay_payment', 'nicepay_enabled_methods', array(
    'type' => 'array',
    'sanitize_callback' => function ( $value ) {
        return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
    },
) );
```

**Impact.** PHP 8 emits "Warning: Undefined array key 0", which lands in page output on any site with `display_errors` on, and `$default_method` becomes null so the form submits an empty `PayMethod` — a required field per spec §4 — producing a NICEPAY-side rejection. The merchant's action had an obvious intent that the UI should have caught. There is also no validation preventing the empty selection in the first place.

**Verifier note.** `templates/payment-form.php` is **safe** — it guards with `count( $enabled_methods ) > 1` at line 31 and `! empty( $enabled_methods )` at line 56 before touching index 0. Only the standalone template is affected.

**Recommendation.** Two fixes. (1) Harden the sanitize callback to reject an empty result: fall back to the previous value and `add_settings_error()` explaining that at least one method must remain enabled — enforce the invariant at the source. (2) Defend at the template:

```php
$enabled_methods = array_values( (array) get_option( 'nicepay_enabled_methods', array( 'CARD' ) ) );
if ( empty( $enabled_methods ) ) {
    echo '<div class="nicepay-notice nicepay-notice-error" role="alert">…no payment methods enabled…</div>';
    return;
}
```

---

#### PLATFORM-22 · Six registered settings have no sanitize_callback, and any `nicepay_mode` other than the literal `test` selects LIVE

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [admin/class-nicepay-admin.php:98-110](../../admin/class-nicepay-admin.php#L98), [includes/class-nicepay-api.php:22-33](../../includes/class-nicepay-api.php#L22)

**Problem.** `nicepay_mode`, `nicepay_language`, `nicepay_currency`, `nicepay_charset`, `nicepay_test_mid`, `nicepay_test_merchant_key`, `nicepay_live_mid` and `nicepay_live_merchant_key` are all registered with `register_setting()` and **no** `sanitize_callback` and no `type`, so options.php writes the unslashed POST value straight through (`update_option()` applies `sanitize_option_{$name}`, to which nothing is attached). The mode is then evaluated with strict equality against one literal, so **any** other stored value (empty string, `'Test'`, `'production'`, stray whitespace) selects LIVE.

**Evidence.**

```php
// admin/class-nicepay-admin.php:100-109 — eight bare registrations
register_setting( 'nicepay_general', 'nicepay_mode' );
// … versus :112-121 where the two payment settings DO pass sanitize callbacks
```

```php
// includes/class-nicepay-api.php:23
$this->is_test_mode = ( get_option( 'nicepay_mode', 'test' ) === 'test' );
// :29-32
} else {
    $this->mid          = get_option( 'nicepay_live_mid', '' );
    $this->merchant_key = get_option( 'nicepay_live_merchant_key', '' );
}
```

**Impact.** The fail-open direction is backwards for a payment gateway: a corrupted or unexpected `nicepay_mode` value silently promotes the plugin to Live, where it reads empty live credentials and `is_available()` disables the WooCommerce gateway with no explanation — while the standalone shortcode still renders a form that posts an empty MID. Note the default only applies when the option **row** is absent: an option stored as `''` returns `''`, which is not `'test'`, so it goes Live. Unvalidated MID/charset/language values likewise flow directly into the NICEPAY request body, and `register_setting()` without sanitization is a standard WordPress.org review rejection reason.

**Verifier note.** Reaching the bad state requires DB corruption or a non-UI write (the admin select only offers test/live), which is why medium rather than higher.

**Recommendation.** Add allowlist callbacks to every registered setting:

| Setting | Sanitizer |
|---|---|
| `nicepay_mode` | `in_array( $v, array( 'test', 'live' ), true ) ? $v : 'test'` |
| `nicepay_language` | allowlist `KO`, `EN`, `CN` |
| `nicepay_currency` | allowlist `KRW`, `USD` |
| `nicepay_charset` | allowlist `utf-8`, `euc-kr` |
| `nicepay_*_mid` | `substr( preg_replace( '/[^A-Za-z0-9]/', '', $v ), 0, 10 )` (spec: 10-byte MID) |
| `nicepay_*_merchant_key` | `sanitize_text_field` + base64 shape check with `add_settings_error()` on failure |

Invert the mode test so live requires explicit opt-in:

```php
$this->is_test_mode = ( get_option( 'nicepay_mode', 'test' ) !== 'live' );
```

---

#### PLATFORM-23 · Transaction amounts in the admin list are formatted with the plugin's global currency because the table has no currency column

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [admin/class-nicepay-transactions.php:133](../../admin/class-nicepay-transactions.php#L133), [includes/nicepay-functions.php:229-239](../../includes/nicepay-functions.php#L229), [nicepay-payment-gateway.php:110-144](../../nicepay-payment-gateway.php#L110)

**Problem.** The Amount column calls `nicepay_format_amount( $item->amount )` with no currency argument, so the helper falls back to `get_option( 'nicepay_currency', 'KRW' )` — a global plugin setting unrelated to the transaction. The root cause is that the schema has **no `currency` column at all**: it stores `amount decimal(12,2)` and nothing else, so the currency of a completed transaction is simply not recorded. For KRW the helper also does `number_format( (int) $amount )`, truncating any fractional part.

**Evidence.**

```php
// admin/class-nicepay-transactions.php:133 — one argument
<td class="nicepay-amount"><?php echo esc_html( nicepay_format_amount( $item->amount ) ); ?></td>
```

```php
// includes/nicepay-functions.php:229-238
function nicepay_format_amount( $amount, $currency = '' ) {
    if ( ! $currency ) { $currency = get_option( 'nicepay_currency', 'KRW' ); }
    if ( $currency === 'KRW' ) { return number_format( (int) $amount ) . ' ' . $currency; }
    return number_format( (float) $amount, 2 ) . ' ' . $currency;
}
```

**Impact.** On a store taking both KRW and USD (the two currencies NICEPAY supports), every row is labelled with whichever currency the global option holds — so a $49.00 USD payment renders as "49 KRW": integer-truncated, wrong symbol, off by three orders of magnitude. This is the merchant's reconciliation screen. The inconsistency is more confusing because the shortcode cards on the same admin pass a per-shortcode currency ([admin/class-nicepay-admin.php:396](../../admin/class-nicepay-admin.php#L396)) and are therefore correct.

**Recommendation.** Add `currency varchar(3) NOT NULL DEFAULT 'KRW'` to the schema — **behind the PLATFORM-09 migration fix, which must land first or the column can never reach existing installs** — populate it in `nicepay_save_transaction()` from `$order->get_currency()` or the shortcode attribute, and render with `nicepay_format_amount( $item->amount, $item->currency )`. Until the column exists, derive it per row from the linked order the same way `ajax_cancel_transaction()` already does at [admin/class-nicepay-transactions.php:198-205](../../admin/class-nicepay-transactions.php#L198). Stop integer-truncating: use `wc_price( $amount, array( 'currency' => $currency ) )` when WooCommerce is present.

---

#### PLATFORM-24 · Every receipt-page render mints a new Moid and inserts another orphan `pending` row

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** medium · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-gateway.php:121-177](../../includes/class-nicepay-gateway.php#L121), [nicepay-payment-gateway.php:142](../../nicepay-payment-gateway.php#L142)

**Problem.** `generate_payment_form()` mints a fresh Moid and inserts a brand-new transaction row on every render of the receipt page, and overwrites the order's `_nicepay_moid` meta each time. A customer who lands on the pay page, hesitates, refreshes, or navigates back and forward produces one `pending` row per view, all tied to the same order. Nothing ever reconciles or removes them — `grep -rn "wp_schedule_event\|wp_next_scheduled"` returns no hits and there is no status sweep.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:122-123 — unconditional on every receipt render
$edi_date = $this->api->generate_edi_date();
$moid     = $this->api->generate_moid( 'WC' . $order->get_id() );
// :153-155
$order->update_meta_data( '_nicepay_moid', $moid );
$order->save();
// :158-168
$tx_id = nicepay_save_transaction( array( 'order_id' => $moid, 'wc_order_id' => $order->get_id(), … 'status' => 'pending', … ) );
```

```sql
-- nicepay-payment-gateway.php:142 — a plain index, not UNIQUE
KEY idx_moid (moid),
```

**Impact.** The transactions table — the merchant's reconciliation surface — fills with abandoned `pending` rows that outnumber real payments, permanently. With the default 20-per-page list, no sorting and no "hide pending" view (PLATFORM-25), finding an actual transaction becomes a search exercise, and the table grows unboundedly on a busy store. As a secondary effect, `_nicepay_moid` always holds the **last** generated Moid, which is what `process_refund()` sends as the cancel Moid.

**Verifier note.** One bound that caps the blast radius: WooCommerce's `WC_Shortcode_Checkout::order_pay()` only fires `woocommerce_receipt_{gateway}` when `$order->needs_payment()` is true, so a **paid** order re-visiting the pay URL does not generate another row (and cannot be double-charged). Accumulation is limited to pre-payment page views — still unbounded for abandoned checkouts.

**Recommendation.** Reuse rather than re-create: before inserting, look for an existing `pending` row for this `wc_order_id` and update it in place — the Moid must change per attempt for NICEPAY, but the row need not. Add a daily `wp_schedule_event` that marks `pending` rows older than a configurable window (24h) as `abandoned` and prunes beyond a retention period; clear the schedule on deactivation. Add a status view filter and default the list to hiding abandoned rows.

---

#### PLATFORM-25 · Transactions screen is hand-rolled instead of `WP_List_Table` — no sorting, screen options, bulk actions, views or export

**Severity:** 🟡 Medium · **Category:** ux-clarity · **Effort:** medium · **Verdict:** 🟣 **PLAUSIBLE — needs confirmation**
**Location:** [admin/class-nicepay-transactions.php:16-178](../../admin/class-nicepay-transactions.php#L16), [includes/nicepay-functions.php:142-220](../../includes/nicepay-functions.php#L142)

**Problem.** The list is a manually assembled `<table class="wp-list-table widefat fixed striped">` with a hardcoded `$per_page = 20`, a bespoke filter form and `paginate_links()`. It borrows WordPress's list-table CSS classes without any of the behaviour those classes imply. The data layer already supports what is missing — `nicepay_get_transactions()` accepts `orderby` (with a proper allowlist) and `order` — but the UI never exposes them, so the sorting capability is dead code.

**Evidence.**

```php
// admin/class-nicepay-transactions.php:21
$per_page = 20;   // hardcoded
// :81-93 — a hand-written <thead> with nine plain <th> elements and no sort links
// :37
$total_pages = ceil( $total / $per_page );
```

```php
// includes/nicepay-functions.php:195-197 — sorting IS implemented server-side, unreachable from the UI
$allowed_orderby = array( 'created_at', 'amount', 'status', 'payment_method' );
$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
```

**Impact.** Merchants get a table that looks like a WordPress list table and behaves like none of them: column headers are not clickable, there is no Screen Options to change page size or hide columns, no status views with counts (All | Paid | Pending | Failed), no bulk actions, no row actions on hover, and no CSV export for accounting — which for a payments log is the single most requested feature. The filter bar also has no reset affordance and no indication of how many filters are active.

**Verification status.** Every code observation is exact — the hardcoded per-page, the nine unsortable headers, and the unreachable server-side sorting allowlist all exist as described. **Kept PLAUSIBLE because the conclusion ("should be `WP_List_Table`") is a design judgement, not a defect: the screen renders and functions correctly as written.**

**Recommendation.** Extend `WP_List_Table`: implement `get_columns()`, `get_sortable_columns()` wired to the existing `orderby` allowlist, `get_views()` for status counts, `prepare_items()` calling `nicepay_get_transactions()`, `column_default()`/`column_{name}()`, and `search_box()`. Register `add_screen_option( 'per_page', … )` on the submenu's `load-` hook. Add a nonce-protected CSV export streaming the filtered result set. Keep the existing empty-state illustration — `no_items()` can render it.

---

#### PLATFORM-26 · All logging is suppressed unless `WP_DEBUG` is on, and everything is emitted at debug level

**Severity:** 🟡 Medium · **Category:** dx · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [includes/nicepay-functions.php:16-35](../../includes/nicepay-functions.php#L16)

**Problem.** `nicepay_log()` opens with an early return unless `WP_DEBUG` is truthy — every call in the plugin is a no-op on a normally configured production site, including the calls that matter most. When it does run, everything is written at `debug` level, and it falls back to raw `error_log()` when WooCommerce is absent. There is no "Enable logging" setting in `init_form_fields()`, which every mainstream WooCommerce gateway ships.

**Evidence.**

```php
// includes/nicepay-functions.php:16-19
function nicepay_log( $message, $data = null ) {
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
        return;
    }
// :30-34 — one level for everything
if ( function_exists( 'wc_get_logger' ) ) {
    wc_get_logger()->debug( $log_entry, array( 'source' => 'nicepay' ) );
} else {
    error_log( $log_entry );
}
```

Callers silenced in production include [class-nicepay-api.php:187](../../includes/class-nicepay-api.php#L187) ("Approval URL validation failed"), [:223](../../includes/class-nicepay-api.php#L223) ("Approval request failed"), [:260](../../includes/class-nicepay-api.php#L260) ("Approval signature verification failed"), [:388](../../includes/class-nicepay-api.php#L388) ("Cancel response signature verification failed") and [class-nicepay-gateway.php:276](../../includes/class-nicepay-gateway.php#L276) ("Auth signature invalid").

**Impact.** When a payment fails on a live store — the moment diagnostics are worth most — there is nothing to look at. The merchant sees a failed order with a terse note; support has no request/response trail; reproducing requires flipping `WP_DEBUG` on production and waiting for an intermittent PG issue to recur. Even with `WP_DEBUG` on, everything lands at `debug` level, so a WooCommerce log-level filter set to `info` or above hides it all.

**Recommendation.** Split severity from verbosity. Always log errors and warnings through `wc_get_logger()` at `error`/`warning` level (signature failures, approval failures, net-cancel invocations, URL rejections) regardless of `WP_DEBUG` — the redaction helper at [includes/class-nicepay-api.php:157-174](../../includes/class-nicepay-api.php#L157) already makes this safe. Gate only `debug`/`info` chatter behind an explicit "Enable logging" checkbox in `init_form_fields()`, defaulting off, exactly as core gateways do, labelled with a link to WooCommerce → Status → Logs. Drop the `error_log()` fallback.

---

#### PLATFORM-27 · The shortcode's 190-line inline script is re-emitted per instance, duplicating a document-level listener

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** medium · **Verdict:** CONFIRMED
**Location:** [templates/standalone-payment-form.php:174-367](../../templates/standalone-payment-form.php#L174), [admin/class-nicepay-admin.php:675-929](../../admin/class-nicepay-admin.php#L675)

**Problem.** `templates/standalone-payment-form.php` closes with a raw `<script>` block containing an IIFE that registers a document-level `change` listener, plus the globals `nicepayStartStandalone`, `showStandaloneError`, `nicepayOpenPaymentModal`, `nicepayClosePaymentModal` and the `_nicepayModalEscHandlers` registry. The template is included once per shortcode occurrence, so two `[nicepay_payment]` shortcodes on one page emit the whole block twice: the function declarations are harmlessly redefined, but the `document.addEventListener('change', …)` is added twice. Only `window.nicepaySubmit` and `window.nicepayClose` are guarded (`= window.nicepaySubmit || …`); the listener is not. The admin shortcode generator has the same shape — ~250 lines of jQuery inlined into a PHP method.

**Evidence.**

```js
// templates/standalone-payment-form.php:176-189 — unguarded, emitted once per shortcode
(function() {
  document.addEventListener('change', function(e) {
    if (e.target.type === 'radio' && e.target.name && e.target.name.indexOf('nicepay_method_') === 0) { … }
  });
})();
```

```js
// :319-329 — the author guarded two globals but not the listener
window.nicepaySubmit = window.nicepaySubmit || function() {…};
```

**Impact.** Duplicate handlers on a page with two payment buttons (a three-tier pricing page is the obvious case). More broadly: inline script cannot be minified, cached or deferred, and breaks any `script-src` Content-Security-Policy that does not allow `unsafe-inline`. The docs already flag CSP as a troubleshooting item ([docs/CONFIGURATION.md:455](../CONFIGURATION.md) — *"Check for Content Security Policy (CSP) headers blocking external scripts"*), which suggests merchants hit this.

**Verifier note.** The duplicate listener is genuinely harmless today — it calls `classList.remove`/`classList.add`, which are idempotent — so the actionable half of this finding is the CSP/maintainability half. (Line-range correction from the original: the admin script block runs 675–929, not 675–905.)

**Recommendation.** Move the shortcode script into `assets/js/nicepay-standalone.js`, enqueue it once from `enqueue_payment_assets()`, and pass per-instance configuration with:

```php
wp_add_inline_script(
    'nicepay-standalone',
    'window.nicepayForms = window.nicepayForms || {}; window.nicepayForms['
        . wp_json_encode( $form_id ) . '] = ' . wp_json_encode( $config ) . ';',
    'before'
);
```

Bind handlers with a single delegated listener registered at file load. Do the same for the admin generator: move it to `assets/js/nicepay-shortcode-builder.js` and pass the icons, labels, i18n strings and `$edit_data` through `wp_localize_script`. That removes the CSP problem, the duplication, and the PHP-in-JS interpolation in one pass.

---

#### PLATFORM-28 · No template override mechanism

**Severity:** 🟡 Medium · **Category:** dx · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-gateway.php:210](../../includes/class-nicepay-gateway.php#L210), [nicepay-payment-gateway.php:261-263](../../nicepay-payment-gateway.php#L261)

**Problem.** Both templates are loaded with a bare `include`. `grep -rn "wc_get_template\|locate_template" .` returns zero hits. WooCommerce's convention — which merchants, agencies and theme authors all expect — is `wc_get_template( 'payment-form.php', $args, 'nicepay/', NICEPAY_PLUGIN_DIR . 'templates/' )`, which resolves overrides from `yourtheme/nicepay/payment-form.php` before falling back to the plugin. The docs advertise a `nicepay_payment_form_template` filter to fill this gap; that filter does not exist (PLATFORM-15).

**Evidence.**

```php
// includes/class-nicepay-gateway.php:210
include NICEPAY_PLUGIN_DIR . 'templates/payment-form.php';

// nicepay-payment-gateway.php:261-263
ob_start();
include NICEPAY_PLUGIN_DIR . 'templates/standalone-payment-form.php';
return ob_get_clean();
```

`templates/payment-form.php:5-7` documents its dependency on `$order`, `$form_data`, `$enabled_methods` in a docblock but nothing enforces it.

**Impact.** Any visual customisation of the checkout payment form — the most customised surface in a WooCommerce store — requires editing plugin files, which are overwritten on every update. The plugin's own documentation sends developers down a path that does not work, so the first customisation attempt fails silently.

**Recommendation.** Route both includes through `wc_get_template()` with the template path `nicepay/` when WooCommerce is present, and through `locate_template()` plus a filter fallback for the standalone shortcode on non-WooCommerce sites. Pass template variables explicitly via the `$args` array instead of relying on variables leaking from the enclosing scope. Add a template-version header comment to each template so WooCommerce → Status can report outdated overrides.

---

#### PLATFORM-29 · Refunds reuse the payment's Moid as the cancel order number and never capture OTID for repeat partial cancels

**Severity:** 🟡 Medium · **Category:** spec-conformance · **Effort:** medium · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-gateway.php:418-474](../../includes/class-nicepay-gateway.php#L418), [includes/class-nicepay-api.php:337-394](../../includes/class-nicepay-api.php#L337)
**Spec:** §9 (8.3) `Moid(64 — merchant-issued unique CANCEL order no)`; §9 (8.4) `OTID(30 — returned for mobile partial cancel/refund; 2nd+ partial cancels MUST use OTID)`; §12.2 partial-cancel cautions.

**Problem.** `process_refund()` always passes the original payment TID and the original payment `_nicepay_moid` to `request_cancel()`. The spec requires two things this violates: `Moid` on a cancel must be a merchant-issued **unique cancel** order number, not the payment's Moid; and for mobile partial cancels the response returns an `OTID` which MUST be used for the second and subsequent partial cancels. The plugin captures neither.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:425-445 — the payment's own Moid is reused verbatim
$tid  = $order->get_meta( '_nicepay_tid' );
$moid = $order->get_meta( '_nicepay_moid' );
$result = $this->api->request_cancel( $tid, $cancel_amt, $reason, $moid, $is_partial );

// includes/class-nicepay-api.php:340-350 — no OTID anywhere
'TID' => $tid, 'MID' => $this->mid, 'Moid' => $moid, 'CancelAmt' => …
// :385-391 — the response handler reads only TID, Signature and CancelAmt
```

**Impact.** WooCommerce's order screen lets a merchant issue multiple partial refunds, which is normal practice. The first succeeds; the second fails with a PG-side error the merchant cannot interpret, and `process_refund()` returns `new WP_Error( 'nicepay_refund_error', $error_msg )` carrying the raw Korean message. Reusing the payment Moid as the cancel Moid also risks duplicate-order-number rejection on the very first refund for MIDs that enforce uniqueness. On top of that the refund flow writes no order note on failure and stores no cancel TID, so there is no audit trail of attempted refunds.

**Verifier note.** The same defect exists in the admin cancel path: [admin/class-nicepay-transactions.php:208](../../admin/class-nicepay-transactions.php#L208) `$api->request_cancel( $tid, $cancel_amount, $reason, $transaction->moid );` also reuses the payment Moid.

**Recommendation.** Generate a unique cancel Moid per attempt (`$this->api->generate_moid( 'CX' . $order->get_id() )`) rather than reusing `_nicepay_moid`. Capture `OTID` from the cancel response and store it as `_nicepay_otid` order meta plus a transactions-table column; on subsequent partial cancels send the stored OTID in place of the TID per spec §9 — `request_cancel()` already accepts an `$extra_params` array that nothing currently uses, so the plumbing exists. Record every refund attempt, success and failure, as an order note including the returned ResultCode/ResultMsg. Surface the spec §12.2 restriction (simple-pay services NaverPay/KakaoPay/PAYCO/SSGPay/SKPay/ApplePay/TossPay cannot be reverted after a partial cancel) as a warning in the refund UI.

---

#### PLATFORM-30 · Settings are split across two unrelated screens with a duplicated, inert Currency setting

**Severity:** 🟡 Medium · **Category:** ux-clarity · **Effort:** medium · **Verdict:** 🟣 **PLAUSIBLE — needs confirmation**
**Location:** [includes/class-nicepay-gateway.php:24-31](../../includes/class-nicepay-gateway.php#L24), [:39-71](../../includes/class-nicepay-gateway.php#L39), [:149](../../includes/class-nicepay-gateway.php#L149), [admin/class-nicepay-admin.php:98-122](../../admin/class-nicepay-admin.php#L98), [nicepay-payment-gateway.php:150-163](../../nicepay-payment-gateway.php#L150)

**Problem.** Exact storage map, verified:

| Store | Settings |
|---|---|
| `woocommerce_nicepay_settings` (WC → Payments → NicePay) | `enabled`, `title`, `description` |
| standalone `wp_options` (NicePay → Settings) | `nicepay_mode`, `nicepay_test_mid`, `nicepay_test_merchant_key`, `nicepay_live_mid`, `nicepay_live_merchant_key`, `nicepay_enabled_methods`, `nicepay_language`, `nicepay_currency`, `nicepay_vbank_expiry_days`, `nicepay_charset`, `nicepay_db_version`, `nicepay_saved_shortcodes` |

Two screens, two Save buttons, two mental models. `nicepay_currency` duplicates WooCommerce's store currency and is **ignored** for WooCommerce orders ([class-nicepay-gateway.php:149](../../includes/class-nicepay-gateway.php#L149) uses `$order->get_currency()`), and `nicepay_language` duplicates the site locale rather than deriving from it. The WooCommerce screen links to NicePay Settings but NicePay Settings never links back, and neither screen reports whether the other half is configured.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:61-69 — one-way link
'Configure MID, Merchant Key, and other API settings on the <a href="%s">NicePay Settings</a> page.'
// :149 — the plugin's own nicepay_currency option is bypassed for WooCommerce orders
$currency = $order->get_currency();
```

**Impact.** The most common misconfiguration — credentials on the Test tab while the mode is Live, or the gateway never enabled in WooCommerce — is invisible from whichever screen the merchant happens to be on. The redundant Currency dropdown actively misleads: a merchant selling in USD sets it to USD, sees no effect on WooCommerce orders, and has no way to know the setting is inert for that path.

**Verification status.** The full storage map and both concrete factual claims (inert `nicepay_currency`, one-way link) are verified exactly. **Kept PLAUSIBLE because the core assertion — that a split configuration surface is bad design — is a judgement about information architecture, not a defect; the settings all save and read correctly.**

**Recommendation.** Pick one home and make the other a pointer. Keep the rich NicePay admin as the single configuration surface, reduce the WooCommerce gateway form to enabled/title/description plus a prominent "Configure NicePay →" button, and add a reciprocal status panel to the NicePay Settings header showing: gateway enabled yes/no (read `woocommerce_nicepay_settings['enabled']`), active mode, whether that mode's credentials are present, store currency vs supported currencies, and whether the return endpoint resolves. Relabel `nicepay_currency` unambiguously as "Default currency for shortcode payments" or delete it, and derive `nicepay_language` from `get_locale()` by default with an explicit override.

---

#### PLATFORM-31 · Not ready for the WordPress.org directory

**Severity:** 🟡 Medium · **Category:** spec-conformance · **Effort:** medium · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:1-16](../../nicepay-payment-gateway.php#L1), [.github/workflows/release.yml:38-62](../../.github/workflows/release.yml#L38)

**Problem.** Several directory-readiness items are missing at once:

| # | Gap | Detail |
|---|---|---|
| a | No `readme.txt` | Only README.md, so no `Stable tag`, `Tested up to`, `Requires at least`, changelog section, screenshots or FAQ in the format the directory and several update mechanisms parse |
| b | `WC tested up to: 9.0` | Stale — WooCommerce prints "has not been tested with your version of WooCommerce" on the plugins screen |
| c | Slug leads with a trademark | `nicepay-…` |
| d | No `Update URI:` | A same-named plugin appearing on WordPress.org could hijack updates on every install |
| e | `License: MIT` with no `License URI:` | Header incomplete |
| f | Release workflow never writes the tag version | A `v2.1.0` tag ships a plugin reporting 2.0.0 |

**Evidence.** The header at lines 1-16 carries `Version: 2.0.0`, `Requires at least: 5.0`, `WC requires at least: 5.0`, `WC tested up to: 9.0`, `License: MIT` — and no License URI, no Update URI, no Requires Plugins. Repo-root `ls -la` shows README.md and no readme.txt. `release.yml:38-62` does `cp nicepay-payment-gateway.php build/${PLUGIN_SLUG}/` then `zip -r ../${PLUGIN_SLUG}-${VERSION}.zip ${PLUGIN_SLUG}/` with no step that rewrites any version string.

**Impact.** The plugin cannot be submitted to WordPress.org as-is (readme.txt and the slug are hard blockers), and distributed outside it, it has no safe update channel and a header version that can silently disagree with the release tag — which would break the `nicepay_db_version` migration gate PLATFORM-09 recommends adding, since that gate depends on the constant being correct. Merchants also see a WooCommerce compatibility warning on the plugins screen, which reads as abandonment.

**Recommendation.** See §3 below for the full gap list. In brief: add `readme.txt` and screenshots; bump `WC tested up to` each release and check it in CI; rename the slug so the trademark is not leading (e.g. `payment-gateway-for-nicepay`) and obtain written permission from NICE Payments; add `Update URI:` and `License URI:`; and `sed` the tag version into both the `Version:` header and `NICEPAY_VERSION` in `release.yml` before zipping, failing the build if CHANGELOG.md has no entry for the tag.

---

#### PLATFORM-32 · Failure-path error messages rely on `wc_add_notice()`, which cannot survive the cross-site return POST on mobile

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED *(added during verification; one link unverified — see note)*
**Location:** [includes/class-nicepay-gateway.php:265-272](../../includes/class-nicepay-gateway.php#L265), [:286-288](../../includes/class-nicepay-gateway.php#L286), [:314-316](../../includes/class-nicepay-gateway.php#L314), [:406-411](../../includes/class-nicepay-gateway.php#L406)

**Problem.** All four failure branches of `handle_return()` call `wc_add_notice( … , 'error' )` and then `wp_safe_redirect( wc_get_checkout_url() )`. `wc_add_notice()` stores the message in the WooCommerce session, keyed by the `wp_woocommerce_session_*` cookie. Per NICEPAY spec §12, the mobile flow POSTs the auth result as a form **directly from NICEPAY's domain** to the merchant's ReturnURL — a cross-site POST. Neither WordPress nor WooCommerce sets an explicit `SameSite` attribute on its cookies (`wc_setcookie()` passes only expires/secure/path/domain/httponly), so browsers apply their `SameSite=Lax` default and withhold the session cookie on that request. `handle_return()` therefore runs against a brand-new, empty session, and the notice is written into a session the buyer's browser will never present.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:265-271 (identical shape at :286-288, :314-316, :406-411)
wc_add_notice( __( 'Payment authentication failed. Please try again.', … ) . ' (' . $auth_result_msg . ')', 'error' );
wp_safe_redirect( wc_get_checkout_url() );
exit;

// :391 — the SUCCESS branch is session-independent (the order-received URL carries the order key)
wp_safe_redirect( $this->get_return_url( $order ) );
```

Spec §12: *"Mobile: data is POSTed as form (name=value) to the ReturnURL endpoint."*

**Impact.** On mobile — the dominant channel in the Korean market — a buyer whose payment fails at authentication, signature verification, or approval is redirected to the checkout page with **no explanation at all**: no error banner, and a cart that appears empty because the original session was not presented. They are left staring at a blank checkout after a failed payment. The PC flow is unaffected, because there `nicepaySubmit()` submits `document.payForm` from the merchant's own page, making the POST same-site and preserving the session.

**Verifier note.** The code shape is fully verified: four session-dependent failure paths, a session-independent success path, and a mobile flow the spec confirms is a cross-domain POST. The one link that could not be executed is the browser cookie behaviour itself — `wc_setcookie()`'s signature confirms no SameSite attribute is set, and modern Chrome/Edge treat an absent attribute as Lax, but there was no running install to observe it. **Worth fixing regardless**, because redirecting a failed payment to `wc_get_checkout_url()` instead of the order's own pay URL is the wrong destination even when the session survives.

**Recommendation.** Do not depend on the session for cross-site returns:

```php
wp_safe_redirect( add_query_arg(
    array( 'nicepay_error' => rawurlencode( $result_code ) ),
    $order->get_checkout_payment_url()
) );
```

Render the message from that parameter via a `woocommerce_before_checkout_form` hook, mapping codes to friendly text. Redirect to `$order->get_checkout_payment_url()` (which authenticates by order key, not session) rather than `wc_get_checkout_url()`, so the buyer lands on a page that still knows what they were buying and can retry. Record the failure reason as an order note as well.

---

#### PLATFORM-33 · `nicepay_init_payment` is an unauthenticated, unthrottled row-insert endpoint

**Severity:** 🟡 Medium · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED *(added during verification)*
**Location:** [nicepay-payment-gateway.php:81-82](../../nicepay-payment-gateway.php#L81), [:313-359](../../nicepay-payment-gateway.php#L313)

**Problem.** `ajax_init_payment()` is registered for both `wp_ajax_` and `wp_ajax_nopriv_`, and beyond a nonce check it applies no capability check, no rate limit, no per-IP or per-session cap, and no bound on how many transaction rows one visitor may create. Each call performs an unconditional `$wpdb->insert()` with attacker-supplied `amount`, `goods_name`, `buyer_name`, `buyer_email` and `buyer_tel` (a `longtext` `payment_data` column is also present and writable on later update). The nonce is not a barrier: it is rendered into the public page HTML for every anonymous visitor and is valid for up to 24 hours, so it can be scraped once and reused in a loop.

**Evidence.**

```php
// nicepay-payment-gateway.php:81-82
add_action( 'wp_ajax_nicepay_init_payment',        array( $this, 'ajax_init_payment' ) );
add_action( 'wp_ajax_nopriv_nicepay_init_payment', array( $this, 'ajax_init_payment' ) );

// :314-320 — the only gate
wp_verify_nonce( …, 'nicepay_init_payment' )

// :338-347 — no throttle in between
$tx_id = nicepay_save_transaction( array(
    'order_id' => $moid, 'moid' => $moid, 'amount' => $amount,
    'status' => 'pending', 'buyer_name' => $buyer_name, …
) );
```

`grep -rn "wp_schedule_event\|wp_next_scheduled" --include='*.php' .` → no output, confirming nothing ever prunes the rows.

**Impact.** Any anonymous visitor to a page carrying the shortcode can insert unlimited rows into the transactions table with a trivial script. This grows the database without bound, floods the merchant's only reconciliation surface with junk that has no distinguishing marker, and provides a cheap write-amplification denial-of-service against the site's database. It compounds PLATFORM-24, which already leaves the table with no cleanup path.

**Recommendation.** Add a cheap throttle before the insert: a transient keyed on the client IP (`'nicepay_rl_' . md5( $ip )`) permitting N initialisations per minute, returning a distinct error code the JS can surface. Only create the transaction row at the point it is actually needed — or better, do not create it here at all: return the EdiDate/Moid/SignData and let the return handler create the row from the verified Moid, which removes the anonymous write entirely. Add the scheduled cleanup from PLATFORM-24, and mark rows created by this endpoint so they can be pruned aggressively.

---

### 🔵 LOW

---

#### PLATFORM-34 · Deactivation flush re-persists the rewrite rule, and no flush ever happens on plugin update

**Severity:** 🔵 Low · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:92-102](../../nicepay-payment-gateway.php#L92), [:202-212](../../nicepay-payment-gateway.php#L202)

**Problem.** Two rewrite-lifecycle defects. (1) `deactivate()` calls `flush_rewrite_rules()` while the plugin is still loaded for that request — plugin deactivation is processed in `wp-admin/plugins.php` after `admin_init`, so `init` has already fired, `register_endpoints()` has already run, and the rule is present in `$wp_rewrite->extra_rules_top`; the flush therefore regenerates it straight back into the `rewrite_rules` option, exactly inverting the intent. (2) `flush_rewrite_rules()` only ever runs on activation — a plugin **update** does not re-run the activation hook, so if a future version changes the rule pattern, every existing install keeps the stale rule and the return URL breaks with no signal.

**Evidence.**

```php
// nicepay-payment-gateway.php:100-102
public function deactivate() { flush_rewrite_rules(); }
// :92-98 — the only flush-on-write
public function activate() {
    $this->create_tables();
    $this->set_default_options();
    $this->register_endpoints();
    flush_rewrite_rules();
}
```

**Impact.** (1) Leaves a dead rewrite rule pointing at a query var nothing handles on every site that has ever deactivated the plugin — and it means "deactivate and reactivate" (the universal first support step) does not actually reset routing state. (2) Is the real risk: the endpoint URL is on the critical payment path and the plugin has no mechanism to notice or repair a rules mismatch after an update, which combined with PLATFORM-05 leaves the return endpoint with two independent silent-failure modes and zero self-diagnosis.

**Verifier note — one sub-claim REFUTED.** The original review's third point — that `register_endpoints()` "is called both on init and during activation and so adds a duplicate closure" — does **not** hold. During the activation request the plugin file is loaded by `activate_plugin()` **after** `init` has already fired, so the init-hooked call never runs in that request; the direct call from `activate()` is the only one. And even if it did double-register, appending `'nicepay_return'` to `$vars` twice is harmless. Severity downgraded medium → low: neither remaining defect has any observable effect today.

**Recommendation.** (1) Defer the deactivation flush: call `add_action( 'shutdown', 'flush_rewrite_rules' );` from `deactivate()`, so the rule is gone from the in-memory rule set before the flush writes. (2) Add a rewrite version gate: store `nicepay_rewrite_version`, compare it on `init` after `register_endpoints()`, and call `flush_rewrite_rules()` plus `update_option()` on mismatch. That makes updates self-healing and lets you retire the manual "Settings → Permalinks → Save" instruction that appears five times across the docs (USER-GUIDE.md:64, :88, :668; CONFIGURATION.md:44, :473).

---

#### PLATFORM-35 · Default shortcode presets are translated at activation time and frozen into the database

**Severity:** 🔵 Low · **Category:** i18n · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:162](../../nicepay-payment-gateway.php#L162), [includes/nicepay-functions.php:325-405](../../includes/nicepay-functions.php#L325), [:428-435](../../includes/nicepay-functions.php#L428)

**Problem.** `nicepay_get_default_presets()` builds its preset array with live `__()` calls for `name`, `goods_name` and `button_text` ("Quick Payment", "Donation", "Buy Now", "Monthly Subscription", "Subscribe", "Donate", …), and the result is written verbatim into the `nicepay_saved_shortcodes` option — once at activation and again lazily from `nicepay_get_all_shortcodes()` if the option is missing. Translated strings become permanent stored data in whatever locale the activating administrator happened to be using.

**Evidence.**

```php
// nicepay-payment-gateway.php:162 — no autoload argument either
add_option( 'nicepay_saved_shortcodes', nicepay_get_default_presets() );

// includes/nicepay-functions.php:330-333 (same pattern for all four presets, lines 328-403)
'name'       => __( 'Quick Payment', 'nicepay-payment-gateway' ),
'goods_name' => __( 'Quick Payment', 'nicepay-payment-gateway' ),

// :428-435 — a write inside a getter, reached from the shortcode render path
function nicepay_get_all_shortcodes() {
    $shortcodes = get_option( 'nicepay_saved_shortcodes', null );
    if ( $shortcodes === null ) {
        $shortcodes = nicepay_get_default_presets();
        update_option( 'nicepay_saved_shortcodes', $shortcodes );
    }
    return $shortcodes;
}
```

**Impact.** On a multilingual store the preset shortcodes are stuck in one language forever: a Korean admin activates the plugin, and English or Turkish visitors see Korean text as the product name and button label on the front end, with no fix except editing each preset by hand. Switching the site locale changes nothing. It also poisons the data model — the `goods_name` transmitted to NICEPAY (and therefore appearing on the buyer's card statement) is a translated UI string rather than merchant-authored content. Additionally, `nicepay_get_all_shortcodes()` performs an `update_option()` write on a front-end read path.

**Verifier note.** Severity downgraded medium → low: these are sample/demo presets the merchant is expected to edit or replace, and nothing malfunctions.

**Recommendation.** Store locale-independent preset keys and translate at render time: keep `'name_key' => 'quick_payment'` in the option and resolve through a `nicepay_preset_label( $key )` helper. For `goods_name` — transmitted to NICEPAY — do not translate at all; seed a neutral value and require the merchant to author it. Remove the `update_option()` from `nicepay_get_all_shortcodes()`; return the defaults without persisting. Pass `'no'` as the autoload argument: `add_option( 'nicepay_saved_shortcodes', …, '', 'no' )` — it is a potentially large array loaded on every request today.

---

#### PLATFORM-36 · Shortcode-triggered CSS is enqueued after `wp_head`, causing a flash of unstyled payment form

**Severity:** 🔵 Low · **Category:** ux-clarity · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:225-226](../../nicepay-payment-gateway.php#L225), [:269-296](../../nicepay-payment-gateway.php#L269), [:462-468](../../nicepay-payment-gateway.php#L462)

**Problem.** `render_payment_shortcode()` calls `enqueue_payment_assets()` at shortcode-render time, which happens during `the_content` — after `wp_enqueue_scripts` and after `wp_head()` has already printed the stylesheet queue. WordPress prints such late-registered styles in the footer instead. The `is_payment_page()` pre-check that would have caught this earlier only inspects `$post->post_content` via `has_shortcode()`, so it misses shortcodes inside widgets, reusable blocks, template parts, FSE templates, page-builder content and any theme-side `do_shortcode()` call.

**Evidence.**

```php
// nicepay-payment-gateway.php:225-226 — called during content rendering
public function render_payment_shortcode( $atts ) {
    $this->enqueue_payment_assets();
// :462-468 — post_content only
private function is_payment_page() {
    global $post;
    if ( $post && has_shortcode( $post->post_content, 'nicepay_payment' ) ) { return true; }
    return false;
}
```

**Impact.** On every page where the shortcode is not detectable from `post_content` — which on a block-theme or page-builder site is most of them — the payment form renders unstyled first and snaps into place when the footer stylesheet loads. A payment button that visibly jumps mid-render undermines the trust the checkout moment requires. Functionality is unaffected: the scripts are enqueued with `$in_footer = true` anyway, so only style ordering suffers.

**Verifier note.** Severity downgraded medium → low: nothing breaks. Note that "register (not enqueue) on `wp_enqueue_scripts`" is **insufficient** on its own — registering does not change print ordering for a style enqueued after `wp_head`.

**Recommendation.** Broaden detection: check `has_shortcode()` against post content **and** `has_block( 'core/shortcode' )`, and walk reusable-block/template-part content. The pragmatic option most plugins take is to enqueue the small CSS on all singular front-end views — `nicepay.css` is scoped entirely under `.nicepay-` selectors with no global element rules, so this is safe — and keep only the heavy third-party PG script conditional.

---

#### PLATFORM-37 · `mb_strcut()` is used on three code paths with no mbstring requirement declared or guarded

**Severity:** 🔵 Low · **Category:** correctness · **Effort:** trivial · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-gateway.php:143-144](../../includes/class-nicepay-gateway.php#L143), [nicepay-payment-gateway.php:346](../../nicepay-payment-gateway.php#L346), [templates/standalone-payment-form.php:47](../../templates/standalone-payment-form.php#L47), [composer.json:12-14](../../composer.json#L12)

**Problem.** All three goods-name truncations call `mb_strcut()`, which requires `ext-mbstring`. `composer.json` declares only `"php": ">=7.4"` — no `ext-mbstring`, no `ext-json` — and the plugin header declares only `Requires PHP: 7.4`. There is no `function_exists( 'mb_strcut' )` guard anywhere. The CI workflow installs mbstring explicitly (`extensions: mbstring, json, hash, curl`), so the test suite can never catch the missing-extension case.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:143-144
// Truncate goods name to 40 bytes (NicePay limit is byte-based)
$goods_name = mb_strcut( $goods_name, 0, 40, 'UTF-8' );
// nicepay-payment-gateway.php:346
'goods_name' => mb_strcut( $goods_name, 0, 40, 'UTF-8' ),
// templates/standalone-payment-form.php:47
$goods_name = mb_strcut( sanitize_text_field( $atts['goods_name'] ), 0, 40, 'UTF-8' );
```

**Impact.** On a host without mbstring the plugin fatals with "Call to undefined function mb_strcut()" at the exact moment a customer loads the receipt page or a shortcode renders. Neither the composer manifest nor the plugin header gives the host or merchant any warning.

**Verifier note.** Severity downgraded medium → low: mbstring is present on essentially every host that can run WordPress with WooCommerce (WooCommerce's own System Status expects it), so the realistic action item is the undeclared dependency rather than the fatal.

**Recommendation.** Declare the dependency honestly in `composer.json` (`"ext-mbstring": "*"`, `"ext-json": "*"`) and add a runtime guard: a helper `nicepay_truncate_bytes( $string, $bytes )` that uses `mb_strcut()` when available and otherwise falls back to a UTF-8-safe manual truncation (walk back from `substr()` until the byte sequence is valid). The requirement is a **byte** limit of 40 per spec §4, not a character limit, so a naive `substr()` fallback would split a multi-byte Korean character mid-sequence and corrupt GoodsName. Add an activation-time environment check that refuses to activate, or shows a persistent notice, when a required extension is absent.

---

#### PLATFORM-38 · Credentials and the shortcode array autoload on every request, and stored secrets are re-rendered into the settings HTML

**Severity:** 🔵 Low · **Category:** security · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [nicepay-payment-gateway.php:150-163](../../nicepay-payment-gateway.php#L150), [admin/class-nicepay-admin.php:278-280](../../admin/class-nicepay-admin.php#L278), [:297-299](../../admin/class-nicepay-admin.php#L297)

**Problem.** All twelve options are created with `add_option( $name, $value )` and no `$autoload` argument, so they are loaded into memory on every request — including `nicepay_live_merchant_key` and the potentially large `nicepay_saved_shortcodes` array. Separately, the API Credentials tab re-renders the stored merchant keys into the page as `value="…"` on `type="password"` inputs, so the live secret is present in the HTML source of the settings page on every load.

**Evidence.**

```php
// admin/class-nicepay-admin.php:297-299 (same pattern for the test key at :278-280)
<input type="password" name="nicepay_live_merchant_key" class="large-text"
       value="<?php echo esc_attr( get_option( 'nicepay_live_merchant_key' ) ); ?>" autocomplete="off">
```

**Impact.** Minor performance cost from autoloading the shortcode array on front-end requests. The credential handling is the substantive part: `type="password"` provides no protection against View Source, browser extensions, a screen-share, or an HTML capture by a caching or debugging plugin. Mature WooCommerce gateways never echo a stored secret back. The autoloaded secret also means the key sits in memory for every anonymous page view, widening the blast radius of any object-cache or memory-disclosure issue.

**Verifier note.** Severity low confirmed — reading the settings page already requires `manage_options`, so this widens exposure rather than creating it. (Line-number correction from the original review: live key at 297-299, test key at 278-280.)

**Recommendation.** Pass `'no'` as the autoload argument for `nicepay_saved_shortcodes`, `nicepay_live_merchant_key`, `nicepay_test_merchant_key` and `nicepay_db_version` (`add_option( $name, $value, '', 'no' )`), and re-write those options once to migrate existing installs. For the credential fields, render a masked placeholder (`value=""` with `placeholder="••••••••"` plus a "Key saved" indicator) and add a sanitize callback that keeps the stored value when the submitted field is empty, so saving the API tab does not blank the key. Optionally support defining credentials via `wp-config.php` constants (`NICEPAY_LIVE_MERCHANT_KEY`) so production secrets need never enter the database.

---

#### PLATFORM-39 · Superglobal, escaping and JSON-in-script hygiene gaps that a WPCS run would flag

**Severity:** 🔵 Low · **Category:** correctness · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-return-handler.php:26](../../includes/class-nicepay-return-handler.php#L26), [admin/class-nicepay-admin.php:324](../../admin/class-nicepay-admin.php#L324), [:520](../../admin/class-nicepay-admin.php#L520), [:762-765](../../admin/class-nicepay-admin.php#L762), [:902](../../admin/class-nicepay-admin.php#L902), [admin/class-nicepay-transactions.php:22](../../admin/class-nicepay-transactions.php#L22), [:37](../../admin/class-nicepay-transactions.php#L37), [:116](../../admin/class-nicepay-transactions.php#L116), [:148](../../admin/class-nicepay-transactions.php#L148), [templates/payment-form.php:39](../../templates/payment-form.php#L39), [templates/standalone-payment-form.php:86](../../templates/standalone-payment-form.php#L86)

**Problem.** A cluster of small standards issues:

| # | Issue | Location |
|---|---|---|
| a | `$_SERVER['REQUEST_METHOD']` read with no `isset`/`wp_unslash`/sanitisation | return-handler.php:26 |
| b | `$_GET['paged']` cast directly without `wp_unslash` | transactions.php:22 |
| c | `nicepay_get_method_icon()` SVG echoed raw, no `wp_kses` | admin:324, :520; payment-form.php:39; standalone:86 |
| d | Two `sprintf( __( … ) )` calls lack translator comments | transactions.php:116, :148 |
| e | `wp_json_encode()` interpolated into `<script>` without `JSON_HEX_TAG` | admin:762-765, :902 |
| f | `ceil()` returns a float handed to `paginate_links( 'total' => … )` | transactions.php:37 |

**Evidence.**

```php
// includes/class-nicepay-return-handler.php:26
if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {

// admin/class-nicepay-transactions.php:22 and :37
$current_page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$total_pages  = ceil( $total / $per_page );

// admin/class-nicepay-admin.php:762-765
$icon_json  = wp_json_encode( $icon_html );
$label_json = wp_json_encode( $label );
echo "methodsHtml += '<div class=\"nicepay-sc-pv-method-option\">' + {$icon_json} + ' ' + {$label_json} + '</div>';\n";
// :902
var d = <?php echo wp_json_encode( $edit_data ); ?>;
```

**Impact.** None of these is exploitable as written — the SVGs at [includes/nicepay-icons.php:20-66](../../includes/nicepay-icons.php#L20) are entirely static literals, the translations come from bundled files, and `$edit_data` is built from `sanitize_text_field()`-filtered option values (which runs `wp_strip_all_tags()` on any value containing `<`, so a `</script>` breakout is **not** reachable through the shortcode builder). But each is a standards violation a WordPress.org review or a WPCS gate will flag, and (e) remains a latent XSS shape if a translation file is ever attacker-influenced. Collectively they signal no static analysis is running: the CI lint job does `php -l` only.

**Recommendation.**

```php
// (a)
$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
// (b)
absint( wp_unslash( $_GET['paged'] ) )
// (c)
wp_kses( nicepay_get_method_icon( $code ), $svg_allowed )   // explicit SVG allowlist constant
// (d) /* translators: %s: transaction TID */ above both sprintf calls
// (e) wp_json_encode( $v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
// (f) (int) ceil( … )
```

Then add WPCS to CI:

```bash
composer require --dev wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer
```

with a `phpcs.xml` targeting the `WordPress` and `WordPress-Extra` rulesets and a `phpcs` step in `tests.yml` alongside `php -l`.

---

#### PLATFORM-40 · Standalone result page bypasses the theme with no cache headers, no HTTP status, and a single dead-end action

**Severity:** 🔵 Low · **Category:** ux-clarity · **Effort:** medium · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-return-handler.php:164-268](../../includes/class-nicepay-return-handler.php#L164)

**Problem.** `render_result_page()` emits a complete standalone HTML document with an inlined `<style>` block, no `wp_head()`/`wp_footer()`, no `nocache_headers()` and HTTP 200 even for failures (a repo-wide grep for `nocache_headers` and `status_header` returns zero hits). The only action offered is "Return to Home", identical on success and failure. For a failed payment there is no retry link back to the originating page; for a successful one there is no receipt, no reference the buyer can quote after navigating away, and no email.

**Evidence.**

```php
// includes/class-nicepay-return-handler.php:171-176 — no wp_head()
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <title><?php echo esc_html( $page_title ); ?></title>
// inline <style> at :177-204

// :259-263 — the only action
<div class="result-actions">
    <a href="<?php echo esc_url( home_url() ); ?>" class="result-btn"><?php esc_html_e( 'Return to Home', … ); ?></a>
</div>
```

**Impact.** The buyer is dropped out of the site's visual identity at the most trust-sensitive moment of the transaction — a generic white card with no logo, navigation or branding. On failure they are given no way back to what they were trying to buy, so the sale is lost rather than retried. The missing `nocache_headers()` risks an intermediary caching a payment result page. Returning 200 for failures also means monitoring and analytics cannot distinguish outcomes.

**Verifier note.** The three code facts are verified. The "render inside the theme" half is a design preference; the missing `nocache_headers()` on a payment-result page is the genuinely actionable item.

**Recommendation.** Send `nocache_headers()` and an appropriate `status_header()` before output. Render inside the theme where possible — hook the result into a merchant-selectable "Payment result" page setting, falling back to the standalone document only when none is configured. Add a "Try again" action on failure that returns to the referring page (store the origin URL on the transaction row at init time), plus a print/save-receipt affordance and an optional buyer-email receipt on success. Display the Moid alongside the TID so the buyer can quote a reference to support.

---

#### PLATFORM-41 · Order meta is written but never surfaced anywhere in the WooCommerce admin

**Severity:** 🔵 Low · **Category:** ux-clarity · **Effort:** small · **Verdict:** CONFIRMED
**Location:** [includes/class-nicepay-gateway.php:153-155](../../includes/class-nicepay-gateway.php#L153), [:363-368](../../includes/class-nicepay-gateway.php#L363)

**Problem.** The gateway stores `_nicepay_moid`, `_nicepay_edi_date`, `_nicepay_tid`, `_nicepay_pay_method` and `_nicepay_auth_code` on the order. All five are underscore-prefixed, so WordPress treats them as protected and hides them from the Custom Fields panel. A repo-wide grep for `woocommerce_admin_order_data`, `manage_edit-shop_order_columns` and `woocommerce_shop_order_search_fields` returns zero hits, so none of this data is visible on the order screen.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:363-368
$order->update_meta_data( '_nicepay_tid', $tid );
$order->update_meta_data( '_nicepay_pay_method', $result_method );
if ( ! empty( $result['AuthCode'] ) ) {
    $order->update_meta_data( '_nicepay_auth_code', $result['AuthCode'] );
}
```

**Impact.** A merchant looking at an order has no way to see which NicePay transaction paid for it, which card was used, or what the auth code was, without leaving the order and searching NicePay → Transactions by hand — and the link in the other direction is broken on HPOS (PLATFORM-11). For support and chargeback handling the payment reference belongs on the order. The data is already captured; only presentation is missing, which makes this cheap to fix and conspicuous to leave undone.

**Recommendation.** Hook `woocommerce_admin_order_data_after_order_details` to render a compact NicePay panel: payment method with the existing SVG icon, TID with a copy button (reuse the `.nicepay-copy-btn` pattern already in `assets/js/nicepay-admin.js`), auth code, masked card number and card name for CARD, virtual account details for VBANK (PLATFORM-07), plus a deep link to the matching transactions row. Add an order-list column showing the payment-method icon, and register `woocommerce_shop_order_search_fields` (and its HPOS equivalent) so merchants can find an order by TID from the orders search box.

---

#### PLATFORM-42 · Text domain is loaded on `plugins_loaded`, which is redundant on WP 6.7+ and one careless line from a `_doing_it_wrong` notice

**Severity:** 🔵 Low · **Category:** i18n · **Effort:** trivial · **Verdict:** 🟣 **PLAUSIBLE — needs confirmation**
**Location:** [nicepay-payment-gateway.php:69](../../nicepay-payment-gateway.php#L69), [:165-171](../../nicepay-payment-gateway.php#L165)

**Problem.** `load_plugin_textdomain()` runs on `plugins_loaded` at priority 10. The pre-`init` execution path was re-traced: `nicepay_init` runs at `plugins_loaded` priority 0 → `includes()` → requires the two admin files, whose file-scope `new NicePay_Admin();` and `new NicePay_Transactions();` constructors contain only `add_action()` calls and no gettext. So no translation is requested before `init` today. Since WP 6.7 translations load just-in-time and the explicit call is unnecessary.

**Evidence.**

```php
// nicepay-payment-gateway.php:69
add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
// :165-171
load_plugin_textdomain( 'nicepay-payment-gateway', false, dirname( NICEPAY_PLUGIN_BASENAME ) . '/languages/' );
// :495
add_action( 'plugins_loaded', 'nicepay_init', 0 );
```

`admin/class-nicepay-admin.php:12-16` and `admin/class-nicepay-transactions.php:12-14` — both constructors contain only `add_action` calls.

**Impact.** No user-visible symptom today. The risk is structural: adding a translated string to either admin constructor, or to `init_hooks()`, would immediately start loading translations before `init` and produce a `_doing_it_wrong` warning on every admin page load for every user of the plugin — a highly visible regression from a one-line change.

**Verification status.** The path was independently re-traced and this does **not** currently fire the WP 6.7 notice. **Kept PLAUSIBLE because the finding is entirely forward-looking — there is no present defect to confirm, and the exact WP 6.7.x behaviour for an explicit early `load_plugin_textdomain()` (as opposed to just-in-time loading) is version-sensitive and could not be verified without a running install.**

**Recommendation.** Move `load_plugin_textdomain()` to `init` priority 0 so it can never run before the point WordPress considers safe, or drop it entirely and rely on just-in-time loading (keeping it only matters for pre-6.7 sites, which the `Requires at least: 5.0` header still claims to support). If you keep it, record why in a comment. Independently, reconsider `Requires at least: 5.0` — the plugin is designed around modern WooCommerce and there is no evidence anything was tested on WP 5.x.

---

## 3. Gap list: "to be listed on WordPress.org"

The items below are what stands between this repository and a submittable WordPress.org plugin. Hard blockers are marked ⛔; the rest are review-guideline items that reviewers routinely reject on.

| # | Gap | Blocker? | Finding | Fix |
|---|---|---|---|---|
| 1 | No `readme.txt` (only README.md) | ⛔ | PLATFORM-31 | Add readme.txt with Contributors, Tags, Requires at least, Tested up to, Requires PHP, Stable tag, License, License URI + Description/Installation/FAQ/Screenshots/Changelog sections |
| 2 | Slug leads with the `nicepay` trademark | ⛔ | PLATFORM-31 | Rename (e.g. `payment-gateway-for-nicepay`) and obtain written permission from NICE Payments before publishing under any name using their mark |
| 3 | Eight `register_setting()` calls with no `sanitize_callback` | ⛔ | PLATFORM-22 | Add allowlist callbacks to every registered option |
| 4 | No `uninstall.php` / `register_uninstall_hook()` | ⛔ | PLATFORM-14 | Add guarded `uninstall.php`; always delete credentials, gate table removal behind an opt-in |
| 5 | Raw SVG echoed without `wp_kses` in four places | — | PLATFORM-39(c) | Wrap in `wp_kses()` with an explicit SVG allowlist |
| 6 | `$_SERVER` / `$_GET` reads without `wp_unslash` | — | PLATFORM-39(a,b) | Sanitise per WPCS |
| 7 | No `License URI:` header | — | PLATFORM-31(e) | `License URI: https://opensource.org/licenses/MIT` |
| 8 | No `Update URI:` header | — | PLATFORM-31(d) | Point at the GitHub repo to prevent update hijacking |
| 9 | `WC tested up to: 9.0` is stale | — | PLATFORM-31(b) | Bump per WooCommerce release; assert in CI |
| 10 | No screenshots | — | PLATFORM-31(a) | `assets/screenshot-N.png` for settings, transactions, shortcode builder |
| 11 | Undeclared `ext-mbstring` / `ext-json` | — | PLATFORM-37 | Declare in composer.json; add a runtime guard and an activation environment check |
| 12 | No HPOS / blocks compatibility declarations | — | PLATFORM-08, PLATFORM-01 | `FeaturesUtil::declare_compatibility()` for both flags |
| 13 | `.pot` drifted from source; zero plural entries | — | PLATFORM-16 | Regenerate with `wp i18n make-pot`; add a CI drift gate |
| 14 | Release ZIP never receives the tag version | — | PLATFORM-31(f) | `sed` the tag into the `Version:` header and `NICEPAY_VERSION`; fail if CHANGELOG.md lacks an entry |
| 15 | No static analysis in CI (only `php -l`) | — | PLATFORM-39 | Add WPCS (`WordPress`, `WordPress-Extra`) as a CI step |
| 16 | Documentation describes a filter that does not exist | — | PLATFORM-15 | Implement the hook surface, then make the docs true; delete the broken `remove_action` snippet |

---

*Report scope: WordPress & WooCommerce platform conformance. 42 findings — 2 critical, 5 high, 26 medium, 9 low. Two findings (PLATFORM-25, PLATFORM-30) plus PLATFORM-42 are marked PLAUSIBLE and need triage before being treated as defects; PLATFORM-13 and PLATFORM-32 carry documented residual uncertainty on one link each. Two sub-claims from the original review were refuted during verification and are recorded inline at PLATFORM-09 (dbDelta `PRIMARY KEY` spacing) and PLATFORM-34 (duplicate `register_endpoints()` closure) so they are not acted on by mistake.*
