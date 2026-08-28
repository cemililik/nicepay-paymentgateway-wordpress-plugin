# NicePay Protocol Conformance

> [!WARNING]
> **Frozen historical snapshot.** This review describes baseline commit `5855db1` (2026-08-19), before the 2.0 remediation work. Its line references and present-tense security claims do not describe the current code. Use `docs/analysis/pr-review2/` and current tests for release decisions.

This document audits the plugin against the official NICEPAY 인증결제 (authenticated payment) manual v2.0.8. The cryptographic core is excellent — all eight SignData/Signature concatenation rules are byte-for-byte correct, the SSRF allowlist is real, and `NextAppURL`/`NetCancelURL` are honoured per-transaction rather than hardcoded. The failures are not in the hashing; they are in everything the protocol expects the *merchant* to do around it: the plugin never reconciles the authenticated amount or order id against its own records (allowing an order-swap that settles an expensive order with a cheap payment), never checks whether a 망취소 net cancel actually succeeded, has no virtual-account deposit-notification endpoint at all, treats a replayed return POST as a fresh payment, and ships an EUC-KR charset switch that changes the labels but never transcodes a single byte. Thirty-four findings follow: 2 critical, 5 high, 21 medium, 6 low.

---

## What this codebase does well

Before the findings, the parts that are genuinely right — and that a reader should not "fix" by accident:

- **All eight signature rules are exactly correct.** `includes/class-nicepay-api.php:74-125` implements every SignData/Signature concatenation order per spec §3. Four of the spec's worked hashes were reproduced byte-for-byte in a shell (auth SignData `475979a5…46bd`, auth Signature `cc94db19…55fe`, approval SignData `4916540b…0204`, approval/cancel Signature `9439b21e…4efd`) and the plugin's plaintexts match character-for-character. Net-cancel correctly reuses the *approval-request* rule (`AuthToken+MID+Amt+EdiDate+Key`) per spec §3 row 5 — the single most commonly botched detail in NICEPAY integrations.
- **`NextAppURL` and `NetCancelURL` are taken from the per-transaction auth response and never hardcoded** ([includes/class-nicepay-api.php:183](../../includes/class-nicepay-api.php#L183), [:279](../../includes/class-nicepay-api.php#L279)). The spec calls this out explicitly; [docs/API-REFERENCE.md:47](../API-REFERENCE.md#L47) repeats the warning for maintainers.
- **The SSRF allowlist is a genuinely well-executed defence** ([includes/class-nicepay-api.php:130-152](../../includes/class-nicepay-api.php#L130)): exactly the four documented NICEPAY hosts, `https` enforced, strict exact-host `in_array( ..., true )` matching, applied to both the approval and net-cancel URLs before any request leaves the server. Most gateway plugins omit this entirely.
- **`TxTid` → `TID` mapping is correct on both the approval and net-cancel calls** ([:194](../../includes/class-nicepay-api.php#L194), [:289](../../includes/class-nicepay-api.php#L289)), honouring spec §5's requirement that approval use the auth-returned `TxTid`; `NetCancel=1` is present on the net-cancel request ([:294](../../includes/class-nicepay-api.php#L294)) as §8 requires.
- **`AuthResultCode` is strictly gated at `!== '0000'`** in both return handlers ([class-nicepay-gateway.php:249](../../includes/class-nicepay-gateway.php#L249), [class-nicepay-return-handler.php:54](../../includes/class-nicepay-return-handler.php#L54)) before any approval call — exactly what spec §5 mandates.
- **Per-method approval success codes are correct** (CARD 3001 / BANK 4000 / VBANK 4100 / CELLPHONE A000 / SSG_BANK 0000), and **cancel code `2211` is handled alongside `2001`** ([includes/class-nicepay-api.php:400-421](../../includes/class-nicepay-api.php#L400)) — the 2211 case is frequently missed, and [tests/unit/NicePayResultCodeTest.php:110-111](../../tests/unit/NicePayResultCodeTest.php#L110) pins it down.
- **`GoodsName` truncation uses `mb_strcut( $goods_name, 0, 40, 'UTF-8' )`** in all three build sites — byte-safe, not character-safe, with a comment explaining why. The author understood the byte-limit trap; the gap is only that the same care was not extended to the other size-limited fields (PROTOCOL-18).
- **The approval-response signature is mandatory, not best-effort** ([includes/class-nicepay-api.php:248-256](../../includes/class-nicepay-api.php#L248) rejects a missing Signature or TID outright), and every verification uses `hash_equals()` for timing safety (lines 86, 105, 123).
- **[tests/unit/NicePaySignatureIntegrationTest.php](../../tests/unit/NicePaySignatureIntegrationTest.php) is a real contract test** against the published spec examples rather than a self-referential round-trip: it asserts the documented hex digests directly, plus negative cases proving an auth signature cannot be replayed as an approval signature. That is the right shape for protocol tests — it just needs the padded-`Amt` case added.
- **Log redaction** ([includes/class-nicepay-api.php:157-174](../../includes/class-nicepay-api.php#L157)) masks `AuthToken`, `SignData`, `Signature`, `MerchantKey`, `CardNo` and `VbankNum` before anything reaches the log.
- **The full decoded approval/cancel response is archived verbatim** in the `payment_data` longtext column, so the parser tolerates NICEPAY adding new fields over time exactly as spec §12 requires — and the data needed to implement several of the missing features is already being captured.

---

## 1. Verdict summary by spec area

| # | Spec area | Spec ref | Verdict | Findings |
|---|---|---|---|---|
| 1 | Signature / SignData rules (8 rules) | §3 | **PASS** | — (see Appendix) |
| 2 | Endpoint handling & SSRF (NextAppURL / NetCancelURL) | §2, §5 | **PASS** | — |
| 3 | Approval-response signature enforcement | §7 | **PASS** | — |
| 4 | `AuthResultCode` gating before approval | §5 | **PASS** | PROTOCOL-22 (consequence only) |
| 5 | Per-method approval success codes | §7 | **PARTIAL** | PROTOCOL-17, PROTOCOL-25 |
| 6 | Cancel success codes (2001 / 2211) | §9 | **PASS** | — |
| 7 | **Order binding / amount reconciliation** | §5, §7 | **FAIL** | PROTOCOL-01, PROTOCOL-02, PROTOCOL-23 |
| 8 | **Idempotency of the auth return** | §5-6 | **FAIL** | PROTOCOL-05, PROTOCOL-24, PROTOCOL-34 |
| 9 | **Net cancel (망취소) outcome handling** | §8 | **FAIL** | PROTOCOL-03 |
| 10 | Cancel flow (승인취소) parameters | §9 | **PARTIAL** | PROTOCOL-08, PROTOCOL-09, PROTOCOL-10, PROTOCOL-11, PROTOCOL-20 |
| 11 | **Encoding / CharSet (EUC-KR)** | §1, §6 | **FAIL** | PROTOCOL-06 |
| 12 | Timeouts (connect 5 s / read 30 s) | §2 | **PARTIAL** | PROTOCOL-13 *(needs confirmation)* |
| 13 | Amount format & currency (`Amt`, `CurrencyCode`) | §4 | **PARTIAL** | PROTOCOL-15 |
| 14 | Timestamp generation (`EdiDate`, `VbankExpDate`) | §4 | **FAIL** | PROTOCOL-14 |
| 15 | Required auth params (`PayMethod` etc.) | §4 | **PARTIAL** | PROTOCOL-12, PROTOCOL-28 |
| 16 | Per-method required extras (GoodsCl, MallUserID, VbankExpDate) | §4.5.2.x | **PARTIAL** | PROTOCOL-16, PROTOCOL-17, PROTOCOL-29 |
| 17 | Field byte-size limits | §4, §9 | **PARTIAL** | PROTOCOL-18 |
| 18 | CARD installment params (SelectQuota etc.) | §4.5.2.1 | **FAIL (not implemented)** | PROTOCOL-19 |
| 19 | **VBANK lifecycle (deposit notification, expiry, refund)** | §2, §7, §9 | **FAIL** | PROTOCOL-04, PROTOCOL-10, PROTOCOL-32 |
| 20 | Response parsing / extra-field tolerance | §6, §12 | **PARTIAL** | PROTOCOL-26 |
| 21 | Return URL reachability (`ReturnURL`, mobile) | §4, §12 | **FAIL** | PROTOCOL-07 |
| 22 | Card / bank code tables | §10, §11 | **PARTIAL** | PROTOCOL-30 |
| 23 | Result-code → user messaging | §7-9 | **FAIL** | PROTOCOL-21 |
| 24 | Optional auth/approval/cancel params | §4, §6, §9 | **FAIL (not implemented)** | PROTOCOL-27 |
| 25 | Response-field surfacing to merchants | §7 | **PARTIAL** | PROTOCOL-31 |
| 26 | Documentation ↔ implementation agreement | — | **FAIL** | PROTOCOL-33 |

Legend: **PASS** = conforms; **PARTIAL** = conforms in the common case but deviates or is incomplete; **FAIL** = does not conform, or the spec-mandated behaviour is absent.

---

## 2. Findings

### Critical

---

#### PROTOCOL-01 — `Moid` on the auth return is attacker-mutable and is not covered by the auth Signature

![critical](https://img.shields.io/badge/severity-CRITICAL-red) `spec-conformance` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-gateway.php:226](../../includes/class-nicepay-gateway.php#L226), [:275](../../includes/class-nicepay-gateway.php#L275), [:358-393](../../includes/class-nicepay-gateway.php#L358) · [includes/class-nicepay-return-handler.php:38-51](../../includes/class-nicepay-return-handler.php#L38), [:142-149](../../includes/class-nicepay-return-handler.php#L142)

**Spec:** §3 (auth response Signature plaintext = `AuthToken+MID+Amt+MerchantKey` — `Moid` is **not** in it); §5 (auth response echoes `Moid`); §7 (approval response carries `Moid`/`Amt` for reconciliation).

**Problem.** Both return handlers resolve the target order purely from the POSTed `Moid`, and never compare the authenticated or approved amount to the order total. `verify_auth_signature()` hashes `$auth_token . $this->mid . $amt . $this->merchant_key` — `Moid` is genuinely absent from the plaintext, so mutating `Moid` leaves the signature valid. The return POST is a browser-side form submit (`window.nicepaySubmit = function() { document.payForm.submit(); }`, [templates/payment-form.php:80-82](../../templates/payment-form.php#L80)), so the shopper fully controls the body: overriding `nicepaySubmit` in devtools to rewrite `document.payForm.Moid.value` before submitting needs no proxy. Nothing downstream reconciles — `$transaction->amount` is read in exactly one place in the whole plugin ([admin/class-nicepay-transactions.php:205](../../admin/class-nicepay-transactions.php#L205)) and never in either handler.

**Evidence.**

```php
// includes/class-nicepay-api.php:84
$plain = $auth_token . $this->mid . $amt . $this->merchant_key;   // no Moid

// includes/class-nicepay-gateway.php:226
$moid = isset( $_POST['Moid'] ) ? sanitize_text_field( wp_unslash( $_POST['Moid'] ) ) : '';
// :234
$transaction = nicepay_get_transaction_by_moid( $moid );
// :275
if ( empty( $signature ) || ! $this->api->verify_auth_signature( $auth_token, $amt, $signature ) )
// :380
$order->payment_complete( $tid );
```

Nothing between :234 and :380 compares `$amt` or `$result['Amt']` to `$order->get_total()`. Identical shape at [class-nicepay-return-handler.php:51/70/142-149](../../includes/class-nicepay-return-handler.php#L51). `grep -rn "transaction->amount" --include='*.php' .` returns only `admin/class-nicepay-transactions.php:205`.

**Impact.** Verified end-to-end attack: (1) attacker adds an expensive item and reaches the receipt page for order A — `generate_payment_form()` writes a `pending` transaction row with Moid_A and `wc_order_id = A` ([gateway.php:158-168](../../includes/class-nicepay-gateway.php#L158)); (2) attacker abandons A and checks out a cheap order B; (3) at the NICEPAY return they replace `Moid` with Moid_A. `verify_auth_signature` passes (Amt and AuthToken are B's, both bound), `request_approval()` charges B's amount, `is_success_code()` passes, and gateway.php:380 calls `$order->payment_complete( $tid )` on **order A**. Order A is Processing with a genuine NICEPAY TID. Direct, repeatable money loss with no admin-visible signal — the transactions row for Moid_A shows the *stored* amount (order A's), not the amount actually charged.

**User scenario.** A hostile shopper pays 1,000 KRW and receives a 1,000,000 KRW order marked Processing with a real NICEPAY TID attached. Neither the order screen nor the NicePay transactions list shows the discrepancy.

**Recommendation.** Add two hard gates in **both** handlers.

1. Immediately after `nicepay_get_transaction_by_moid()`: reject unless `(int) $amt === (int) $transaction->amount`, and on the WooCommerce path additionally `(int) $amt === (int) nicepay_get_amount( $order->get_total(), $order->get_currency() )`. Fail **without calling `request_approval()` at all** — no approval means no charge.
2. After approval: reject and net-cancel unless `(int) $result['Amt'] === $expected_amt`, `$result['Moid'] === $transaction->moid` (when present), and `$result['MID'] === $this->api->get_mid()`.

Additionally send `ReqReserved` on the auth request carrying an HMAC of `order_id|moid` (spec §4, 500 B, echoed back per §5) and verify it on return, so the order binding no longer rests on an unsigned field.

```php
$expected_amt = (int) nicepay_get_amount( $order->get_total(), $order->get_currency() );
if ( (int) $amt !== $expected_amt || (int) $amt !== (int) $transaction->amount ) {
    nicepay_log( 'Amount/order binding mismatch on return', array( 'posted' => $amt, 'expected' => $expected_amt, 'row' => $transaction->amount, 'moid' => $moid ) );
    nicepay_update_transaction( $transaction->id, array( 'status' => 'failed', 'result_code' => 'AMT_MISMATCH' ) );
    wc_add_notice( __( 'Payment could not be verified. No charge was made.', 'nicepay-payment-gateway' ), 'error' );
    wp_safe_redirect( wc_get_checkout_url() ); exit; // before request_approval()
}
```

---

#### PROTOCOL-02 — Public `nopriv` AJAX endpoint signs a client-supplied amount

![critical](https://img.shields.io/badge/severity-CRITICAL-red) `spec-conformance` · CONFIRMED · effort: medium

**Files:** [nicepay-payment-gateway.php:81-82](../../nicepay-payment-gateway.php#L81), [:313-359](../../nicepay-payment-gateway.php#L313) · [templates/standalone-payment-form.php:130](../../templates/standalone-payment-form.php#L130), [:305](../../templates/standalone-payment-form.php#L305)

**Spec:** §3 (auth SignData = `EdiDate+MID+Amt+MerchantKey` — the merchant is the authority for `Amt`); §4 (`Amt` required).

**Problem.** `ajax_init_payment` is registered on both `wp_ajax_` and `wp_ajax_nopriv_` and hashes the POSTed amount directly. The nonce is no barrier: it is printed into the page (`nonce=<?php echo esc_js( wp_create_nonce( 'nicepay_init_payment' ) ); ?>`, standalone-payment-form.php:305) and, for logged-out visitors, WordPress derives it from uid 0 with an empty session token, so it is identical for every anonymous visitor for the full nonce lifetime. Nothing ties `$amount` back to the shortcode config that rendered the button, even though `nicepay_get_saved_shortcode()` holds the authoritative price server-side and the shortcode `id` is right there. The client also owns the `Amt` hidden field it later submits, so signed value and submitted value stay consistent under tampering. Secondary: the amount is signed as the raw sanitized string, never normalised through `nicepay_get_amount()`, unlike the template path at line 25.

**Evidence.**

```php
// nicepay-payment-gateway.php:82
add_action( 'wp_ajax_nopriv_nicepay_init_payment', array( $this, 'ajax_init_payment' ) );
// :322
$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
// :328  — the only validation
if ( empty( $amount ) || (float) $amount <= 0 )
// :336
$sign_data = $api->create_auth_sign_data( $edi_date, $amount );
// :338-347  inserts the row with 'amount' => $amount
```

```php
// templates/standalone-payment-form.php:130
<input type="hidden" name="Amt" value="<?php echo esc_attr( $amount ); ?>">
// :305
&amount=<?php echo esc_js( $amount ); ?>
```

**Impact.** Any visitor can pay an arbitrary amount for a fixed-price shortcode: call admin-ajax with `amount=1`, set the hidden `Amt` to 1, submit. The payment genuinely succeeds at NICEPAY with a valid signature and TID. The merchant *does* see the tampered figure in the transactions list (the row stores the posted amount), so it is detectable on review — but nothing flags it and there is no reconciliation against the shortcode's configured price. Separately, the endpoint is unauthenticated and inserts a DB row per call with no throttle, so it is also an unbounded table-growth vector.

**User scenario.** A shop sells a 290,000 KRW course via `[nicepay_payment id="course"]`. A buyer pays 100 KRW. The transactions screen shows a signature-verified NICEPAY payment of 100 KRW with a valid TID.

**Recommendation.** Stop accepting the amount from the client. Post the shortcode `id` instead, resolve the price with `nicepay_get_saved_shortcode( $id )`, and normalise via `nicepay_get_amount()` before signing. Return the normalised amount in the AJAX response and have the JS write it into the hidden `Amt` input (currently the flow is the reverse), so signed and submitted values provably share one source. For genuinely open amounts (donations), add an explicit `allow_custom_amount` flag plus server-side min/max on the saved shortcode. Add a per-IP throttle (e.g. a 10/minute transient) on the `nopriv` path.

```php
$sc_id = isset( $_POST['sc_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sc_id'] ) ) : '';
$saved = $sc_id ? nicepay_get_saved_shortcode( $sc_id ) : null;
if ( ! $saved ) { wp_send_json_error( array( 'message' => __( 'Unknown payment configuration.', 'nicepay-payment-gateway' ) ) ); return; }
$amount = nicepay_get_amount( $saved['amount'], isset( $saved['currency'] ) ? $saved['currency'] : '' );
if ( (float) $amount <= 0 ) { wp_send_json_error( array( 'message' => __( 'Invalid payment amount.', 'nicepay-payment-gateway' ) ) ); return; }
$sign_data = $api->create_auth_sign_data( $edi_date, $amount );
// wp_send_json_success( array( ..., 'amt' => $amount ) ); and have the JS write resp.data.amt into the Amt input
```

---

### High

---

#### PROTOCOL-03 — Net-cancel outcome is never checked, its Signature never verified, and every failure is invisible in production

![high](https://img.shields.io/badge/severity-HIGH-orange) `spec-conformance` · CONFIRMED · effort: medium

**Files:** [includes/class-nicepay-api.php:222-267](../../includes/class-nicepay-api.php#L222), [:278-324](../../includes/class-nicepay-api.php#L278) · [includes/nicepay-functions.php:16-19](../../includes/nicepay-functions.php#L16)

**Spec:** §8 (net-cancel response: `ResultCode` 2001 = success; `ErrorCD`/`ErrorMsg`/`CancelAmt`/`Signature`; trigger = approval call failed).

**Problem.** `request_net_cancel()` posts, decodes, logs and returns — it never checks `ResultCode === '2001'`, never verifies the response Signature (the class already has `verify_cancel_signature()` for exactly the `TID+MID+CancelAmt+MerchantKey` shape the spec specifies), and never reads `ErrorCD`/`ErrorMsg`. All four call sites discard the return value. Compounding it, `nicepay_log()` returns immediately unless `WP_DEBUG` is on — the normal production configuration — so on a live site a failed 망취소 leaves literally no record anywhere.

**Evidence.**

```php
// includes/class-nicepay-api.php:319-323
$body   = wp_remote_retrieve_body( $response );
$result = json_decode( $body, true );
nicepay_log( 'Network cancel response', $result ? $this->redact_for_log( $result ) : 'parse_error' );
return $result ? $result : new WP_Error( 'nicepay_parse_error', … );
```

Call sites at 225-227, 238-240, 251-253 and 262-264 are all bare `$this->request_net_cancel( $auth_data );`.

```php
// includes/nicepay-functions.php:16-19
function nicepay_log( $message, $data = null ) {
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) { return; }
```

**Impact.** The net cancel is the plugin's only protection against "the PG approved but I could not confirm it". It fires on transport error, on parse failure, on missing signature and on signature mismatch — and in all four cases the plugin proceeds to fail the order without knowing whether the void succeeded. When the void itself fails (PG-side error, second timeout, PHP execution limit reached mid-call), the capture stands, the order is marked failed, and there is no order note, no transaction flag, no admin notice and no log line. Discovery happens at end-of-month settlement reconciliation.

**User scenario.** NICEPAY's DC1 times out after approving a 300,000 KRW card payment. The plugin fires a net cancel; that request also fails. The customer sees "Payment processing failed", the order is failed, and 300,000 KRW is captured with no order, no note, no notice and no log entry.

**Recommendation.** Have `request_net_cancel()` verify `verify_cancel_signature( $result['TID'], $result['CancelAmt'], $result['Signature'] )` and assert `ResultCode === '2001'`, returning a `WP_Error` with `ErrorCD`/`ErrorMsg` interpolated otherwise. Have every caller inspect the outcome and, on failure, escalate: persist `status = 'needs_reconciliation'` on the transaction row with the TID and AuthToken, add a WooCommerce order note, and raise a dismissible admin notice. Log net-cancel outcomes unconditionally through `wc_get_logger()->error()` rather than the `WP_DEBUG`-gated `nicepay_log()`, and retry the net cancel once before giving up. Separately, add an unconditional error channel to `nicepay_log()` (e.g. a `$level` argument that bypasses the `WP_DEBUG` gate for `error`) — silent money-safety failures are not a debug concern.

---

#### PROTOCOL-04 — No virtual-account deposit-notification endpoint: VBANK orders never progress past on-hold

![high](https://img.shields.io/badge/severity-HIGH-orange) `spec-conformance` · CONFIRMED · effort: large

**Files:** [nicepay-payment-gateway.php:202-223](../../nicepay-payment-gateway.php#L202) · [includes/class-nicepay-gateway.php:36](../../includes/class-nicepay-gateway.php#L36), [:370-378](../../includes/class-nicepay-gateway.php#L370) · [includes/class-nicepay-return-handler.php:142-149](../../includes/class-nicepay-return-handler.php#L142)

**Spec:** §2 — inbound deposit notification (입금통보) from 121.133.126.10 / .11 / 211.33.136.39, TCP/HTTPS.

**Problem.** The plugin issues virtual accounts (sends `VbankExpDate`, stores `VbankNum`/`VbankBankName`, sets the order `on-hold` and the transaction `waiting`) but registers no endpoint to receive the deposit notification. The only rewrite rule is `^nicepay-return/?$` and the only WooCommerce API hook is `woocommerce_api_nicepay_return`. Nothing anywhere transitions `waiting` → `paid` or `on-hold` → `processing`.

**Evidence.**

```php
// nicepay-payment-gateway.php:202-212 — the only route
add_rewrite_rule( '^nicepay-return/?$', 'index.php?nicepay_return=1', 'top' );
// includes/class-nicepay-gateway.php:36 — the only WC API hook
add_action( 'woocommerce_api_nicepay_return', array( $this, 'handle_return' ) );
```

`grep -rniE 'deposit.?notif|입금|webhook' --include='*.php' .` returns zero implementation hits (only unrelated `nicepay-notice` CSS-class matches). [class-nicepay-gateway.php:370-378](../../includes/class-nicepay-gateway.php#L370) sets `on-hold` with no counterpart transition anywhere.

**Impact.** Every virtual-account order stays on-hold indefinitely after the customer pays. The merchant must watch their bank feed and flip each order by hand — for one of the four headline payment methods. There is also no expiry sweep: an unpaid VBANK order sits on-hold past `VbankExpDate` with no automatic cancellation, and staff cannot distinguish "deposited but unreconciled" from "never deposited". This also blocks the VBANK refund path (PROTOCOL-10) and makes the README's inbound-IP firewall table actively misleading (PROTOCOL-33).

**User scenario.** A customer chooses virtual account, deposits 89,000 KRW that afternoon, and waits. The order is still "On hold" the next morning. Support checks the bank manually and edits the order — for every VBANK sale.

**Recommendation.** Add a dedicated notification endpoint (rewrite `^nicepay-vbank-noti/?$` plus a `woocommerce_api_nicepay_vbank` alias so it works with plain permalinks too) that:

- verifies the payload MID/signature and optionally the source IP against the three documented addresses;
- matches on TID first, `Moid` second;
- asserts the deposited amount equals `$transaction->amount`;
- is idempotent against replays (no-op when already `paid`);
- moves the transaction to `paid` and calls `$order->payment_complete()`;
- echoes the acknowledgement string NICEPAY expects.

Add a WP-Cron sweep that expires `waiting` transactions past `VbankExpDate` and cancels the order. Surface the notification URL in the settings screen with a copy button so the merchant can register it with NICEPAY. Until it ships, correct [README.md:186-192](../../README.md#L186) and [docs/USER-GUIDE.md:493](../USER-GUIDE.md#L493).

---

#### PROTOCOL-05 — No idempotency guard on either return handler: a replayed auth POST downgrades an already-paid order to failed

![high](https://img.shields.io/badge/severity-HIGH-orange) `correctness` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-gateway.php:234-246](../../includes/class-nicepay-gateway.php#L234), [:358-412](../../includes/class-nicepay-gateway.php#L358) · [includes/class-nicepay-return-handler.php:51](../../includes/class-nicepay-return-handler.php#L51), [:142-158](../../includes/class-nicepay-return-handler.php#L142)

**Spec:** §5-6 — `AuthToken` is a single-use credential; approval MUST be requested with the `TxTid` from that auth.

**Problem.** Neither handler checks whether the transaction it is about to process has already been settled. There is no guard on `$transaction->status`, no check for an existing `tid`, and no lock. The gateway checks only `! $transaction || ! $transaction->wc_order_id`. A deliberate re-POST of the return URL, a "Confirm Form Resubmission" on back-navigation, or a concurrent double submit re-runs verify → approve → write. NICEPAY refuses the second approval (the AuthToken is consumed), the plugin reads the failure ResultCode, and then unconditionally overwrites the row and the order.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:235 — the only precondition
if ( ! $transaction || ! $transaction->wc_order_id ) {
// :396-404 — no prior check of $transaction->status or $transaction->tid
$update_data['status'] = 'failed';
nicepay_update_transaction( $transaction->id, $update_data );
$order->update_status( 'failed', sprintf( … ) );
```

Identical shape at [class-nicepay-return-handler.php:150-157](../../includes/class-nicepay-return-handler.php#L150). No `set_transient`, `get_transient` or `FOR UPDATE` anywhere in the plugin.

**Impact.** A completed order is downgraded to `failed` after `payment_complete()` has already fired stock reduction, the customer email and any fulfilment integrations — and WooCommerce does not restore stock on that transition. The customer is redirected to checkout with "Payment failed" having paid. Any shopper can do this to their own completed order at will. One correction to the reviewer's framing: a plain refresh of the post-redirect thank-you page is a GET and is harmless — the replay requires re-submitting the POST, which browsers prompt for but shoppers routinely confirm.

**User scenario.** A shopper on a flaky connection re-submits the return POST. Their completed order flips to Failed, stock is not restored, and they see "Payment failed. Please try again." after having paid.

**Recommendation.** Guard both handlers immediately after the transaction lookup: if `$transaction->status` is already `paid`/`waiting`/`cancelled`/`refunded`, or `$transaction->tid` is non-empty, skip the approval entirely and redirect to the thank-you page (or re-render the success result page). Wrap the approval in a short-lived lock keyed on the `Moid` so two concurrent POSTs cannot both proceed. Make status transitions one-way: never write `failed` over a terminal status.

```php
if ( in_array( $transaction->status, array( 'paid', 'waiting', 'cancelled', 'refunded' ), true ) || ! empty( $transaction->tid ) ) {
    nicepay_log( 'Duplicate return ignored', array( 'moid' => $moid, 'status' => $transaction->status ) );
    wp_safe_redirect( $this->get_return_url( $order ) );
    exit;
}
$lock = 'nicepay_lock_' . md5( $moid );
if ( get_transient( $lock ) ) { wp_safe_redirect( $this->get_return_url( $order ) ); exit; }
set_transient( $lock, 1, 60 );
```

---

#### PROTOCOL-06 — EUC-KR mode changes the labels but not the bytes

![high](https://img.shields.io/badge/severity-HIGH-orange) `spec-conformance` · CONFIRMED · effort: medium

**Files:** [includes/class-nicepay-api.php:216-219](../../includes/class-nicepay-api.php#L216), [:232-243](../../includes/class-nicepay-api.php#L232) · [includes/class-nicepay-gateway.php:150](../../includes/class-nicepay-gateway.php#L150), [:192](../../includes/class-nicepay-gateway.php#L192) · [templates/payment-form.php:51](../../templates/payment-form.php#L51) · [templates/standalone-payment-form.php:128](../../templates/standalone-payment-form.php#L128) · [admin/class-nicepay-admin.php:236-243](../../admin/class-nicepay-admin.php#L236)

**Spec:** §1 and §6 — EUC-KR for the payment-window POST and the approval/cancel API; `CharSet` param `utf-8` / `euc-kr` (default).

**Problem.** The `nicepay_charset` option is threaded into the `CharSet` parameter, the `Content-Type` header and the form `accept-charset` — but nothing is ever transcoded. There is no `mb_convert_encoding` or `iconv` call anywhere in the plugin. Selecting EUC-KR therefore declares EUC-KR while transmitting UTF-8 bytes, in three directions:

1. **Outbound** — `GoodsName`/`BuyerName`/`CancelMsg` are mislabelled → mojibake in the payment window, the card descriptor and the NICEPAY console.
2. **Inbound** — an EUC-KR-encoded response body is fed to `json_decode()`, which returns `null` on non-UTF-8 input (`JSON_ERROR_UTF8`) — landing in the parse-error branch that fires a net cancel of a **successful** approval.
3. **Return POST** — EUC-KR `AuthResultMsg`/`ResultMsg` are sanitised and stored as broken bytes.

The `accept-charset` attributes are separately inert: the HTML Living Standard restricts the value to UTF-8 and browsers ignore anything else.

**Evidence.** `grep -rn "mb_convert_encoding\|iconv" --include='*.php' --include='*.js' .` → **zero matches, repo-wide.**

```php
// includes/class-nicepay-api.php:216-219  (body array untouched)
'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=' . $this->charset ),
'body'    => $params,
// :232-234
$body   = wp_remote_retrieve_body( $response );
$result = json_decode( $body, true );
// :235-242  parse failure → $this->request_net_cancel( $auth_data );
```

`admin/class-nicepay-admin.php:238-241` offers `euc-kr` with no warning. Mitigating: `nicepay-payment-gateway.php:160` `add_option( 'nicepay_charset', 'utf-8' );`.

**Impact.** Choosing the setting the plugin's own API reference calls the NICEPAY *default* turns every card payment into a captured-then-voided transaction and garbles Korean product names. Mitigating factor: `nicepay_charset` defaults to `utf-8`, the admin dropdown defaults to UTF-8, and [docs/USER-GUIDE.md:117](../USER-GUIDE.md#L117) correctly advises "Use UTF-8 unless your site specifically requires EUC-KR" — so this is an opt-in foot-gun rather than an out-of-the-box break. But [docs/API-REFERENCE.md:34-36](../API-REFERENCE.md#L34) and [:80](../API-REFERENCE.md#L80) pull the other way.

**User scenario.** A Korean merchant reads the plugin's own API reference, sees EUC-KR described as the NICEPAY default, and flips the setting. Every subsequent card payment is charged and then silently voided, and the shopper sees "Failed to parse approval response."

**Recommendation.** Either implement EUC-KR properly or remove the option. To implement: build the request body yourself with `http_build_query()` after transcoding every text field, run the response body back through `mb_convert_encoding` before `json_decode`, and transcode inbound `$_POST` on the return endpoint before sanitising. Otherwise hard-code `CharSet=utf-8`, delete the setting, and fix `docs/API-REFERENCE.md:34-36`/`:80`. Either way, drop the inert `accept-charset` attributes.

```php
private function encode_body( array $params ) {
    if ( $this->charset !== 'euc-kr' ) { return $params; }
    return array_map( function ( $v ) { return is_string( $v ) ? mb_convert_encoding( $v, 'EUC-KR', 'UTF-8' ) : $v; }, $params );
}
private function decode_body( $body ) {
    return ( $this->charset === 'euc-kr' ) ? mb_convert_encoding( $body, 'UTF-8', 'EUC-KR' ) : $body;
}
// 'body' => $this->encode_body( $params )  …  json_decode( $this->decode_body( $body ), true )
```

---

#### PROTOCOL-07 — Standalone shortcode `ReturnURL` is a pretty permalink with no plain-permalink fallback

![high](https://img.shields.io/badge/severity-HIGH-orange) `spec-conformance` · CONFIRMED · effort: trivial

**Files:** [nicepay-payment-gateway.php:202-212](../../nicepay-payment-gateway.php#L202), [:299](../../nicepay-payment-gateway.php#L299) · [templates/standalone-payment-form.php:127](../../templates/standalone-payment-form.php#L127), [:136](../../templates/standalone-payment-form.php#L136)

**Spec:** §4 (`ReturnURL(500)` — REQUIRED for mobile) and §12 (mobile POSTs the result to `ReturnURL`).

**Problem.** The standalone flow hardcodes `home_url( '/nicepay-return/' )` as both the form `action` and the `ReturnURL` parameter, and that path exists only through `add_rewrite_rule( '^nicepay-return/?$', … )`. `WP_Rewrite::rewrite_rules()` returns an empty array when `permalink_structure` is empty, so on a site using the default **Plain** permalink setting the rule is never generated and the URL 404s. The `nicepay_return` query var *is* registered via the `query_vars` filter, so `?nicepay_return=1` would work — but the plugin never emits that form. The WooCommerce path is immune because `WC()->api_request_url()` falls back to `add_query_arg( 'wc-api', $request, home_url() )` when permalinks are off; the standalone path has no equivalent.

**Evidence.**

```php
// nicepay-payment-gateway.php:203-207 — the only route
add_rewrite_rule( '^nicepay-return/?$', 'index.php?nicepay_return=1', 'top' );
// templates/standalone-payment-form.php:127
action="<?php echo esc_url( home_url( '/nicepay-return/' ) ); ?>"
// :136
<input type="hidden" name="ReturnURL" value="<?php echo esc_url( home_url( '/nicepay-return/' ) ); ?>">
// nicepay-payment-gateway.php:299
'returnUrl' => home_url( '/nicepay-return/' ),
```

`grep -rn "permalink" --include='*.php' .` returns zero hits in plugin code — nothing consults `get_option( 'permalink_structure' )`. Contrast [class-nicepay-gateway.php:125](../../includes/class-nicepay-gateway.php#L125) `$return_url = WC()->api_request_url( 'nicepay_return' );`.

**Impact.** On any WordPress site left on Plain permalinks, every `[nicepay_payment]` payment authenticates at NICEPAY and then lands on a 404 — the approval is never requested, so no charge occurs, but the shopper sees a 404 page after entering card details and the transaction row stays `pending` forever. The failure is total for the shortcode feature and gives the merchant no diagnostic. Plain permalinks are the WordPress default on a fresh install.

**User scenario.** A site owner installs the plugin on a fresh WordPress (Plain permalinks), drops in `[nicepay_payment id="donation"]`, and tests it. The NICEPAY window opens and authenticates, then the browser lands on a 404. Nothing in the plugin explains why.

**Recommendation.** Mirror WooCommerce's pattern in a single helper and use it at all three sites. Additionally, surface the resolved return URL in the settings screen so the merchant can see and test it, and add an admin notice when permalinks are plain.

```php
function nicepay_return_url() {
    return get_option( 'permalink_structure' )
        ? home_url( '/nicepay-return/' )
        : add_query_arg( 'nicepay_return', '1', home_url( '/' ) );
}
```

---

### Medium

---

#### PROTOCOL-08 — Cancel-response Signature check is skipped when the field is absent, and a verification failure looks like a plain refund failure

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-api.php:384-393](../../includes/class-nicepay-api.php#L384) · [includes/class-nicepay-gateway.php:445-473](../../includes/class-nicepay-gateway.php#L445)

**Spec:** §9 — cancel response: `ResultCode` 2001 or 2211 = success; `Signature` = `TID+MID+CancelAmt+MerchantKey`.

**Problem.** (a) Verification is conditional on `! empty( $result['TID'] ) && ! empty( $result['Signature'] )`, whereas the approval path at [:248-256](../../includes/class-nicepay-api.php#L248) treats a missing Signature or TID as a hard failure — an unexplained asymmetry. (b) When verification does fail, `request_cancel()` returns a `WP_Error` that `process_refund()` passes straight to WooCommerce, which records the refund as failed and leaves the refund UI armed — even though the cancel may already have executed at the PG.

**Evidence.**

```php
// includes/class-nicepay-api.php:385-391
if ( ! empty( $result['TID'] ) && ! empty( $result['Signature'] ) ) {
    $resp_cancel_amt = isset( $result['CancelAmt'] ) ? $result['CancelAmt'] : $cancel_amt;
    if ( ! $this->verify_cancel_signature( $result['TID'], $resp_cancel_amt, $result['Signature'] ) ) {
        nicepay_log( 'Cancel response signature verification failed' );
        return new WP_Error( 'nicepay_signature_error', __( 'Cancel signature verification failed.', 'nicepay-payment-gateway' ) );
    }
}
// includes/class-nicepay-gateway.php:447-449
if ( is_wp_error( $result ) ) { return $result; }
```

**Impact.** *Severity downgraded from critical: no money-loss or security-compromise path was verifiable.* A spoofed/injected body accepted as a successful refund is much weaker than it first appears — `sslverify => true` on a pinned host means an injected body requires a TLS compromise, and a genuine PG error response would not carry `ResultCode` 2001 anyway, so branch (a) cannot manufacture a false success in practice. What is real is the **retry-safety** problem, and it is broader than signatures: any post-dispatch failure — the 30 s `timeout` firing after NICEPAY processed the cancel, a signature mismatch, a parse failure at line 377 — surfaces to the merchant as an undifferentiated "refund failed" with no warning that the money may already have moved.

**User scenario.** A merchant refunds 45,000 KRW. The HTTP call times out at 30 s after NICEPAY has already processed the cancel. WooCommerce shows "Refund failed". The merchant clicks Refund again and gets an opaque PG error, with nothing telling them the first attempt may have succeeded.

**Recommendation.** Make the signature mandatory on cancel exactly as on approval. More importantly, separate "the cancel did not happen" from "the cancel may have happened but I could not confirm it": whenever the request was dispatched and the outcome is unknown, persist `status = 'needs_reconciliation'` with the TID, add a WooCommerce order note, and return an error message that explicitly says "do not retry — verify in the NicePay console".

```php
if ( empty( $result['TID'] ) || empty( $result['Signature'] ) ) {
    nicepay_log( 'Cancel response missing Signature or TID', $this->redact_for_log( $result ) );
    return new WP_Error( 'nicepay_signature_error', __( 'The cancellation could not be authenticated. Verify it in the NicePay console before retrying.', 'nicepay-payment-gateway' ) );
}
$resp_cancel_amt = isset( $result['CancelAmt'] ) ? $result['CancelAmt'] : $cancel_amt;
if ( ! $this->verify_cancel_signature( $result['TID'], $resp_cancel_amt, $result['Signature'] ) ) {
    $maybe_done = $this->is_cancel_success( isset( $result['ResultCode'] ) ? $result['ResultCode'] : '' );
    return new WP_Error( 'nicepay_signature_error', $maybe_done
        ? __( 'The cancellation may have completed but could not be verified. Do NOT retry — check the NicePay console.', 'nicepay-payment-gateway' )
        : __( 'Cancel signature verification failed.', 'nicepay-payment-gateway' ) );
}
```

---

#### PROTOCOL-09 — Cancel requests reuse the original payment `Moid` instead of issuing a new unique cancel order number

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-api.php:66-68](../../includes/class-nicepay-api.php#L66), [:337-350](../../includes/class-nicepay-api.php#L337) · [includes/class-nicepay-gateway.php:153](../../includes/class-nicepay-gateway.php#L153), [:430](../../includes/class-nicepay-gateway.php#L430), [:445](../../includes/class-nicepay-gateway.php#L445) · [admin/class-nicepay-transactions.php:208](../../admin/class-nicepay-transactions.php#L208)

**Spec:** §9 — cancel request `Moid(64 — merchant-issued unique **CANCEL** order no)`; [docs/API-REFERENCE.md:243](../API-REFERENCE.md#L243) "Cancel order ID (should be unique)".

**Problem.** The spec — and the plugin's own API reference — describe the cancel `Moid` as a new, merchant-issued, unique cancellation order number. Both callers pass the original payment `Moid` through unchanged. `NicePay_API::generate_moid()` already exists and is unused by the cancel path. Compounding it, `_nicepay_moid` is overwritten on every receipt-page render, so after a retry the stored `Moid` may belong to an abandoned auth attempt rather than the one whose TID is being cancelled.

**Evidence.**

```php
// includes/class-nicepay-api.php:340-350 — Moid taken verbatim from the caller
'Moid' => $moid,
// :66-68 — exists, unused by request_cancel()
public function generate_moid( $prefix = 'WC' ) { return $prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 ); }
// includes/class-nicepay-gateway.php:430 / :445
$moid = $order->get_meta( '_nicepay_moid' );
$result = $this->api->request_cancel( $tid, $cancel_amt, $reason, $moid, $is_partial );
// admin/class-nicepay-transactions.php:208
$result = $api->request_cancel( $tid, $cancel_amount, $reason, $transaction->moid );
// includes/class-nicepay-gateway.php:153 — runs on every receipt render
$order->update_meta_data( '_nicepay_moid', $moid );
```

**Impact.** Two partial cancels of the same payment submit an identical `Moid`, which the PG may reject as a duplicate, and merchant-side reconciliation against NICEPAY's settlement file cannot distinguish the two cancel legs. *Severity downgraded from high:* NICEPAY's actual duplicate-Moid handling on `cancel_process.jsp` could not be verified, so the acute failure is unproven — full cancels (the common case) happen once per TID and would be unaffected. The reconciliation and stale-Moid problems are certain; the PG rejection is not.

> **Needs confirmation.** The consequence is PLAUSIBLE rather than proven — there is no way here to test whether NICEPAY rejects a repeated cancel `Moid`. The spec-conformance deviation itself is unambiguous.

**Recommendation.** Generate the cancel `Moid` inside `request_cancel()` via `$this->generate_moid( 'CXL' )` and persist it on the order and the transaction row alongside the original payment `Moid` so refunds are auditable. Keep the original `Moid` strictly for lookup. Separately, stop overwriting `_nicepay_moid` — append attempts to an array keyed by TID, or store the `Moid` on the transaction row only.

```php
public function request_cancel( $tid, $cancel_amt, $cancel_msg, $original_moid = '', $partial = false, $extra_params = array() ) {
    $cancel_moid = $this->generate_moid( 'CXL' ); // spec 8.3: NEW unique cancel order no
    $params = array(
        'TID'  => $tid,
        'MID'  => $this->mid,
        'Moid' => $cancel_moid,
        // …
    );
```

---

#### PROTOCOL-10 — Virtual-account refunds after deposit are impossible: `RefundAcctNo` / `RefundBankCd` / `RefundAcctNm` are never sent

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: medium

**Files:** [includes/class-nicepay-gateway.php:418-473](../../includes/class-nicepay-gateway.php#L418) · [includes/class-nicepay-api.php:337-352](../../includes/class-nicepay-api.php#L337) · [admin/class-nicepay-transactions.php:143-151](../../admin/class-nicepay-transactions.php#L143)

**Spec:** §9 — conditional `RefundAcctNo(16)` / `RefundBankCd(3)` / `RefundAcctNm(10)` for VBANK refunds after deposit.

**Problem.** `process_refund()` is method-agnostic — it builds the identical cancel call for CARD, BANK, VBANK and CELLPHONE with no branch on `_nicepay_pay_method`, no UI to collect the customer's refund bank account, and no use of the `$extra_params` hook that `request_cancel()` already exposes. The admin transactions screen renders a Cancel button for VBANK rows in both `paid` and `waiting` status and calls the same parameter-less cancel.

**Evidence.** `includes/class-nicepay-gateway.php:437-445` builds the cancel identically regardless of method. `grep -rn "RefundAcct\|RefundBankCd" --include='*.php' --include='*.js' .` returns **zero hits anywhere in the plugin**.

```php
// includes/class-nicepay-api.php:352 — the extension point exists; no caller uses it
$params = array_merge( $params, $extra_params );
// admin/class-nicepay-transactions.php:143
if ( in_array( $item->status, array( 'paid', 'waiting' ), true ) && $item->tid )
```

[docs/API-REFERENCE.md:253-255](../API-REFERENCE.md#L253) and [docs/USER-GUIDE.md:497](../USER-GUIDE.md#L497) both document the requirement the code cannot meet.

**Impact.** *Severity downgraded from high on reachability grounds.* Because the plugin has no deposit-notification endpoint (PROTOCOL-04), a VBANK order never leaves `on-hold`/`waiting` on its own, and WooCommerce does not render the Refund UI for on-hold orders — so the WooCommerce path is largely unreachable until a merchant manually advances the order. The reachable path today is the admin Transactions **Cancel** button on a `waiting` row after the customer has in fact deposited: that submits a post-deposit cancel with no refund account, which NICEPAY rejects. Nothing in the UI distinguishes "not yet deposited, plain cancel is correct" from "deposited, refund account required".

**User scenario.** A customer deposits 120,000 KRW into the issued virtual account, then asks to cancel. The merchant clicks Cancel in the Transactions screen; NICEPAY rejects it for missing refund-account parameters; the merchant wires the money back by hand.

**Recommendation.** Branch `process_refund()` and `ajax_cancel_transaction()` on the stored payment method. For VBANK where the deposit has been received, require the three fields before submitting: add account number / bank code / holder-name inputs to the refund and admin-cancel dialogs, validate `RefundBankCd` against the spec §11 bank table, byte-truncate `RefundAcctNm` to 10 with `mb_strcut`, and pass them through `$extra_params`. Where the deposit has **not** arrived, the plain cancel is correct — label the two cases distinctly in the UI.

---

#### PROTOCOL-11 — `OTID` is never captured, so second and later mobile partial cancels cannot be issued

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: medium

**Files:** [includes/class-nicepay-api.php:374-393](../../includes/class-nicepay-api.php#L374) · [includes/class-nicepay-gateway.php:425](../../includes/class-nicepay-gateway.php#L425), [:439-445](../../includes/class-nicepay-gateway.php#L439)

**Spec:** §9 — cancel response `OTID(30 — returned for mobile partial cancel/refund; 2nd+ partial cancels MUST use OTID)`.

**Problem.** The cancel response is returned wholesale but `OTID` is never read, persisted or resent. `process_refund()` always sources `$tid` from `$order->get_meta( '_nicepay_tid' )` — the original approval TID — with no notion that a prior partial cancel changed the identifier to use. Related fields that would let the plugin reason about refund state (`RemainAmt`, `CancelNum`, `CancelDate`/`CancelTime`) are likewise never read, so the plugin cannot tell how much of a payment remains cancellable and relies on `$order->get_total()` arithmetic instead.

**Evidence.** `grep -rn "OTID\|RemainAmt\|CancelNum" --include='*.php' --include='*.js' .` returns zero hits in plugin code ([docs/API-REFERENCE.md:273](../API-REFERENCE.md#L273) documents `RemainAmt`).

```php
// includes/class-nicepay-gateway.php:425 — always the original TID
$tid = $order->get_meta( '_nicepay_tid' );
// :439 — partiality derived from the order total, not the remaining balance
$is_partial = ( (float) $cancel_amt < (float) $total_amt );
```

**Impact.** For CELLPHONE payments, the second partial refund of the same order fails at the PG. WooCommerce offers repeated partial refunds freely, so the merchant discovers this only when it breaks. *Severity downgraded from high:* the blast radius is one payment method's second-and-later partial refunds.

**Recommendation.** After every successful cancel, persist `OTID`, `RemainAmt`, `CancelNum` and `CancelDate`/`CancelTime` on the order and the transaction row. On subsequent cancels, prefer the stored `OTID` over `TID` when the payment method is CELLPHONE and a prior partial cancel exists. Use the stored `RemainAmt` to compute `PartialCancelCode` and to block over-refunds before hitting the network.

---

#### PROTOCOL-12 — With no payment methods enabled the auth form omits the required `PayMethod` parameter and warns in PHP 8

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `correctness` · CONFIRMED · effort: trivial

**Files:** [templates/payment-form.php:56-59](../../templates/payment-form.php#L56) · [templates/standalone-payment-form.php:39](../../templates/standalone-payment-form.php#L39) · [admin/class-nicepay-admin.php:112-117](../../admin/class-nicepay-admin.php#L112) · [includes/class-nicepay-gateway.php:76-86](../../includes/class-nicepay-gateway.php#L76)

**Spec:** §4 — required auth-request parameters include `PayMethod(10)`.

**Problem.** The sanitiser returns an empty array when every checkbox is unticked, and nothing enforces a minimum of one method. WordPress's options.php passes `null` for an entirely absent field, the callback turns it into `array()`, and `get_option( 'nicepay_enabled_methods', array( 'CARD' ) )` then returns that stored empty array — the default never applies because the option exists. Downstream, the WooCommerce template omits the `PayMethod` input entirely, and the standalone template indexes `$enabled_methods[0]` unguarded.

**Evidence.**

```php
// admin/class-nicepay-admin.php:113-117
'sanitize_callback' => function ( $value ) { return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array(); },
// templates/payment-form.php:56-59 — PayMethod input wrapped in a guard
if ( ! empty( $enabled_methods ) )
// templates/standalone-payment-form.php:39 — unguarded
$default_method = $pay_method ? $pay_method : $enabled_methods[0];
// includes/class-nicepay-gateway.php:76-86 — is_available() checks only $this->enabled and MID/key
```

**Impact.** WooCommerce path: a required spec parameter is missing, so NICEPAY rejects the auth with an opaque error on its own page and the merchant has no clue a settings checkbox caused it. Standalone path: PHP 8 raises "Undefined array key 0" (potentially printed into page output) and `PayMethod` is submitted empty. `is_available()` never consults `nicepay_enabled_methods`, so the gateway remains visible at checkout in this state. *Severity downgraded from high:* this is a self-inflicted misconfiguration with an obviously-wrong admin state.

**Recommendation.** In the sanitiser, intersect against `array_keys( NicePay_API::get_available_methods() )` and fall back to `array( 'CARD' )` on empty (or `add_settings_error()` and reject the save). Defensively guard both templates. Make `is_available()` return false when no method is enabled so the gateway hides at checkout, and render an inline warning on the Payment Methods tab when zero are selected.

```php
'sanitize_callback' => function ( $value ) {
    $value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
    $value = array_values( array_intersect( $value, array_keys( NicePay_API::get_available_methods() ) ) );
    return $value ? $value : array( 'CARD' );
},
```

---

#### PROTOCOL-13 — Connect timeout is never set to the mandated 5 s, redirects are followed, and the HTTP status code is never inspected

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · **PLAUSIBLE — needs confirmation** · effort: small

**Files:** [includes/class-nicepay-api.php:213-220](../../includes/class-nicepay-api.php#L213), [:305-312](../../includes/class-nicepay-api.php#L305), [:360-367](../../includes/class-nicepay-api.php#L360)

**Spec:** §2 — "Timeouts: Connection 5 sec, Receive(Read) 30 sec."

**Problem.** All three outbound calls pass only `'timeout' => 30` and `'sslverify' => true`. WordPress maps `timeout` to the total/read timeout; the plugin never specifies a connect timeout, so it inherits the transport default — neither of which is the 5 s the spec mandates. The read timeout of 30 s does match. Additionally none of the three calls passes `'redirection' => 0` (WP's default of 5 silently follows redirects, and a followed POST redirect degrades to a GET with no body), and `wp_remote_retrieve_response_code()` is never called anywhere in the plugin, so a 502 with an HTML body is indistinguishable from a malformed 200 and falls through to the parse-error branch that fires a net cancel.

**Evidence.** Lines 213-220, 305-312 and 360-367 each pass exactly `'timeout' => 30, 'sslverify' => true, 'headers' => …, 'body' => …` — no connect timeout, no `redirection`. `grep -rn "http_api_curl\|redirection\|wp_remote_retrieve_response_code" --include='*.php' .` returns zero hits repo-wide.

**Impact.** A blackholed DC costs the transport default (10 s under the Requests transport, potentially the full 30 s on the legacy curl path) before failing instead of 5 s — and the failure path immediately issues a net cancel with the same budget, so worst case is roughly 60 s inside a user-facing request. If PHP's `max_execution_time` (commonly 30-60 s) is reached, the process can be killed *during* the net cancel: the approval succeeded, the void never completed, and nothing records it. The shopper meanwhile sees a blank page for up to a minute.

> **Needs confirmation.** CONFIRMED that the plugin sets no connect timeout, no redirection cap and never reads the HTTP status. PLAUSIBLE for the specific consequence — the exact default connect timeout depends on which WP HTTP transport is active, and whether the ~60 s worst case trips `max_execution_time` depends on host configuration.

**Recommendation.** Add an `http_api_curl` filter scoped to `.nicepay.co.kr` hostnames setting `CURLOPT_CONNECTTIMEOUT => 5`; pass `'redirection' => 0` on all three calls; check `wp_remote_retrieve_response_code()` before parsing and treat non-2xx as an explicit transport failure. Before the approval call, add `ignore_user_abort( true )` and raise `set_time_limit()` past 30 s + 30 s so the void cannot be truncated.

```php
add_action( 'http_api_curl', function ( $handle, $args, $url ) {
    if ( strpos( $url, '.nicepay.co.kr' ) !== false ) {
        curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 5 ); // spec §2
    }
}, 10, 3 );
// on each call: 'redirection' => 0,
// after each call: $code = wp_remote_retrieve_response_code( $response ); if ( $code < 200 || $code >= 300 ) { /* transport failure */ }
```

---

#### PROTOCOL-14 — `EdiDate` and `VbankExpDate` are generated in UTC, not KST — virtual-account windows are short by 9 hours

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `correctness` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-api.php:59-61](../../includes/class-nicepay-api.php#L59), [:66-68](../../includes/class-nicepay-api.php#L66), [:426-429](../../includes/class-nicepay-api.php#L426)

**Spec:** §4 (`EdiDate(30)` YYYYMMDDHHMISS); §4/5.2.2 (`VbankExpDate` 8 = YYYYMMDD or 12 = YYYYMMDDHHMI).

**Problem.** All NICEPAY timestamps use bare `date()`. WordPress calls `date_default_timezone_set( 'UTC' )` in wp-settings.php, so these are UTC wall-clock strings, while NICEPAY interprets them as Korea Standard Time (UTC+9). Using `date()` at all also violates the WordPress coding standards, which require `gmdate()`/`wp_date()`.

**Evidence.**

```php
// :60
return date( 'YmdHis' );
// :67
return $prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
// :428
return date( 'YmdHi', strtotime( '+' . $days . ' days' ) );
```

`grep -rn "KST\|Asia/Seoul\|DateTimeZone\|gmdate\|wp_date" --include='*.php' .` returns zero hits in plugin code.

**Impact.** `VbankExpDate` is the concrete harm: a value computed as UTC now+N days and read by the PG as a KST timestamp lands 9 hours earlier than intended. With the default 3-day setting the window shrinks from 72 h to 63 h; with the minimum 1-day setting from 24 h to 15 h — a customer issued an account at 18:00 KST expecting until 18:00 tomorrow loses it at 09:00. `EdiDate` skew leaves merchant-side timestamps 9 hours off from the NICEPAY console, making support lookups and reconciliation confusing.

> **Needs confirmation.** The `VbankExpDate` consequence is arithmetically certain. Whether NICEPAY validates `EdiDate` freshness — which would make the `EdiDate` skew far more serious — is not stated in the spec digest and could not be tested.

**Recommendation.** Compute all NICEPAY timestamps explicitly in Asia/Seoul. Display the resulting expiry to the shopper and in the admin as a formatted local datetime with an explicit KST label, not the raw 12-digit string.

```php
private function kst_now() { return new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Seoul' ) ); }
public function generate_edi_date() { return $this->kst_now()->format( 'YmdHis' ); }
public function get_vbank_exp_date() {
    $days = max( 1, (int) get_option( 'nicepay_vbank_expiry_days', 3 ) );
    return $this->kst_now()->modify( '+' . $days . ' days' )->format( 'YmdHi' );
}
```

---

#### PROTOCOL-15 — `CurrencyCode` passes through any WooCommerce currency; non-KRW amounts carry decimals and KRW amounts are truncated, not rounded

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-gateway.php:149](../../includes/class-nicepay-gateway.php#L149), [:191](../../includes/class-nicepay-gateway.php#L191) · [includes/nicepay-functions.php:248-258](../../includes/nicepay-functions.php#L248) · [templates/standalone-payment-form.php:17](../../templates/standalone-payment-form.php#L17), [:141](../../templates/standalone-payment-form.php#L141)

**Spec:** §4 — `CurrencyCode(3: KRW default / USD)`; `Amt(12)`.

**Problem.** The gateway sends whatever currency the store uses, unchecked, and `nicepay_get_amount()` emits a two-decimal string for anything that is not KRW. The spec permits only KRW and USD. There is no validation at the gateway, in `is_available()`, or on the shortcode's `currency` attribute (`sanitize_text_field` only). The admin dropdown *does* restrict `nicepay_currency` to KRW/USD, but the WooCommerce path ignores that option entirely and uses `$order->get_currency()`.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:149 / :191
$currency = $order->get_currency();
'CurrencyCode' => $currency,
// includes/nicepay-functions.php:253-257
if ( $currency === 'KRW' ) { return (string) (int) $amount; }
return number_format( (float) $amount, 2, '.', '' );
// templates/standalone-payment-form.php:17
$currency = sanitize_text_field( $atts['currency'] );
```

**Impact.** Silent, hard-to-diagnose PG rejections for any non-KRW/USD store, appearing as a generic error inside the NICEPAY window. Even for USD, the decimal `Amt` is unverified against the spec's `Amt(12)`. Separately the KRW branch **truncates**: `(string) (int) 1004.6` is `1004`, so a total of 1004.60 (reachable when `woocommerce_price_num_decimals` is left at its default of 2 and tax rounding applies) is charged as 1004 while WooCommerce records 1004.60 and then marks the order fully paid.

**Recommendation.** Whitelist the currency at the boundary: in `WC_Gateway_NicePay::is_available()`, return false unless `get_woocommerce_currency()` is KRW or USD, and show an admin notice explaining why the gateway is hidden. Use `round()` instead of `(int)` truncation for KRW, and return an empty string (which callers must reject) for anything outside {KRW, USD}. Constrain the shortcode `currency` attribute to the same whitelist. Confirm with NICEPAY whether USD `Amt` is minor-unit or decimal and encode the answer in a test.

```php
function nicepay_get_amount( $amount, $currency = '' ) {
    if ( ! $currency ) { $currency = get_option( 'nicepay_currency', 'KRW' ); }
    $currency = strtoupper( $currency );
    if ( $currency === 'KRW' ) { return (string) (int) round( (float) $amount ); }
    if ( $currency === 'USD' ) { return number_format( (float) $amount, 2, '.', '' ); }
    return ''; // caller must reject — NicePay supports KRW and USD only
}
```

---

#### PROTOCOL-16 — `GoodsCl` is hardcoded to physical goods, so content sellers mis-declare every mobile payment

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-gateway.php:200-203](../../includes/class-nicepay-gateway.php#L200) · [templates/payment-form.php:61-63](../../templates/payment-form.php#L61) · [templates/standalone-payment-form.php:148-150](../../templates/standalone-payment-form.php#L148)

**Spec:** §4/5.2.3 — CELLPHONE extras: `GoodsCl` REQUIRED, `0` = contents, `1` = physical goods.

**Problem.** Every CELLPHONE payment declares physical goods, with a comment acknowledging the assumption. The same literal `1` is hardcoded in both templates. There is no setting, no per-product override, and no derivation from WooCommerce's own `$product->is_virtual()` / `is_downloadable()` — data the gateway already has via `$order->get_items()` (it iterates them at line 128 to build `GoodsName`).

**Evidence.**

```php
// includes/class-nicepay-gateway.php:201-203
if ( in_array( 'CELLPHONE', $enabled_methods, true ) ) {
    $form_data['GoodsCl'] = '1'; // Physical goods
}
// templates/payment-form.php:62 (and standalone-payment-form.php:149, identical)
<input type="hidden" name="GoodsCl" value="1">
```

**Impact.** Korean carrier billing applies different monthly limits and consumer-protection rules to contents (0) versus physical goods (1). A store selling digital downloads, courses or subscriptions declares the wrong class on every mobile transaction — a compliance exposure with the carriers and the PG. [docs/USER-GUIDE.md:503](../USER-GUIDE.md#L503) documents the hardcoding as if it were a feature.

**Recommendation.** Derive it for WooCommerce: if every line item is virtual or downloadable, send `0`, otherwise `1`. Add a `nicepay_goods_cl` setting (Contents / Physical goods / Auto) for the default and the shortcode path, expose it as a `goods_cl` shortcode attribute, and wrap the result in a filter so mixed catalogues can override per order.

```php
$all_virtual = true;
foreach ( $order->get_items() as $item ) {
    $product = $item->get_product();
    if ( ! $product || ! ( $product->is_virtual() || $product->is_downloadable() ) ) { $all_virtual = false; break; }
}
$form_data['GoodsCl'] = apply_filters( 'nicepay_goods_cl', $all_virtual ? '0' : '1', $order );
```

---

#### PROTOCOL-17 — `MallUserID` is set to the buyer's email, exceeding the 20-byte limit, and `GIFT_CULT`'s success code is unverified

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-gateway.php:205-208](../../includes/class-nicepay-gateway.php#L205) · [templates/standalone-payment-form.php:243-248](../../templates/standalone-payment-form.php#L243) · [includes/class-nicepay-api.php:396-414](../../includes/class-nicepay-api.php#L396)

**Spec:** §4 (`MallUserID(20)`); §4/5.2.4 (GIFT_CULT requires `MallUserID`); §7 (success codes documented for CARD/BANK/VBANK/CELLPHONE/SSG_BANK only).

**Problem.** Two problems in the Culture Cash path. First, the required `MallUserID` is populated with the buyer's email with no truncation, in both the server template and the standalone JS — the spec caps it at 20 bytes and typical email addresses exceed that. Second, `is_success_code()` declares `'GIFT_CULT' => '0000'`, a value the spec digest does not document for this method (it documents 0000 for SSG_BANK only), and [tests/unit/NicePayResultCodeTest.php:69-71](../../tests/unit/NicePayResultCodeTest.php#L69) locks the guess in as if it were spec.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:206-208
if ( in_array( 'GIFT_CULT', $enabled_methods, true ) ) {
    $form_data['MallUserID'] = $order->get_billing_email();
}
// templates/standalone-payment-form.php:244-248
var email = document.getElementById(formId + '-buyeremail').value;
mallUserIdInput.value = email || '';
// includes/class-nicepay-api.php:400-407 — GIFT_CULT is the only entry with no counterpart in digest §7
$success_codes = array( 'CARD' => '3001', 'BANK' => '4000', 'VBANK' => '4100', 'CELLPHONE' => 'A000', 'SSG_BANK' => '0000', 'GIFT_CULT' => '0000', );
```

**Impact.** Culture Cash auth requests are likely rejected on the over-length `MallUserID`. If the success code is also wrong, a genuinely successful Culture Cash approval would be classified as a failure — which on the WooCommerce path fails the order for a payment that actually went through. GIFT_CULT is offered as a first-class checkbox in the admin settings with no warning that the integration is unverified.

**Recommendation.** Use a stable short merchant-side identifier rather than an email. Confirm the GIFT_CULT approval success code with NICEPAY (it@nicepay.co.kr) before shipping the method; until confirmed, put GIFT_CULT and SSG_BANK behind an "advanced — contact your NICEPAY rep" disclosure in settings, and annotate the test assertion as unverified rather than authoritative.

```php
$mall_user_id = $order->get_customer_id() ? 'u' . $order->get_customer_id() : 'g' . substr( md5( $order->get_billing_email() ), 0, 16 );
$form_data['MallUserID'] = mb_strcut( $mall_user_id, 0, 20, 'UTF-8' );
```

---

#### PROTOCOL-18 — Only `GoodsName` is byte-truncated; `BuyerName`, `BuyerTel`, `BuyerEmail`, `Moid` and `CancelMsg` can silently overflow

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-gateway.php:143-144](../../includes/class-nicepay-gateway.php#L143), [:187-189](../../includes/class-nicepay-gateway.php#L187) · [templates/standalone-payment-form.php:47](../../templates/standalone-payment-form.php#L47), [:96-121](../../templates/standalone-payment-form.php#L96) · [includes/class-nicepay-api.php:345](../../includes/class-nicepay-api.php#L345)

**Spec:** §4 — `GoodsName(40)`, `BuyerName(30)`, `BuyerTel(20)`, `BuyerEmail(60)`, `Moid(64)`, `ReqReserved(500)`; §9 — `CancelMsg(100)`.

**Problem.** `GoodsName` is correctly byte-truncated with `mb_strcut` in all three places that build it — and nowhere else in the plugin is any other size-limited field clipped. `BuyerName` is `first . ' ' . last` straight from billing; 10 Korean characters is already 30 UTF-8 bytes, and European/Turkish full names routinely exceed 30. `BuyerTel`, `BuyerEmail` and `CancelMsg` pass through untouched. In the standalone form the buyer types these values with no `maxlength` attribute and no server-side length check in `ajax_init_payment`. `CancelMsg` comes from a free-text admin textarea ([assets/js/nicepay-admin.js:151-166](../../assets/js/nicepay-admin.js#L151)) or a WooCommerce refund reason, with no cap.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:143-144
// Truncate goods name to 40 bytes (NicePay limit is byte-based)
$goods_name = mb_strcut( $goods_name, 0, 40, 'UTF-8' );
// :187-189 — untouched
'BuyerName'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
'BuyerTel'   => $order->get_billing_phone(),
'BuyerEmail' => $order->get_billing_email(),
// includes/class-nicepay-api.php:345
'CancelMsg' => $cancel_msg,
```

`grep -rn mb_strcut --include='*.php' .` returns exactly three hits, all `GoodsName` (gateway.php:144, standalone-payment-form.php:47, nicepay-payment-gateway.php:346).

**Impact.** Over-length fields are rejected or silently truncated by the PG, producing auth failures the merchant cannot explain or corrupt buyer records in the NICEPAY console. The inconsistency is the tell: the author knew these are byte limits and applied the knowledge in exactly one place. Note also that if EUC-KR mode is ever made to work (PROTOCOL-06), the byte accounting changes (Korean is 2 bytes in EUC-KR vs 3 in UTF-8), so the truncation width must follow the wire charset.

**Recommendation.** Add one helper and apply it at every field-assembly site: `BuyerName` 30, `BuyerTel` 20, `BuyerEmail` 60, `Moid` 64, `CancelMsg` 100, `ReqReserved` 500, `MallUserID` 20. Add matching `maxlength` attributes to the standalone form inputs, and mirror the checks server-side in `ajax_init_payment`.

```php
function nicepay_clip( $value, $bytes ) {
    $charset = get_option( 'nicepay_charset', 'utf-8' ) === 'euc-kr' ? 'EUC-KR' : 'UTF-8';
    return mb_strcut( (string) $value, 0, $bytes, $charset );
}
// 'BuyerName' => nicepay_clip( $name, 30 ), 'BuyerTel' => nicepay_clip( $tel, 20 ),
// 'BuyerEmail' => nicepay_clip( $email, 60 ), 'CancelMsg' => nicepay_clip( $cancel_msg, 100 ),
```

---

#### PROTOCOL-19 — No installment support at all: `SelectQuota`, `SelectCardCode`, `ShopInterest` and `QuotaInterest` are never sent

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: large

**Files:** [includes/class-nicepay-gateway.php:179-208](../../includes/class-nicepay-gateway.php#L179) · [templates/standalone-payment-form.php:129-154](../../templates/standalone-payment-form.php#L129) · [docs/API-REFERENCE.md:87-92](../API-REFERENCE.md#L87)

**Spec:** §4/5.2.1 — CARD extras: `SelectQuota(2, comma list, '00' = lump sum, min 50,000 KRW for installments; mobile requires SelectCardCode together with SelectQuota)`, `SelectCardCode(2)`, `ShopInterest(1)`, `QuotaInterest`, `AcquReqDate(8)`.

**Problem.** Not one card-specific auth parameter is implemented. Every card payment falls back to whatever the MID default allows, with no merchant control over which installment plans appear, which issuers are offered, or who bears the interest. `AcquReqDate` also blocks reserved-purchase MIDs entirely.

**Evidence.** `grep -rnoE "\b(SelectQuota|SelectCardCode|ShopInterest|QuotaInterest|AcquReqDate)\b" --include='*.php' --include='*.js' .` returns zero hits in plugin code. `includes/class-nicepay-gateway.php:179-193` — the complete `$form_data` array contains no card parameters; `templates/standalone-payment-form.php:129-154` likewise. `docs/API-REFERENCE.md:87-92` documents all four under "Credit Card Extra Parameters".

**Impact.** 할부 (installments) are table stakes for Korean card checkout above 50,000 KRW, and merchant-funded interest-free installments (`ShopInterest`/`QuotaInterest`) are a primary conversion lever Korean shops advertise on the product page. A merchant cannot express any of it. This is a missing feature rather than a defect, hence medium — but it is the largest single gap between "works" and "first-class" for the Korean market.

**User scenario.** A Korean shopper buying a 600,000 KRW item expects the 6-month interest-free installments the merchant advertises, but the payment window offers only the MID default and the merchant has no setting to change it.

**Recommendation.** Add a Card section to the Payment Methods tab: allowed installment months (multi-select rendering `SelectQuota` as a comma list with `00` always included), optional issuer restriction (`SelectCardCode`), merchant interest-free toggle (`ShopInterest`), and a `QuotaInterest` builder. Enforce the spec's rules in code: refuse to send installment months when `Amt < 50000`, and always pair `SelectCardCode` with `SelectQuota` on mobile. Expose the same as shortcode attributes. Until implemented, correct `docs/API-REFERENCE.md:87-92` so it does not read as a feature list.

---

#### PROTOCOL-20 — `CcPartCl` and `ClickpayCl` are ignored, so partial cancels are attempted where they are unsupported or irreversible

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: medium

**Files:** [includes/class-nicepay-gateway.php:325-355](../../includes/class-nicepay-gateway.php#L325), [:418-473](../../includes/class-nicepay-gateway.php#L418) · [admin/class-nicepay-transactions.php:143-151](../../admin/class-nicepay-transactions.php#L143)

**Spec:** §7 CARD extras — `CcPartCl(1: partial-cancel allowed 0/1)`, `ClickpayCl(2)` simple-pay codes 6/7/15/16/18/20/21/22/25; §12.2 — simple-pay services cannot be reverted after partial cancel.

**Problem.** `process_refund()` derives the partial flag from arithmetic alone and submits. The approval response tells the plugin whether partial cancel is even permitted (`CcPartCl`) and whether the payment ran through a simple-pay wallet (`ClickpayCl`) for which the spec warns partial cancellation is irreversible. Neither field is read anywhere, even though the whole response is archived in `payment_data` so the data is present and simply unused.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:439 — the sole determinant
$is_partial = ( (float) $cancel_amt < (float) $total_amt );
// :332 — confirms the fields are stored
'payment_data' => $result,
```

`grep -rn "CcPartCl\|ClickpayCl" --include='*.php' --include='*.js' .` returns zero plugin hits. `includes/class-nicepay-gateway.php:336-355` promotes only `card_*`/`bank_*`/`vbank_*` fields into `$update_data`.

**Impact.** Two bad outcomes. (1) A partial refund on a `CcPartCl=0` payment fails at the PG with a message the merchant cannot act on. (2) A partial refund on a NaverPay/KakaoPay/PAYCO/SSGPay/SKPay/ApplePay/TossPay transaction succeeds and permanently strands the remaining balance — the merchant can never cancel the rest, and nothing in the UI warned them. The plugin's own docs know this ([docs/CONFIGURATION.md:494](../CONFIGURATION.md#L494) advises checking `CcPartCl`) but the code does not.

**User scenario.** A merchant partially refunds 20,000 KRW of a 100,000 KRW KakaoPay order. It succeeds. A week later the customer returns the rest of the goods — and the remaining 80,000 KRW can never be cancelled through NICEPAY.

**Recommendation.** Promote `CcPartCl`, `ClickpayCl` and `CardType` into first-class columns at approval time (or read them back out of `payment_data`). In `process_refund()` and the admin cancel, refuse a partial cancel when `CcPartCl === '0'` with a message naming the reason, and when `ClickpayCl` is one of 6/7/15/16/18/20/21/22/25 require an explicit confirmation stating the remainder can never be refunded afterwards. Show a simple-pay badge on the transactions list so the constraint is visible before the merchant clicks.

---

#### PROTOCOL-21 — Raw Korean PG messages are shown to shoppers: there is no result-code → localized-message mapping

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `i18n` · CONFIRMED · effort: medium

**Files:** [includes/class-nicepay-gateway.php:265-268](../../includes/class-nicepay-gateway.php#L265), [:406-409](../../includes/class-nicepay-gateway.php#L406) · [includes/class-nicepay-return-handler.php:108](../../includes/class-nicepay-return-handler.php#L108), [:157](../../includes/class-nicepay-return-handler.php#L157), [:216](../../includes/class-nicepay-return-handler.php#L216)

**Spec:** §7/§8/§9 — `ResultCode`/`ResultMsg`, `ErrorCD(5)`/`ErrorMsg(97)`, `AuthResultCode`/`AuthResultMsg`.

**Problem.** Every failure surface concatenates the PG's own message straight into the shopper-facing notice, and the standalone result page renders `$message` — which is `$result_msg`, or on the error paths a raw `WP_Error` string such as "Signature verification failed." or "Failed to parse approval response." There is no lookup table anywhere translating NICEPAY result codes into merchant-authored localized copy, and the cancel/net-cancel `ErrorCD`/`ErrorMsg` fields are never read at all. The plugin ships four locales and an `NpLang` setting, so it explicitly targets non-Korean audiences.

**Evidence.**

```php
// includes/class-nicepay-gateway.php:265-268
wc_add_notice( __( 'Payment authentication failed. Please try again.', 'nicepay-payment-gateway' ) . ' (' . $auth_result_msg . ')', 'error' );
// :406-409
wc_add_notice( __( 'Payment failed.', 'nicepay-payment-gateway' ) . ' ' . $result_msg, 'error' );
// includes/class-nicepay-return-handler.php:108 / :157 / :216
$this->render_result_page( false, $result->get_error_message() );
$this->render_result_page( false, $result_msg );
<p class="result-message"><?php echo esc_html( $message ); ?></p>
```

`grep -rn "ErrorCD\|ErrorMsg" --include='*.php' .` → zero plugin hits.

**Impact.** An English or Turkish shopper on a failed payment sees Korean text appended to a translated sentence, or a developer-facing string that means nothing and offers no next step. Support load rises and the failure looks like a broken site rather than, say, an insufficient credit limit. For merchants, discarding `ErrorCD`/`ErrorMsg` throws away the most diagnostic fields NICEPAY returns on a cancel failure.

**User scenario.** A Turkish customer's card is declined. The checkout notice reads: `Ödeme kimlik doğrulaması başarısız oldu. Lütfen tekrar deneyin. (카드사 승인거절)`.

**Recommendation.** Add `nicepay_get_result_message( $code, $fallback_msg )` mapping the codes merchants actually hit (limit exceeded, invalid card, cancelled by user, expired, issuer decline, PG timeout) to translatable action-oriented copy, falling back to the PG string only for unmapped codes. Show the raw `ResultCode`/`ResultMsg`/`ErrorCD`/`ErrorMsg` to the merchant (order note + transactions row + details drawer), never to the shopper. Give every shopper-facing failure a next action — Retry, Choose another method, Contact support — rather than a dead end with a lone "Return to Home" button ([return-handler.php:259-263](../../includes/class-nicepay-return-handler.php#L259)).

---

#### PROTOCOL-22 — Every non-`0000` `AuthResultCode` marks the WooCommerce order failed, including a shopper who simply backs out

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `ux-clarity` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-gateway.php:248-272](../../includes/class-nicepay-gateway.php#L248) · [includes/class-nicepay-return-handler.php:53-67](../../includes/class-nicepay-return-handler.php#L53)

**Spec:** §5 (`AuthResultCode(4)` — 0000 = success, anything else = failure; only call `NextAppURL` when 0000); §12 (mobile posts the result to `ReturnURL`, including user-abort outcomes).

**Problem.** Both handlers collapse every non-success auth outcome into a hard failure. The gate itself is correct per spec — approval must only be attempted on 0000. What is wrong is the **consequence**: NICEPAY returns distinct codes for a user-initiated cancel, a timeout and a genuine decline, and on mobile the cancel outcome is POSTed to `ReturnURL` just like a decline, so the plugin cannot tell them apart and backing out of the payment window destroys the order.

**Evidence.** `includes/class-nicepay-gateway.php:249-271` — one branch for all non-0000 codes ending in `$order->update_status( 'failed', … ); wc_add_notice( … ); wp_safe_redirect( wc_get_checkout_url() );`. Same collapse at `class-nicepay-return-handler.php:54-66`. Contrast:

```php
// templates/payment-form.php:83-86 — PC close path handled client-side without failing the order
window.nicepayClose = function() { … window.location.href = '<?php echo esc_js( wc_get_checkout_url() ); ?>'; };
```

so the two channels behave inconsistently for the same user action.

**Impact.** Ordinary shopper hesitation produces `failed` orders. Because the order is `pending` at receipt-page time, the pending→failed transition fires WooCommerce's failed-order admin email, pollutes the order list and skews conversion reporting.

> **Refuted sub-claim.** The shopper does **not** have to rebuild their basket. WooCommerce's `wc_clear_cart_after_payment()` only empties the cart when the awaiting-payment order is *not* in failed/pending/cancelled state, so the cart is preserved across a failed NicePay return. The real cost is the redirect to `wc_get_checkout_url()` instead of `$order->get_checkout_payment_url()`, which drops the shopper out of the payment they were mid-way through, plus the spurious failed-order noise.

**User scenario.** A shopper opens the payment window, decides to use a different card, and closes it. Their order is marked Failed, the merchant gets a failed-order email, and the shopper is dropped back to the generic checkout instead of the pay page for the order they already created.

**Recommendation.** Classify the auth outcome before acting: treat user-cancel/window-close codes as `pending` (order note only, no status change, no failed-order email) and redirect to `$order->get_checkout_payment_url()` so the shopper can retry in one click; reserve `failed` for genuine declines. Store `AuthResultCode`/`AuthResultMsg` on the order regardless. Obtain the cancel-specific codes from NICEPAY and encode them in the classifier; until then, at minimum stop setting `failed` when no approval was ever attempted.

---

#### PROTOCOL-23 — Standalone return handler runs a real approval even when no transaction matches the `Moid`

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `correctness` · CONFIRMED · effort: trivial

**Files:** [includes/class-nicepay-return-handler.php:50-51](../../includes/class-nicepay-return-handler.php#L50), [:97](../../includes/class-nicepay-return-handler.php#L97), [:145](../../includes/class-nicepay-return-handler.php#L145) · [includes/class-nicepay-gateway.php:235-239](../../includes/class-nicepay-gateway.php#L235)

**Spec:** §5-6 — the merchant must reconcile the auth response against its own order before approving.

**Problem.** The handler looks the transaction up but never requires it. Every database write is wrapped in `if ( $transaction )` — five times, at lines 57, 73, 100, 145 and 153 — while the money-moving `request_approval()` call at line 97 is unguarded. If the `Moid` matches no row (a replayed POST with a mutated `Moid`, a stale form, a `Moid` from another environment, a deleted row), the plugin still charges the card, still renders the success page, and records nothing at all.

**Evidence.**

```php
// includes/class-nicepay-return-handler.php:51
$transaction = nicepay_get_transaction_by_moid( $moid );
// :97 — no guard
$result = $this->api->request_approval( $auth_data );
```

Contrast the WooCommerce handler:

```php
// includes/class-nicepay-gateway.php:235-239
if ( ! $transaction || ! $transaction->wc_order_id ) {
    … wp_die( esc_html__( 'Order not found.', … ), 'NicePay Error', array( 'response' => 404 ) ); return;
}
```

**Impact.** A real charge with zero merchant-side record: no transaction row, no order, nothing in the admin list, and the shopper sees "Payment Successful". Reconciliation against the NICEPAY settlement file is the only way it surfaces. The two handlers disagree on the same safety question — itself a bug signal.

**Recommendation.** Fail closed: if no transaction matches the `Moid`, log at error level and render the failure page **before** calling `request_approval()`. Put the other two reconciliation checks at the same gate: assert `$transaction->status === 'pending'` (PROTOCOL-05) and `(int) $amt === (int) $transaction->amount` (PROTOCOL-01), so all three live in one place.

```php
$transaction = nicepay_get_transaction_by_moid( $moid );
if ( ! $transaction ) {
    nicepay_log( 'Standalone return: no transaction for moid', $moid );
    $this->render_result_page( false, __( 'We could not match this payment to an order. No charge was made. Please contact the site owner.', 'nicepay-payment-gateway' ) );
    return;
}
```

---

#### PROTOCOL-24 — Every receipt-page render mints a new `Moid` and inserts another transaction row

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `correctness` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-gateway.php:121-177](../../includes/class-nicepay-gateway.php#L121), [:430](../../includes/class-nicepay-gateway.php#L430)

**Spec:** §4 — `Moid(64)` merchant order id, unique per transaction.

**Problem.** `generate_payment_form()` unconditionally creates fresh state on every load: a new `Moid`, a new `EdiDate`, an overwrite of `_nicepay_moid`/`_nicepay_edi_date`, and a fresh `nicepay_save_transaction()` insert with `status => 'pending'`. A refresh, a back-navigation or a retry after a failed attempt therefore produces N pending rows for one order, and `_nicepay_moid` always points at the newest — which may not be the attempt that ultimately succeeded.

**Evidence.**

```php
// :122-123
$edi_date = $this->api->generate_edi_date();
$moid     = $this->api->generate_moid( 'WC' . $order->get_id() );
// :153-155
$order->update_meta_data( '_nicepay_moid', $moid );
$order->update_meta_data( '_nicepay_edi_date', $edi_date );
$order->save();
// :158-168
$tx_id = nicepay_save_transaction( array( 'order_id' => $moid, 'wc_order_id' => $order->get_id(), 'moid' => $moid, …, 'status' => 'pending', … ) );
```

All executed on every `receipt_page()` call with no reuse check; `:430` `$moid = $order->get_meta( '_nicepay_moid' );` in the refund path.

**Impact.** (a) The transactions table accumulates orphan `pending` rows that never resolve, cluttering the admin list and skewing any counting; there is no cleanup or expiry anywhere in the plugin. (b) `process_refund()` reads `_nicepay_moid` for the cancel `Moid`, so after a retry the cancel carries a `Moid` belonging to an abandoned attempt — compounding PROTOCOL-09. (c) The pending rows are indistinguishable from genuinely in-flight payments, so a merchant cannot tell what is stuck.

**Recommendation.** Reuse the existing pending attempt when one is present and still fresh: look up by `wc_order_id` with `status = 'pending'` and, if the order total is unchanged, reuse its `Moid` and row, refreshing only `EdiDate` and `SignData`. Otherwise mark the old row `abandoned` before inserting a new one. Keep a per-order history of `Moid`s rather than one overwritten meta key, and add a scheduled sweep that expires `pending` rows older than the VBANK expiry window.

---

#### PROTOCOL-25 — `is_success_code()` silently accepts any method's success code when `PayMethod` is missing or unrecognised

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: trivial

**Files:** [includes/class-nicepay-api.php:396-414](../../includes/class-nicepay-api.php#L396) · [includes/class-nicepay-gateway.php:322](../../includes/class-nicepay-gateway.php#L322), [:358-359](../../includes/class-nicepay-gateway.php#L358) · [tests/unit/NicePayResultCodeTest.php:124-127](../../tests/unit/NicePayResultCodeTest.php#L124)

**Spec:** §7 — ResultCode success codes are per method: CARD 3001, BANK 4000, VBANK 4100, CELLPHONE A000, SSG_BANK 0000.

**Problem.** The fallback branch discards the per-method contract: an unknown or empty `PayMethod` makes `3001` a success for a bank transfer and `4100` a success for a card. Callers reach this branch whenever the PG omits `PayMethod` from the approval response, since `$result_method` falls back to `$pay_method`, which itself comes from the unsigned return POST. The behaviour is deliberate enough to have a test blessing it.

**Evidence.**

```php
// includes/class-nicepay-api.php:409-413
if ( $payment_method && isset( $success_codes[ $payment_method ] ) ) {
    return $code === $success_codes[ $payment_method ];
}
return in_array( $code, array_values( $success_codes ), true );
// includes/class-nicepay-gateway.php:322 — fallback to the POST value
$result_method = isset( $result['PayMethod'] ) ? $result['PayMethod'] : $pay_method;
// :359
$update_data['status'] = ( $result_method === 'VBANK' ) ? 'waiting' : 'paid';
// tests/unit/NicePayResultCodeTest.php:124-127
public function test_success_code_unknown_method_checks_all(): void { $this->assertTrue( $this->api->is_success_code( '3001', 'UNKNOWN_METHOD' ) ); }
```

**Impact.** A response whose `ResultCode` is a *different* method's success value is treated as a successful payment — precisely the case where the merchant should be most suspicious. The downstream branch that chooses between `paid` and `waiting` also keys off `$result_method === 'VBANK'`, so a lost `PayMethod` marks a virtual account fully paid (and calls `payment_complete()`) before any deposit exists. Verified as a real fail-open path; its likelihood depends on NICEPAY omitting `PayMethod`, which the spec says it returns, so the trigger is conditional rather than routine.

**Recommendation.** Make the fallback fail closed and have callers treat that as a failed approval requiring a net cancel. Better still, compare the response's `PayMethod` against the method the shopper actually selected and refuse to settle on a mismatch. Update the test to assert the strict behaviour.

```php
public function is_success_code( $code, $payment_method = '' ) {
    $success_codes = array( 'CARD' => '3001', 'BANK' => '4000', 'VBANK' => '4100', 'CELLPHONE' => 'A000', 'SSG_BANK' => '0000' );
    if ( ! $payment_method || ! isset( $success_codes[ $payment_method ] ) ) {
        nicepay_log( 'is_success_code called with unknown PayMethod', $payment_method );
        return false; // fail closed — per-method codes are not interchangeable
    }
    return hash_equals( $success_codes[ $payment_method ], (string) $code );
}
```

---

#### PROTOCOL-26 — Any non-JSON approval response, including an HTTP error page, is treated as a parse error and triggers a net cancel

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-api.php:232-243](../../includes/class-nicepay-api.php#L232), [:319-323](../../includes/class-nicepay-api.php#L319), [:374-380](../../includes/class-nicepay-api.php#L374)

**Spec:** §6 (`EdiType(10 — unset = JSON, KV = key=value)`); §12 ("PG responses default to JSON; new fields may be added over time — merchants must tolerate extra fields").

**Problem.** Response handling is a bare `json_decode` plus a falsy check. Tolerance to *new fields* is fine — the decoded array is stored wholesale, so extra keys are harmless (a genuine strength worth keeping). Tolerance to *format* is not: a KV body, an EUC-KR body, an HTML error page and a gateway 502 all decode to `null` and land in the same branch that voids the payment. The HTTP status code is never inspected anywhere in the plugin.

**Evidence.**

```php
// includes/class-nicepay-api.php:232-242 — note: no body excerpt, no status code in the log
$body   = wp_remote_retrieve_body( $response );
$result = json_decode( $body, true );
if ( ! $result ) {
    nicepay_log( 'Approval response parse error' );
    if ( ! empty( $auth_data['NetCancelURL'] ) ) { $this->request_net_cancel( $auth_data ); }
    return new WP_Error( 'nicepay_parse_error', … );
}
```

Same shape at :319-323 (net cancel) and :374-380 (cancel). `grep -rn "wp_remote_retrieve_response_code" --include='*.php' .` → zero hits.

**Impact.** Any response the parser does not recognise voids a payment that may well have been approved. That is the right instinct for a true transport failure but the wrong outcome for a merely unexpected format — it turns an infrastructure hiccup (a 502 from a proxy in front of the PG) into a charge-then-void for the shopper. The plugin also cannot opt into `EdiType=KV`, so a merchant whose MID is provisioned for KV has no path. Note that the KV concern is theoretical today: the plugin never sends `EdiType`, so JSON is the contracted format.

**Recommendation.** Inspect `wp_remote_retrieve_response_code()` first and branch explicitly on non-2xx before attempting to parse. Then `json_decode`; if that fails, try `parse_str()` as a KV fallback before declaring a parse error, and log a redacted excerpt of the body so the failure is diagnosable at all. Distinguish "transport failed, approval status unknown" (net cancel warranted) from "approval clearly rejected" (no net cancel).

```php
$status = wp_remote_retrieve_response_code( $response );
$body   = wp_remote_retrieve_body( $response );
$result = json_decode( $body, true );
if ( ! is_array( $result ) && strpos( (string) $body, '=' ) !== false ) {
    parse_str( $body, $kv ); // EdiType=KV fallback, spec §6
    if ( ! empty( $kv['ResultCode'] ) ) { $result = $kv; }
}
if ( ! is_array( $result ) || empty( $result['ResultCode'] ) ) {
    nicepay_log( 'Approval response unparseable', array( 'http' => $status, 'excerpt' => substr( (string) $body, 0, 200 ) ) );
    /* net cancel */
}
```

---

#### PROTOCOL-27 — Documented spec parameters that are simply not implemented, several presented by the docs as supported

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: large

**Files:** [includes/class-nicepay-gateway.php:179-208](../../includes/class-nicepay-gateway.php#L179), [:231](../../includes/class-nicepay-gateway.php#L231) · [includes/class-nicepay-api.php:193-205](../../includes/class-nicepay-api.php#L193), [:340-352](../../includes/class-nicepay-api.php#L340) · [templates/standalone-payment-form.php:129-154](../../templates/standalone-payment-form.php#L129) · [docs/API-REFERENCE.md:77-83](../API-REFERENCE.md#L77)

**Spec:** §4 (optional auth params), §6 (approval optional params), §9 (cancel optional params).

**Problem.** Beyond the items reported separately, these documented parameters are never sent anywhere:

| Parameter | Spec ref | Consequence of absence |
|---|---|---|
| `ReqReserved` | §4, §5 | Read into a dead variable at gateway.php:231 and never used again — the natural round-trip channel for binding an order id is left unused, which is exactly what PROTOCOL-01 needs |
| `MallReserved` | §6, §9 | No merchant passthrough on approval/cancel |
| `EdiType` | §6 | Cannot opt into KV responses |
| `TransType` | §4 | No escrow support |
| `SupplyAmt` / `GoodsVat` / `ServiceAmt` / `TaxFreeAmt` | §4 | No tax breakdown, despite WooCommerce holding exactly this data; the four must sum to `Amt` |
| `SkinType`, `LogoImage`, `NPDisableScroll`, `ConnWithIframe` | §4 | No branding or window-presentation control |
| `WapUrl`, `IspCancelUrl` | §4 | Degrades in-app/webview and ISP card-app returns |
| `Period`, `CupDepositAmt`, `UserCI`, `AcquReqDate` | §4 | Reserved-purchase, cup-deposit and CI flows unavailable |

**Evidence.** `grep -rnoE "\b(ReqReserved|MallReserved|EdiType|TransType|SupplyAmt|GoodsVat|ServiceAmt|TaxFreeAmt|SkinType|LogoImage|ConnWithIframe|NPDisableScroll|WapUrl|IspCancelUrl|Period|CupDepositAmt|UserCI|AcquReqDate)\b" --include='*.php' --include='*.js' .` returns exactly two hits, both on the same line:

```php
// includes/class-nicepay-gateway.php:231 — the variable name and the POST key; zero further references
$req_reserved = isset( $_POST['ReqReserved'] ) ? sanitize_text_field( wp_unslash( $_POST['ReqReserved'] ) ) : '';
```

All other names appear only in `docs/API-REFERENCE.md`.

**Impact.** `docs/API-REFERENCE.md` lists `ReqReserved` (L77, L122), `LogoImage` (L81), `SkinType` (L82), `ConnWithIframe` (L83), `EdiType` (L142), `SupplyAmt` (L249) and the refund-account fields (L253-255) in its parameter tables without marking any of them unsupported, so the reference reads as a capability list. A merchant evaluating the plugin will believe it can brand the payment window, pass custom data and issue tax-split or escrow payments. The tax fields are the most consequential: Korean merchants selling tax-free goods cannot represent that, and cash-receipt (현금영수증) amounts will be wrong. `WapUrl`/`IspCancelUrl` matter to any merchant whose traffic arrives through a native app webview, common in Korea.

**Recommendation.** Triage into three buckets.

1. **Ship now, cheap and high value:** send `ReqReserved` carrying an HMAC of the order id on the auth request and verify it on return (it comes back per §5) — this doubles as the fix for PROTOCOL-01; add `SkinType` and `LogoImage` as branding settings.
2. **Ship next:** `SupplyAmt`/`GoodsVat`/`ServiceAmt`/`TaxFreeAmt` derived from WooCommerce tax data with a sum-equals-`Amt` assertion before send; `WapUrl`/`IspCancelUrl` for webview support.
3. **Explicitly out of scope:** escrow, `CupDepositAmt`, `UserCI`, `AcquReqDate` — say so.

Immediately, add a "Supported / Not supported" column to `docs/API-REFERENCE.md` so the reference stops describing the protocol as if it described the plugin.

---

#### PROTOCOL-28 — Shortcode payment form renders and signs with empty MID/MerchantKey when Live mode is not configured

![medium](https://img.shields.io/badge/severity-MEDIUM-yellow) `spec-conformance` · CONFIRMED · effort: trivial

**Files:** [templates/standalone-payment-form.php:16](../../templates/standalone-payment-form.php#L16), [:131](../../templates/standalone-payment-form.php#L131) · [nicepay-payment-gateway.php:313-359](../../nicepay-payment-gateway.php#L313) · [includes/class-nicepay-api.php:22-33](../../includes/class-nicepay-api.php#L22)

**Spec:** §3-4 — `MID(10)` is required on the auth request and is part of every SignData plaintext.

**Problem.** `NicePay_API::__construct()` sets `$this->mid = get_option( 'nicepay_live_mid', '' )` and `$this->merchant_key = get_option( 'nicepay_live_merchant_key', '' )` in live mode. `WC_Gateway_NicePay::is_available()` guards the WooCommerce path against empty credentials, but the shortcode path has no equivalent check anywhere: the template renders `<input type="hidden" name="MID" value="">` and `ajax_init_payment` happily computes `create_auth_sign_data( $edi_date, $amount )` over `$edi_date . '' . $amount . ''` and inserts a transaction row.

**Evidence.**

```php
// includes/class-nicepay-api.php:29-32
} else { $this->mid = get_option( 'nicepay_live_mid', '' ); $this->merchant_key = get_option( 'nicepay_live_merchant_key', '' ); }
// includes/class-nicepay-gateway.php:81-83 — the WooCommerce guard
if ( ! $this->api->get_mid() || ! $this->api->get_merchant_key() ) { return false; }
// templates/standalone-payment-form.php:16 / :131 — no credential check between
$api = new NicePay_API();
<input type="hidden" name="MID" value="<?php echo esc_attr( $api->get_mid() ); ?>">
// nicepay-payment-gateway.php:333-336 — no guard
$api = new NicePay_API(); … $sign_data = $api->create_auth_sign_data( $edi_date, $amount );
```

`nicepay-payment-gateway.php:154-155` seeds both live options as empty strings on activation.

**Impact.** A merchant who flips Mode to Live before entering their live credentials gets a WooCommerce checkout that correctly hides the gateway, and a shortcode payment button that looks fully functional, writes a `pending` DB row per click, and then fails inside the NICEPAY window with an opaque MID error. The asymmetry makes the misconfiguration much harder to diagnose than it should be.

**Recommendation.** Add the same credential guard to both shortcode surfaces. In `templates/standalone-payment-form.php`, after constructing `$api`, bail when `! $api->get_mid() || ! $api->get_merchant_key()` — render a "NicePay is not configured" message with a link to the settings page for `manage_options` users, and nothing for everyone else. In `ajax_init_payment`, return `wp_send_json_error()` on the same condition before creating any transaction row. Also add a persistent admin notice when `nicepay_mode` is `live` and either live credential is empty.

---

### Low

---

#### PROTOCOL-29 — `GoodsCl` is emitted twice in the WooCommerce payment form, producing a duplicated POST parameter

![low](https://img.shields.io/badge/severity-LOW-lightgrey) `correctness` · CONFIRMED · effort: trivial

**Files:** [templates/payment-form.php:52-63](../../templates/payment-form.php#L52) · [includes/class-nicepay-gateway.php:200-203](../../includes/class-nicepay-gateway.php#L200)

**Spec:** §4/5.2.3 — `GoodsCl` is a single-valued required CELLPHONE parameter.

**Problem.** The gateway puts `GoodsCl` into `$form_data`, the template renders every `$form_data` entry as a hidden input, and *then* the template adds a second independent `GoodsCl` input. When CELLPHONE is enabled the form serialises `GoodsCl=1&GoodsCl=1`.

**Evidence.**

```php
// templates/payment-form.php:52-54
<?php foreach ( $form_data as $key => $value ) : ?><input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php endforeach; ?>
// :61-63 — the second, independent source
<?php if ( in_array( 'CELLPHONE', $enabled_methods, true ) ) : ?><input type="hidden" name="GoodsCl" value="1"><?php endif; ?>
```

**Impact.** Currently harmless — both values are identical. It becomes a genuine defect the moment the two sources disagree, which is exactly what the PROTOCOL-16 fix would cause (`$form_data` carrying `0` while the hardcoded literal still says `1`). *Severity downgraded from medium:* there is no present failure, only latent breakage and a signal that nobody has diffed the rendered form against the parameter list.

**Recommendation.** Delete the literal block at payment-form.php:61-63 and keep `$form_data` as the single source of truth for every NICEPAY parameter. Add a unit test over the built `$form_data` array asserting no parameter name is emitted twice by the template.

---

#### PROTOCOL-30 — Card and bank code tables are incomplete, contain two wrong names, and are dead code no caller reaches

![low](https://img.shields.io/badge/severity-LOW-lightgrey) `spec-conformance` · CONFIRMED · effort: medium

**Files:** [includes/nicepay-functions.php:279-318](../../includes/nicepay-functions.php#L279) · [includes/nicepay-icons.php:19-75](../../includes/nicepay-icons.php#L19) · [admin/class-nicepay-transactions.php:80-92](../../admin/class-nicepay-transactions.php#L80)

**Spec:** §10 (card codes 11.1) and §11 (bank/securities codes 11.2, incl. v2.0.8 changes: 031 renamed iM Bank (Daegu), 271 Toss Securities).

**Problem.** Verified code-by-code against the digest:

| Table | Implemented | Spec total | Wrong entries |
|---|---|---|---|
| `nicepay_get_card_name()` | 21 | 39 | `'14' => 'Shinheup'` (→ Shinhyup / 신협); `'12' => 'NH'` (spec: NH Chaeum) |
| `nicepay_get_bank_name()` | 22 | ~74 | `'048' => 'Shinheup'` (same typo) |

Missing card codes (18): 09, 10, 24, **25 Visa, 26 Mastercard, 27 Diners, 28 AMEX, 29 JCB**, 31, 32, 33, **34 UnionPay**, 35, 36, **39 PAYCO Point, 40 KakaoMoney, 41 SSG Money, 42 Naver Point**. Missing bank codes include 001, 005, 008, 012, 026, **050 Mutual savings**, 051-065, 076, 077, 093-095, 099 and **all 26 securities codes 209-292** including the v2.0.8 addition **271 Toss Securities**. Correctly present: `'031' => 'iM Bank(Daegu)'` — the v2.0.8 rename *is* applied, and no invented codes exist in either table.

Both functions are dead: `grep -rn "nicepay_get_card_name\|nicepay_get_bank_name" --include='*.php' .` finds only their definitions and `tests/unit/NicePayFunctionsTest.php`.

**Impact.** Zero today — nothing calls the functions, and the transactions list does not even render `card_name` or `bank_name` as columns ([admin/class-nicepay-transactions.php:83-91](../../admin/class-nicepay-transactions.php#L83) defines nine columns, none of them a card or bank name), so no user ever sees these strings. *Severity downgraded from medium to low on that basis.* The tables are nonetheless exercised by the test suite, which lends them false authority, and the gaps bite exactly where they hurt the moment they are wired up: the six overseas brands are what non-Korean shoppers use, the simple-pay codes (39/40/41/42/44/46) are the most common Korean payment identities, and 저축은행 (050) plus the securities firms are frequent virtual-account issuers.

**Recommendation.** Complete both tables from spec §11.1/§11.2, fix `'Shinheup'` → `'Shinhyup'` in both places and `'12' => 'NH Chaeum'`, and make the values translatable so Korean/English/Chinese admins each see a sensible name. Then actually use them: render `nicepay_get_card_name( $item->card_code )` and `nicepay_get_bank_name( $item->bank_code )` in the transactions list and on the VBANK result page as the primary label, falling back to the PG-supplied string. Add brand marks for the eight highest-volume issuers plus the simple-pay wallets — `includes/nicepay-icons.php` currently provides six method glyphs and no card-brand marks.

---

#### PROTOCOL-31 — Approval response fields that merchants need are captured but never surfaced anywhere

![low](https://img.shields.io/badge/severity-LOW-lightgrey) `ux-clarity` · CONFIRMED · effort: medium

**Files:** [includes/class-nicepay-gateway.php:324-368](../../includes/class-nicepay-gateway.php#L324) · [includes/class-nicepay-return-handler.php:117-140](../../includes/class-nicepay-return-handler.php#L117) · [admin/class-nicepay-transactions.php:80-157](../../admin/class-nicepay-transactions.php#L80)

**Spec:** §7 — `AuthCode(30)`, `AuthDate(12)`, `CardCl`, `CardType`, `CcPartCl`, `ClickpayCl`, `AcquCardCode`/`AcquCardName`, `CardInterest`, `RcptType`/`RcptTID`/`RcptAuthCode`, `VbankExpTime(6)`, `CartData`.

**Problem.** The approval response is archived as JSON in `payment_data` and a handful of fields are promoted to columns (`card_code`/`name`/`no`/`quota`, `bank_code`/`name`, `vbank_num`, `vbank_exp_date`). Everything else is write-only. `AuthCode` is stored on the order meta but never displayed; `AuthDate`, `CardType` (personal/corporate/overseas), `CardCl` (credit/check), `AcquCardCode`/`AcquCardName` (the acquirer, which differs from the issuer and is what appears on settlement), `CardInterest`, the cash-receipt trio `RcptType`/`RcptTID`/`RcptAuthCode`, and `VbankExpTime` are never read anywhere. The transactions list shows nine columns and offers no detail view, no row expansion and no export.

**Evidence.** `includes/class-nicepay-gateway.php:325-355` promotes only `card_*`/`bank_*`/`vbank_*` into `$update_data`.

```php
// :366-368 — stored, never rendered
if ( ! empty( $result['AuthCode'] ) ) { $order->update_meta_data( '_nicepay_auth_code', $result['AuthCode'] ); }
```

`admin/class-nicepay-transactions.php:83-91` defines the nine columns (ID, TID, Order, Amount, Method, Status, Buyer, Date, Actions) with no detail affordance. `grep -rn "AuthDate\|CardType\|RcptType\|VbankExpTime\|AcquCard" --include='*.php' .` → zero plugin hits.

**Impact.** When a customer disputes a charge or asks for a cash receipt, the merchant has the data in the database but no way to see it without querying MySQL directly. The cash-receipt fields in particular are legally significant in Korea. The admin surface stops at a flat list, which is the single largest gap between "works" and "first-class" on the merchant side.

**Recommendation.** Add an expandable detail panel (or a dedicated screen) per transaction rendering the decoded `payment_data`: approval time, auth code, acquirer, card class and type, installment months, cash-receipt id, virtual-account expiry with time, and the full raw response behind a disclosure toggle for support. Show `AuthCode` and the acquirer on the WooCommerce order screen too. Add CSV export of the filtered list for settlement reconciliation.

---

#### PROTOCOL-32 — `VbankExpDate` is rendered to shoppers as a raw 8/12-digit string, with no copy affordance on the account number

![low](https://img.shields.io/badge/severity-LOW-lightgrey) `ux-clarity` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-return-handler.php:226-237](../../includes/class-nicepay-return-handler.php#L226) · [includes/class-nicepay-gateway.php:372-378](../../includes/class-nicepay-gateway.php#L372)

**Spec:** §7 VBANK extras — `VbankExpDate(8, yyyyMMdd)`, `VbankExpTime(6, HHmmss)`.

**Problem.** The deposit deadline — the single most action-critical value on the virtual-account success page — is echoed unformatted, so the shopper sees `20260822` (or `202608221530`). The companion `VbankExpTime` field is never read. The same raw string is interpolated into the WooCommerce order note. `VbankNum` is rendered as plain text with no copy button, even though the admin transactions screen has one for TIDs.

**Evidence.**

```php
// includes/class-nicepay-return-handler.php:234-237
<dt><?php esc_html_e( 'Deadline', 'nicepay-payment-gateway' ); ?></dt>
<dd><?php echo esc_html( $data['VbankExpDate'] ); ?></dd>
// :226-229 renders VbankNum as plain <dd> text
// includes/class-nicepay-gateway.php:377 passes $result['VbankExpDate'] raw into the order note
```

`grep -rn VbankExpTime --include='*.php' .` → zero hits.

**Impact.** The one deadline a customer must act on is displayed in a format they have to decode, and it may also be wrong by 9 hours (PROTOCOL-14). The account number they must transcribe into their banking app is not selectable-friendly and has no copy affordance. There is also no repetition of the deposit details in the order-confirmation email — they exist only on a page the shopper may never see again.

**User scenario.** A customer finishes checkout, sees "Deadline: 20260822", is unsure whether that is a deadline or a reference number, and hand-copies a 14-digit account number from an unselectable line of text.

**Recommendation.** Parse `VbankExpDate` (plus `VbankExpTime` when present) into a `DateTime` in Asia/Seoul and render with `wp_date()` in the site's format plus a relative hint ("by Sat 22 Aug, 15:30 KST — 2 days left"). Add a copy button to the account number and to the exact amount, since both must be entered precisely for the deposit to match. Repeat the same block in the order-confirmation email.

---

#### PROTOCOL-33 — Documentation contradicts the implementation on charset, deposit notification, VBANK refunds and `CcPartCl`

![low](https://img.shields.io/badge/severity-LOW-lightgrey) `dx` · CONFIRMED · effort: small

**Files:** [docs/API-REFERENCE.md:34-36](../API-REFERENCE.md#L34), [:80](../API-REFERENCE.md#L80), [:253-255](../API-REFERENCE.md#L253) · [docs/USER-GUIDE.md:117](../USER-GUIDE.md#L117), [:493](../USER-GUIDE.md#L493), [:497](../USER-GUIDE.md#L497), [:503](../USER-GUIDE.md#L503) · [README.md:186-192](../../README.md#L186) · [docs/CONFIGURATION.md:494](../CONFIGURATION.md#L494)

**Problem.** Four contradictions verified against the code:

| # | Doc claim | Reality |
|---|---|---|
| 1 | API-REFERENCE.md:34-36 — Approval and Cancel phases use EUC-KR; :80 — `CharSet` is "`euc-kr` (default) / `utf-8`" | Plugin defaults to utf-8 and never transcodes at all (PROTOCOL-06) |
| 2 | README.md:186-192 lists inbound deposit-notification source IPs under firewall config; USER-GUIDE.md:493 "NicePay confirms the deposit and notifies your server" | There is no notification endpoint (PROTOCOL-04) |
| 3 | USER-GUIDE.md:497 "Refund: Requires bank account details"; API-REFERENCE.md:253-255 documents the three refund-account fields | The code cannot send them (PROTOCOL-10) |
| 4 | CONFIGURATION.md:494 "Check `CcPartCl` response field to verify if partial cancel is supported" | The plugin never reads it (PROTOCOL-20) |

More broadly, the API reference tables mix "the NICEPAY protocol supports this" with "this plugin does this" in one undifferentiated list.

**Evidence.** Verified by reading the files: `docs/API-REFERENCE.md:34-36` table rows `| Approval | Server → NicePay | EUC-KR | application/x-www-form-urlencoded |` and the matching Cancel row; `:80` `` | `CharSet` | 10 | `euc-kr` (default) / `utf-8` | ``; `:253-255` the three `RefundAcct*` rows. `README.md:186-192` the inbound-IP table. `docs/USER-GUIDE.md:503` "Product type: Automatically set to 'physical goods' (GoodsCl=1)".

**Impact.** A merchant configures their firewall for inbound notifications that never arrive, plans VBANK refunds the plugin cannot execute, and — most damagingly — switches CharSet to EUC-KR because the plugin's own reference calls that the NICEPAY default, breaking every payment. Documentation that describes the protocol instead of the product actively misleads. Partially offset: `docs/USER-GUIDE.md:117` *does* correctly advise "Use UTF-8 unless your site specifically requires EUC-KR", so the two documents contradict each other as well.

**Recommendation.** Split every parameter table into "NICEPAY protocol" and "Supported by this plugin" columns and mark each row Sent / Received-and-stored / Not implemented. Correct the four contradictions outright. Move the inbound-notification firewall section behind a "Planned" heading (or delete it) until the endpoint ships, and change the USER-GUIDE's VBANK lifecycle description to state plainly that deposit confirmation is currently manual. Add a "Known limitations" section to README.md covering installments, escrow, tax fields, VBANK refunds and deposit notification so merchants can evaluate honestly before installing.

---

#### PROTOCOL-34 — `generate_moid()` collision window plus a non-unique `moid` column can mis-attribute a standalone payment

![low](https://img.shields.io/badge/severity-LOW-lightgrey) `correctness` · CONFIRMED · effort: small

**Files:** [includes/class-nicepay-api.php:66-68](../../includes/class-nicepay-api.php#L66) · [nicepay-payment-gateway.php:115](../../nicepay-payment-gateway.php#L115), [:142](../../nicepay-payment-gateway.php#L142), [:335](../../nicepay-payment-gateway.php#L335) · [includes/nicepay-functions.php:130-134](../../includes/nicepay-functions.php#L130)

**Spec:** §4 — `Moid(64)` merchant order id, unique per transaction.

**Problem.** `generate_moid( $prefix )` returns `$prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 )` — one second of resolution plus 9,000 random values. The standalone AJAX path calls it with a constant prefix `'SP'` for every visitor, so two shoppers who click Pay in the same second collide with probability 1/9000. The `moid` column is declared `varchar(64) NOT NULL DEFAULT ''` with a plain `KEY idx_moid (moid)` — no UNIQUE constraint — and `nicepay_get_transaction_by_moid()` uses `$wpdb->get_row()`, which silently returns the first match.

**Evidence.**

```php
// includes/class-nicepay-api.php:66-68
public function generate_moid( $prefix = 'WC' ) { return $prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 ); }
// nicepay-payment-gateway.php:335 — constant prefix for all standalone payments
$moid = $api->generate_moid( 'SP' );
// nicepay-payment-gateway.php:115 / :142 — indexed, not unique
moid varchar(64) NOT NULL DEFAULT '',
KEY idx_moid (moid)
// includes/nicepay-functions.php:133 — returns the first match silently
return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE moid = %s", $moid ) );
```

**Impact.** On a collision, both shoppers' auth returns resolve to the same (first-inserted) transaction row: one payment is recorded, the other is silently attributed to the wrong buyer's row and its own row stays `pending` forever. Low probability per pair, but it scales with the square of concurrent standalone traffic and there is no detection. The WooCommerce path is largely protected because the prefix embeds the order id (`'WC' . $order->get_id()`), so cross-order collisions are impossible — only same-order double-renders within one second can collide.

**Recommendation.** Use a collision-proof suffix: `$prefix . '_' . $kst_now->format( 'YmdHis' ) . '_' . bin2hex( random_bytes( 4 ) )` (8 hex chars = 4 billion values), and add `UNIQUE KEY uniq_moid (moid)` to the table in a versioned `dbDelta` upgrade so a collision fails loudly at insert time instead of corrupting attribution. Have `nicepay_save_transaction()` retry with a fresh `Moid` on a duplicate-key error.

---

## 3. Spec features not implemented

Distinct from defects: these are parts of the NICEPAY manual the plugin simply does not cover. Ordered by merchant impact.

| Feature | Spec ref | Status | Finding |
|---|---|---|---|
| Virtual-account deposit notification (입금통보) endpoint | §2 | **Absent** — VBANK orders never leave on-hold | PROTOCOL-04 |
| VBANK refund after deposit (`RefundAcctNo`/`RefundBankCd`/`RefundAcctNm`) | §9 | **Absent** — no UI, no params | PROTOCOL-10 |
| Card installments (`SelectQuota`, `SelectCardCode`, `ShopInterest`, `QuotaInterest`) | §4.5.2.1 | **Absent** — MID defaults only | PROTOCOL-19 |
| `OTID` reuse for 2nd+ mobile partial cancels | §9 | **Absent** — never captured | PROTOCOL-11 |
| `CcPartCl` / `ClickpayCl` partial-cancel guards | §7, §12.2 | **Absent** — never read | PROTOCOL-20 |
| Tax breakdown (`SupplyAmt`/`GoodsVat`/`ServiceAmt`/`TaxFreeAmt`) | §4, §9 | **Absent** | PROTOCOL-27 |
| Escrow (`TransType=1`) | §4 | **Absent** | PROTOCOL-27 |
| `ReqReserved` merchant round-trip | §4, §5 | **Read but discarded** | PROTOCOL-27, PROTOCOL-01 |
| `MallReserved` passthrough | §6, §9 | **Absent** | PROTOCOL-27 |
| Payment-window branding (`SkinType`, `LogoImage`) | §4 | **Absent** | PROTOCOL-27 |
| Window presentation (`NPDisableScroll`, `ConnWithIframe`) | §4 | **Absent** | PROTOCOL-27 |
| Webview / ISP app returns (`WapUrl`, `IspCancelUrl`) | §4 | **Absent** | PROTOCOL-27 |
| `EdiType=KV` response format | §6 | **Absent** — JSON only | PROTOCOL-26, PROTOCOL-27 |
| EUC-KR wire encoding | §1, §6 | **Declared but not implemented** | PROTOCOL-06 |
| Connect-timeout 5 s | §2 | **Absent** — read timeout only | PROTOCOL-13 |
| Reserved purchase (`AcquReqDate`), `Period`, `CupDepositAmt`, `UserCI` | §4 | **Absent** | PROTOCOL-27 |
| Full card / bank / securities code tables | §10, §11 | **Partial (21/39, 22/~74) and unused** | PROTOCOL-30 |
| Result-code → localized message mapping | §7-9 | **Absent** — raw PG strings shown | PROTOCOL-21 |
| `ErrorCD` / `ErrorMsg` handling on cancel and net cancel | §8, §9 | **Absent** — never read | PROTOCOL-03, PROTOCOL-21 |

---

## Appendix — The eight signature rules vs. the implementation

All eight are **correct**. Four spec-published digests were reproduced byte-for-byte from the plugin's own plaintexts.

| # | Step | Spec plaintext (§3) | Plugin implementation | Line | Verdict |
|---|---|---|---|---|---|
| 1 | Auth request `SignData` | `EdiDate + MID + Amt + MerchantKey` | `$edi_date . $this->mid . $amt . $this->merchant_key` | [api.php:74](../../includes/class-nicepay-api.php#L74) | **PASS** — reproduces `475979a5…46bd` |
| 2 | Auth response `Signature` | `AuthToken + MID + Amt + MerchantKey` | `$auth_token . $this->mid . $amt . $this->merchant_key` | [api.php:84](../../includes/class-nicepay-api.php#L84) | **PASS** — reproduces `cc94db19…55fe` |
| 3 | Approval request `SignData` | `AuthToken + MID + Amt + EdiDate + MerchantKey` | `$auth_token . $this->mid . $amt . $edi_date . $this->merchant_key` | [api.php:91](../../includes/class-nicepay-api.php#L91) | **PASS** — reproduces `4916540b…0204` |
| 4 | Approval response `Signature` | `TID + MID + Amt + MerchantKey` | `$tid . $this->mid . $amt . $this->merchant_key` | [api.php:103](../../includes/class-nicepay-api.php#L103) | **PASS** — reproduces `9439b21e…4efd` |
| 5 | Net-cancel request `SignData` | `AuthToken + MID + Amt + EdiDate + MerchantKey` (= rule 3) | `create_approval_sign_data( $auth_data['AuthToken'], $auth_data['Amt'], $edi_date )` | [api.php:295](../../includes/class-nicepay-api.php#L295) | **PASS** — correctly reuses rule 3 |
| 6 | Net-cancel response `Signature` | `TID + MID + CancelAmt + MerchantKey` | `verify_cancel_signature()` exists with the right plaintext… | [api.php:121](../../includes/class-nicepay-api.php#L121) | **Rule PASS / never invoked** — see PROTOCOL-03 |
| 7 | Cancel request `SignData` | `MID + CancelAmt + EdiDate + MerchantKey` | `$this->mid . $cancel_amt . $edi_date . $this->merchant_key` | [api.php:110](../../includes/class-nicepay-api.php#L110) | **PASS** |
| 8 | Cancel response `Signature` | `TID + MID + CancelAmt + MerchantKey` | `$tid . $this->mid . $cancel_amt . $this->merchant_key` | [api.php:121](../../includes/class-nicepay-api.php#L121) | **PASS (rule)** — but conditionally skipped, see PROTOCOL-08 |

**Open protocol question (untested):** spec §3's note records that the signature worked examples use the *unpadded* `Amt` (`1004`) while the approval response `Amt` field is documented zero-padded (`000000001004`). [tests/unit/NicePaySignatureIntegrationTest.php](../../tests/unit/NicePaySignatureIntegrationTest.php) asserts the documented digests but does not cover the padded case, so which form `verify_approval_signature()` must receive in production is not pinned down by any test. Add a padded-`Amt` case to that suite.

All verifications use `hash_equals()` ([api.php:86](../../includes/class-nicepay-api.php#L86), [:105](../../includes/class-nicepay-api.php#L105), [:123](../../includes/class-nicepay-api.php#L123)) for timing safety.
