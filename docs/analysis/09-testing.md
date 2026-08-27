# Test Suite & Quality Gates

> [!WARNING]
> **Frozen historical snapshot.** This review describes baseline commit `5855db1` (2026-08-19), before the 2.0 remediation work. Its line references and present-tense security claims do not describe the current code. Use `docs/analysis/pr-review2/` and current tests for release decisions.

The NicePay plugin ships 98 PHPUnit test methods across four files, and the best of them are genuinely excellent: three of the four signature rules are anchored to real vendor fixtures that reproduce byte-for-byte under `shasum -a 256`, the per-method result-code table is validated with proper data providers including its case-sensitivity edges, and every GitHub Action is pinned to a full commit SHA. But those 98 tests reach **24 of 86 production functions**, and the 62 they do not reach include every function that moves money: `request_approval()`, `request_net_cancel()`, `request_cancel()`, `process_refund()`, both return handlers, and all four AJAX endpoints. `phpunit.xml` compounds this by excluding the gateway and the return handler from the coverage denominator entirely, so the published coverage number is structurally incapable of reporting the gap it should be flagging. This document maps that coverage, records 37 findings, and then — in Section 3 — lays out a prioritised, copy-pasteable backlog of the 15 tests that would retire the most money risk per hour of work.

---

## What this codebase does well

Before the criticism, the parts that are genuinely above the bar for a WooCommerce gateway plugin:

- **The signature contract tests are anchored to real vendor fixtures, not self-computed values.** I independently recomputed all four with `shasum -a 256` and every one matches the spec digest exactly: auth SignData `475979a5628498711052598c6d4a73a17f0e3f0ef45960eed2e1b1776e3146bd`, auth response Signature `cc94db193780ffb83d79845bb001b26da397cb5855dd285a3b85a4acc1fa55fe`, approval SignData `4916540b27cd60f3b6e37aa2e1257c7e4fb9faa0b4a84a1d85fac91461d00204`, approval response Signature `9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd`. `NicePaySignatureIntegrationTest.php` is a real contract test against the vendor manual — a level of rigour most gateway plugins never reach.
- **The per-method result-code table is exactly right against the spec** and is tested with proper data providers, including the case-sensitivity edge (`['CELLPHONE','a000',false]`) and the cross-type negatives (`['2001', false]` for approval, `['3001', false]` for cancel). [tests/unit/NicePayResultCodeTest.php:42](../../tests/unit/NicePayResultCodeTest.php#L42)-[118](../../tests/unit/NicePayResultCodeTest.php#L118) is the best-written file in the suite.
- **The test author was intellectually honest about limitations rather than papering over them.** [tests/unit/NicePayApiTest.php:219](../../tests/unit/NicePayApiTest.php#L219)-221 documents exactly why the cancel fixture could not be used (`doc example uses "nictest04m" as MID ... So we compute expected ourselves`), and [tests/unit/NicePayFunctionsTest.php:218](../../tests/unit/NicePayFunctionsTest.php#L218) documents why the logging test asserts nothing. Those comments are what made this audit tractable.
- **Supply-chain hygiene in CI is excellent.** Every action is pinned to a full commit SHA with a version comment (`actions/checkout@11bd719… # v4.2.2`, `shivammathur/setup-php@cf4cade… # v2.33.0`, `softprops/action-gh-release@da05d55… # v2.2.2`) — something most repos this size skip entirely.
- **`phpunit.xml` sets `failOnRisky="true"` and `failOnWarning="true"`**, so an assertion-free or deprecation-emitting test fails the build rather than passing quietly. Combined with `fail-fast: false` on a five-version PHP matrix, the CI configuration has good instincts even where its coverage scope is wrong.
- **Test names are consistently descriptive and behaviour-oriented** (`test_verify_auth_signature_tampered_amount`, `test_success_code_unknown_method_checks_all`, `test_get_nicepay_lang_unknown_defaults_to_ko`), and files are organised with clear section banners. Adding tests to this suite is easy — the precondition for fixing everything below.
- **The lookup-table functions are covered thoroughly and correctly** (card codes, bank codes, status labels, currency formatting, language mapping), including the v2.0.8 rename to `iM Bank(Daegu)` for code 031 — evidence someone read the *current* spec rather than an older one.

---

## 1. Coverage map

Function counts were derived independently with `grep -cE "^\s*(public |private |protected |static )*function "` per file; test count with `grep -c "public function test" tests/unit/*.php` (5 + 42 + 38 + 13 = **98**).

| Production unit | Fns | Tested? | Risk if untested |
|---|---|---|---|
| `includes/class-nicepay-api.php` | 23 | **18/23** — all non-network methods | Money-path methods `request_approval`, `request_net_cancel`, `request_cancel` plus `validate_nicepay_url` and `redact_for_log` are untestable (no HTTP seam; private) |
| `includes/nicepay-functions.php` | 14 | **6/14** — pure helpers only | 5 DB functions untested (no `$wpdb` stub); `nicepay_log`'s active branch never executes (no `WP_DEBUG`) |
| `includes/class-nicepay-gateway.php` | 8 | **0/8** | Excluded from coverage at `phpunit.xml:24`. Auth-form build, return handling, status routing, `process_refund` — all unverified |
| `includes/class-nicepay-return-handler.php` | 3 | **0/3** | Excluded at `phpunit.xml:25`. Standalone approval + result page unverified |
| `includes/nicepay-icons.php` | 1 | **0/1** | Not even `require`d by the bootstrap; `processUncoveredFiles="false"` hides it from the report instead of showing 0% |
| `nicepay-payment-gateway.php` | 21 | **0/21** | Outside `<include>`. Activation, `dbDelta`, rewrite endpoint, shortcode render, all 3 AJAX handlers |
| `admin/class-nicepay-admin.php` | 13 | **0/13** | Outside `<include>`. Settings persistence and shortcode builder |
| `admin/class-nicepay-transactions.php` | 3 | **0/3** | Outside `<include>`. `ajax_cancel_transaction` fires a real PG cancel |
| `templates/*.php` (2 files) | — | **0** | Hidden-field emission, `accept-charset`, unescaped icon echo |
| `assets/js/*.js` + ~130 lines inline JS | — | **0** | No `package.json`, no ESLint, no JS test runner, no JS step in CI |
| **Total** | **86** | **24 reached** | Every branch through which money moves is unverified |

Structural cause, in one line: [tests/bootstrap/bootstrap.php:24](../../tests/bootstrap/bootstrap.php#L24)-26 loads only two production files, so nothing else is even parsed during a run.

```php
// Load plugin files under test
require_once NICEPAY_PLUGIN_DIR . 'includes/nicepay-functions.php';
require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-api.php';
```

---

## 2. Findings

37 findings: 1 critical, 7 high, 21 medium, 6 low, 2 enhancement. Two findings are marked **PLAUSIBLE — needs confirmation**; everything else was verified against the code.

### TESTS-01 · 🔴 CRITICAL · No server-side amount binding anywhere

**Files:** [nicepay-payment-gateway.php:322](../../nicepay-payment-gateway.php#L322) · [includes/class-nicepay-gateway.php:292](../../includes/class-nicepay-gateway.php#L292)-303 · [includes/class-nicepay-return-handler.php:86](../../includes/class-nicepay-return-handler.php#L86)-97

**Problem.** Two independent holes on the same axis.

*(a) The public AJAX endpoint signs any client-supplied amount.* `ajax_init_payment()` is registered for `wp_ajax_nopriv` ([nicepay-payment-gateway.php:82](../../nicepay-payment-gateway.php#L82)), takes `amount` straight from `$_POST`, validates only `(float) $amount <= 0`, then mints a valid SignData for it:

```php
// nicepay-payment-gateway.php:322
$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
// nicepay-payment-gateway.php:336
$sign_data = $api->create_auth_sign_data( $edi_date, $amount );
```

The endpoint never receives a shortcode id and never consults `nicepay_get_saved_shortcode()`, so the server-side price in the saved config is never used. The nonce it verifies is printed into the page for every visitor at [templates/standalone-payment-form.php:305](../../templates/standalone-payment-form.php#L305).

*(b) Both return handlers approve the POSTed `Amt` without comparing it to the stored transaction.* `$amt` comes from `$_POST` ([gateway:227](../../includes/class-nicepay-gateway.php#L227), [handler:39](../../includes/class-nicepay-return-handler.php#L39)) and flows into `$auth_data['Amt']`. The transaction row is fetched by Moid ([gateway:234](../../includes/class-nicepay-gateway.php#L234)) and its `amount` column is **never read on either path**.

**Impact.** Because the auth Signature plaintext is `$auth_token . $this->mid . $amt . $this->merchant_key` ([class-nicepay-api.php:84](../../includes/class-nicepay-api.php#L84)) — it covers `Amt` but **not** `Moid` — while order resolution keys on `Moid`, an attacker can pay 100 KRW through their own session, capture the browser-side POST to `ReturnURL`, swap only the `Moid` field, and complete an arbitrary victim order. The signature verifies, `request_approval()` approves the small amount, `is_success_code()` passes, and [gateway:380](../../includes/class-nicepay-gateway.php#L380) calls `$order->payment_complete( $tid )` on the expensive order. Direct revenue loss on both flows, unauthenticated, with no exotic prerequisites. The transaction row records the low amount, so the admin Transactions screen shows a consistent, plausible-looking paid transaction.

**Recommendation.**
1. `ajax_init_payment()` must accept a `shortcode_id`, load the config with `nicepay_get_saved_shortcode()`, and sign `nicepay_get_amount( $saved['amount'], $saved['currency'] )` — ignoring any client `amount` entirely. For genuinely open-amount forms, validate against explicit min/max persisted in the config.
2. In both handlers, immediately after resolving `$transaction`:

```php
if ( (int) $amt !== (int) $transaction->amount ) {
    nicepay_update_transaction( $transaction->id, array( 'status' => 'failed', 'result_code' => 'AMT_MISMATCH' ) );
    nicepay_log( 'Amount mismatch', array( 'posted' => $amt, 'stored' => $transaction->amount ) );
    return; // never call request_approval()
}
```
3. Add the two regression tests (backlog items 7, 8, 9 in Section 3).

---

### TESTS-02 · 🟠 HIGH · No test drives a payment end-to-end

**Files:** [phpunit.xml:17](../../phpunit.xml#L17)-27 · [tests/bootstrap/bootstrap.php:24](../../tests/bootstrap/bootstrap.php#L24)-26 · [includes/class-nicepay-api.php:182](../../includes/class-nicepay-api.php#L182)-394

**Problem.** 98 test methods reach 24 of 86 production functions — all 18 non-network methods of `NicePay_API` plus 6 pure helpers. Verified untested set: `validate_nicepay_url` (140), `redact_for_log` (157), `request_approval` (182), `request_net_cancel` (278), `request_cancel` (337); all five DB functions; every method of `WC_Gateway_NicePay` and `NicePay_Return_Handler`; every AJAX handler; `activate`/`create_tables`/`register_endpoints`/`render_payment_shortcode`; all of `admin/`.

```xml
<!-- phpunit.xml:21-26 -->
<exclude>
    <directory>vendor</directory>
    <directory>tests</directory>
    <file>includes/class-nicepay-gateway.php</file>
    <file>includes/class-nicepay-return-handler.php</file>
</exclude>
```

**Impact.** Any refactor of `handle_return()`, `process()`, `request_approval()` or `process_refund()` ships with a green CI on all five PHP legs. For a payment gateway the two failure modes are "customer charged, order not completed" and "order completed, no money captured" — neither has an automated detector.

**Recommendation.** (1) Delete the two `<file>` excludes so the published number stops hiding the gap. (2) Introduce an injectable HTTP transport on `NicePay_API` (TESTS-11) — that single change unblocks all five untested API methods. (3) Add a WP integration suite (TESTS-17) so the gateway and return handler can be loaded at all. (4) Gate CI on a coverage floor set at today's honest number and ratchet it upward.

> *Note on severity:* absent tests are not themselves money loss — they are the missing safety net that makes the separately-reported defects invisible.

---

### TESTS-03 · 🟠 HIGH · Zero-padded approval-response `Amt` handled by neither code nor test — **PLAUSIBLE, needs confirmation**

**Files:** [includes/class-nicepay-api.php:258](../../includes/class-nicepay-api.php#L258)-267 · [tests/unit/NicePayApiTest.php:190](../../tests/unit/NicePayApiTest.php#L190)-200 · [tests/unit/NicePaySignatureIntegrationTest.php:96](../../tests/unit/NicePaySignatureIntegrationTest.php#L96)-103

**Problem.** The spec digest documents the approval-response `Amt` field as 12 bytes zero-padded (`000000001004`) while the vendor's own worked Signature example is computed over the unpadded `1004`. I recomputed both digests with `shasum -a 256` against the documented test key:

| Plaintext `Amt` | SHA-256 of `TID + MID + Amt + MerchantKey` | Matches doc fixture? |
|---|---|---|
| `1004` | `9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd` | ✅ yes |
| `000000001004` | `59c36831e223d8d7c96e248123816a31a1a4510088e4bd63862455fe91851de5` | ❌ completely different |

`request_approval()` feeds the response's `Amt` verbatim into the verifier, and a mismatch fires a net cancel:

```php
// includes/class-nicepay-api.php:258-264
$amt = isset( $result['Amt'] ) ? $result['Amt'] : $auth_data['Amt'];
if ( ! $this->verify_approval_signature( $result['TID'], $amt, $result['Signature'] ) ) {
    ...
    if ( ! empty( $auth_data['NetCancelURL'] ) ) { $this->request_net_cancel( $auth_data ); }
```

Every existing signature test uses the literal `'1004'` ([ApiTest:192](../../tests/unit/NicePayApiTest.php#L192), [IntegrationTest:23](../../tests/unit/NicePaySignatureIntegrationTest.php#L23)), so the padding case is invisible in either direction. Separately, `(int) $result['Amt'] === (int) $auth_data['Amt']` appears **nowhere** in the file.

**Impact.** If the PG pads, this is a 100% payment-failure-plus-net-cancel loop with no automated detection. If someone later "fixes" it by unconditionally stripping leading zeros without a numeric equality check, `0000001` normalises to `1` and the amount binding weakens. The suite cannot distinguish the two futures.

**Recommendation.** In order:
1. Add the missing numeric guard first — in `request_approval()`, before the signature check, abort when `(int) $result['Amt'] !== (int) $auth_data['Amt']`. This is the assertion that protects the money and it is unconditionally correct regardless of padding.
2. Then make `verify_approval_signature()` try both the received string and its zero-stripped form, each with `hash_equals`.
3. Pin all three cases with tests (backlog items 1, 2).

> **Needs confirmation:** whether NICEPAY actually returns a padded `Amt` in the approval response is live PG behaviour that cannot be observed from this repo. The verified, non-speculative core is: the padding ambiguity is unhandled in the code, untested in either direction, and there is no numeric amount-equality check anywhere.

---

### TESTS-04 · 🟠 HIGH · All net-cancel branches untested, and the approval-URL rejection path never attempts a net cancel

**Files:** [includes/class-nicepay-api.php:184](../../includes/class-nicepay-api.php#L184)-189, 222-267, 278-324 · [tests/bootstrap/wp-stubs.php:79](../../tests/bootstrap/wp-stubs.php#L79)-85

**Problem.** `request_approval()` has four net-cancel triggers — transport `WP_Error` (225-227), JSON parse failure (238-240), missing Signature/TID (251-253), signature mismatch (262-264) — and `request_net_cancel()` has its own URL-validation, transport-error and parse branches. No test executes any of them, and the suite structurally cannot: `wp_remote_post()` is hard-declared to always return a `WP_Error`, and no test calls `request_approval()` at all.

The URL-validation branch is also asymmetric with the other four — it returns without net-cancelling:

```php
// includes/class-nicepay-api.php:186-189
if ( ! $this->validate_nicepay_url( $next_app_url ) ) {
    nicepay_log( 'Approval URL validation failed', $next_app_url );
    return new WP_Error( 'nicepay_url_error', __( 'Invalid approval URL.', 'nicepay-payment-gateway' ) );
}
```

**Impact.** The whole 망취소 mechanism could be deleted and CI would stay green. On the paths where the missing call matters, the buyer's card is left authorised with no cancellation and no merchant-side record. Reachability is narrow — `NextAppURL` arrives from NICEPAY and per §5 is always one of the four allowlisted hosts — so the branch fires only if NICEPAY adds a data-centre host or changes host casing.

**Recommendation.** Add `if ( ! empty( $auth_data['NetCancelURL'] ) ) { $this->request_net_cancel( $auth_data ); }` before the return at line 188. Then add tests for all five triggers via an injectable transport, asserting the net-cancel POST body carries `NetCancel === '1'`, the same TID and AuthToken, and `SignData === hash('sha256', AuthToken.MID.Amt.EdiDate.MerchantKey)` — the code builds this correctly at 288-301 but nothing pins it.

---

### TESTS-05 · 🟠 HIGH · Neither return handler is idempotent

**Files:** [includes/class-nicepay-gateway.php:216](../../includes/class-nicepay-gateway.php#L216)-246, 358-412 · [includes/class-nicepay-return-handler.php:25](../../includes/class-nicepay-return-handler.php#L25)-158

**Problem.** Both handlers are fully re-entrant with no state check. `gateway:234-246` fetches `$transaction` and `$order` and never inspects `$transaction->status` or `$order->is_paid()`; there is no early return before `request_approval()`. The auth signature is a static hash over `AuthToken+MID+Amt+Key`, so it verifies identically on every replay. On the second run a non-success `ResultCode` takes the failure branch:

```php
// includes/class-nicepay-gateway.php:396-404
$update_data['status'] = 'failed';
nicepay_update_transaction( $transaction->id, $update_data );
$order->update_status( 'failed', sprintf( __( 'NicePay payment failed. Code: %1$s, Message: %2$s', 'nicepay-payment-gateway' ), $result_code, $result_msg ) );
```

Same shape at [class-nicepay-return-handler.php:151](../../includes/class-nicepay-return-handler.php#L151)-157. No test file references `handle_return` or `process`.

**Impact.** A paid order silently transitions to failed: fulfilment stops, stock is restored, the buyer sees a failure page after being charged, and the transactions table misreports the payment. Anyone who observes one return POST can replay it to sabotage completed orders. Per §12 mobile data is POSTed to `ReturnURL` and browsers may retry, so this happens without an attacker.

**Recommendation.** Add an idempotency guard as the first thing after the transaction lookup in both handlers: if `$transaction->status` is already one of `paid`/`waiting`/`cancelled`/`refunded`, log and redirect (gateway) or render the existing result (handler) **without** calling `request_approval()` or touching the order status. Back it with backlog item 10.

> *Residual uncertainty:* the exact PG response to a replayed, already-consumed AuthToken cannot be determined from the repo. A non-success code is the overwhelmingly likely outcome and is what takes the destructive branch.

---

### TESTS-06 · 🟠 HIGH · Refund path has zero tests and three spec deviations — **PLAUSIBLE, needs confirmation**

**Files:** [includes/class-nicepay-gateway.php:418](../../includes/class-nicepay-gateway.php#L418)-474 · [includes/class-nicepay-api.php:337](../../includes/class-nicepay-api.php#L337)-394 · [admin/class-nicepay-transactions.php:180](../../admin/class-nicepay-transactions.php#L180)-237

**Problem.** `process_refund()` and `ajax_cancel_transaction()` are the only two ways money goes back out and neither has any test. Against §9:

| # | Spec requirement | Actual code | Status |
|---|---|---|---|
| 1 | `Moid` = merchant-issued **unique cancel** order number | Passes the **original payment** Moid: `$moid = $order->get_meta( '_nicepay_moid' );` ([gateway:430](../../includes/class-nicepay-gateway.php#L430)); admin passes `$transaction->moid` ([transactions:208](../../admin/class-nicepay-transactions.php#L208)) | ❌ reused |
| 2 | `OTID(30)` MUST be sent on 2nd+ partial cancels | `grep -rn "OTID"` over the repo returns **nothing** | ❌ absent |
| 3 | `RefundAcctNo` / `RefundBankCd` / `RefundAcctNm` for VBANK refunds | Never sent; `$extra_params` ([api:337](../../includes/class-nicepay-api.php#L337), merged at 352) is **dead** — no caller supplies a 6th argument | ❌ absent |
| 4 | Partial-cancel flag | `ajax_cancel_transaction()` always leaves `$partial` at its `false` default while sending the full stored amount | ⚠ admin can only issue full cancels |

**Impact.** Refunds behave unpredictably in exactly the situations a merchant hits under pressure — a second partial refund, a VBANK refund after deposit. WooCommerce will already have recorded the refund locally if the PG rejects it, and nothing tells anyone the path is broken.

**Recommendation.** (a) Generate a fresh cancel Moid per cancel, e.g. `$this->api->generate_moid( 'CXL' . $order->get_id() )`. (b) Persist `OTID` from every cancel response onto the order/transaction and send it on subsequent partial cancels. (c) Accept and forward `RefundAcctNo`/`RefundBankCd`/`RefundAcctNm`, or delete `$extra_params` if VBANK refunds are out of scope. (d) Pin each with a transport-level test asserting the two cancel requests carry different Moid values, that the second carries the first response's OTID, and that `PartialCancelCode` is `'1'` on both.

> **Needs confirmation:** whether NICEPAY actually rejects a reused cancel Moid or a missing OTID is PG behaviour not observable from the repo. Sub-claims 1-3 are confirmed code facts.
>
> **Refuted sub-claim (do not act on):** a related concern that `$is_partial = ( (float) $cancel_amt < (float) $total_amt )` ([gateway:439](../../includes/class-nicepay-gateway.php#L439)) misflags a second partial refund is **not reachable** through WooCommerce, which caps the refund amount at the remaining refundable total. Comparing against order total rather than remaining is untidy but produces no failing case via the supported UI.

---

### TESTS-07 · 🟠 HIGH · VBANK has no deposit-notification endpoint at all

**Files:** [includes/class-nicepay-gateway.php:358](../../includes/class-nicepay-gateway.php#L358)-387 · [includes/class-nicepay-return-handler.php:135](../../includes/class-nicepay-return-handler.php#L135)-140, 234-237 · [tests/unit/NicePayApiTest.php:313](../../tests/unit/NicePayApiTest.php#L313)-339

**Problem.** Only `get_vbank_exp_date()` is tested (format, futureness, option respected). Everything that makes VBANK work is not:

- The §4 required-field rule: `VbankExpDate` is emitted only when VBANK is in `nicepay_enabled_methods` ([gateway:196](../../includes/class-nicepay-gateway.php#L196)-198). Nothing asserts a VBANK-enabled configuration always emits it.
- Response field mapping `VbankBankCode`/`VbankBankName`/`VbankNum`/`VbankExpDate` → `bank_code`/`bank_name`/`vbank_num`/`vbank_exp_date` ([gateway:350](../../includes/class-nicepay-gateway.php#L350)-355). Untested.
- The status routing that decides whether goods ship before money arrives:

```php
// includes/class-nicepay-gateway.php:358-359
if ( $this->api->is_success_code( $result_code, $result_method ) ) {
    $update_data['status'] = ( $result_method === 'VBANK' ) ? 'waiting' : 'paid';
```
with the on-hold branch at 370-378 and `$order->payment_complete( $tid );` at 380. Untested.
- **Most importantly: there is no inbound deposit-notification (입금통보) endpoint anywhere in the plugin.** §2 documents three source IPs for it; no handler exists. `add_rewrite_rule` registrations are limited to the single `^nicepay-return/?$` rule at [nicepay-payment-gateway.php:202](../../nicepay-payment-gateway.php#L202)-212. A VBANK order therefore stays `on-hold`/`waiting` forever even after the buyer deposits. The `waiting` status label exists ([nicepay-functions.php:273](../../includes/nicepay-functions.php#L273)) with nothing that can ever clear it.
- `VbankExpDate` is rendered raw to the buyer as an unformatted digit string ([return-handler:236](../../includes/class-nicepay-return-handler.php#L236)).

**Impact.** Virtual account is one of four advertised methods, enabled by default ([main:156](../../nicepay-payment-gateway.php#L156)), and the one where money arrives asynchronously. Without the deposit endpoint every VBANK order requires manual reconciliation through the NICEPAY merchant admin. This is a functional gap in a shipped feature, not merely a coverage gap.

**Recommendation.** Add the deposit-notification endpoint with source-IP verification against the three documented inbound IPs plus signature verification, transitioning `waiting → paid` and `on-hold → processing`. Then test the lifecycle end to end (backlog item 12). Format the expiry date for humans in `render_result_page()`.

---

### TESTS-08 · 🟠 HIGH · Net-cancel results are discarded at all four call sites

**Files:** [includes/class-nicepay-api.php:226](../../includes/class-nicepay-api.php#L226), 239, 252, 263, 278-324

**Problem.** `request_net_cancel( $auth_data );` appears at lines 226, 239, 252 and 263 as a bare statement — the return value is thrown away in all four cases. Inside the method the response is decoded and returned but nothing ever checks it:

```php
// includes/class-nicepay-api.php:319-323
$body   = wp_remote_retrieve_body( $response );
$result = json_decode( $body, true );
nicepay_log( 'Network cancel response', $result ? $this->redact_for_log( $result ) : 'parse_error' );
return $result ? $result : new WP_Error( 'nicepay_parse_error', ... );
```

There is no `is_cancel_success( $result['ResultCode'] )` check against the spec's `2001`, and — unlike `request_approval` (verifies at 248-267) and `request_cancel` (verifies at 385-391) — `request_net_cancel` performs **no signature verification at all**, despite §8 documenting a `Signature` over `TID+MID+CancelAmt+MerchantKey`. Callers simply mark the transaction `NET_ERROR` and move on.

**Impact.** The net cancel reverses a live card authorisation when approval fails. If it fails — network error, PG rejection, invalid `NetCancelURL` — the plugin reports the same outcome as if it had succeeded. The merchant discovers the stranded authorisation only when the cardholder complains. No transaction record, no order note, no admin notice, no retry, no test.

**Recommendation.** Have `request_net_cancel` verify the response signature with `verify_cancel_signature( $result['TID'], $result['CancelAmt'], $result['Signature'] )` and check `is_cancel_success( $result['ResultCode'] )`, returning a `WP_Error` otherwise. At all four call sites capture the return value and, on failure, write a distinct result code (e.g. `NET_CANCEL_FAILED`) plus an order note, and log at error level so it surfaces in **WooCommerce → Status → Logs**. Then test each trigger against a `2001` and a non-`2001` response.

---

### TESTS-09 · 🟡 MEDIUM · Six signature tests recompute the expectation with the code's own formula

**Files:** [tests/unit/NicePayApiTest.php:154](../../tests/unit/NicePayApiTest.php#L154)-165, 213-226, 233-245, 401-419 · [tests/unit/NicePaySignatureIntegrationTest.php:157](../../tests/unit/NicePaySignatureIntegrationTest.php#L157)-178

**Problem.** Three signature rules are genuinely anchored to vendor fixtures. These are not:

| Test | Why it cannot fail |
|---|---|
| `test_create_cancel_sign_data` (213-226) | Rebuilds the expectation from the same field order the production method uses |
| `test_verify_cancel_signature_valid` (233-245) | Self-generates the signature it then verifies |
| `test_verify_auth_signature_tampered_amount` (154-165) | Self-generates the "valid" signature; deleting `$amt` from the production plaintext still leaves this test green |
| 3 roundtrips at `IntegrationTest:157-178` | Re-implement the plaintext and assert the verifier agrees |
| `test_signature_uses_timing_safe_comparison` (401-419) | Asserts only valid/off-by-one/empty outcomes — replacing `hash_equals()` with `==` passes unchanged |

```php
// tests/unit/NicePayApiTest.php:219-225
// From NicePay documentation example (Section 9.7)
// Note: doc example uses "nictest04m" as MID in plaintext but we use "nicepay00m"
// So we compute expected ourselves
$plain    = 'nicepay00m' . '1004' . '20191219133357' . $this->api->get_merchant_key();
$expected = hash( 'sha256', $plain );
$this->assertEquals( $expected, $result );
```

The author's workaround was honest: `sha256('nictest04m'+'1004'+'20191219133357'+nicepay00m key)` = `83c1b9190f8ac194d49ee864ace7e9b71859f784b7c0aa19d2e052f7dc4fecb2`, not the doc's `3367c624…`, so the doc fixture genuinely uses `nictest04m`'s own key.

**Impact.** The cancel-**request** SignData rule — which governs refunds — is verified only against itself, and the timing-safety guarantee is unverified. A wrong field order would ship green and every refund would be rejected by the PG.

**Recommendation.** Replace each self-computing expectation with a golden literal. For the cancel rule the value was computed externally and can be pasted directly:

```php
// MID nicepay00m + CancelAmt 1004 + EdiDate 20191219133357 + documented test merchant key
$this->assertSame(
    'e6959c2ff876c64ccf0008828cab0007a81230c589f3cd727d0277acdc3c4cd5',
    $this->api->create_cancel_sign_data( '1004', '20191219133357' )
);
```

Add explicit negative field-order assertions. Rename `test_signature_uses_timing_safe_comparison` to describe what it checks, and add a real guard (assert the source contains `hash_equals`, plus a magic-hash case proving `==` semantics are absent).

> *Scope correction:* the cancel **response** contract *is* anchored — `verify_cancel_signature` is covered by the `9439b21e…` fixture at [IntegrationTest:130](../../tests/unit/NicePaySignatureIntegrationTest.php#L130)-151. Only the cancel **request** rule lacks an external anchor. The production field order at [class-nicepay-api.php:113](../../includes/class-nicepay-api.php#L113) (`$plain = $this->mid . $cancel_amt . $edi_date . $this->merchant_key;`) is currently **correct** — this is regression exposure, not a live defect.

---

### TESTS-10 · 🟡 MEDIUM · `wp-stubs.php` is more permissive than real WordPress

**Files:** [tests/bootstrap/wp-stubs.php:55](../../tests/bootstrap/wp-stubs.php#L55)-59, 61-65, 126-136

**Problem.** Verified divergences on exactly the functions sitting between NICEPAY's POST and the signature check:

| Stub | Stub behaviour | Real WordPress | Consequence |
|---|---|---|---|
| `sanitize_text_field` | `trim( strip_tags( $str ) )` | also `wp_check_invalid_utf8()`, %-octet removal, whitespace collapsing | non-UTF-8 bytes survive in tests, are dropped in production |
| `sanitize_email` | `filter_var(…, FILTER_SANITIZE_EMAIL)` — returns `notanemail` unchanged | validates and returns `''` | `ajax_init_payment` (main:325) and `ajax_save_shortcode` (main:389) depend on the real semantics |
| `esc_url_raw` / `esc_url` | `filter_var(…, FILTER_SANITIZE_URL)` — keeps `javascript:` | protocol allowlist | first layer applied to `NextAppURL`/`NetCancelURL` |
| `__()` | ignores the domain, echoes the msgid | domain-aware | `assertEquals('Credit Card', …)` passes regardless of text-domain correctness |

```php
// tests/bootstrap/wp-stubs.php:55-65
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $str ) { return trim( strip_tags( $str ) ); } }
if ( ! function_exists( 'sanitize_email' ) ) { function sanitize_email( $email ) { return filter_var( trim( $email ), FILTER_SANITIZE_EMAIL ); } }
```

Missing entirely — which is why nothing outside the two loaded files is testable: `wp_verify_nonce`, `current_user_can`, `wp_send_json_success`/`_error`, `wp_unslash`, `$wpdb`, `wc_get_order`, `sanitize_title`, `sanitize_hex_color`, `wp_kses_post`, `wp_remote_retrieve_response_code`.

**Impact.** Green tests that encode the wrong contract. Any future test of sanitisation, nonce handling or SSRF filtering written at this layer would be testing a different function than production runs.

**Recommendation.** Short term: port `wp_check_invalid_utf8` + newline collapsing into the `sanitize_text_field` stub, make `sanitize_email` return `''` when `FILTER_VALIDATE_EMAIL` fails, and make `esc_url_raw` return `''` for any scheme outside `http`/`https`. Correct fix: stop asserting on stubbed sanitisation at all — move anything that depends on it into a real WP integration suite (TESTS-17) and keep the unit suite for pure functions.

> **Refuted sub-claim (do not act on):** a related concern that the plugin stamps `accept-charset="euc-kr"` on the payment form, causing live data loss, is **false**. [templates/standalone-payment-form.php:128](../../templates/standalone-payment-form.php#L128) is `accept-charset="<?php echo esc_attr( $charset ); ?>"` with `$charset = get_option( 'nicepay_charset', 'utf-8' )` (line 19). The default is UTF-8; the EUC-KR issue is real but opt-in — see TESTS-16.

---

### TESTS-11 · 🟡 MEDIUM · `wp_remote_post` hard-stubbed to always fail; brain/monkey and mockery are unused

**Files:** [tests/bootstrap/wp-stubs.php:79](../../tests/bootstrap/wp-stubs.php#L79)-85 · [composer.json:15](../../composer.json#L15)-19 · [includes/class-nicepay-api.php:213](../../includes/class-nicepay-api.php#L213), 305, 360

**Problem.** `composer.json` requires `brain/monkey ^2.6` and `mockery/mockery ^1.6`. `grep -rn "Brain\|Monkey\|Mockery\|mock" tests/` returns exactly one hit — the comment inside the stub itself. Neither library is used by any test. The structural reason:

```php
// tests/bootstrap/wp-stubs.php:79-85
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = array() ) {
        // Stub: returns WP_Error by default in tests
        // Override with Brain\Monkey in specific tests
        return new WP_Error( 'stub', 'wp_remote_post is stubbed' );
    }
}
```

`NicePay_API` calls the global directly at lines 213, 305 and 360, so there is no seam to substitute. That single decision is why all three network methods — every money-moving branch of the API class — are untestable.

**Impact.** Three unused dev dependencies signal "we mock HTTP" while no HTTP behaviour is exercised. Any change to approval/net-cancel/cancel must be verified by hand against the live PG, which in practice means it is not verified.

**Recommendation.** This is the **enabling fix** — do it first. Add an optional transport to the constructor and route all three calls through one private helper:

```php
public function __construct( callable $transport = null ) {
    $this->transport = $transport ?: static function ( $url, $args ) { return wp_remote_post( $url, $args ); };
}
private function post( $url, array $args ) { return call_user_func( $this->transport, $url, $args ); }
```

Then delete the always-failing stub and either use brain/monkey properly or drop both unused dependencies.

---

### TESTS-12 · 🟡 MEDIUM · Coverage configuration excludes the payment files and enforces no threshold

**Files:** [phpunit.xml:17](../../phpunit.xml#L17)-27 · [.github/workflows/tests.yml:48](../../.github/workflows/tests.yml#L48)-58 · [composer.json:27](../../composer.json#L27)

**Problem.** Four compounding masks:

| # | Mask | Effect |
|---|---|---|
| 1 | `phpunit.xml:24-25` excludes gateway + return handler | removed from the denominator |
| 2 | `phpunit.xml:17` `processUncoveredFiles="false"` | `includes/nicepay-icons.php` (never `require`d) vanishes instead of showing 0% |
| 3 | `<include>` covers only `includes` (18-20) | `nicepay-payment-gateway.php` (495 lines, all AJAX handlers, activation, dbDelta, rewrite) and all of `admin/` are outside measurement |
| 4 | `composer test-coverage` emits HTML only | no clover, no text, no failure threshold; CI uploads a 5-day artifact |

Net effect: the published number describes `class-nicepay-api.php` plus `nicepay-functions.php` — precisely where the tests already are.

**Recommendation.** Drop the two `<file>` excludes, set `processUncoveredFiles="true"`, add `<directory suffix=".php">admin</directory>` and `<file>nicepay-payment-gateway.php</file>` to the include set, emit clover plus `--coverage-text`, and add a CI step that parses the clover metrics and fails below a floor. Set the floor at today's honest number and ratchet it.

---

### TESTS-13 · 🟡 MEDIUM · Release workflow publishes the ZIP without running tests, lint, or a version check

**Files:** [.github/workflows/release.yml:1](../../.github/workflows/release.yml#L1)-70 · [nicepay-payment-gateway.php:6](../../nicepay-payment-gateway.php#L6), 22

**Problem.** The workflow triggers on `push: tags: ['v*']` and runs exactly four steps: checkout, extract version from tag, extract changelog, build ZIP, create release. No `composer install`, no `composer test`, no `php -l` sweep, no `needs:` on the Tests workflow, no `workflow_call` reuse. It also never checks that the tag matches the `* Version: 2.0.0` header or `define( 'NICEPAY_VERSION', '2.0.0' )`. `tests.yml` is wired only to `pull_request` and push on `main`/`development` — never to tags — so nothing gates a tag push.

**Impact.** A tag pushed on a red commit ships a broken payment plugin to everyone who downloads the release ZIP. A tag/header/constant mismatch produces a release WordPress will not offer as an update, with no signal.

**Recommendation.** Convert `tests.yml` into a reusable `workflow_call` workflow and add `needs: [test, lint]` to the release job, or inline `composer install --prefer-dist --no-progress && composer test` plus the `php -l` sweep before the "Build plugin ZIP" step. Add a version-consistency step that greps the plugin header, the `NICEPAY_VERSION` define and the CHANGELOG heading for the tag version and exits 1 on mismatch.

---

### TESTS-14 · 🟡 MEDIUM · `test_generate_moid_unique` is a 1-in-9,000 flake; `moid` has no UNIQUE key

**Files:** [tests/unit/NicePayApiTest.php:90](../../tests/unit/NicePayApiTest.php#L90)-96 · [includes/class-nicepay-api.php:67](../../includes/class-nicepay-api.php#L67) · [nicepay-payment-gateway.php:142](../../nicepay-payment-gateway.php#L142)

**Problem.**

```php
// includes/class-nicepay-api.php:67
return $prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
```
```php
// tests/unit/NicePayApiTest.php:90-96
$moid1 = $this->api->generate_moid();
$moid2 = $this->api->generate_moid();
// Random suffix makes collision extremely unlikely
$this->assertNotEquals( $moid1, $moid2 );
```

Two calls within the same wall-clock second differ only by a 9,000-value random suffix, so this fails with probability ~1/9,000 per run — roughly one red build per 1,800 pushes across the five-job matrix, which will be dismissed as CI flakiness. The comment encodes the assumption instead of testing it. On the production side [nicepay-payment-gateway.php:142](../../nicepay-payment-gateway.php#L142) declares `KEY idx_moid (moid),` — a plain index, not a UNIQUE key — and `nicepay_get_transaction_by_moid()` returns an arbitrary row among duplicates.

**Impact.** A persistently flaky test plus real duplicate-Moid exposure on the **standalone** flow, where [main:335](../../nicepay-payment-gateway.php#L335) uses a constant `generate_moid( 'SP' )` prefix. Those rows have no `wc_order_id`, so the consequence is a mis-attributed transaction record.

**Recommendation.** Replace the entropy source with `bin2hex( random_bytes( 6 ) )` truncated to the spec's 64-byte `Moid` limit; add `UNIQUE KEY uniq_moid (moid)` with a dbDelta upgrade routine (which also needs the missing `nicepay_db_version` check — see TESTS-17); and turn the test into a real uniqueness assertion over ~10,000 calls, which fails today because only 9,000 distinct suffixes exist.

> *Scope correction:* the WooCommerce flow is **not** affected — [gateway:123](../../includes/class-nicepay-gateway.php#L123) calls `generate_moid( 'WC' . $order->get_id() )`, giving every order a distinct prefix, so cross-order collisions are impossible there.

---

### TESTS-15 · 🟡 MEDIUM · A test locks in the permissive `is_success_code` fallback

**Files:** [includes/class-nicepay-api.php:409](../../includes/class-nicepay-api.php#L409)-413 · [tests/unit/NicePayResultCodeTest.php:124](../../tests/unit/NicePayResultCodeTest.php#L124)-127 · [includes/class-nicepay-gateway.php:322](../../includes/class-nicepay-gateway.php#L322)

**Problem.**

```php
// includes/class-nicepay-api.php:409-413
if ( $payment_method && isset( $success_codes[ $payment_method ] ) ) {
    return $code === $success_codes[ $payment_method ];
}
return in_array( $code, array_values( $success_codes ), true );
```
```php
// tests/unit/NicePayResultCodeTest.php:124-127
public function test_success_code_unknown_method_checks_all(): void {
    // Unknown method falls through to in_array check against all success codes
    $this->assertTrue( $this->api->is_success_code( '3001', 'UNKNOWN_METHOD' ) );
}
```

The test asserts the permissive union behaviour is correct and protects it from being tightened. In `handle_return()` the method is `$result_method = isset( $result['PayMethod'] ) ? $result['PayMethod'] : $pay_method;` where `$pay_method` came from `$_POST`. With an unrecognised or empty method, the union accepts `'4100'` (VBANK issued, no money received) for what the merchant believes is a card sale; line 359's `( $result_method === 'VBANK' )` is then false, so line 380 calls `$order->payment_complete( $tid )` instead of putting the order on-hold.

**Recommendation.** Fail closed when a method is supplied but unknown:

```php
if ( '' !== $payment_method ) {
    return isset( $success_codes[ $payment_method ] ) && $code === $success_codes[ $payment_method ];
}
```

Both call sites ([gateway:358](../../includes/class-nicepay-gateway.php#L358), [return-handler:142](../../includes/class-nicepay-return-handler.php#L142)) always pass a method, so nothing legitimate regresses. Replace `test_success_code_unknown_method_checks_all` with an assertion that an unknown method is **rejected**, and add a test proving `'4100'` never satisfies `'CARD'`.

> *Reachability is narrow:* the plugin's map covers all six documented `PayMethod` values, so triggering the union path requires the PG to return an undocumented method or omit `PayMethod` from both the response and the return POST. The result-code table itself is correct against §7 and §9.

---

### TESTS-16 · 🟡 MEDIUM · EUC-KR is offered in the admin but never actually encoded

**Files:** [includes/class-nicepay-api.php:213](../../includes/class-nicepay-api.php#L213)-220, 300, 349 · [admin/class-nicepay-admin.php:239](../../admin/class-nicepay-admin.php#L239)-240

**Problem.** `grep -rn "mb_convert\|iconv" --include="*.php" .` returns **zero** matches across the whole plugin. The plugin only *declares* the charset — it sets the `CharSet` body param and a `Content-Type: …; charset=<charset>` header — while `wp_remote_post` transmits the PHP strings byte-for-byte, i.e. UTF-8, because WordPress is UTF-8 internally:

```php
// includes/class-nicepay-api.php:213-220
$response = wp_remote_post( $next_app_url, array(
    'timeout'   => 30,
    'sslverify' => true,
    'headers'   => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=' . $this->charset ),
    'body'      => $params,
) );
```

The default is `utf-8` ([main:160](../../nicepay-payment-gateway.php#L160)), which is spec-legal per §4. But [admin/class-nicepay-admin.php:240](../../admin/class-nicepay-admin.php#L240) offers `<option value="euc-kr" ...>EUC-KR</option>` as a first-class choice, and selecting it makes the plugin tell NICEPAY the body is EUC-KR while sending UTF-8. Both templates propagate the option into `accept-charset` ([payment-form.php:51](../../templates/payment-form.php#L51), [standalone-payment-form.php:128](../../templates/standalone-payment-form.php#L128)).

**Impact.** Any merchant who picks EUC-KR gets a charset declaration that does not match the bytes, with corrupted Korean `GoodsName`, `BuyerName` and `CancelMsg` on the PG side. Zero tests for either the missing conversion or the round trip.

**Recommendation.** Pick one and pin it. **Simplest and safest:** remove the EUC-KR option, hard-code `utf-8` (spec-legal per §4), and drop the `accept-charset` attributes. If EUC-KR must stay, transcode every outgoing body value with `mb_convert_encoding` when the option is `euc-kr`, and transcode the incoming POST back to UTF-8 **before** any `sanitize_text_field` call. Either way add a test asserting the wire bytes match the declared `CharSet`, plus a test that `GoodsName` truncation is byte-based and never splits a multibyte character (the code already uses `mb_strcut` at [gateway:144](../../includes/class-nicepay-gateway.php#L144) — nothing pins it).

---

### TESTS-17 · 🟡 MEDIUM · No WordPress integration suite

**Files:** [tests/bootstrap/bootstrap.php:13](../../tests/bootstrap/bootstrap.php#L13), 24-26 · [nicepay-payment-gateway.php:104](../../nicepay-payment-gateway.php#L104)-148, 161, 202-264

**Problem.** There is no `.wp-env.json`, no `package.json`, no `wordpress-tests-lib` bootstrap and no `WP_UnitTestCase` anywhere; `phpunit.xml` declares a single `Unit` testsuite over `tests/unit`. Consequently untested:

| Unit | Failure mode if wrong |
|---|---|
| `activate()` → `create_tables()` → `dbDelta` (104-148) | `dbDelta` is whitespace/KEY-format sensitive; a malformed `CREATE TABLE` silently produces **no table** |
| Rewrite rule `^nicepay-return/?$` + flush ordering (202-212) | A rule that does not persist means the whole standalone flow 404s |
| `render_payment_shortcode()` id→config merge and `shortcode_atts` precedence (225-264) | Wrong amount or method set rendered |
| Gateway registration via `woocommerce_payment_gateways` (180-183), `plugins_loaded` priority 11 | Gateway does not appear at checkout |
| Schema upgrade path | `nicepay_db_version` appears **exactly once** in the entire repo — at [main:161](../../nicepay-payment-gateway.php#L161) where it is *written* — and is never read, so a schema change can never reach an existing install |

**Recommendation.** Add a second suite: `composer require --dev wp-phpunit/wp-phpunit yoast/phpunit-polyfills`, a `tests/integration/bootstrap.php` that loads the WP test library plus the plugin, and a `<testsuite name="Integration">` entry. Minimum set: activation creates the table with all 24 columns and five indexes; `/nicepay-return/` resolves with `get_query_var('nicepay_return') === '1'` and `is_404()` false; the shortcode renders the amount, the method radios and the hidden `Amt`; `WC()->payment_gateways()` contains `WC_Gateway_NicePay`; and a version bump re-runs `dbDelta` — which requires first adding the missing `if ( get_option('nicepay_db_version') !== NICEPAY_VERSION )` upgrade check.

> *Latent trap:* [bootstrap.php:13](../../tests/bootstrap/bootstrap.php#L13) defines `ABSPATH` as `/tmp/wordpress/`, so `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` ([main:146](../../nicepay-payment-gateway.php#L146)) would fatal if `create_tables()` were ever reached from the current suite.

---

### TESTS-18 · 🟡 MEDIUM · AJAX nonce/capability checks untested, and the localized nonce action does not match the one verified

**Files:** [nicepay-payment-gateway.php:300](../../nicepay-payment-gateway.php#L300), 314-317 · [admin/class-nicepay-transactions.php:186](../../admin/class-nicepay-transactions.php#L186)

**Problem.** No test touches any handler, and the suite could not load them anyway (TESTS-10). The checks that exist are mostly well-built: `ajax_save_shortcode` and `ajax_delete_shortcode` both require `manage_options` **and** the `nicepay_admin_shortcodes` nonce; `ajax_cancel_transaction` requires `manage_options` **and** a per-row nonce:

```php
// admin/class-nicepay-transactions.php:186
if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'nicepay_cancel_' . $id ) ) {
```

Two things only a test would keep honest:

1. `ajax_init_payment` is `nopriv` by design ([main:82](../../nicepay-payment-gateway.php#L82)) and completely **unthrottled** — any visitor can loop it to create unlimited `pending` rows in `wp_nicepay_transactions`.
2. A dead nonce: `enqueue_payment_assets()` localizes `'nonce' => wp_create_nonce( 'nicepay_payment' )` (main:300) while the handler verifies `'nicepay_init_payment'` (main:316). `nicepayParams.nonce` is never read by any JS — only `nicepayParams.i18n` is ([nicepay.js:37](../../assets/js/nicepay.js#L37), 54, 61) — so the localized key is a decoy that will silently produce "Invalid request." for the next developer who wires a JS call to it. The working nonce is minted inline in the template at [standalone-payment-form.php:305](../../templates/standalone-payment-form.php#L305).

**Recommendation.** Add integration tests for all four handlers covering missing nonce, wrong-action nonce, valid nonce with insufficient capability, and the happy path — asserting `success === false` with message `Unauthorized.` and zero PG calls (backlog item 11). Delete the unused `'nonce'` key at main:300 or change it to `wp_create_nonce('nicepay_init_payment')`. Add a per-IP transient throttle on `ajax_init_payment` and test it.

---

### TESTS-19 · 🟡 MEDIUM · Log redaction is entirely unverified — and weaker than it looks

**Files:** [tests/unit/NicePayFunctionsTest.php:217](../../tests/unit/NicePayFunctionsTest.php#L217)-225 · [includes/nicepay-functions.php:16](../../includes/nicepay-functions.php#L16)-35 · [includes/class-nicepay-api.php:157](../../includes/class-nicepay-api.php#L157)-174

**Problem.** `WP_DEBUG` is defined in neither `bootstrap.php` nor `phpunit.xml`, so `nicepay_log()` returns at lines 17-19 on every call. The only test acknowledges this and then asserts nothing:

```php
// tests/unit/NicePayFunctionsTest.php:217-225
public function test_log_does_not_error_when_debug_off(): void {
    // WP_DEBUG is not defined in test env, so logging should silently skip
    ...
    $this->assertTrue( true ); // No exception = pass
}
```

Dead as far as the suite is concerned: the `wc_get_logger()` branch, the `error_log()` fallback, the `JSON_UNESCAPED_UNICODE` encoding of Korean payloads, and `redact_for_log()`, which is private and untested. Reading it:

```php
// includes/class-nicepay-api.php:167-171
foreach ( $sensitive_keys as $key ) {
    if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && strlen( $data[ $key ] ) > 8 ) {
        $data[ $key ] = substr( $data[ $key ], 0, 4 ) . '****' . substr( $data[ $key ], -4 );
    }
}
```

It masks only **top-level** keys from a fixed list (`AuthToken`, `SignData`, `Signature`, `MerchantKey`, `CardNo`, `CardNumber`, `VbankNum`) and only when the value exceeds 8 characters — so a short `CardNo` passes through verbatim, nested structures are untouched, and `BuyerTel`, `BuyerEmail`, `BuyerName`, `MallUserID`, `AuthCode` and `RcptAuthCode` are not in the list at all. Separately, the **full unredacted** approval response is persisted to the database as `payment_data` ([gateway:332](../../includes/class-nicepay-gateway.php#L332), [return-handler:125](../../includes/class-nicepay-return-handler.php#L125)) along with `auth_token`.

**Recommendation.** Define `WP_DEBUG` in a `<php>` block in `phpunit.xml` so the real branch runs; extract redaction into a public `nicepay_redact_for_log()` helper so it is testable without reflection; extend the key list to buyer PII and `AuthCode`; apply it recursively; drop the `strlen > 8` condition in favour of masking any non-empty value; and add a test that captures the emitted line and asserts no raw secret appears.

---

### TESTS-20 · 🟡 MEDIUM · `validate_nicepay_url` — the SSRF allowlist — has no test

**Files:** [includes/class-nicepay-api.php:130](../../includes/class-nicepay-api.php#L130)-152

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

Private, zero tests, and three gaps: the host comparison is **strict and case-sensitive** against an all-lowercase list, but DNS hostnames are case-insensitive and `parse_url` preserves case — so `https://DC1-API.nicepay.co.kr/webapi/pay_process.jsp` is a legitimate URL this function rejects (and rejection on the approval path returns early **without** a net cancel — TESTS-04). The **port is not constrained**: `parse_url` splits the port out of `host`, so `https://dc1-api.nicepay.co.kr:9999/x` passes while §2 fixes the port at 443. The **path is not validated** either, though §5 defines only `pay_process.jsp` and `cancel_process.jsp`.

On the credit side, the allowlist correctly contains the four documented hosts and userinfo tricks like `https://dc1-api.nicepay.co.kr@evil.com` are correctly rejected because `parse_url` returns `evil.com` as the host — but nothing asserts that, so a future rewrite using a regex could reintroduce it silently.

**Recommendation.** Lowercase the host and scheme before comparison, reject any port other than 443, optionally pin the path to the two documented JSPs, make the method `protected`/`public static` so it is testable, and add the ten-case hostile-corpus data provider (backlog item 15).

---

### TESTS-21 · 🟡 MEDIUM · All five database functions untested because `wp-stubs.php` defines no `$wpdb`

**Files:** [includes/nicepay-functions.php:43](../../includes/nicepay-functions.php#L43)-220 · [tests/bootstrap/wp-stubs.php](../../tests/bootstrap/wp-stubs.php)

**Problem.** `nicepay_save_transaction`, `nicepay_update_transaction`, `nicepay_get_transaction_by_tid`, `nicepay_get_transaction_by_moid` and `nicepay_get_transactions` all use `global $wpdb`, which the stub file never defines. Any test touching them would fatal, so none exist. Untested behaviours that matter:

- `nicepay_save_transaction` passes `$data` straight to `$wpdb->insert( $table, $data )` (81) with **no format array and no key whitelist**, so an unexpected key silently becomes an invalid column and the insert returns `false`.
- `nicepay_get_transactions` interpolates the sort clause:

```php
// includes/nicepay-functions.php:195-197, 210
$allowed_orderby = array( 'created_at', 'amount', 'status', 'payment_method' );
$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
$sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
```

The allowlist is correct today, but nothing pins it — a future edit that drops it is an injection with no test to stop it.
- The `$count_sql` is only prepared when `$values` is non-empty (204-206) — an uncovered branch.
- The `$defaults` array (48-73) enumerates 24 columns that must stay in sync with the `CREATE TABLE` at [main:110](../../nicepay-payment-gateway.php#L110)-144, with nothing comparing them.

**Recommendation.** Test these in the WP integration suite against a real transactional database rather than faking `$wpdb` — `WP_UnitTestCase` rolls back after each test. Minimum set: round-trip `save → get_by_tid → get_by_moid`; each filter (status, method, date range, search with LIKE-wildcard escaping); pagination offset arithmetic; an `ORDER BY` injection attempt asserting the query still returns valid rows; and a schema-drift test asserting every key in `$defaults` exists as a column in the created table.

---

### TESTS-22 · 🟡 MEDIUM · CI matrix omits PHP 8.4, has no WP/WC dimension, installs Xdebug on every job

**Files:** [.github/workflows/tests.yml:17](../../.github/workflows/tests.yml#L17), 28, 48-50 · [nicepay-payment-gateway.php:11](../../nicepay-payment-gateway.php#L11)-14

**Problem.** The matrix is `php: ['7.4', '8.0', '8.1', '8.2', '8.3']`. The header declares `Requires PHP: 7.4` with no upper bound, so PHP 8.4 (GA Nov 2024) and 8.5 are untested — and 8.4 is where this codebase's habits bite, since `failOnWarning="true"` turns implicit-null deprecations into red builds. There is no WordPress or WooCommerce dimension at all, so the three compatibility promises in the header (`Requires at least: 5.0`, `WC requires at least: 5.0`, `WC tested up to: 9.0`) are never exercised. There is also no `Tested up to:` WordPress header. Minor waste: `coverage: xdebug` is set for all five legs (line 28) though coverage runs only on 8.2 (line 49), slowing every job for nothing.

**Recommendation.** Add `'8.4'` to the matrix (check the PHPUnit constraint supports it — `^9.6.20` or a `^10` upgrade may be needed). Set `coverage: none` except on the single coverage leg via a matrix `include`. Add a `Tested up to:` header and raise the WC ceiling. Once the integration suite exists, add a second job with a WP × WC matrix plus one `WP_MULTISITE=1` run.

> *Overlap note:* the WP/WC-matrix half of this overlaps TESTS-17 and should not be counted twice when prioritising.

---

### TESTS-23 · 🟡 MEDIUM · No static analysis and no coding-standards gate

**Files:** [.github/workflows/tests.yml:77](../../.github/workflows/tests.yml#L77)-79 · [CONTRIBUTING.md:123](../../CONTRIBUTING.md#L123)

**Problem.** The lint job is `php -l` only — a parse check — and it explicitly excludes the test directory:

```yaml
# .github/workflows/tests.yml:77-79
- name: Check syntax (PHP Lint)
  run: |
    find . -name "*.php" -not -path "./vendor/*" -not -path "./tests/*" -print0 | xargs -0 -n1 php -l
```

No `phpcs.xml`, `phpcs.xml.dist` or `phpstan.neon` exists anywhere in the repo. Missing: PHPCS with WordPress-Coding-Standards, which `CONTRIBUTING.md:123` asks contributors to satisfy manually (`- [ ] Code follows WordPress coding standards`) with zero automation behind it, and which would flag concrete issues present here — 4-space indentation instead of tabs throughout, `date()` instead of `gmdate()` (api:60, 67, 428), `error_log()` (functions:33), and unescaped output such as `<?php echo nicepay_get_method_icon( $method ); ?>` ([templates/payment-form.php:39](../../templates/payment-form.php#L39)). Also missing: PHPStan with `szepeviktor/phpstan-wordpress`, the official `wordpress/plugin-check-action`, and any i18n check (`wp i18n make-pot --check`) despite four locales and nine committed `.po`/`.mo` files.

**Recommendation.** Add PHPCS with WordPress-Extra + WordPress.Security (start with `--warning-severity=0` so warnings do not block on day one), PHPStan level 5 with the WordPress extension, and `wordpress/plugin-check-action`, each as its own job so failures are legible. Remove the `-not -path "./tests/*"` exclusion.

---

### TESTS-24 · 🟡 MEDIUM · `date()` instead of `gmdate()` makes `EdiDate` and `VbankExpDate` timezone-dependent

**Files:** [includes/class-nicepay-api.php:60](../../includes/class-nicepay-api.php#L60), 67, 428 · [tests/unit/NicePayApiTest.php:320](../../tests/unit/NicePayApiTest.php#L320)-327 · [phpunit.xml](../../phpunit.xml)

**Problem.** Three production values are built with bare `date()`:

```php
// includes/class-nicepay-api.php:59-61, 66-68, 426-429
public function generate_edi_date() { return date( 'YmdHis' ); }
return $prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
$days = (int) get_option( 'nicepay_vbank_expiry_days', 3 );
return date( 'YmdHi', strtotime( '+' . $days . ' days' ) );
```

WordPress forces PHP's default timezone to UTC in `wp-settings.php`, so `EdiDate` and `VbankExpDate` are emitted in UTC — nine hours behind KST, which is how NICEPAY interprets them. The existing tests cannot detect this because they compare `date()` output against `new DateTime()` — both resolve in the same ambient timezone:

```php
// tests/unit/NicePayApiTest.php:320-327
$exp_time = \DateTime::createFromFormat( 'YmdHi', $exp );
$now = new \DateTime();
$this->assertGreaterThan( $now, $exp_time );
```

`phpunit.xml` has no `<php>` block at all, so the suite inherits the runner's timezone: a developer in `Asia/Seoul` and CI in UTC exercise different values with no visible difference.

**Impact.** Virtual-account deadlines are wrong by nine hours for the plugin's primary market, and `EdiDate` — part of the approval SignData plaintext — is generated in a timezone the merchant never chose.

**Recommendation.** Decide the timezone explicitly and freeze the clock in tests. If NICEPAY expects KST, build both values from a `DateTimeImmutable` in `Asia/Seoul` (or use `wp_date()`/`current_time()` so the site timezone governs) and never call bare `date()`. Add `<php><ini name="date.timezone" value="UTC"/></php>` to `phpunit.xml`, inject a clock into `NicePay_API`, and assert exact strings rather than "is in the future".

---

### TESTS-25 · 🟡 MEDIUM · No JavaScript tests, no JS linting, ~130 lines of payment logic inline in a PHP template

**Files:** [templates/standalone-payment-form.php:174](../../templates/standalone-payment-form.php#L174)-366 · [assets/js/nicepay.js:32](../../assets/js/nicepay.js#L32)-65 · [assets/js/nicepay-admin.js:142](../../assets/js/nicepay-admin.js#L142)-186

**Problem.** There is no `package.json`, no ESLint config, no Jest/Vitest and no JS step in CI. Meanwhile roughly 700 lines of JavaScript carry real payment logic, much of it inline in a PHP template where it is unreachable by any linter or unit test: `standalone-payment-form.php:191-306` does client-side validation (email regex at 212, phone regex `/^[\d\-+() ]{7,20}$/` at 214), builds a hand-concatenated XHR body, sets `document.payForm = form` (276) and calls `nicepayStart()` (278):

```js
// templates/standalone-payment-form.php:305 — the entire request body is one concatenated string
xhr.send('action=nicepay_init_payment&nonce=<?php echo esc_js( wp_create_nonce( 'nicepay_init_payment' ) ); ?>&amount=<?php echo esc_js( $amount ); ?>&goods_name=' + encodeURIComponent(...)
```

[assets/js/nicepay.js:47](../../assets/js/nicepay.js#L47) (`if (typeof nicepayStart === 'function') {`) is the only guard against the vendor script failing to load. `assets/js/nicepay-admin.js:142-186` is the transaction-cancel confirm modal that fires a real PG cancel. The template also relies on globals and inline handlers (`onclick="nicepayOpenPaymentModal('…')"` at 56, `window.nicepaySubmit = window.nicepaySubmit || …` at 319), so two shortcodes on one page share `window.nicepaySubmit` and `document.payForm`.

**Recommendation.** Extract the inline template JS into `assets/js/nicepay-standalone.js` with `wp_localize_script()` for the nonce, amount and labels (this also removes an un-lintable, un-CSP-able inline script). Add `package.json` with `@wordpress/eslint-plugin` and Vitest + jsdom, and test: validation rejects a bad email/phone and focuses the first invalid field; a successful AJAX response populates `EdiDate`/`Moid`/`SignData` and calls `nicepayStart()`; a failed response restores the button and renders exactly one notice; two shortcode instances on one page do not clobber each other's `document.payForm`.

---

### TESTS-26 · 🟡 MEDIUM · No recorded PG response fixtures and no end-to-end checkout test

**Files:** [docs/DEVELOPER-GUIDE.md:491](../../docs/DEVELOPER-GUIDE.md#L491)-509 · `tests/`

**Problem.** The only documented testing for the checkout flow is manual: the DEVELOPER-GUIDE "Testing" section contains the test MID and merchant key plus three cautions (VBANK only to issuance, avoid partial cancels on simple-pay, merchant-admin login) — useful guidance, but a human runbook that never mentions `composer test` or the unit suite. There is no Playwright/Cypress suite, no WooCommerce checkout smoke test, and — the cheapest fixable gap — **no committed fixtures of real NICEPAY responses**. `tests/` contains only `bootstrap/` and `unit/`; there is no `fixtures/` directory. Without fixtures, every future test author invents what an approval response looks like, and inventions drift from reality — which is exactly how the padded-`Amt` ambiguity (TESTS-03) survives unnoticed. §12's "new fields may be added over time; merchants must tolerate extra fields" is also a testable property nobody tests.

**Recommendation.** Commit `tests/fixtures/` with sanitised JSON captured from the test MID for each method — CARD approval `3001`, BANK `4000`, VBANK `4100`, CELLPHONE `A000`, a declined card, a net-cancel `2001`, a cancel `2001` and a cancel `2211` — and drive the handler tests from them with a data provider. Add a "tolerates unknown response fields" test per §12. Layer a Playwright happy-path checkout on top once the integration suite exists. **Sequence the fixtures half first** — it is cheap and high-leverage.

---

### TESTS-27 · 🟡 MEDIUM · `request_cancel` skips response-signature verification when the response omits TID or Signature

**Files:** [includes/class-nicepay-api.php:384](../../includes/class-nicepay-api.php#L384)-393 (compare [247](../../includes/class-nicepay-api.php#L247)-256)

**Problem.** `request_approval` treats a missing Signature as fatal — net cancel plus `WP_Error`. `request_cancel` does the opposite:

```php
// includes/class-nicepay-api.php:384-391
// Verify cancel response signature
if ( ! empty( $result['TID'] ) && ! empty( $result['Signature'] ) ) {
    $resp_cancel_amt = isset( $result['CancelAmt'] ) ? $result['CancelAmt'] : $cancel_amt;
    if ( ! $this->verify_cancel_signature( $result['TID'], $resp_cancel_amt, $result['Signature'] ) ) {
        ...
        return new WP_Error( ... );
    }
}
```

The outer `if` makes the whole check **optional**: a cancel response that simply omits either field skips verification and is returned as a success payload. Both callers then act on it — `process_refund` tells WooCommerce the refund succeeded ([gateway:453](../../includes/class-nicepay-gateway.php#L453)-469) and `ajax_cancel_transaction` marks the transaction and the WooCommerce order cancelled ([transactions:217](../../admin/class-nicepay-transactions.php#L217)-232). No test covers either path.

**Impact.** Any cancel response the plugin cannot authenticate is accepted as authentic — a refund recorded locally that the PG may not have performed, with the merchant's books and the PG's books diverging silently. TLS makes forgery hard, but the point of a signature is to not depend on that, and the approval path already gets this right.

**Recommendation.** Make cancel verification mandatory and symmetric with approval: require `TID` and `Signature`, return `new WP_Error( 'nicepay_signature_error', … )` when either is missing, and only then verify. Add two tests — a valid-signature cancel succeeds; a cancel with `Signature` removed returns a `WP_Error` and does **not** mark the transaction cancelled.

---

### TESTS-28 · 🟡 MEDIUM · The spec-mandated 5-second connect timeout is not implemented

**Files:** [includes/class-nicepay-api.php:213](../../includes/class-nicepay-api.php#L213)-220, 305-312, 360-367

**Problem.** §2 states the required timeouts as "Connection 5 sec, Receive(Read) 30 sec". All three `wp_remote_post` calls pass only `'timeout' => 30`, which WordPress's cURL transport applies to **both** `CURLOPT_CONNECTTIMEOUT` and `CURLOPT_TIMEOUT` — so the connect phase is allowed 30 seconds, six times the specified budget. `grep -n "connect_timeout\|CONNECTTIMEOUT"` over the plugin returns nothing. No test inspects the transport arguments at all.

**Impact.** A NICEPAY host that accepts no connection makes the approval request hang for 30 seconds on a customer-facing request before the net cancel is even attempted, and repeated hangs saturate PHP-FPM workers during a PG incident — precisely the scenario the tighter connect budget exists to bound. The timeout can also be changed to anything without CI noticing.

**Recommendation.** Hook `http_api_curl` to set `CURLOPT_CONNECTTIMEOUT` to 5 while leaving `'timeout' => 30` as the read budget. Once the transport is injectable, assert the argument array on all three call sites:

```php
$this->assertSame( 30, $args['timeout'] );
$this->assertTrue( $args['sslverify'] );
```

---

### TESTS-29 · 🟡 MEDIUM · `ajax_init_payment` is the only amount path that skips `nicepay_get_amount()` normalization

**Files:** [nicepay-payment-gateway.php:322](../../nicepay-payment-gateway.php#L322)-347 · [includes/nicepay-functions.php:248](../../includes/nicepay-functions.php#L248)-258

**Problem.** Every other amount path normalizes — [gateway:124](../../includes/class-nicepay-gateway.php#L124) (auth form), gateway:437-438 (refund), [transactions:205](../../admin/class-nicepay-transactions.php#L205) (admin cancel), [standalone-payment-form.php:25](../../templates/standalone-payment-form.php#L25) (template render). `ajax_init_payment` does not:

```php
// nicepay-payment-gateway.php:322, 328, 336
$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
if ( empty( $amount ) || (float) $amount <= 0 ) { ... }
$sign_data = $api->create_auth_sign_data( $edi_date, $amount );
```

So `amount=100.99` with the site currency KRW is signed and stored as `'100.99'`, while every other path would have produced `'100'`; `amount=1e3` likewise passes the float guard and is signed literally. Per §4 `Amt` is a 12-byte field and KRW is an integer currency.

**Impact.** The SignData is minted over a value that does not match the canonical form the rest of the plugin produces, so the amount the PG sees, the amount stored in the `decimal(12,2)` column, and the amount any later refund computes can disagree. This is a **second, independent** defect from TESTS-01 and survives even after the tampering guard is added.

**Recommendation.** Mirror `templates/standalone-payment-form.php:24-25`:

```php
$amount = nicepay_get_amount( preg_replace( '/[^0-9.]/', '', $amount ), get_option( 'nicepay_currency', 'KRW' ) );
if ( ! ( (float) $amount > 0 ) ) { wp_send_json_error( ... ); }
```

before both `create_auth_sign_data()` and `nicepay_save_transaction()`. Add a test asserting `amount=100.99` with KRW produces the digest for `'100'` and stores `100`.

---

### TESTS-30 · 🔵 LOW · `composer.lock` is gitignored, so CI resolves dependencies fresh every run

**Files:** [.gitignore:25](../../.gitignore#L25)-26 · [.github/workflows/tests.yml:39](../../.github/workflows/tests.yml#L39), 42-43

**Problem.** `.gitignore` ends with `# Composer` / `composer.lock`, and no lock file exists. So `composer install --prefer-dist --no-progress` resolves fresh on every run: `phpunit/phpunit ^9.6`, `mockery/mockery ^1.6` and `brain/monkey ^2.6` can each move underneath the project — a green build today can be red tomorrow with no code change. The cache step compounds it: `key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock', '**/composer.json') }}` hashes a file that never exists, so the key degrades to the `composer.json` hash and the cache pins whatever versions were resolved when it was first populated, letting different matrix legs run different dependency sets.

**Recommendation.** Commit `composer.lock` (the plugin has no runtime dependencies, so this pins only dev tooling and there is no downstream-consumer argument against it), remove the two ignore lines, and add `--no-interaction` to the install. Add Dependabot for `composer` and `github-actions` so upgrades arrive as reviewable PRs rather than surprise CI failures.

---

### TESTS-31 · 🔵 LOW · `phpunit.xml` has no `<php>` block

**Files:** [phpunit.xml:2](../../phpunit.xml#L2)-15

**Problem.** The config sets `failOnRisky` and `failOnWarning` (good) but omits everything that makes runs reproducible and legible. The file is 28 lines and contains only `<testsuites>` (11-15) and `<coverage>` (17-27): no `<php>` section, so `date.timezone`, `WP_DEBUG` and `error_reporting` are whatever the machine says; no `cacheResultFile` location; no `beStrictAboutOutputDuringTests`, which matters here because `NicePay_Return_Handler::render_result_page()` echoes a full HTML document ([return-handler:170](../../includes/class-nicepay-return-handler.php#L170)-267) — the moment anyone tests it, output leaks into the runner instead of failing loudly; and no JUnit logging or testdox, so GitHub shows a wall of dots.

**Recommendation.**

```xml
<php>
    <ini name="date.timezone" value="UTC"/>
    <ini name="error_reporting" value="-1"/>
    <const name="WP_DEBUG" value="true"/>
</php>
```

Also enable `beStrictAboutOutputDuringTests` and `beStrictAboutTestsThatDoNotTestAnything`, set `cacheResultFile`, and emit JUnit XML plus `--testdox` in CI. Note that defining `WP_DEBUG` switches on the untested logging branch (TESTS-19).

---

### TESTS-32 · 🔵 LOW · Saved-shortcode option default diverges between the reader and the two AJAX writers

**Files:** [includes/nicepay-functions.php:429](../../includes/nicepay-functions.php#L429)-433 · [nicepay-payment-gateway.php:380](../../nicepay-payment-gateway.php#L380), 403, 453-456

**Problem.**

```php
// includes/nicepay-functions.php:429-433 — reader re-seeds on null
$shortcodes = get_option( 'nicepay_saved_shortcodes', null );
if ( $shortcodes === null ) {
    $shortcodes = nicepay_get_default_presets();
    update_option( 'nicepay_saved_shortcodes', $shortcodes );
}
```
```php
// nicepay-payment-gateway.php:380 (identical at :453) — writers default to []
$shortcodes = get_option( 'nicepay_saved_shortcodes', array() );
```

If the option is absent — files copied into place without running `activate()`, a migration, a manual `delete_option` — saving one shortcode starts from `[]` and writes an array containing only the new entry, **permanently destroying the four presets**, because the seeding path only fires on `null` and the option is now a non-null `[]`. Related: both writers dereference `$sc['id']` without an `isset` guard (main:403 in the update loop, main:455 in the delete filter), raising a warning on PHP 8 for an entry lacking an `id` key.

**Recommendation.** Route every read through `nicepay_get_all_shortcodes()` so seeding is centralised, and add `isset( $sc['id'] )` guards at both main:403 and main:455. Test that saving a shortcode with the option absent preserves the four presets, and that delete tolerates an entry without an id.

> *Reachability is narrow:* `set_default_options()` seeds the option via `add_option` at [main:162](../../nicepay-payment-gateway.php#L162) during activation.

---

### TESTS-33 · 🔵 LOW · `nicepay_get_method_icon` is not loaded by the bootstrap and its output is echoed unescaped

**Files:** [includes/nicepay-icons.php:68](../../includes/nicepay-icons.php#L68)-74 · [tests/bootstrap/bootstrap.php:24](../../tests/bootstrap/bootstrap.php#L24)-26 · [templates/payment-form.php:39](../../templates/payment-form.php#L39) · [templates/standalone-payment-form.php:86](../../templates/standalone-payment-form.php#L86)

**Problem.** The function returns raw SVG markup that both templates emit with an unescaped echo (`<?php echo nicepay_get_method_icon( $method ); ?>`). `bootstrap.php` never requires `includes/nicepay-icons.php`, so the function is not even defined during tests, and because `phpunit.xml:17` sets `processUncoveredFiles="false"` the file vanishes from the coverage denominator rather than being reported at 0%. The function is currently **safe** — all six SVGs are hard-coded literals and an unknown method returns `''`:

```php
// includes/nicepay-icons.php:68-74
$svg = isset( $icons[ $method ] ) ? $icons[ $method ] : '';
if ( ! $svg ) { return ''; }
return '<span class="nicepay-method-icon" aria-hidden="true">' . $svg . '</span>';
```

— but that safety is exactly the invariant worth pinning, since the call sites deliberately bypass escaping.

**Recommendation.** Add `require_once NICEPAY_PLUGIN_DIR . 'includes/nicepay-icons.php';` to `bootstrap.php`, set `processUncoveredFiles="true"`, and add a data-provider test asserting each of the six methods returns a `<span class="nicepay-method-icon" aria-hidden="true">` wrapper containing an `<svg`, that unknown input returns exactly `''`, and that the output matches no `<script`, `javascript:` or `on*=` pattern.

---

### TESTS-34 · 🔵 LOW · Contributors are never told the test suite exists

**Files:** [CONTRIBUTING.md:47](../../CONTRIBUTING.md#L47)-60, 122-127 · [composer.json:25](../../composer.json#L25)-29

**Problem.** `composer.json` defines three scripts (`test`, `test-coverage`, `test-filter`) that appear nowhere in the documentation. `grep -n "composer\|phpunit\|test" CONTRIBUTING.md` returns four hits, none of them the test command: "WooCommerce 5.0+ (for gateway testing)", the prose prompts "Testing — Describe how you tested the changes" and "How was this tested?", and a commit-type table row. The Local Setup block covers clone, `cd`, `ln -s` and "Activate the plugin in WordPress admin" — `composer install` is never mentioned. The PR checklist is:

```
- [ ] Code follows WordPress coding standards
- [ ] No sensitive data (keys, passwords) in code
- [ ] All strings are translatable
- [ ] Tested with WooCommerce enabled
- [ ] Tested with WooCommerce disabled (standalone mode)
```

No automated gate, no "add a test" item. `ls -R .github` returns only `workflows`, so the PR template embedded in CONTRIBUTING.md is never actually rendered to a contributor opening a PR.

**Recommendation.** Add a "Running the tests" section with `composer install && composer test`, document `composer test-filter <name>` and the coverage command, add `- [ ] New or changed logic is covered by a test` and `- [ ] composer test passes locally` to the checklist, point contributors at `tests/unit/NicePaySignatureIntegrationTest.php` as the vendor-fixture pattern to copy, and move the template into `.github/PULL_REQUEST_TEMPLATE.md` so GitHub renders it.

---

### TESTS-35 · 🔵 LOW · No HTTP status code is ever checked on the three PG calls

**Files:** [includes/class-nicepay-api.php:222](../../includes/class-nicepay-api.php#L222)-243, 314-323, 369-380 · [tests/bootstrap/wp-stubs.php:87](../../tests/bootstrap/wp-stubs.php#L87)-94

**Problem.** All three network methods go from `is_wp_error( $response )` straight to `wp_remote_retrieve_body()` and `json_decode()`:

```php
// includes/class-nicepay-api.php:222-233 (same shape at 314-320 and 369-375)
if ( is_wp_error( $response ) ) { ... return $response; }
$body   = wp_remote_retrieve_body( $response );
$result = json_decode( $body, true );
```

`grep -n "wp_remote_retrieve_response_code" includes/class-nicepay-api.php` returns nothing — a 500, 502 or 403 from the PG is treated exactly like a 200 and distinguished only by whether its body happens to parse as JSON. `wp-stubs.php` stubs `wp_remote_retrieve_body` but not `wp_remote_retrieve_response_code`, consistent with the code never calling it.

**Impact.** Behaviour is mostly saved by luck: an HTML error page fails `json_decode`, so approval falls into the parse-error branch that does fire a net cancel. But diagnostics are poor (the log says "parse error" when the cause was HTTP 502), and a gateway or WAF returning a 4xx with a JSON body would be interpreted as PG data.

**Recommendation.** After the `is_wp_error` check in each method, add `$code = (int) wp_remote_retrieve_response_code( $response );` and treat anything other than 200 as a failure — for `request_approval` that means taking the same net-cancel path as a transport error — logging the status alongside the body. Stub `wp_remote_retrieve_response_code` and add a test per method.

---

## 3. TESTS-36 · ✨ The work plan: 15 tests to add, ordered by money risk

This is the deliverable. Items **1-6** need the injectable transport (TESTS-11). Items **7-13** need the WP integration suite (TESTS-17). Items **14-15** are pure unit tests writable **today**.

| # | Test name | Retires |
|---|---|---|
| 1 | `test_approval_signature_accepts_zero_padded_amt` | TESTS-03 |
| 2 | `test_approval_aborts_when_response_amt_differs_numerically` | TESTS-03, TESTS-01 |
| 3 | `test_net_cancel_is_sent_when_approval_transport_fails` | TESTS-04, TESTS-08 |
| 4 | `test_net_cancel_is_sent_when_approval_signature_is_invalid` | TESTS-04 |
| 5 | `test_net_cancel_is_sent_when_approval_url_is_rejected` | TESTS-04 (**fails today**) |
| 6 | `test_approval_declined_does_not_trigger_net_cancel` | TESTS-04 |
| 7 | `test_return_handler_aborts_when_posted_amt_differs_from_stored_amount` | TESTS-01 |
| 8 | `test_return_handler_rejects_moid_replay_from_a_different_session` | TESTS-01 |
| 9 | `test_ajax_init_payment_ignores_client_amount_and_uses_saved_shortcode_price` | TESTS-01, TESTS-29 |
| 10 | `test_return_endpoint_is_idempotent_for_a_completed_payment` | TESTS-05 |
| 11 | `test_admin_ajax_handlers_reject_unauthorized` | TESTS-18 |
| 12 | `test_vbank_issuance_puts_order_on_hold_and_never_completes_payment` | TESTS-07 |
| 13 | `test_activation_creates_transactions_table_and_return_rewrite_resolves` | TESTS-17 |
| 14 | `test_create_cancel_sign_data_matches_golden_digest` | TESTS-09 |
| 15 | `test_validate_nicepay_url` (data provider) | TESTS-20 (**fails today**) |

### Wave A — needs the injectable transport (TESTS-11) first

**1. `test_approval_signature_accepts_zero_padded_amt`**
*Arrange:* `NicePay_API` with the documented test MID/key.
*Act:* `verify_approval_signature( 'nicepay00m01012006221311045107', '000000001004', '9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd' )`.
*Assert:* `true`. Add a sibling asserting the unpadded `'1004'` still verifies, and a third asserting `'10040'` (a padding difference that changes the numeric value) is **rejected**.

**2. `test_approval_aborts_when_response_amt_differs_numerically`**
*Arrange:* transport returns `ResultCode 3001` with `Amt => '100'` and a Signature that is *valid for 100*; `$auth_data['Amt']` is `'50000'`.
*Act:* `request_approval( $auth_data )`.
*Assert:* returns `WP_Error`, the order is never marked paid. **This guard does not exist yet — the test drives it.**

**3. `test_net_cancel_is_sent_when_approval_transport_fails`**
*Arrange:* transport returns `WP_Error` for `NextAppURL`.
*Act:* `request_approval()`.
*Assert:* a second POST is issued to `NetCancelURL` whose body carries `NetCancel === '1'`, the same `TID` and `AuthToken`, and `SignData === hash('sha256', AuthToken.MID.Amt.EdiDate.MerchantKey)`.

**4. `test_net_cancel_is_sent_when_approval_signature_is_invalid`**
*Arrange:* transport returns HTTP 200 with a wrong `Signature`.
*Assert:* net cancel fired; return value is a `WP_Error` with code `nicepay_signature_error`.

**5. `test_net_cancel_is_sent_when_approval_url_is_rejected`** — **fails today**
*Arrange:* `$auth_data['NextAppURL'] = 'https://evil.com/x'`.
*Assert:* net cancel fired. Currently `request_approval()` returns early at line 188 with no net cancel.

**6. `test_approval_declined_does_not_trigger_net_cancel`**
*Arrange:* valid signature, `ResultCode 3F01`.
*Assert:* zero net-cancel calls; the result array is returned so the caller can fail the order.

### Wave B — needs the WP integration suite (TESTS-17)

**7. `test_return_handler_aborts_when_posted_amt_differs_from_stored_amount`**
*Arrange:* transaction row with `amount = 50000`; POST `Amt = 100` with a signature valid for 100.
*Assert:* no approval request issued; transaction status `failed`; order not paid.

**8. `test_return_handler_rejects_moid_replay_from_a_different_session`**
*Arrange:* a genuine auth response for a 100 KRW standalone payment; re-POST it with a *different* order's `Moid`.
*Assert:* that order is **not** completed. This is the exact vector in TESTS-01.

**9. `test_ajax_init_payment_ignores_client_amount_and_uses_saved_shortcode_price`**
*Arrange:* saved preset with `amount = 5000`; POST `amount=1` with a valid nonce.
*Assert:* the returned `sign_data` equals the digest for `5000`, and the stored row's `amount` is `5000`.

**10. `test_return_endpoint_is_idempotent_for_a_completed_payment`**
*Act:* run `handle_return()` twice on an identical POST payload.
*Assert:* order status unchanged after the second run; no second approval request issued.

**11. `test_admin_ajax_handlers_reject_unauthorized`** (data provider over all four handlers)
*Arrange:* subscriber role, or a valid nonce for the wrong action.
*Assert:* `success === false`, message `Unauthorized.`, and zero PG calls.

**12. `test_vbank_issuance_puts_order_on_hold_and_never_completes_payment`**
*Arrange:* approval response `ResultCode 4100` with `VbankNum` / `VbankExpDate` / `VbankBankCode`.
*Assert:* order is `on-hold`, `is_paid()` is false, transaction status is `waiting`, and `vbank_num` + `vbank_exp_date` are persisted.

**13. `test_activation_creates_transactions_table_and_return_rewrite_resolves`**
*Act:* call `activate()`, then `go_to( home_url('/nicepay-return/') )`.
*Assert:* the table and all 24 columns exist; `get_query_var('nicepay_return') === '1'`; `is_404() === false`.

### Wave C — writable today, no infrastructure needed

**14. `test_create_cancel_sign_data_matches_golden_digest`**

```php
$this->assertSame(
    'e6959c2ff876c64ccf0008828cab0007a81230c589f3cd727d0277acdc3c4cd5',
    $this->api->create_cancel_sign_data( '1004', '20191219133357' )
);
```
Replaces the current tautology at `NicePayApiTest.php:213-226`. The digest was computed outside the codebase with `shasum -a 256` over `nicepay00m` + `1004` + `20191219133357` + the documented test merchant key.

**15. `test_validate_nicepay_url`** (data provider) — **partially fails today**
Ten-case hostile corpus: uppercase host (`https://DC1-API.nicepay.co.kr/...` → should pass, **fails today**), subdomain suffix `…nicepay.co.kr.evil.com`, userinfo `https://dc1-api.nicepay.co.kr@evil.com`, scheme-relative `//host/x`, `http://`, `127.0.0.1`, port `:9999` (**passes today, should not**), empty string, missing scheme, valid canonical URL.

### Runners-up

| Test | Note |
|---|---|
| `test_generate_moid_is_unique_across_10000_calls_in_one_second` | **Fails today** — only 9,000 suffixes exist (TESTS-14) |
| `test_log_never_emits_raw_secrets` | TESTS-19 |
| `test_success_code_unknown_method_is_rejected` | Replaces the test that locks in the fail-open (TESTS-15) |
| `test_unknown_response_fields_are_tolerated` | §12 forward-compatibility (TESTS-26) |
| `test_saving_a_shortcode_when_option_absent_preserves_presets` | TESTS-32 |
| `test_cancel_response_without_signature_is_rejected` | TESTS-27 |

---

## 4. CI & quality-gate recommendations

Ordered by leverage per hour. Items 1-3 are prerequisites for everything in Section 3.

| # | Change | Where | Effort |
|---|---|---|---|
| 1 | Inject an HTTP transport into `NicePay_API`; delete the always-failing `wp_remote_post` stub | `includes/class-nicepay-api.php`, `tests/bootstrap/wp-stubs.php` | medium |
| 2 | Add an Integration testsuite on `wp-phpunit/wp-phpunit` + `yoast/phpunit-polyfills` | `phpunit.xml`, `tests/integration/` | large |
| 3 | Commit `tests/fixtures/` with sanitised real PG responses per method | `tests/fixtures/` | medium |
| 4 | Remove the two `<file>` excludes and `processUncoveredFiles="false"`; add `admin/` and the root plugin file to `<include>` | `phpunit.xml` | trivial |
| 5 | Emit clover + `--coverage-text`; add a step that parses clover and fails below a floor set at today's honest number, ratcheting upward | `composer.json`, `tests.yml` | small |
| 6 | Add a `<php>` block: `date.timezone=UTC`, `error_reporting=-1`, `WP_DEBUG=true`; enable `beStrictAboutOutputDuringTests` and `beStrictAboutTestsThatDoNotTestAnything`; emit JUnit + `--testdox` | `phpunit.xml` | trivial |
| 7 | Gate the release workflow on tests + lint (`workflow_call` reuse or `needs:`), and add a tag ↔ header ↔ `NICEPAY_VERSION` ↔ CHANGELOG consistency check that exits 1 on mismatch | `release.yml` | trivial |
| 8 | Commit `composer.lock`, remove the two `.gitignore` lines, add `--no-interaction`, enable Dependabot for `composer` + `github-actions` | `.gitignore`, `tests.yml` | trivial |
| 9 | Add PHP `8.4` to the matrix; move `coverage: xdebug` to a single matrix `include` leg and use `coverage: none` elsewhere | `tests.yml` | small |
| 10 | Add PHPCS (WordPress-Extra + WordPress.Security, start `--warning-severity=0`), PHPStan level 5 with `szepeviktor/phpstan-wordpress`, and `wordpress/plugin-check-action` — each as its own job. Remove `-not -path "./tests/*"` from the lint sweep | `tests.yml`, `phpcs.xml.dist`, `phpstan.neon` | medium |
| 11 | Add `package.json` with `@wordpress/eslint-plugin` + Vitest/jsdom; extract the inline template JS into `assets/js/nicepay-standalone.js` first | repo root, `templates/` | medium |
| 12 | Add `wp i18n make-pot --check` as a CI job (four locales, nine committed `.po`/`.mo` files, no verification today) | `tests.yml` | small |
| 13 | Once the integration suite exists: a WP × WC compatibility matrix plus one `WP_MULTISITE=1` run, so the four header compatibility claims are actually exercised | `tests.yml` | medium |
| 14 | Add a "Running the tests" section + two checklist items to CONTRIBUTING; move the PR template to `.github/PULL_REQUEST_TEMPLATE.md` | `CONTRIBUTING.md`, `.github/` | trivial |

### TESTS-37 · ✨ Add mutation testing so the suite has to prove it can fail

The self-consistent tests in TESTS-09 were found by reading them. That review should be mechanical. **Evidence:** reordering the concatenation in `create_cancel_sign_data` ([includes/class-nicepay-api.php:113](../../includes/class-nicepay-api.php#L113)) leaves the entire 98-test suite green, because its only test rebuilds the expectation from the same field order.

For a suite whose core value proposition is "our signature logic is correct", an objective "can these tests detect a wrong signature?" metric is the right guarantee. Add `infection/infection` as a dev dependency scoped to `includes/`, set a minimum MSI meaningful for the pure functions (start around 70% on `class-nicepay-api.php` and raise it as the HTTP paths become testable), and run it as a non-blocking PR job first, then blocking.

---

*Findings: 37 (1 critical, 7 high, 21 medium, 6 low, 2 enhancement). Two marked PLAUSIBLE — needs confirmation: TESTS-03, TESTS-06. Two sub-claims refuted during verification and explicitly flagged in TESTS-06 and TESTS-10 — do not act on them.*
