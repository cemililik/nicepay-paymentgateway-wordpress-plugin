# The Path to First-Class

> [!WARNING]
> **Frozen historical snapshot.** This review describes baseline commit `5855db1` (2026-08-19), before the 2.0 remediation work. Its line references and present-tense security claims do not describe the current code. Use `docs/analysis/pr-review2/` and current tests for release decisions.

This is the forward-looking document of the review. It does not re-litigate every defect — it answers a
narrower question: *what stands between this plugin and the stated goal of "a perfect, first-class experience
— excellent in UI/UX, in ease of use and clarity, and in functionality"?* Forty findings are indexed here,
drawn from the three cross-cutting review passes (unread-surface audit, end-to-end journey critique, and the
excellence critique). Two are critical data-integrity defects that can lose a merchant's money with no trace;
eight are high-severity dead-ends that a real store hits in its first week; eleven are missing capabilities
that separate "integrates with NICEPAY" from "operable by a Korean business." The document ends with an
opinionated three-horizon roadmap and a ranked list of the ~20% of work that produces most of the quality jump.

> **Confidence note — read this first.** Every finding below carries the verdict `PLAUSIBLE`, not `CONFIRMED`.
> No PHP runtime, no `vendor/` directory and no WordPress installation were available in the review
> environment, so nothing was executed: all claims are derived from reading the actual source, plus documented
> WordPress/WooCommerce core behaviour. Each finding is marked **(needs confirmation)** and states in its
> evidence what was read versus what was inferred. Triage accordingly — the reasoning is auditable, the
> runtime behaviour is not yet observed.

---

## 1. Where this plugin stands today

This is a competent, conventionally-written WordPress integration of the NICEPAY 인증결제 flow that has not yet
been operated. The happy path is built and it is built correctly: authentication is signed, the signature on
the return is verified, approval is called, the response signature is checked, refunds reach the cancel API,
and the whole thing is wrapped in an admin UI that looks considerably more finished than most gateway plugins
on the WordPress.org repository. If you install it in test mode and pay 1,000 KRW with a card, it works, and it
looks good doing it.

Everything that is missing is on the *unhappy* paths and on the *second day*. The plugin has no memory of
partial state — the PG's transaction id is discarded on every failure branch ([EXCELLENCE-01](#excellence-01)),
a refund whose outcome is unknown leaves no footprint at all ([EXCELLENCE-02](#excellence-02)), and no reversal
attempt is ever recorded. It has no lifecycle — a virtual account that is issued is never expired, never
resent, never reconciled ([EXCELLENCE-06](#excellence-06), [EXCELLENCE-32](#excellence-32)). It has no
self-knowledge — there is no readiness check, no test payment, no admin notice, and the diagnostic logger is
compiled out on every production site ([EXCELLENCE-30](#excellence-30)). It has no upgrade story — the schema
routine runs only on activation, so an in-place update can produce a 100% silent payment outage
([EXCELLENCE-09](#excellence-09)). And it does not yet run on the default WooCommerce checkout: a Blocks store
sees *no payment methods at all* while the admin reports the gateway healthy ([EXCELLENCE-10](#excellence-10)).

The gap, in one sentence: **this is a payment integration, not yet a payment product.** It records payments but
cannot total them, reverse them safely, prove itself working, survive its own upgrade, or tell the merchant
anything when it fails. Those are not polish items. They are the difference between software a merchant tries
and software a merchant trusts with live money — and closing them is roughly one focused release, because the
architecture underneath is sound and the expensive parts (signing, verification, the admin shell, the shortcode
store, the rewrite endpoint) are already built.

---

## 2. What this codebase does well

These are drawn from the review evidence itself — each one is a thing a reviewer went looking for and found
already correct. They are the reason the roadmap below is measured in weeks and not months.

| Strength | Evidence |
|---|---|
| **HPOS-safe throughout, with no N+1.** The transactions list prints `$item->wc_order_id` directly instead of calling `wc_get_order()` per row, and order-meta access is uniformly CRUD-API based. | [admin/class-nicepay-transactions.php:16](../../admin/class-nicepay-transactions.php#L16) (verified while auditing [EXCELLENCE-07](#excellence-07)) |
| **The AJAX contract is almost exactly right.** A full emitted-vs-consumed parameter matrix across all four endpoints found three matching field-for-field; the fourth diverges only by *omission*, never by name mismatch. | [EXCELLENCE-12](#excellence-12) |
| **Escaping discipline is present at the sinks.** The escaping defects found are *double*-escaping and *wrong-helper* escaping — not missing escaping. Every sink audited applies `esc_html()`/`esc_attr()`. | [EXCELLENCE-11](#excellence-11), [EXCELLENCE-13](#excellence-13) |
| **Markup and CSS correspond almost perfectly in both directions.** A reverse audit of 32 emitted class names against both stylesheets found exactly one orphan. | [EXCELLENCE-29](#excellence-29) |
| **The correct fresh-signature pattern already exists in the codebase.** The standalone shortcode mints `EdiDate`/`SignData` per click via AJAX — the fix for the receipt page is to copy a pattern the plugin already implements. | [nicepay-payment-gateway.php:334](../../nicepay-payment-gateway.php#L334) |
| **Blocks support is unusually cheap here.** `has_fields = false` and `process_payment()` already returns `{'result':'success','redirect':…}` — precisely the shape the Blocks checkout consumes. No server-side change is needed. | [includes/class-nicepay-gateway.php:91](../../includes/class-nicepay-gateway.php#L91) |
| **Outbound URLs are allowlisted and cancel responses are signature-verified.** `validate_nicepay_url()` gates the approval and net-cancel URLs, and the cancel response's signature is checked before it is trusted. | [includes/class-nicepay-api.php:140](../../includes/class-nicepay-api.php#L140), [:387](../../includes/class-nicepay-api.php#L387) |
| **A health probe already exists, unused.** `NicePay_Return_Handler::process()` returns 405 for non-POST — a readiness panel can use that today to prove the rewrite endpoint is alive. | [includes/class-nicepay-return-handler.php:26](../../includes/class-nicepay-return-handler.php#L26) |

---

## 3. Journey dead-ends

Eight end-to-end journeys were walked. Each of the following is a point at which the journey *stops* — the
buyer or the merchant reaches a state from which the product offers no next action.

<a id="excellence-01"></a>
### EXCELLENCE-01 · `CRITICAL` · The money is gone and the receipt is not · *(needs confirmation)*

[includes/class-nicepay-gateway.php:321](../../includes/class-nicepay-gateway.php#L321) ·
[includes/class-nicepay-return-handler.php:114](../../includes/class-nicepay-return-handler.php#L114) ·
[admin/class-nicepay-transactions.php:143](../../admin/class-nicepay-transactions.php#L143)

**The story.** A buyer authenticates a card. The plugin calls approval. The approval POST times out at 29
seconds — but NICEPAY actually captured the money. The fire-and-forget net cancel also fails. The plugin
writes `status => 'failed'` and stops.

**The dead-end.** Both handlers read the PG's transaction id into a local at the top of the request:

```php
$tx_tid = isset( $_POST['TxTid'] ) ? sanitize_text_field( wp_unslash( $_POST['TxTid'] ) ) : '';
```

…and only ever persist it at `gateway.php:321` / `handler.php:114`:

```php
$tid = isset( $result['TID'] ) ? $result['TID'] : $tx_tid;
```

which sits **after** every early-return failure branch (auth-code ≠ `0000` at 249-272, signature failure at
275-289, approval `WP_Error` at 305-317). Those branches write only `status`, `result_code`, `result_msg`.
No `tid`. No `auth_token`. No `_nicepay_tid` order meta. Nothing is written *before* `request_approval()`
either. The one identifier NICEPAY recognises is discarded.

The merchant now has no route to reverse the charge. The Cancel button is gated on
`in_array( $item->status, array( 'paid','waiting' ), true ) && $item->tid` — status is `failed`, tid is empty.
`process_refund()` bails with `Transaction ID not found.` The sole surviving copy of the TID is inside
`nicepay_log()`, which returns immediately unless `WP_DEBUG` is true. Recovery means logging into the NICEPAY
merchant console and matching by timestamp and amount.

**The fix.** Persist the PG identifiers the moment the auth signature verifies, *before* approval is called,
and introduce an `approving` status that the Cancel gate accepts.

```php
// includes/class-nicepay-gateway.php — insert immediately after the signature check at line 289
nicepay_update_transaction( $transaction->id, array(
    'tid'            => $tx_tid,
    'auth_token'     => $auth_token,
    'payment_method' => $pay_method,
    'status'         => 'approving',
) );
$order->update_meta_data( '_nicepay_tid', $tx_tid );
$order->save();
```

Add a `net_cancel_result varchar(20)` column and have `request_net_cancel()` return its `ResultCode`, so the
failure branches can record `2001` / the real code / `unknown` instead of nothing.

*Spec §5: `TxTid(30)` is returned only on auth success and approval MUST be requested with this TID. §9 cancel
requires TID.*

---

<a id="excellence-02"></a>
### EXCELLENCE-02 · `CRITICAL` · A slow refund makes WooCommerce delete its own refund record · *(needs confirmation)*

[includes/class-nicepay-gateway.php:445](../../includes/class-nicepay-gateway.php#L445) ·
[includes/class-nicepay-api.php:360](../../includes/class-nicepay-api.php#L360)

**The story.** A merchant refunds the unshipped half of a partially-shipped order. NICEPAY's network is slow.
The cancel actually executes. WooCommerce shows an error.

**The dead-end.** `process_refund()` forwards failures and writes nothing:

```php
$result = $this->api->request_cancel( $tid, $cancel_amt, $reason, $moid, $is_partial );
if ( is_wp_error( $result ) ) {
    return $result;
}
```

`request_cancel()` returns a `WP_Error` in three situations that all occur **after** the cancel may have
executed at the PG: transport timeout (`'timeout' => 30`), unparseable body, and cancel-response signature
mismatch. The transaction row is touched only inside the success branch at gateway.php:462-467. WooCommerce
core then destroys its own record — `wc_create_refund()` responds to a failed `wc_refund_payment()` with
`$refund->delete( true )`.

Result: no refund line, `get_total_refunded()` still 0, the Refund button still armed — so the natural next
action is to click it again and **reverse the money twice**. The plugin's own row still reads `paid` at the
full amount. Neither ledger agrees with NICEPAY, and neither shows a trace, because
`nicepay_log( 'Cancel request failed', … )` is a no-op without `WP_DEBUG`.

**The fix.** Never let a cancel attempt leave no footprint. On any non-success return, write an audit record
and an order note:

```php
nicepay_update_transaction( $transaction->id, array(
    'result_code' => 'CANCEL_UNKNOWN',
    'result_msg'  => $result->get_error_message(),
) );
$order->add_order_note( sprintf(
    __( 'NicePay cancel attempted for %1$s but the outcome is UNKNOWN (%2$s). Verify in the NicePay console before retrying — the money may already be returned.', 'nicepay-payment-gateway' ),
    $cancel_amt, $result->get_error_message()
) );
```

And distinguish the three causes: a **signature-verification failure on a cancel response means the cancel
almost certainly succeeded** — the PG answered. Reporting that to WooCommerce as a plain failure is the single
most dangerous line in the refund path.

*Spec §9: response carries `RemainAmt(12)` and `OTID(30)` for 2nd+ partial cancels — neither is read anywhere
(`grep -rn "RemainAmt"` → 0 hits).*

---

<a id="excellence-04"></a>
### EXCELLENCE-04 · `HIGH` · Every shopper who changes their mind emails the merchant "Order has failed" · *(needs confirmation)*

[includes/class-nicepay-gateway.php:258](../../includes/class-nicepay-gateway.php#L258)

**The story.** A buyer opens the NICEPAY window on their phone and backs out. On mobile this is the majority of
non-completions.

**The dead-end.** Per spec §5 and §12 the mobile flow POSTs the auth outcome to `ReturnURL` for **all**
outcomes, including a plain cancel. `handle_return()` collapses every non-`0000` `AuthResultCode` into:

```php
$order->update_status( 'failed', sprintf( __( 'NicePay authentication failed. Code: %1$s, Message: %2$s' ), $auth_result_code, $auth_result_msg ) );
```

The order is `pending` at that point, so this is exactly the `pending → failed` transition on which WooCommerce
core fires `WC_Email_Failed_Order`. Every abandonment becomes an admin alert email, a permanently `failed`
order in the ledger, and a failed-order data point in Analytics. Within days the merchant is trained to ignore
the one alert that matters when a genuine decline occurs. And because this branch runs *before* the signature
check, an unauthenticated POST carrying only `Moid` and any non-zero `AuthResultCode` can email the merchant on
demand.

**The fix.** Move the signature check first, then split the outcome. Treat shopper-cancellation and
window-timeout codes as non-terminal: leave the order `pending` (WooCommerce keeps it payable), write
`status => 'cancelled_by_user'` on the transaction row, add an order note instead of a status transition, and
redirect to `$order->get_checkout_payment_url()` rather than `wc_get_checkout_url()` so the same order can be
retried without creating a duplicate. Reserve `failed` for genuine declines and merchant-side errors.

---

<a id="excellence-05"></a>
### EXCELLENCE-05 · `HIGH` · The signature is baked into the page and goes stale with it · *(needs confirmation)*

[includes/class-nicepay-gateway.php:122](../../includes/class-nicepay-gateway.php#L122) ·
[:146](../../includes/class-nicepay-gateway.php#L146) ·
[:197](../../includes/class-nicepay-gateway.php#L197) ·
[templates/standalone-payment-form.php:145](../../templates/standalone-payment-form.php#L145)

**The story.** A Korean buyer on mobile picks 가상계좌, gets interrupted mid-checkout, and returns to the open
tab hours later.

**The dead-end.** `generate_payment_form()` mints every time-sensitive value once, at HTML render time:

```php
$edi_date  = $this->api->generate_edi_date();               // line 122
$sign_data = $this->api->create_auth_sign_data( $edi_date, $amount );  // line 146
$form_data['VbankExpDate'] = $this->api->get_vbank_exp_date();         // line 197
```

`get_vbank_exp_date()` is `date( 'YmdHi', strtotime( '+' . $days . ' days' ) )` — measured from the instant the
page rendered, not from the instant the buyer pays. All three sit in hidden inputs for the lifetime of the tab.
No `nocache_headers()` anywhere in the plugin (`grep` → 0 hits), no TTL, no staleness check.

For VBANK the harm is concrete: with the default 3-day setting, a buyer returning on day 3 is issued a virtual
account whose deposit window is already expired or expires within minutes — and because there is no
deposit-notification endpoint and no expiry sweep, that order is then stuck forever with stock reserved. The
standalone flow is half-fixed: it mints `EdiDate`/`SignData` per click, but still bakes `VbankExpDate` into the
rendered HTML, so on a cached page the deposit window is measured from cache-fill time.

**The fix.** Move the receipt page to the mint-on-click pattern the shortcode already uses: render empty
`EdiDate`/`SignData`/`VbankExpDate` inputs and fetch fresh values from a nonce-protected AJAX endpoint bound to
the order id (the server re-derives the amount from `$order->get_total()`, which also closes the
client-supplied-amount hole). Compute the expiry in KST at mint time:

```php
gmdate( 'YmdHi', strtotime( '+' . $days . ' days', current_time( 'timestamp', true ) + 9 * HOUR_IN_SECONDS ) )
```

Add `nocache_headers()` to the receipt page and a JS guard that re-mints if the page has been open more than a
few minutes.

*Spec §5.2.2: `VbankExpDate` is REQUIRED for VBANK. §4: `EdiDate(30, YYYYMMDDHHMISS)`.*

---

<a id="excellence-06"></a>
### EXCELLENCE-06 · `HIGH` · "I deposited yesterday" has no answer · *(needs confirmation)*

[admin/class-nicepay-transactions.php:143](../../admin/class-nicepay-transactions.php#L143) ·
[:197](../../admin/class-nicepay-transactions.php#L197) ·
[includes/class-nicepay-gateway.php:359](../../includes/class-nicepay-gateway.php#L359)

**The story.** A buyer transfers the money to their virtual account and phones to say so.

**The dead-end.** VBANK approval sets the transaction to `waiting` and the order to `on-hold`. From that state
the Transactions screen offers exactly one action — Cancel — and it is a **full** cancel:

```php
// admin/class-nicepay-transactions.php:208 — $partial is left at its false default
$result = $this->api->request_cancel( $tid, $cancel_amount, $reason, $transaction->moid );
// …then, line 228:
$order->update_status( 'cancelled', … );
```

WooCommerce core hooks `wc_maybe_increase_stock_levels` to `woocommerce_order_status_cancelled`, and stock was
reduced when the order went `on-hold`, so the items are restocked. There is no "mark as deposited", no
"re-check status at NicePay", and no path from `waiting` to `paid`. The merchant's only in-plugin action is
the one that voids the account. Their workaround — setting the order to `processing` by hand — leaves the
transactions row at `waiting` forever, and nothing in the plugin ever compares order status to transaction
status in either direction (`grep -rn "woocommerce_order_status_"` → 0 hits).

**The fix.** Add two actions to `waiting` rows: **Mark as deposited** (sets `paid`, writes an order note naming
the admin, calls `$order->payment_complete( $transaction->tid )`) and **Re-check at NicePay**. Relabel Cancel
on a `waiting` VBANK row to **Close virtual account** and warn in the modal that it cancels the order and
restocks. Add a reconciliation flag for rows whose status disagrees with the linked order's status.

---

<a id="excellence-07"></a>
### EXCELLENCE-07 · `HIGH` · The Transactions screen is a full table scan on the default view · *(needs confirmation)*

[includes/nicepay-functions.php:210](../../includes/nicepay-functions.php#L210) ·
[nicepay-payment-gateway.php:139](../../nicepay-payment-gateway.php#L139)

**The story.** An HPOS store with 200k orders opens NicePay → Transactions.

**The dead-end.** The list query is

```sql
SELECT * FROM {$table} WHERE 1=1 ORDER BY created_at DESC LIMIT %d OFFSET %d
```

preceded by `SELECT COUNT(*) FROM {$table} WHERE 1=1`. The schema declares five secondary indexes —
`idx_tid`, `idx_order_id`, `idx_wc_order_id`, `idx_moid`, `idx_status` — and **none on `created_at`**, which is
the default and only sort column. `SELECT *` drags `payment_data longtext` (the entire approval JSON,
including `CartData(4000)`) through the filesort.

Row count is not bounded by order count: a fresh `pending` row is inserted on every receipt-page render *and*
every shortcode click, and nothing ever reaps them (`grep -rn "wp_schedule_event|wp_next_scheduled"` → 0 hits).
400k+ rows is realistic. Date filters hit the same missing index; search runs four leading-wildcard `LIKE`s;
deep pagination uses `OFFSET`, so page 500 scans and discards 10,000 rows.

**The fix.** One migration:

```sql
ALTER TABLE {$table}
  ADD KEY idx_created_at (created_at),
  ADD KEY idx_status_created (status, created_at),
  DROP KEY idx_order_id,                       -- order_id duplicates moid and is never filtered on
  ADD COLUMN mode varchar(4) NOT NULL DEFAULT '',
  ADD COLUMN currency varchar(3) NOT NULL DEFAULT '';
```

Replace `SELECT *` with an explicit column list excluding `payment_data`. Cache the unfiltered count in a
short-lived transient. Add a daily cron deleting `pending` rows older than 24 hours with no `tid`.

---

<a id="excellence-08"></a>
### EXCELLENCE-08 · `HIGH` · A TRY store is offered five KRW-only Korean domestic rails · *(needs confirmation)*

[includes/class-nicepay-api.php:450](../../includes/class-nicepay-api.php#L450) ·
[includes/class-nicepay-gateway.php:76](../../includes/class-nicepay-gateway.php#L76)

`get_available_methods()` returns all six methods unconditionally, with no currency parameter and no filter.
`is_available()` checks only `enabled`, MID and merchant key — it contains no currency reference at all — and
`generate_payment_form()` forwards `'CurrencyCode' => $order->get_currency()` verbatim. BANK (실시간 계좌이체),
VBANK (가상계좌), CELLPHONE (휴대폰 결제), SSG_BANK and GIFT_CULT exist only in KRW; the spec permits
`CurrencyCode` of KRW or USD only.

So a Turkish store on TRY installs the plugin, activation seeds CARD/BANK/VBANK/CELLPHONE as enabled, and
checkout offers "Virtual Account" and "Mobile Payment" to Turkish buyers — each producing an unsupported
currency plus a method the MID cannot process, surfacing as a raw Korean PG error.

**The fix.** Give `get_available_methods()` a `$currency` parameter (CARD only for USD, full set for KRW, empty
otherwise). Add a currency guard to `is_available()` paired with `needs_setup()` returning true, so WooCommerce
shows a "Set up" state instead of silently hiding the gateway:

```php
if ( ! in_array( get_woocommerce_currency(), array( 'KRW', 'USD' ), true ) ) {
    return false;
}
```

Render an inline `notice-error` on the Payment Methods tab naming the store's actual currency.

*Spec §4: `CurrencyCode(3)` — KRW default / USD. §12: available pay methods depend on MID configuration.*

---

<a id="excellence-09"></a>
### EXCELLENCE-09 · `HIGH` · An in-place upgrade runs no migration, and the failure is silent and total · *(needs confirmation)*

[nicepay-payment-gateway.php:479](../../nicepay-payment-gateway.php#L479) ·
[:161](../../nicepay-payment-gateway.php#L161) ·
[includes/nicepay-functions.php:81](../../includes/nicepay-functions.php#L81)

`create_tables()` and `set_default_options()` are reachable **only** from `activate()`, which is registered only
via `register_activation_hook`. WordPress does not fire that hook on an in-place update — not through the
built-in updater, not through re-uploading the ZIP, not through `git pull`. And there is no version gate in
either direction: `add_option( 'nicepay_db_version', NICEPAY_VERSION )` is never read anywhere (`grep` returns
that single line plus one docs row), and `add_option` cannot update an existing value even on reactivation.

Meanwhile `nicepay_save_transaction()` hands a fixed 24-key array straight to `$wpdb->insert()` with no column
whitelist. If the live table lacks any of them, MySQL errors and `insert()` returns `false` — and then:

- WooCommerce: `$tx_id === false` transitions the brand-new order to `failed` and renders a dead-end message.
- Shortcode: `ajax_init_payment()` returns "Payment initialization failed."

**100% of payments fail before a single byte reaches NICEPAY**, and the only diagnostic is a `nicepay_log()`
call that is inert without `WP_DEBUG`. There is no `admin_notices` hook anywhere in the plugin, so there is no
channel through which the merchant could be told.

**The fix.**

```php
// on admin_init (and plugins_loaded for front-end safety)
$stored = get_option( 'nicepay_db_version' );
if ( $stored !== NICEPAY_VERSION ) {
    $this->create_tables();
    $this->register_endpoints();
    flush_rewrite_rules();
    update_option( 'nicepay_db_version', NICEPAY_VERSION );
}
```

Change the DDL from `CREATE TABLE IF NOT EXISTS` to plain `CREATE TABLE` so `dbDelta`'s
`|CREATE TABLE ([^ ]*)|` parser can actually capture the table name and diff columns. Intersect `$data` against
a known column list before insert. Log insert failures unconditionally via `wc_get_logger()->error()` and raise
a dismissible admin notice naming `$wpdb->last_error`.

---

<a id="excellence-10"></a>
### EXCELLENCE-10 · `HIGH` · A Blocks checkout has no payment methods at all, and the admin says everything is fine · *(needs confirmation)*

[nicepay-payment-gateway.php:180](../../nicepay-payment-gateway.php#L180) ·
[:186](../../nicepay-payment-gateway.php#L186) ·
[includes/class-nicepay-gateway.php:76](../../includes/class-nicepay-gateway.php#L76)

The plugin registers only a classic `WC_Payment_Gateway`. WooCommerce Blocks — the default checkout since WC
8.3 — renders only methods registered against its own `PaymentMethodRegistry` with a matching client-side
`registerPaymentMethod()`. The classic list is not consulted.

Trace what each party sees. `is_available()` returns **true** (enabled by default plus seeded sandbox
credentials), so WooCommerce → Payments shows NicePay enabled with no warning, and the gateway implements no
`needs_setup()`. On the storefront, `enqueue_scripts()` still fires because `is_checkout()` is true for the
Checkout block page, so the Korean CDN script `https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js`
loads on every blocks checkout for a gateway that cannot be selected.

For a Korea-only store where NicePay is the only gateway — the plugin's primary audience — the checkout renders
**"There are no payment methods available"** and no order can be placed. The store is not degraded; it is
closed. `grep -rn "admin_notices|needs_setup|FeaturesUtil|blocks"` returns nothing across the whole plugin, so
there is no signal of any kind.

**The fix.** Add an `AbstractPaymentMethodType` subclass registered on
`woocommerce_blocks_payment_method_type_registration`, whose `get_payment_method_script_handles()` returns one
small bundle calling:

```js
registerPaymentMethod( {
    name: 'nicepay',
    label, content, edit,
    canMakePayment: () => true,
    supports: { features: [ 'products', 'refunds' ] },
} );
```

No server-side change is needed — `process_payment()` already returns the exact shape Blocks consumes. Also
declare `cart_checkout_blocks` and `custom_order_tables` compatibility on `before_woocommerce_init`, implement
`needs_setup()`, and move asset loading into a gateway `payment_scripts()` method that returns early unless the
gateway is actually in play.

---

<a id="excellence-15"></a>
### EXCELLENCE-15 · `MEDIUM` · Going live fails the orders of everyone mid-payment · *(needs confirmation)*

[includes/class-nicepay-api.php:23](../../includes/class-nicepay-api.php#L23) ·
[:83](../../includes/class-nicepay-api.php#L83)

```php
$this->is_test_mode = ( get_option( 'nicepay_mode', 'test' ) === 'test' );
```

The credential pair is resolved **at request time**, and both return handlers construct a fresh `NicePay_API`
per request. `verify_auth_signature()` hashes `$auth_token . $this->mid . $amt . $this->merchant_key` using
whatever the option says *now*. The transactions table records neither MID nor mode — the schema has no such
columns — so a row carries no memory of which credential pair created it.

The merchant flips Mode to Live and saves. Every buyer with the NICEPAY window open at that instant returns
with a signature computed under the test key: verification fails, the row is stamped `SIG_FAIL`, the order goes
`failed`, and (per [EXCELLENCE-04](#excellence-04)) the merchant is emailed about it. There is no warning on
the Mode selector, no drain period, and afterwards no way to distinguish a mode-switch casualty from a genuine
signature attack — both write the same code.

**The fix.** Record `mid` and `mode` on the row at creation, and resolve the verification key from the row's
stored MID (`NicePay_API::for_mid( $transaction->mid )`) rather than the current option. Show an inline warning
next to the Mode selector when any `pending`/`approving`/`waiting` transaction exists.

---

<a id="excellence-17"></a>
### EXCELLENCE-17 · `MEDIUM` · Four silent guards mean the 망취소 quietly does not happen · *(needs confirmation)*

[includes/class-nicepay-api.php:186](../../includes/class-nicepay-api.php#L186) ·
[:225](../../includes/class-nicepay-api.php#L225)

Every net-cancel invocation is `if ( ! empty( $auth_data['NetCancelURL'] ) ) { $this->request_net_cancel( $auth_data ); }`
with **no else branch** — absent or mangled URL means the reversal is skipped and nothing is recorded. Worse,
the *earliest* failure point does not attempt a reversal at all: when `validate_nicepay_url( $next_app_url )`
fails, the method returns a `WP_Error` at 186-189 without calling `request_net_cancel()`, even though
authentication has already succeeded and `NetCancelURL` is sitting in the same array. And
`validate_nicepay_url()` is case-sensitive (`in_array( $parsed['host'], self::$allowed_hosts, true )`), so a
legitimately uppercased host lands in exactly that no-reversal branch.

An authenticated card hold is abandoned with no reversal attempt and no trace — the only record is a
`nicepay_log()` call that is inert in production.

**The fix.** Lowercase the host before the allowlist comparison. Fire the net cancel on the URL-validation
failure path too, deriving the cancel URL from the validated DC when `NetCancelURL` is absent
(`https://{dc}-api.nicepay.co.kr/webapi/cancel_process.jsp`). Replace all four bare guards with one helper that
**always** records its outcome — success code, failure code, or `no_cancel_url` — onto the transaction row.

*Spec §8: trigger condition is "approval call failed (connection timeout / merchant internal error)" — precisely
this situation.*

---

<a id="excellence-18"></a>
### EXCELLENCE-18 · `MEDIUM` · The buyer stares at a blank viewport for up to 60 seconds, then gets a 504 · *(needs confirmation)*

[includes/class-nicepay-api.php:213](../../includes/class-nicepay-api.php#L213) ·
[:305](../../includes/class-nicepay-api.php#L305)

On the failure path one buyer-facing request makes two sequential blocking HTTP calls, each `'timeout' => 30` —
a 60-second worst case in one PHP request. Neither handler calls `ignore_user_abort( true )`, neither raises the
execution limit, and neither emits output before the work completes. WordPress maps the single `timeout`
argument to both connect and read, so an unreachable host burns the full 30s before the reversal even starts,
against the spec's 5s connect budget.

nginx's default `fastcgi_read_timeout` is 60s, so the buyer's likely outcome is a 504 rather than any message
the plugin authored — and a 504 or a reload re-POSTs the identical body against a consumed `AuthToken`, with no
idempotency guard anywhere.

**The fix.** `ignore_user_abort( true )` at the top of both handlers so the state machine always completes.
Split the budgets: `'timeout' => 30, 'connect_timeout' => 5` for approval, `'timeout' => 10` for the net cancel
(≤45s total). Emit an interstitial ("Confirming your payment — do not close this window") before the blocking
call, and add `nocache_headers()`.

*Spec §2: Connection 5 sec, Receive(Read) 30 sec.*

---

<a id="excellence-16"></a>
### EXCELLENCE-16 · `MEDIUM` · Refund partiality is computed against the wrong number · *(needs confirmation)*

[includes/class-nicepay-gateway.php:437](../../includes/class-nicepay-gateway.php#L437) ·
[admin/class-nicepay-transactions.php:208](../../admin/class-nicepay-transactions.php#L208)

```php
$cancel_amt = nicepay_get_amount( $amount, $order->get_currency() );
$total_amt  = nicepay_get_amount( $order->get_total(), $order->get_currency() );
$is_partial = ( (float) $cancel_amt < (float) $total_amt );
```

The amount NICEPAY actually **captured** is available in two places the code never reads — `$transaction->amount`
and `Amt` inside the archived `payment_data` — and the transaction row is loaded only *after* the cancel
succeeds. The admin path is worse: it always sends a full cancel, because `$partial` is left at its `false`
default.

If the captured amount ever differs from the current order total (an edited total, a KRW truncation, a Moid
swap), the plugin sends the wrong `PartialCancelCode` and the merchant sees a raw Korean `ResultMsg`. Because
`RemainAmt` is never read (`grep` → 0 hits), a second partial refund is computed against the original total
again — the plugin can never tell how much is still refundable.

**The fix.** Load the settled row at the top of `process_refund()`; add `refunded_amount` and `remain_amt`
columns; compute `$is_partial = ( (float) $cancel_amt < (float) $remaining )` and update `$remaining` from each
cancel response's `RemainAmt`. Reject `$cancel_amt > $remaining` with an explicit `WP_Error` rather than letting
the PG do it. Give the admin Cancel button an amount field so both reversal surfaces have equal capability.

---

<a id="excellence-19"></a>
### EXCELLENCE-19 · `MEDIUM` · Two payment surfaces, two currencies, one site · *(needs confirmation)*

[nicepay-payment-gateway.php:243](../../nicepay-payment-gateway.php#L243) ·
[includes/class-nicepay-gateway.php:149](../../includes/class-nicepay-gateway.php#L149)

`render_payment_shortcode()` defaults currency to `get_option( 'nicepay_currency', 'KRW' )`; the WooCommerce
gateway uses `$order->get_currency()` and ignores that option entirely; `ajax_init_payment()` — which mints the
signature for the shortcode path — receives no currency at all.

A Turkish store running WooCommerce in TRY ships a checkout posting `CurrencyCode=TRY` and shortcode buttons
posting `CurrencyCode=KRW`, showing "10,000 KRW" beside a catalogue priced in lira. Neither surface warns, and
because the table has no currency column both land in one ledger formatted with the global option.

**The fix.** Make one source authoritative — either delete `nicepay_currency` and derive from
`get_woocommerce_currency()`, or relabel it "Currency for shortcode payments" with a description stating that
WooCommerce orders always use the store currency. Validate the shortcode `currency` attribute against
`array( 'KRW', 'USD' )` in both `render_payment_shortcode()` and `ajax_save_shortcode()`. Send `currency` to
`ajax_init_payment()` and store it.

---

## 4. The unread-surface findings

These came from auditing files and paths that no dimension reviewer had opened. They are smaller, but two of
them break the product for its primary market.

<a id="excellence-03"></a>
### EXCELLENCE-03 · `HIGH` · Naming a shortcode in Korean permanently breaks Edit, Save and Delete · *(needs confirmation)*

[nicepay-payment-gateway.php:417](../../nicepay-payment-gateway.php#L417) ·
[admin/class-nicepay-admin.php:407](../../admin/class-nicepay-admin.php#L407) ·
[includes/nicepay-functions.php:416](../../includes/nicepay-functions.php#L416) ·
[assets/js/nicepay-admin.js:232](../../assets/js/nicepay-admin.js#L232)

`ajax_save_shortcode()` derives the permanent id with `$slug = sanitize_title( $name );`. For text
`remove_accents()` cannot transliterate — Hangul, CJK — `sanitize_title_with_dashes()` runs `utf8_uri_encode()`
and then **keeps** the `%` characters, because its filter is `preg_replace( '|[^%a-z0-9 _-]|', '', $title )`
with `%` explicitly allowlisted. A shortcode named 빠른 결제 gets
`id = '%eb%b9%a0%eb%a5%b8-%ea%b2%b0%ec%a0%9c'`. Three things then break, in three different files:

| # | Surface | Mechanism |
|---|---|---|
| 1 | **Edit is dead** | `admin_url( '…&edit=' . $sc['id'] )` keeps the raw `%`; the browser sends it, PHP percent-**decodes** it, so `$_GET['edit']` is `빠른-결제` and the `===` comparison against the stored `%eb…` never matches. Builder opens blank. |
| 2 | **Save is dead** | `data-edit-id="빠른-결제"` feeds `editId`; the update loop `if ( $sc['id'] === $edit_id )` never matches, so the handler returns "Shortcode not found." with no route out. |
| 3 | **Delete lies** | `$('#sc-card-' + id)` — `%` is legal in an HTML `id` but not a valid CSS identifier character, so Sizzle throws `SyntaxError: Unrecognized expression`. Because that line runs *after* `modal.close()` and the success toast, the merchant sees "Shortcode deleted." while the card stays. |

Maximally confusing, because the shortcode **still renders correctly** on the front end
(`shortcode_parse_atts()` does not decode percent-escapes). The button works and can never be edited again.

**The fix.** Stop deriving the id from the display name.

```php
// nicepay-payment-gateway.php — ajax_save_shortcode(), replacing lines 417-425
$existing_ids = array_column( $shortcodes, 'id' );
$slug = sanitize_title( $name );
// sanitize_title() percent-encodes non-transliterable UTF-8 and keeps the '%';
// such an id survives neither a URL round trip nor a CSS id selector.
if ( '' === $slug || preg_match( '/[^a-z0-9_-]/', $slug ) ) {
    do { $slug = 'sc-' . wp_generate_password( 8, false, false ); }
    while ( in_array( $slug, $existing_ids, true ) );
} else {
    $base = $slug; $n = 2;
    while ( in_array( $slug, $existing_ids, true ) ) { $slug = $base . '-' . $n; $n++; }
}
```

Harden both consumers too: `rawurlencode( $sc['id'] )` in the `edit=` link, and in the JS use
`document.getElementById('sc-card-' + id)` — not a selector parse, and it accepts `%`.

*This also subsumes [EXCELLENCE-24](#excellence-24) (a name of pure punctuation or emoji reduces to the empty
string, producing `id=""`, a shortcode that silently discards its saved configuration, and a delete request
that matches every other empty-id entry).*

---

<a id="excellence-11"></a>
### EXCELLENCE-11 · `MEDIUM` · The Shortcodes tab hands the merchant a corrupted shortcode; the Generator tab hands them a correct one · *(needs confirmation)*

[admin/class-nicepay-admin.php:374](../../admin/class-nicepay-admin.php#L374) ·
[:403](../../admin/class-nicepay-admin.php#L403) ·
[:412](../../admin/class-nicepay-admin.php#L412)

`render_shortcodes_tab()` applies `esc_attr()` to each attribute **while building the string**:

```php
$sc_parts[] = 'goods_name="' . esc_attr( $sc['goods_name'] ) . '"';
```

…and the finished string is escaped a **second** time by both of its sinks, `esc_html( $full_shortcode )` at
403 and `esc_attr( $full_shortcode )` at 412. After the second pass the clipboard receives the once-escaped
text: `goods_name="Café &amp; Bar"`. Pasted into a page, `shortcode_parse_atts()` does not decode entities, so
the mangled name is what the buyer sees, what is signed as `GoodsName`, what appears on the card statement, and
what is stored locally. Meanwhile the Generator's `buildShortcode()` concatenates with **no** escaping at all
and produces the correct string — two screens, two different answers for the same preset.

**The fix.** Build raw, escape only at the sinks: delete every `esc_attr()` from the `$sc_parts` assembly.
Fix the reciprocal defect in the JS builder (`function q(v){ return String(v).replace(/"/g,'&quot;'); }`, so
`12" Vinyl` cannot terminate an attribute). Extract the attribute list into one shared source — the PHP builder
currently omits `buyer_name`/`buyer_email`/`buyer_tel`/`button_class` that the JS one emits.

---

<a id="excellence-13"></a>
### EXCELLENCE-13 · `MEDIUM` · The same product name is escaped two different ways and the two copies diverge · *(needs confirmation)*

[templates/standalone-payment-form.php:129](../../templates/standalone-payment-form.php#L129) ·
[:305](../../templates/standalone-payment-form.php#L305)

`$goods_name` is computed once and leaves the page through two different escapers:

- To **NICEPAY**, via `esc_attr()` in a hidden input — the browser decodes the entity, so the posted
  `GoodsName` is the correct `Café & Bar`.
- To the **plugin's own database**, via `esc_js()` in the XHR body — and `esc_js()` runs
  `_wp_specialchars( $text, ENT_COMPAT )` *first*, so `&` becomes `&amp;` and nothing downstream decodes it.

One payment therefore produces a row saying `Café &amp; Bar` while the PG's record for the same TID says
`Café & Bar`. `goods_name` is one of the four columns the search box queries, so searching for a name the buyer
quotes from their PG receipt will not match the plugin's own row. The 40-byte `mb_strcut` is applied
pre-escape, so entity expansion can also push the stored copy past the limit.

**The fix.** Stop passing data through `esc_js()` — it is for legacy inline event-handler attributes. Emit JSON
literals instead:

```php
var _npGoods  = <?php echo wp_json_encode( $goods_name ); ?>;
var _npAmount = <?php echo wp_json_encode( (string) $amount ); ?>;
```

---

<a id="excellence-12"></a>
### EXCELLENCE-12 · `MEDIUM` · The init endpoint is never told the method or the currency, so abandoned rows are unfilterable · *(needs confirmation)*

[templates/standalone-payment-form.php:305](../../templates/standalone-payment-form.php#L305) ·
[nicepay-payment-gateway.php:322](../../nicepay-payment-gateway.php#L322) ·
[includes/nicepay-functions.php:169](../../includes/nicepay-functions.php#L169)

The XHR sends exactly `action, nonce, amount, goods_name, buyer_name, buyer_email, buyer_tel` — and the handler
reads exactly those. Two values the page already holds are never sent: **pay method** (resolved into
`methodInput.value` seven lines above the XHR) and **currency** (resolved at line 17 and used to choose the
KRW-integer vs 2-decimal normalisation, so the server-side meaning of the number being signed depends on a
value the server never receives).

Downstream: the Method filter builds `WHERE payment_method = %s` from a dropdown of the six codes, so it can
never return a single abandoned standalone attempt; and the Method column renders empty for exactly the rows
that dominate the first page.

**The fix.** Send both, validate both server-side against `nicepay_enabled_methods` and `array('KRW','USD')`,
store both, and normalise the amount through `nicepay_get_amount( $amount, $currency )` **before signing** — this
endpoint is currently the only amount path in the plugin that skips normalisation.

---

<a id="excellence-14"></a>
### EXCELLENCE-14 · `MEDIUM` · A translated label is persisted as data, so the ledger is a permanent mixture of locales · *(needs confirmation)*

[includes/class-nicepay-gateway.php:328](../../includes/class-nicepay-gateway.php#L328) ·
[admin/class-nicepay-transactions.php:134](../../admin/class-nicepay-transactions.php#L134)

Both return handlers persist `'pay_method_name' => NicePay_API::get_payment_method_name( $result_method )` — a
`__()` result, resolved against whatever locale is active in the request that processes the approval, which for
WooCommerce is the **shopper's**. The admin then renders it in preference to the canonical code.

A Seoul merchant sees 신용카드, Credit Card, 가상계좌, Virtual Account and 信用卡 interleaved by date, with no
way to tell that four of those five are the same two methods. Changing the site language never re-translates
history. This is entirely avoidable: the canonical code is in the adjacent column, and the Method **filter**
four lines earlier already resolves labels live.

**The fix.** Remove the `pay_method_name` write from both handlers and derive at render time:

```php
<td><?php echo esc_html( $item->payment_method
    ? NicePay_API::get_payment_method_name( $item->payment_method )
    : '—' ); ?></td>
```

**Adopt this as a general rule: never persist the output of `__()`.** The same class of defect freezes the four
shortcode presets at activation time.

---

### The remaining low-severity items

| ID | Sev | Finding | Where |
|---|---|---|---|
| <a id="excellence-22"></a>**EXCELLENCE-22** | `LOW` | No default-method setting — both templates hardcode `$enabled_methods[0]`, whose order is the plugin's registry order, not the merchant's. A store where 가상계좌 carries the volume cannot lead with it without disabling cards. Add `nicepay_default_method`, resolve once into `$default_method`, expose as a shortcode attribute. | [templates/payment-form.php:38](../../templates/payment-form.php#L38), [includes/class-nicepay-api.php:451](../../includes/class-nicepay-api.php#L451) |
| <a id="excellence-23"></a>**EXCELLENCE-23** | `LOW` | The shortcode card's Display Mode badge prints the raw enum `inline`/`modal` — the only untranslated user-visible label in the settings screens, and the correctly translated strings already exist 80 lines away in the Generator tab. Add `nicepay_get_display_mode_label()` beside `nicepay_get_status_label()`. | [admin/class-nicepay-admin.php:385](../../admin/class-nicepay-admin.php#L385) |
| <a id="excellence-24"></a>**EXCELLENCE-24** | `LOW` | A name of pure punctuation or emoji yields an empty slug: `id=""`, a shortcode that silently discards its saved config (the render guards with `! empty( $raw_atts['id'] )`), and a delete that removes every empty-id entry. Subsumed by the [EXCELLENCE-03](#excellence-03) fix. | [nicepay-payment-gateway.php:417](../../nicepay-payment-gateway.php#L417), [:455](../../nicepay-payment-gateway.php#L455) |
| <a id="excellence-25"></a>**EXCELLENCE-25** | `LOW` | A unit test **blesses the money bug**: `test_get_amount_krw_strips_decimals()` asserts `'10000'` for `10000.50`. The name states truncation as the intended contract, so fixing the rounding bug means editing a test that reads like specification. Third instance of this pattern in the suite. Change to `(int) round( (float) $amount )` and rename to `test_get_amount_krw_rounds_to_nearest_won()`. | [tests/unit/NicePayFunctionsTest.php:67](../../tests/unit/NicePayFunctionsTest.php#L67), [includes/nicepay-functions.php:253](../../includes/nicepay-functions.php#L253) |
| <a id="excellence-26"></a>**EXCELLENCE-26** | `LOW` | The `en_US` catalogue is a 157-entry identity map (parsed: 0 divergences, 0 empty msgstrs) — pure overhead that makes every future string land in five catalogues instead of four, and makes `msgfmt --statistics` report a meaningless 100%. Delete it; generate in CI if tooling needs it. | [languages/nicepay-payment-gateway-en_US.po](../../languages/nicepay-payment-gateway-en_US.po) |
| <a id="excellence-27"></a>**EXCELLENCE-27** | `LOW` | `CODE_OF_CONDUCT.md` routes conduct reports to a **public GitHub Issue** and names no contact address — directly contradicting `CONTRIBUTING.md`, which correctly says "Do NOT open a public issue" for security. No acknowledgement window, no recusal rule, no attribution. Adopt Contributor Covenant 2.1 verbatim and add a `SECURITY.md`. | [CODE_OF_CONDUCT.md:26](../../CODE_OF_CONDUCT.md#L26), [CONTRIBUTING.md:277](../../CONTRIBUTING.md#L277) |
| <a id="excellence-28"></a>**EXCELLENCE-28** | `LOW` | `nicepay_get_all_shortcodes()` re-seeds only when the option is exactly `null`; anything else is returned unchecked to three `foreach` sites, one of which is the **public front-end shortcode**. A scalar value there is a PHP 8 fatal — a white screen on a customer-facing payment page, with no notice and no log. Also performs an `update_option()` inside a read path reachable by an anonymous GET. | [includes/nicepay-functions.php:428](../../includes/nicepay-functions.php#L428) |
| <a id="excellence-29"></a>**EXCELLENCE-29** | `LOW` | `.nicepay-shortcodes-page` is emitted as the outermost wrapper of the Shortcodes tab and has no rule in either stylesheet — the sole orphan in a 32-class reverse audit. Give it a rule consistent with the sibling tabs, or drop the class so markup and CSS correspond exactly in both directions. | [admin/class-nicepay-admin.php:351](../../admin/class-nicepay-admin.php#L351) |

---

## 5. Missing capabilities

Each of these is a thing a first-class payment product has and this one does not. Ordered by payoff-per-unit-effort.

| ID | Capability | Why a first-class gateway has it | Effort | Payoff |
|---|---|---|---|---|
| <a id="excellence-30"></a>**EXCELLENCE-30** | **Readiness panel, test payment, system report** | Nothing in the product proves it works. `grep` returns **zero** hits for `admin_notices`, `wp_add_dashboard_widget`, `site_status_tests` and `debug_information`. `render_api_tab()` never contacts NICEPAY and never checks that credentials for the *active* mode are even non-empty. `is_available()` returns false silently. `nicepay_log()` is compiled out in production. | M | **Highest.** Eliminates most week-one support load. |
| <a id="excellence-33"></a>**EXCELLENCE-33** | **Reconciliation: totals, currency, MID, CSV export** | The screen renders rows and a row *count* — `nicepay_get_transactions()` computes `COUNT(*)`, never `SUM(amount)`. The schema has no `mid`, `currency`, `refunded_amount`, `cancel_tid` or `mode`. Month-end 대사 cannot be done in the product at all. | M | **Very high** — and schema, so every day of delay is unrecoverable data. |
| <a id="excellence-40"></a>**EXCELLENCE-40** | **Shortcode references that actually reference** | Both copy buttons emit a fully-expanded snapshot, and `shortcode_atts()` lets any pasted attribute override the saved default. Editing a saved shortcode's price changes **nothing** on the pages using it. The generator's own comment says a short form was intended; none is rendered. | S | **High** — this is a silent financial error. |
| <a id="excellence-31"></a>**EXCELLENCE-31** | **Inline method selection at checkout** | `has_fields = false` and no `payment_fields()` override, so the method choice — which determines the entire downstream flow — happens on a *second page*, **after** the order already exists in `pending`. Every abandonment there manufactures a permanent pending order with no method recorded. | M | High: removes a page and a click from every order. |
| <a id="excellence-20"></a>**EXCELLENCE-20** | **A status vocabulary a merchant can read** | Six plugin statuses sit beside WooCommerce statuses and NICEPAY ResultCodes with no key. Two are **inverted**: `$is_partial ? 'refunded' : 'cancelled'` means a *full* refund is labelled "Cancelled" and a *partial* one "Refunded". A merchant reconciling a month will be wrong about both. | S | High, near-zero cost. |
| <a id="excellence-32"></a>**EXCELLENCE-32** | **Virtual-account lifecycle** | `vbank_exp_date` is stored and **never read back**. Zero hits for `wp_schedule_event`, `wp_mail`, `WC_Email`, `woocommerce_order_details_after_order_table`. The buyer sees the account number exactly once, in a POST response that cannot be revisited. Unfunded accounts hold stock forever. | M | High — VBANK is where the whole post-purchase support burden lives. |
| <a id="excellence-39"></a>**EXCELLENCE-39** | **Config export/import and wp-config constants** | Zero hits for `import`/`export`. The API tab echoes stored values back into the form, so any value injected by the documented `option_*` filter is **re-persisted to the database on the next Save** — the recommended hardening recipe is actively defeated. | S | Medium-high for agencies; fixes a real secrets-handling gap. |
| <a id="excellence-38"></a>**EXCELLENCE-38** | **Multi-MID / per-method credential routing** | `NicePay_API::__construct()` resolves exactly one pair and hardcodes which options it reads; all five call sites instantiate it with no context. Korean merchants routinely hold a separate 에스크로 MID and a separate USD MID. The MID a payment ran under is never recorded. | M | Medium — but the `mid` column is urgent and retroactively unrecoverable. |
| <a id="excellence-36"></a>**EXCELLENCE-36** | **간편결제 surfacing and window branding** | Zero hits for `ClickpayCl`, `KAKAOPAY`, `NAVERPAY`, `TossPay`, `PAYCO`, `LogoImage`, `SkinType`. Korean shoppers look for a wallet mark, not "Credit Card". The BANK and VBANK icons are literally the same bank-building outline. Spec §6.4.1 returns the wallet code and it is discarded. | M | Medium-high: conversion at the picker + wallet-level reconciliation. |
| <a id="excellence-34"></a>**EXCELLENCE-34** | **A record spine for standalone payments** | Produces a database row and nothing else: no payer identity even for logged-in users, no retrievable receipt, no confirmation email despite email being required, and **zero** `do_action`/`apply_filters` in the entire plugin, so nothing can hook a completed payment. | L | High for anyone using the shortcode for real work. |
| <a id="excellence-35"></a>**EXCELLENCE-35** | **Shareable payment links** | The only entry point is `add_shortcode()` inside post content. No URL, no expiry, no usage limit, no disable. Sending someone a link is how a small merchant collects a one-off payment; this plugin cannot produce one — despite already owning a saved-config store and a rewrite endpoint. | L | High: the headline capability of every PSP tool merchants compare against. |
| <a id="excellence-37"></a>**EXCELLENCE-37** | **Korean tax and compliance surface** | Zero hits for `RcptType`, `RcptTID`, `RcptAuthCode`, `TransType`, `SupplyAmt`, `GoodsVat`, `TaxFreeAmt`. 현금영수증 fields are returned by NICEPAY on every CARD/BANK approval and **discarded**; escrow cannot be transacted at all; the 과세/면세 breakdown is never sent even though WooCommerce holds the data. | L | Each independently disqualifies a class of Korean merchant. |
| <a id="excellence-21"></a>**EXCELLENCE-21** | **Recurring billing (빌링)** — and, today, **not shipping a preset that lies** | Every fresh install seeds a "Subscription" preset with a "Subscribe" button for "Monthly Subscription" at 29,900 KRW that charges **once**, stores no billing key, schedules nothing, and grants the payer nothing. `$supports = array( 'products', 'refunds' )` — no `subscriptions`, no `tokenization`. Zero hits for `Billing`, `BID`, `WC_Subscriptions`. | L (rename: XS) | The rename is urgent; the capability caps the addressable market. |

---

## 6. The 20% that yields 80% of the quality jump

Ranked by (quality delta) ÷ (effort), with the reasoning stated rather than asserted.

**1. Make the plugin remember what it did.** — [EXCELLENCE-01](#excellence-01), [EXCELLENCE-02](#excellence-02),
[EXCELLENCE-17](#excellence-17)
Persist `tid`/`auth_token` before approval; introduce an `approving` status; record every reversal attempt and
its outcome; add an order note whenever a cancel result is unknown. *Why first:* these are the only findings
where the failure mode is **irrecoverable loss of the merchant's money with no trace**. Everything else is
recoverable by a human who knows what happened; these findings are precisely the ones that ensure nobody knows.
Small code change, largest possible consequence.

**2. One schema migration, done once, with a version gate.** — [EXCELLENCE-09](#excellence-09),
[EXCELLENCE-07](#excellence-07), [EXCELLENCE-33](#excellence-33), [EXCELLENCE-38](#excellence-38)
Add the version gate on `admin_init`, switch to plain `CREATE TABLE` so `dbDelta` can diff, and in the same
migration add `mid`, `mode`, `currency`, `refunded_amount`, `remain_amt`, `net_cancel_result`, `wallet_code`,
`rcpt_*` and `KEY idx_status_created (status, created_at)`. *Why second:* the version gate prevents a total
silent outage on the next update, and every missing column is **retroactively unrecoverable** — data not
captured today cannot be reconstructed. This one change unblocks reconciliation, multi-MID, refund correctness,
per-row currency formatting and the transactions-screen performance fix simultaneously.

**3. Stop turning ordinary shopper behaviour into failure.** — [EXCELLENCE-04](#excellence-04),
[EXCELLENCE-20](#excellence-20)
Signature check first, then split cancellation from decline; leave abandoned orders `pending` and retryable;
rename the six statuses to words a merchant already owns and fix the inverted refunded/cancelled assignment.
*Why third:* it costs a day and it changes the merchant's daily experience from "constant false alarms and a
ledger I cannot read" to "quiet, legible." It also restores the signal value of the failed-order email, which
is what makes findings 1 and 2 actionable in practice.

**4. Ship a readiness panel and a test payment.** — [EXCELLENCE-30](#excellence-30)
*Why fourth:* it is the single highest-leverage *addition*, because it converts every one of the silent
configuration failures — empty live credentials, plain permalinks, a stale rewrite rule, blocked outbound
HTTPS, an unsupported store currency, no methods ticked — from a lost customer into a red line of text. The
405-from-non-POST behaviour of the return handler already gives you a free endpoint probe.

**5. Support the Blocks checkout.** — [EXCELLENCE-10](#excellence-10)
*Why fifth despite being severity-high:* it is a hard blocker (the store is *closed*, not degraded) but the fix
is genuinely small here — `has_fields = false`, and `process_payment()` already returns the exact response shape
Blocks consumes. A day's work removes an entire category of "the plugin doesn't work" reports.

**6. Mint the auth payload on click, not on render.** — [EXCELLENCE-05](#excellence-05)
*Why sixth:* it fixes stale signatures, already-expired virtual accounts, and the client-supplied-amount
surface in one move — and the correct pattern is already implemented in the shortcode path, so this is
transplanting existing code rather than designing new code.

**7. Give the Transactions screen a financial view.** — [EXCELLENCE-33](#excellence-33)
Totals strip computed over the *current filter*, per-method breakdown, CSV export with KST timestamps.
*Why seventh:* it is the first thing that makes the screen worth opening, and once item 2 has landed the columns
exist, so this becomes mostly presentation.

**8. Close the virtual-account loop.** — [EXCELLENCE-32](#excellence-32), [EXCELLENCE-06](#excellence-06)
Persist the bank/account/deadline to order meta, render them on the thank-you page, in My Account and in the
on-hold email; expire lapsed accounts on cron and release stock; add "Mark as deposited" and "Resend details".
*Why eighth:* highest support-load reduction per line of code for a Korean store, and the only remaining place
where reserved stock is lost silently.

**9. Make the shipped defaults truthful.** — [EXCELLENCE-21](#excellence-21) (rename),
[EXCELLENCE-40](#excellence-40), [EXCELLENCE-03](#excellence-03)
Rename the Subscription preset; make the reference-form shortcode the default copy target; give shortcodes
opaque ASCII ids. *Why last in the top tier:* small, but each one is a case where **the product actively
misleads the merchant** — a subscribe button that charges once, a "central" shortcode that is a frozen copy, and
a Korean-named shortcode that silently becomes uneditable. Trust is the product; these are trust bugs.

---

## 7. Roadmap

### Horizon 0 — Must fix before any production use

Do not take live money until all of these are done. Each one either loses money, hides money, or turns the
store off.

| ID | Item |
|---|---|
| [EXCELLENCE-01](#excellence-01) | Persist `tid`/`auth_token` before approval; add `approving` status; open the Cancel gate to it |
| [EXCELLENCE-02](#excellence-02) | Audit record + order note on every non-success cancel; treat a cancel-response signature failure as *probably succeeded* |
| [EXCELLENCE-17](#excellence-17) | Always attempt and always record the net cancel; lowercase the host in `validate_nicepay_url()` |
| [EXCELLENCE-09](#excellence-09) | Version-gated migration on `admin_init`; plain `CREATE TABLE`; column whitelist on insert; unconditional error logging + admin notice |
| [EXCELLENCE-10](#excellence-10) | Blocks payment-method registration; `needs_setup()`; declare `cart_checkout_blocks` + `custom_order_tables` |
| [EXCELLENCE-04](#excellence-04) | Signature check first; abandonment stays `pending` and retryable, not `failed` |
| [EXCELLENCE-15](#excellence-15) | Record `mid`/`mode` per row and verify against the row's MID, not the current option |
| [EXCELLENCE-05](#excellence-05) | Mint `EdiDate`/`SignData`/`VbankExpDate` per click; `nocache_headers()` |
| [EXCELLENCE-18](#excellence-18) | `ignore_user_abort( true )`; split connect/read timeouts to the spec's 5s/30s; interstitial |
| [EXCELLENCE-03](#excellence-03) + [EXCELLENCE-24](#excellence-24) | Opaque ASCII shortcode ids; `getElementById` instead of a built selector |
| [EXCELLENCE-08](#excellence-08) | Currency guard in `is_available()`; currency-aware `get_available_methods()` |
| [EXCELLENCE-28](#excellence-28) | Type guard in `nicepay_get_all_shortcodes()` (a scalar option is a public-facing PHP 8 fatal) |
| [EXCELLENCE-25](#excellence-25) | KRW `round()` instead of truncate, and rewrite the test that ratifies the bug |
| [EXCELLENCE-21](#excellence-21) | Rename or remove the "Subscription" preset — do not ship a promise the code cannot keep |

### Horizon 1 — Next release

The release that turns it from "works" into "operable."

| ID | Item |
|---|---|
| [EXCELLENCE-30](#excellence-30) | Readiness panel, "Run a test payment", "Copy system report", Site Health integration |
| [EXCELLENCE-33](#excellence-33) | Totals strip over the current filter, per-method breakdown, CSV export |
| [EXCELLENCE-07](#excellence-07) | `idx_status_created`, explicit column list, drop `idx_order_id`, transient count, pending-row reaper cron |
| [EXCELLENCE-32](#excellence-32) + [EXCELLENCE-06](#excellence-06) | VBANK lifecycle: persist + surface details, expiry cron, reminder email, Mark-as-deposited, Resend |
| [EXCELLENCE-20](#excellence-20) | Status rename, split refunded/cancelled, gate actions on remaining balance, inline "what these mean" help |
| [EXCELLENCE-16](#excellence-16) | Partiality from captured amount and `RemainAmt`; amount field on the admin Cancel action |
| [EXCELLENCE-31](#excellence-31) | `payment_fields()` at checkout; persist `_nicepay_pay_method`; auto-invoke on the receipt page |
| [EXCELLENCE-40](#excellence-40) | Reference-form shortcode as the default copy; "Used on N pages"; saved config wins for price fields |
| [EXCELLENCE-11](#excellence-11) [EXCELLENCE-13](#excellence-13) [EXCELLENCE-12](#excellence-12) [EXCELLENCE-14](#excellence-14) [EXCELLENCE-19](#excellence-19) | The escaping/contract/i18n-data cluster — all small, all correctness |
| [EXCELLENCE-39](#excellence-39) | Config export/import with validation; first-class `NICEPAY_LIVE_*` constants rendered read-only |
| [EXCELLENCE-22](#excellence-22) [EXCELLENCE-23](#excellence-23) [EXCELLENCE-26](#excellence-26) [EXCELLENCE-27](#excellence-27) [EXCELLENCE-29](#excellence-29) | Default-method setting, badge translation, delete `en_US`, Contributor Covenant + `SECURITY.md`, orphan class |

### Horizon 2 — The excellence tier

The work that makes it the obvious choice for a Korean WooCommerce store rather than a working one.

| ID | Item |
|---|---|
| [EXCELLENCE-34](#excellence-34) | Standalone payments as first-class objects: payer identity, retrievable receipt at `/nicepay-receipt/{token}`, confirmation email, **`do_action( 'nicepay_payment_completed', … )`**, human reference, row detail view |
| [EXCELLENCE-35](#excellence-35) | Payment links: `^nicepay-pay/([^/]+)/?$`, expiry, usage limits, disable toggle, optional custom amount with min/max, per-link paid counter |
| [EXCELLENCE-36](#excellence-36) | Record `ClickpayCl`; wallet marks in the picker; method sub-labels that explain what each rail *is*; `LogoImage` + `SkinType` so the PG window carries the shop's identity |
| [EXCELLENCE-37](#excellence-37) | Capture `RcptType`/`RcptTID`/`RcptAuthCode`; tax breakdown (`SupplyAmt`/`GoodsVat`/`TaxFreeAmt`, opt-in, asserted to sum to `Amt`); `TransType` escrow; derive `GoodsCl` from the cart instead of hardcoding `'1'` |
| [EXCELLENCE-38](#excellence-38) | `NicePay_API::for_context()`; per-method credential overrides; assert the returned `MID` matches the transaction's |
| [EXCELLENCE-21](#excellence-21) | 빌링: billing key against the user (never the PAN), full `$supports` set, `woocommerce_scheduled_subscription_payment_nicepay`, My Account payment-method management |

---

## Appendix — full finding index

| ID | Severity | Finding | Primary file |
|---|---|---|---|
| [EXCELLENCE-01](#excellence-01) | `CRITICAL` | `TxTid` discarded on every failure path | [class-nicepay-gateway.php:321](../../includes/class-nicepay-gateway.php#L321) |
| [EXCELLENCE-02](#excellence-02) | `CRITICAL` | Unknown-outcome refund leaves no footprint; WC deletes its own refund | [class-nicepay-gateway.php:445](../../includes/class-nicepay-gateway.php#L445) |
| [EXCELLENCE-03](#excellence-03) | `HIGH` | CJK shortcode names produce percent-encoded ids | [nicepay-payment-gateway.php:417](../../nicepay-payment-gateway.php#L417) |
| [EXCELLENCE-04](#excellence-04) | `HIGH` | Abandonment fires the admin failed-order email | [class-nicepay-gateway.php:258](../../includes/class-nicepay-gateway.php#L258) |
| [EXCELLENCE-05](#excellence-05) | `HIGH` | Auth payload frozen into the rendered page | [class-nicepay-gateway.php:122](../../includes/class-nicepay-gateway.php#L122) |
| [EXCELLENCE-06](#excellence-06) | `HIGH` | `waiting` VBANK has no resolution path | [class-nicepay-transactions.php:143](../../admin/class-nicepay-transactions.php#L143) |
| [EXCELLENCE-07](#excellence-07) | `HIGH` | Transactions list is a full scan + filesort with `SELECT *` | [nicepay-functions.php:210](../../includes/nicepay-functions.php#L210) |
| [EXCELLENCE-08](#excellence-08) | `HIGH` | No currency gating of payment methods | [class-nicepay-api.php:450](../../includes/class-nicepay-api.php#L450) |
| [EXCELLENCE-09](#excellence-09) | `HIGH` | No upgrade migration; silent total payment outage | [nicepay-payment-gateway.php:479](../../nicepay-payment-gateway.php#L479) |
| [EXCELLENCE-10](#excellence-10) | `HIGH` | Blocks checkout shows no payment methods | [nicepay-payment-gateway.php:180](../../nicepay-payment-gateway.php#L180) |
| [EXCELLENCE-11](#excellence-11) | `MEDIUM` | Shortcodes tab double-escapes the copyable shortcode | [class-nicepay-admin.php:374](../../admin/class-nicepay-admin.php#L374) |
| [EXCELLENCE-12](#excellence-12) | `MEDIUM` | Init endpoint never receives method or currency | [standalone-payment-form.php:305](../../templates/standalone-payment-form.php#L305) |
| [EXCELLENCE-13](#excellence-13) | `MEDIUM` | `esc_js()` vs `esc_attr()` divergence on `goods_name` | [standalone-payment-form.php:129](../../templates/standalone-payment-form.php#L129) |
| [EXCELLENCE-14](#excellence-14) | `MEDIUM` | Translated method label persisted per transaction | [class-nicepay-gateway.php:328](../../includes/class-nicepay-gateway.php#L328) |
| [EXCELLENCE-15](#excellence-15) | `MEDIUM` | Mode switch invalidates in-flight authentications | [class-nicepay-api.php:23](../../includes/class-nicepay-api.php#L23) |
| [EXCELLENCE-16](#excellence-16) | `MEDIUM` | Refund partiality computed against order total, not capture | [class-nicepay-gateway.php:437](../../includes/class-nicepay-gateway.php#L437) |
| [EXCELLENCE-17](#excellence-17) | `MEDIUM` | Net cancel silently skipped on four paths, absent on a fifth | [class-nicepay-api.php:186](../../includes/class-nicepay-api.php#L186) |
| [EXCELLENCE-18](#excellence-18) | `MEDIUM` | 60-second blocking return handler with no abort protection | [class-nicepay-api.php:213](../../includes/class-nicepay-api.php#L213) |
| [EXCELLENCE-19](#excellence-19) | `MEDIUM` | Shortcode and WooCommerce currencies diverge | [nicepay-payment-gateway.php:243](../../nicepay-payment-gateway.php#L243) |
| [EXCELLENCE-20](#excellence-20) | `MEDIUM` | Status vocabulary is a third language, two words inverted | [nicepay-functions.php:266](../../includes/nicepay-functions.php#L266) |
| [EXCELLENCE-21](#excellence-21) | `MEDIUM` | Ships a "Subscription" preset that charges once | [nicepay-functions.php:385](../../includes/nicepay-functions.php#L385) |
| [EXCELLENCE-22](#excellence-22) | `LOW` | No merchant-selectable default payment method | [templates/payment-form.php:38](../../templates/payment-form.php#L38) |
| [EXCELLENCE-23](#excellence-23) | `LOW` | Display-mode badge prints the raw untranslated enum | [class-nicepay-admin.php:385](../../admin/class-nicepay-admin.php#L385) |
| [EXCELLENCE-24](#excellence-24) | `LOW` | Punctuation-only name yields an empty shortcode id | [nicepay-payment-gateway.php:417](../../nicepay-payment-gateway.php#L417) |
| [EXCELLENCE-25](#excellence-25) | `LOW` | A unit test ratifies KRW truncation as intended | [NicePayFunctionsTest.php:67](../../tests/unit/NicePayFunctionsTest.php#L67) |
| [EXCELLENCE-26](#excellence-26) | `LOW` | `en_US` catalogue is a 157-entry identity map | [nicepay-payment-gateway-en_US.po](../../languages/nicepay-payment-gateway-en_US.po) |
| [EXCELLENCE-27](#excellence-27) | `LOW` | Conduct reports routed to a public issue, no contact address | [CODE_OF_CONDUCT.md:26](../../CODE_OF_CONDUCT.md#L26) |
| [EXCELLENCE-28](#excellence-28) | `LOW` | No type guard on the saved-shortcodes option | [nicepay-functions.php:428](../../includes/nicepay-functions.php#L428) |
| [EXCELLENCE-29](#excellence-29) | `LOW` | `.nicepay-shortcodes-page` has no rule in either stylesheet | [class-nicepay-admin.php:351](../../admin/class-nicepay-admin.php#L351) |
| [EXCELLENCE-30](#excellence-30) | `ENHANCEMENT` | No readiness panel, test payment or system report | [class-nicepay-admin.php:250](../../admin/class-nicepay-admin.php#L250) |
| [EXCELLENCE-31](#excellence-31) | `ENHANCEMENT` | Extra page and click; method chosen after the order exists | [class-nicepay-gateway.php:91](../../includes/class-nicepay-gateway.php#L91) |
| [EXCELLENCE-32](#excellence-32) | `ENHANCEMENT` | No virtual-account lifecycle | [class-nicepay-api.php:426](../../includes/class-nicepay-api.php#L426) |
| [EXCELLENCE-33](#excellence-33) | `ENHANCEMENT` | No reconciliation surface | [class-nicepay-transactions.php:70](../../admin/class-nicepay-transactions.php#L70) |
| [EXCELLENCE-34](#excellence-34) | `ENHANCEMENT` | Standalone payments have no record spine | [class-nicepay-return-handler.php:164](../../includes/class-nicepay-return-handler.php#L164) |
| [EXCELLENCE-35](#excellence-35) | `ENHANCEMENT` | No shareable payment links | [nicepay-payment-gateway.php:78](../../nicepay-payment-gateway.php#L78) |
| [EXCELLENCE-36](#excellence-36) | `ENHANCEMENT` | No 간편결제 surfacing, no window branding | [nicepay-icons.php:19](../../includes/nicepay-icons.php#L19) |
| [EXCELLENCE-37](#excellence-37) | `ENHANCEMENT` | 현금영수증 / 에스크로 / tax breakdown entirely absent | [class-nicepay-gateway.php:179](../../includes/class-nicepay-gateway.php#L179) |
| [EXCELLENCE-38](#excellence-38) | `ENHANCEMENT` | One MID for everything; MID never recorded | [class-nicepay-api.php:22](../../includes/class-nicepay-api.php#L22) |
| [EXCELLENCE-39](#excellence-39) | `ENHANCEMENT` | No configuration portability; wp-config filter defeated | [class-nicepay-admin.php:298](../../admin/class-nicepay-admin.php#L298) |
| [EXCELLENCE-40](#excellence-40) | `ENHANCEMENT` | Saved shortcodes copied as frozen expanded strings | [class-nicepay-admin.php:370](../../admin/class-nicepay-admin.php#L370) |

---

*All forty findings carry verdict `PLAUSIBLE` and are marked "needs confirmation": no PHP runtime, WordPress
installation or test harness was available during the review. Every code quotation was read from the working
tree at branch `main`; every claim about WordPress or WooCommerce core behaviour is documented behaviour, not
observed behaviour. Confirm before acting on the schema changes in particular — they are the only items in this
document that are irreversible.*
