# NicePay Payment Gateway — Comprehensive Review: Executive Summary

The NicePay Payment Gateway plugin (v2.0.0, ~12.5k LOC across 34 files) was reviewed across twelve dimensions, with every claim re-verified against the source by an adversarial second pass. The result is **489 surviving findings — 14 critical, 66 high, 246 medium, 135 low, 23 enhancement — with 2 claims refuted outright** and a further sixteen sub-claims killed inside findings that otherwise survived. The headline is a split verdict: the *cryptographic and protocol* layer of this plugin is done to a standard well above the WordPress-gateway average — all eight NICEPAY signature rules are byte-for-byte correct, the SSRF allowlist is real, every comparison is strict, every query is parameterised — while the *orchestration* layer around it never reconciles what NICEPAY tells it against what the merchant's own database says. The plugin can prove a message came from NICEPAY. It cannot prove the message is about the order it is being applied to, or for the amount that order costs. That single gap, plus a public endpoint that signs whatever amount a browser asks it to, is the difference between a plugin that is nearly ready and one that must not take live money today.

---

## 1. Scope and method

**Twelve review dimensions**, each conducted as an independent line-by-line read of the relevant source, then re-verified:

| # | Dimension | Output document |
|---|---|---|
| 1 | NICEPAY protocol conformance (against 인증결제 v2.0.8) | [02](02-protocol-conformance.md) |
| 2 | Payment lifecycle, state machine & money correctness | [03](03-payment-flow-and-money-correctness.md) |
| 3 | Security & abuse resistance | [04](04-security.md) |
| 4–6 | Merchant admin UX · Customer payment UX · Visual design & front-end robustness | [05](05-user-experience.md) |
| 7 | WordPress & WooCommerce platform conformance | [06](06-wordpress-woocommerce-platform.md) |
| 8 | Architecture, code quality & maintainability | [07](07-architecture-and-code-quality.md) |
| 9 | Internationalization & localization | [08](08-internationalization.md) |
| 10 | Test suite & quality gates | [09](09-testing.md) |
| 11 | Documentation accuracy & completeness | [10](10-documentation.md) |
| 12 | Build, CI/CD, packaging & release engineering | [11](11-build-release-and-repo-hygiene.md) |

**Three cross-cutting critics** then re-read the codebase looking for what the dimension reviews could not see — defects that only appear when you follow a complete user journey end to end, surfaces no dimension reviewer had opened, and the gap between "works" and "first-class." Their 40 findings are in [12-path-to-first-class.md](12-path-to-first-class.md).

**Adversarial verification.** Every finding was re-checked against the working tree: quoted code confirmed character-for-character, greps re-run independently, contrast ratios and SHA-256 digests recomputed from scratch. Findings whose consequence could not be established were downgraded, reframed, or killed. Two were killed. Severities were recalibrated downward in roughly 60 cases where the code observation was exact but the stated impact was not supported.

**Coverage.** All 34 files were read, not sampled — including the four files no dimension reviewer had opened (`CODE_OF_CONDUCT.md`, and the reverse CSS-to-markup audit that produced [EXCELLENCE-29](12-path-to-first-class.md#excellence-29)). Four vendor signature fixtures from the NICEPAY manual were reproduced independently with `shasum -a 256`; all four match the plugin's plaintexts exactly.

**What could not be verified.** No PHP runtime, no WordPress or WooCommerce installation, and no NICEPAY sandbox account were available. Every claim that depends on live PG behaviour, browser runtime, or WooCommerce core execution is marked `PLAUSIBLE — needs confirmation` in the index and in its dimension document. The whole of document 12 carries that marker by construction. **Confirm any PLAUSIBLE finding before acting on it**, especially the four that recommend irreversible schema changes.

The complete row-by-row listing is in [01-findings-index.md](01-findings-index.md).

---

## 2. The verdict

**This plugin is not production-ready, and three specific defects are what block it.** First, neither return handler ever compares the authenticated amount or order id against its own transaction record — and `Moid` is not covered by any NICEPAY signature — so a shopper can complete a cheap payment and re-point it at an expensive pending order, which is then marked paid with a genuine TID and no merchant-visible discrepancy. Second, a `wp_ajax_nopriv` endpoint signs whatever amount the browser sends it using the merchant's live key, so any visitor can pay ₩100 for a ₩50,000 item; because the same MID and key serve both payment flows, that signature is also accepted by the WooCommerce return handler, removing the last precondition from the first defect. Third, the gateway ships `enabled => 'yes'` pre-seeded with NICEPAY's *published* sandbox credentials, so merely activating the plugin puts a functioning payment method on a live checkout that marks orders paid while collecting nothing.

Below those, two advertised payment surfaces do not work at all: Virtual Account (enabled by default) has no deposit-notification endpoint and never shows the buyer the account number, and the gateway is entirely absent from the WooCommerce Cart/Checkout blocks — the default checkout for new stores since WooCommerce 8.3.

**None of this is a rewrite.** The hard part — the protocol layer — is correct. Eight of the fourteen critical findings collapse into two changes: reconcile amount and order binding on the inbound path, and make the server the price authority on the outbound path. Both are small. The realistic path to production is roughly **two to three weeks of focused work on P0**, followed by four to six weeks to close the two broken payment surfaces and the operational gaps that make failures invisible.

---

## 3. The top 10 findings, by real-world impact

**1. A cheap payment can settle an expensive order.** Both return handlers resolve the target order purely from the POSTed `Moid`, which is absent from the auth Signature plaintext (`AuthToken + MID + Amt + MerchantKey`), and never compare the approved amount to `$order->get_total()` or to `$transaction->amount`. A shopper pays 1,000 KRW for order B, rewrites one field in the browser-submitted return POST, and order A completes for 1,000,000 KRW with a valid TID attached.
[`includes/class-nicepay-gateway.php:226`](../../includes/class-nicepay-gateway.php#L226) · [PROTOCOL-01](02-protocol-conformance.md) · [FLOW-01/02](03-payment-flow-and-money-correctness.md) · [SECURITY-01](04-security.md)

**2. A public endpoint signs any amount a browser names.** `ajax_init_payment()` is registered for `wp_ajax_nopriv`, takes `$_POST['amount']`, validates only that it is positive, and returns `sha256( EdiDate + MID + Amt + MerchantKey )` computed with the merchant's live key. The shortcode's real price sits in `nicepay_saved_shortcodes` and is never consulted — the endpoint does not even receive a shortcode id. The nonce is no barrier: it is printed into the page and, for logged-out visitors, is one shared constant.
[`nicepay-payment-gateway.php:313-359`](../../nicepay-payment-gateway.php#L313) · [PROTOCOL-02](02-protocol-conformance.md) · [SECURITY-03](04-security.md) · [CODE-01](07-architecture-and-code-quality.md)

**3. Activating the plugin opens a live checkout backed by a demo account.** `init_form_fields()` sets `'enabled' => 'yes'`, and `set_default_options()` seeds NICEPAY's publicly documented sandbox MID `nicepay00m` and its merchant key. `is_available()` therefore passes immediately. A real customer can select "NicePay Payment", complete a sandbox authorisation, and have `payment_complete()` fire — stock reduced, confirmation sent, nothing collected. No banner anywhere marks test mode to the buyer.
[`includes/class-nicepay-gateway.php:41-46`](../../includes/class-nicepay-gateway.php#L41) · [PLATFORM-03](06-wordpress-woocommerce-platform.md) · [UX-004](05-user-experience.md) · [SECURITY-10](04-security.md)

**4. Virtual Account is broken end to end, and it is on by default.** There is no deposit-notification (입금통보) endpoint — the only registered route is `^nicepay-return/?$` — so a `waiting` transaction can never become `paid`. Separately, the bank name, account number and deadline are written only into a *private* order note: they reach neither the thank-you page, nor the on-hold email, nor My Account. The buyer has no number to transfer to, and the merchant has no automatic way to confirm the transfer that follows.
[`includes/class-nicepay-gateway.php:370-378`](../../includes/class-nicepay-gateway.php#L370) · [PROTOCOL-04](02-protocol-conformance.md) · [FLOW-07/08](03-payment-flow-and-money-correctness.md) · [PLATFORM-07](06-wordpress-woocommerce-platform.md)

**5. The gateway does not exist on the block checkout.** The plugin registers only a classic `WC_Payment_Gateway`; repo-wide greps for `AbstractPaymentMethodType`, `woocommerce_blocks_payment_method_type_registration` and `registerPaymentMethod` return nothing. On a store using the Cart/Checkout blocks — the default since WooCommerce 8.3 — NicePay simply does not render, while WooCommerce → Payments still shows it enabled and the Korean CDN script still loads on every checkout.
[`nicepay-payment-gateway.php:180-183`](../../nicepay-payment-gateway.php#L180) · [PLATFORM-01](06-wordpress-woocommerce-platform.md) · [EXCELLENCE-10](12-path-to-first-class.md#excellence-10)

**6. A replayed return POST turns a paid order into a failed one.** Neither handler checks `$transaction->status`, `$order->needs_payment()` or takes a lock — repo-wide greps for `is_paid`, `has_status` and `set_transient` return nothing. On a second delivery the spent AuthToken is re-submitted, NICEPAY rejects it, and the code falls through to `$order->update_status( 'failed' )` on an order whose money was genuinely captured. On one branch it also fires a net cancel against the original live TID.
[`includes/class-nicepay-gateway.php:396-404`](../../includes/class-nicepay-gateway.php#L396) · [FLOW-04](03-payment-flow-and-money-correctness.md) · [SECURITY-05](04-security.md) · [TESTS-05](09-testing.md)

**7. Any unauthenticated POST can flip an order to failed.** The `AuthResultCode !== '0000'` branch performs both a transaction update and `$order->update_status( 'failed', … )` *twenty-six lines before* the signature check, with an attacker-authored message interpolated into the order note. And the `Moid` is not secret: it is rendered as a visible hidden input on every pay page, so a customer can flip their own completed order to failed after receiving the goods.
[`includes/class-nicepay-gateway.php:249-272`](../../includes/class-nicepay-gateway.php#L249) · [FLOW-05](03-payment-flow-and-money-correctness.md) · [SECURITY-04](04-security.md)

**8. The one mechanism that protects a stranded card hold is unverified and invisible.** `request_net_cancel()` is called from four places and its return value is discarded at every one; inside, it never checks `ResultCode === '2001'` and never verifies the response signature, even though `verify_cancel_signature()` exists in the same class. The only trace is `nicepay_log()`, which returns immediately unless `WP_DEBUG` is true — so on a normal production site a failed 망취소 leaves no record anywhere.
[`includes/class-nicepay-api.php:278-324`](../../includes/class-nicepay-api.php#L278) · [PROTOCOL-03](02-protocol-conformance.md) · [FLOW-06](03-payment-flow-and-money-correctness.md) · [CODE-05](07-architecture-and-code-quality.md)

**9. The admin "Cancel" button returns money without recording a refund.** `ajax_cancel_transaction()` sends a full cancel to NICEPAY and then calls `$order->update_status( 'cancelled' )`. It never calls `wc_create_refund()`, so `get_total_refunded()` stays at 0, no refund line appears, the customer is not notified, stock is silently restored, and the Refund button on the order screen remains armed for a second reversal of the same TID.
[`admin/class-nicepay-transactions.php:217-232`](../../admin/class-nicepay-transactions.php#L217) · [FLOW-15](03-payment-flow-and-money-correctness.md) · [PLATFORM-04](06-wordpress-woocommerce-platform.md) · [UX-006](05-user-experience.md)

**10. A third of the interface is untranslatable, in a plugin whose primary market is Korea.** An independent re-extraction found 209 translatable units in code against 156 non-empty msgids in the `.pot`: **74 strings exist in code and in no catalogue**, including the entire Shortcode Generator tab and every placeholder and validation message in the buyer-facing standalone payment form. All four locales report 100% translated, because the missing strings were never extracted. README advertises complete four-language support.
[`languages/nicepay-payment-gateway.pot`](../../languages/nicepay-payment-gateway.pot) · [I18N-01](08-internationalization.md) · [UX-009](05-user-experience.md) · [RELEASE-03](11-build-release-and-repo-hygiene.md)

> **Note on overlap.** These ten are *defects*, not documents: the same underlying code is frequently reported by several dimensions, because each states the consequence its own audience cares about. Fixing items 1 and 2 closes eight of the fourteen critical findings.

---

## 4. Distribution

### By severity

| Severity | Count | Share | Meaning |
|---|---:|---:|---|
| 🔴 Critical | 14 | 2.9% | Money loss, security compromise, or total feature failure |
| 🟠 High | 66 | 13.6% | Severe defect, silent state corruption, or an advertised capability that does not work |
| 🟡 Medium | 246 | 50.8% | Real defect with bounded blast radius, or a spec deviation |
| 🔵 Low | 135 | 27.9% | Hardening, hygiene, polish, latent risk |
| ⚪ Enhancement | 23 | 4.8% | Missing capability, not a defect |
| **Total** | **489** | | 2 further claims refuted during verification |

### By dimension

| Dimension | Document | Total | 🔴 | 🟠 | 🟡 | 🔵 | ⚪ |
|---|---|---:|---:|---:|---:|---:|---:|
| Protocol conformance | [02](02-protocol-conformance.md) | 34 | 2 | 5 | 21 | 6 | — |
| Payment flow & money | [03](03-payment-flow-and-money-correctness.md) | 42 | 3 | 5 | 22 | 11 | 1 |
| Security | [04](04-security.md) | 28 | 1 | 4 | 9 | 14 | — |
| User experience | [05](05-user-experience.md) | 126 | 2 | 15 | 72 | 29 | 7 |
| WP/WC platform | [06](06-wordpress-woocommerce-platform.md) | 42 | 2 | 5 | 26 | 9 | — |
| Architecture & code quality | [07](07-architecture-and-code-quality.md) | 55 | 1 | 9 | 31 | 14 | — |
| Internationalization | [08](08-internationalization.md) | 21 | — | 3 | 6 | 10 | 2 |
| Testing | [09](09-testing.md) | 37 | 1 | 7 | 21 | 6 | 2 |
| Documentation | [10](10-documentation.md) | 39 | — | 2 | 20 | 17 | — |
| Build & release | [11](11-build-release-and-repo-hygiene.md) | 21 | — | 3 | 7 | 11 | — |
| Cross-cutting critics | [12](12-path-to-first-class.md) | 40 | 2 | 8 | 11 | 8 | 11 |

*Arithmetic note: the eleven documents record 485 numbered entries against 489 verified findings, and the index table lists 484 rows — a document folds near-identical findings into a single entry rather than repeating them (UX-117, for example, shares an entry with UX-099).*

### By verdict

Roughly 88% of findings are `CONFIRMED` — the verifier reproduced the code observation directly from the working tree. The `PLAUSIBLE` remainder concentrates in document 12 (all 40, by construction) plus about twenty findings whose consequence depends on live NICEPAY behaviour, browser cookie policy, or WooCommerce core execution.

---

## 5. What this codebase does well

This is not a weak codebase. Several things here are better than most commercial WordPress payment plugins, and a remediation effort should be careful not to "fix" them by accident.

**The cryptography is correct, and it is the part most integrations get wrong.** All eight SignData/Signature concatenation rules in [`includes/class-nicepay-api.php:74-125`](../../includes/class-nicepay-api.php#L74) match the spec exactly. Four of the vendor manual's worked hashes were reproduced independently with `shasum -a 256` — `475979a5…46bd`, `cc94db19…55fe`, `4916540b…0204`, `9439b21e…4efd` — and the plugin's plaintexts match character-for-character. Net cancel correctly reuses the *approval-request* rule rather than the cancel rule, which is the single most commonly botched detail in NICEPAY integrations. Every comparison uses `hash_equals()`.

**`NextAppURL` and `NetCancelURL` are treated as untrusted per-transaction input**, never hardcoded — exactly as the spec demands — and then constrained by a real SSRF allowlist ([`:130-152`](../../includes/class-nicepay-api.php#L130)): exactly the four documented NICEPAY hosts, `https` enforced, strict exact-host `in_array( …, true )`. I probed it with uppercase hosts, trailing dots, `userinfo@evil.com`, IDN homographs and lookalike suffixes; every one fails closed. Most gateway plugins omit this entirely.

**Comparison discipline is exemplary.** A sweep for the classic `"0000" == 0` type-juggling bug found **not a single loose `==` or `!=` anywhere in the PHP**. Every result code is checked with `===` or `in_array( …, true )`.

**The data layer is injection-free and has no N+1.** Every `$wpdb` call is parameterised, `ORDER BY` is whitelisted against an explicit array rather than interpolated, direction is normalised to a literal, LIMIT/OFFSET are bound as `%d`, and search input goes through `$wpdb->esc_like()`. The admin list issues exactly two queries regardless of row count.

**Order data access is already HPOS-safe** — `wc_get_order()`, `update_meta_data()`, `get_meta()`, `save()` throughout, with zero `get_post_meta`/`update_post_meta` anywhere. The plugin is one three-line declaration away from real HPOS compatibility.

**Output escaping is correct per context**, including inside the hand-written inline JavaScript, where both JS files build DOM text via `$('<span>').text( x ).html()` rather than concatenating markup. All ten shipped PHP files carry `ABSPATH` guards. Admin AJAX endpoints pair a capability check with a nonce in the same guard clause, and the destructive cancel action uses a *per-row* nonce rather than one global token.

**The tests that exist are the right shape.** `NicePaySignatureIntegrationTest.php` is a genuine contract test asserting the vendor's published hex digests, not a self-referential round-trip, with negative cases proving an auth signature cannot be replayed as an approval signature. The per-method result-code table is validated with data providers including its case-sensitivity edges, and cancel code `2211` — frequently missed — is handled and pinned.

**CI has good instincts.** Every third-party GitHub Action is pinned to a full commit SHA rather than a mutable tag; PHP 7.4 through 8.3 are matrixed; `failOnRisky` and `failOnWarning` are set so an assertion-free test fails the build.

**The admin has real product thinking in it.** A live-preview shortcode builder that renders an accurate mock as you type, purpose-built empty states with illustrations, toast notifications, a confirm modal whose dismiss paths are correctly guarded while a request is in flight, and copy-to-clipboard with an `execCommand` fallback. The customer-facing method picker is built on real `<input type="radio">` elements inside `<label>` within a `role="radiogroup"` — native keyboard navigation and AT semantics for free, which most plugins reimplement with divs and get wrong.

**The stylesheets are fully namespaced** — no bare element selectors, four `!important` declarations across 1,800 lines — and organised around 24 design tokens on `:root`.

**Documentation is unusually thorough** for a plugin this size: ~3,200 lines across nine files, with accurate Mermaid class and sequence diagrams, a correct signature-rules table cross-verified against both the code and the vendor spec, and language discipline clean enough that a non-ASCII scan across every Markdown file returns only em-dashes. All four compiled `.mo` files are byte-identical to fresh `msgfmt` output, and the `ko_KR` terminology matches official Korean payments vocabulary precisely.

---

## 6. Remediation plan

Effort figures are for one experienced WordPress/WooCommerce developer, and assume the findings marked `PLAUSIBLE` are confirmed first (see §1).

### P0 — Blockers. Do not take live money until these are done. *(~2–3 weeks)*

| # | Change | Closes | Effort |
|---|---|---|---|
| 1 | **Reconcile the inbound path.** In both return handlers, immediately after the signature check: assert `(int) $amt === (int) nicepay_get_amount( $order->get_total(), … )` **and** `=== (int) $transaction->amount`; after approval, assert `$result['Moid'] === $transaction->moid` and `$result['MID'] === $api->get_mid()`. Any mismatch → net cancel, fail, do not complete. | PROTOCOL-01, FLOW-01/02, SECURITY-01, CODE-25 | S |
| 2 | **Make the server the price authority.** `ajax_init_payment()` accepts a shortcode `id`, resolves the price via `nicepay_get_saved_shortcode()`, normalises through `nicepay_get_amount()`, and ignores any client `amount`. Return the normalised value and have the JS write it into the hidden `Amt` field. | PROTOCOL-02, FLOW-03, SECURITY-03, UX-002, PLATFORM-02, CODE-01, TESTS-01 | M |
| 3 | **Add a flow discriminator.** Reject any `WC…` Moid at the standalone endpoint and any non-`WC` Moid at the WooCommerce endpoint; longer term, carry an HMAC in `ReqReserved` and verify it on return. | SECURITY-02, CODE-41 | S |
| 4 | **Guard idempotency.** Short-circuit both handlers when `$transaction->status` is terminal or `! $order->needs_payment()`; wrap the approval in a per-Moid transient lock; never write `failed` over a paid order. | PROTOCOL-05, FLOW-04, SECURITY-05, PLATFORM-13, CODE-03 | S |
| 5 | **Verify before you write.** Move signature verification ahead of the `AuthResultCode` branch; an unverified POST must produce HTTP 400 and **zero** state changes. Widen `generate_moid()` entropy to `random_bytes` and add `UNIQUE KEY` on `moid`. | FLOW-05, SECURITY-04, SECURITY-09, FLOW-18 | S |
| 6 | **Stop shipping a live payment method.** Default `enabled` to `'no'`; append a test-mode marker to the gateway title and description; add a persistent `admin_notices` warning for test mode and for live mode with empty credentials. | PLATFORM-03, UX-004, SECURITY-10, UX-003 | S |
| 7 | **Make the net cancel real.** Verify `ResultCode === '2001'` and the response signature; capture the return value at all four call sites; record failures on the transaction row **and** as an order note; log money-critical events unconditionally via `wc_get_logger()->error()` rather than the `WP_DEBUG`-gated helper. | PROTOCOL-03, FLOW-06, SECURITY-06, CODE-05, PLATFORM-26 | S |
| 8 | **Fix the migration path first.** Drop `IF NOT EXISTS` so `dbDelta` can parse the table name, and add a version-gated upgrade runner on `admin_init` that actually reads `nicepay_db_version`. **Every schema fix in P1 depends on this landing first.** | PLATFORM-09, CODE-11, RELEASE-08, UX-052 | S |
| 9 | **Declare HPOS** on `before_woocommerce_init`, and replace the hardcoded `post.php?post=` link with `$order->get_edit_order_url()`. | PLATFORM-08, PLATFORM-11, RELEASE-02, FLOW-27 | T |
| 10 | **Fix the two silent-404 configurations.** Add a plain-permalink fallback for the standalone `ReturnURL`, and loop `activate()` over `get_sites()` on network activation plus `wp_initialize_site`. | PROTOCOL-07, PLATFORM-05, PLATFORM-06, CODE-10, UX-012 | S |

### P1 — Before the next release *(~4–6 weeks)*

| Theme | Work | Effort |
|---|---|---|
| **Virtual Account, end to end** | Deposit-notification endpoint (source-IP checked, signature verified, amount asserted, idempotent) · persist bank/account/expiry to order meta and render on thank-you page, on-hold email and My Account · expiry cron that cancels and restocks · refund-account fields for post-deposit cancels · KST-correct `VbankExpDate` | L |
| **Blocks checkout** | `AbstractPaymentMethodType` subclass + client `registerPaymentMethod()`; `process_payment()`'s redirect response already works unchanged | L |
| **Refund integrity** | Route the admin Cancel through `wc_create_refund()` · fresh unique cancel `Moid` · capture and reuse `OTID`/`RemainAmt` · `refunded_amount` column · read `CcPartCl`/`ClickpayCl` before offering a partial cancel · round rather than truncate KRW · compare the returned `CancelAmt` | M |
| **Schema & reconciliation** | Add `currency`, `mid`, `mode`, `refunded_amount`, `otid` columns · index `created_at` · stale-`pending` cleanup cron · transaction detail view · totals strip and CSV export · a "linked order status" column | M |
| **Gate the impossible states** | Currency whitelist in `is_available()` + `needs_setup()` + admin notice · enforce ≥1 payment method in the sanitize callback · whitelist every registered setting · fail-safe `nicepay_mode` comparison | S |
| **Byte limits & required fields** | One `nicepay_clip()` helper applied to `BuyerName` (30), `BuyerTel` (20), `BuyerEmail` (60), `CancelMsg` (100), `MallUserID` (20 — and stop using the buyer's email) · derive `GoodsCl` from the cart | S |
| **Failure messaging** | Result-code → localized, actionable message map · never render raw PG or `WP_Error` text to a buyer · give every failure a retry action and a support reference · carry the reason through the redirect rather than the session | M |
| **i18n** | Regenerate the `.pot` with `wp i18n make-pot`, `msgmerge` all four locales, translate the 74 missing strings, recompile `.mo` · fix the two dead `_n()` calls · proofread `tr_TR` diacritics · CI gate that fails on `.pot` drift | S |
| **Test infrastructure** | Injectable HTTP transport on `NicePay_API` (unblocks all approval/cancel/net-cancel tests) · WordPress integration suite · remove the two `phpunit.xml` coverage exclusions · PHPCS + WPCS and a `.pot` freshness gate in CI · gate the release workflow on tests | L |
| **Documentation truth** | Correct the charset, VBANK deposit, `CcPartCl`, order-status and credential-filter contradictions · mark every API-REFERENCE parameter Sent / Received / Not implemented · add a "Known limitations" section · ship `docs/` in the release ZIP | S |

### P2 — Excellence *(ongoing)*

- **Prove it works:** readiness panel with live checks, a one-click sandbox test payment, and a copyable system report. Highest payoff-per-hour item in the whole review — it eliminates most week-one support load.
- **Make it extensible:** the plugin currently fires **zero** filters and **zero** actions. A dozen well-chosen hooks plus `wc_get_template()`-style template overrides turns every future customisation from a fork into a snippet.
- **Korean market fit:** installment configuration (`SelectQuota`/`ShopInterest`), simple-pay wallet surfacing and `ClickpayCl` capture, `LogoImage`/`SkinType` window branding, 현금영수증 receipt fields, tax breakdown, escrow.
- **Product surface:** shareable payment links with expiry and usage caps; open-amount donations; a receipt/record spine for standalone payments (identity, retrievable receipt, confirmation email, a fulfilment hook).
- **Fix the shortcode reference semantics** so editing a saved shortcode actually changes the pages using it — today both copy buttons emit a frozen snapshot, and a price change silently does nothing.
- **Rename the status vocabulary** (`cancelled` currently means *full refund*; `refunded` means *partial*) and show the linked WooCommerce order status alongside it.
- **Design-system consolidation:** one token set, `prefers-reduced-motion`, `prefers-color-scheme`, `:focus-within` on every custom control, and contrast fixes for the unselected states.
- **Distribution:** `readme.txt`, `Update URI:`, `SECURITY.md`, release checksums, version-consistency gating, `uninstall.php`.
- **Recurring billing (빌링)** — and until it exists, stop shipping a "Subscription" preset that charges once.

---

## 7. The documents

| Document | Covers | Findings |
|---|---|---:|
| [00-executive-summary.md](00-executive-summary.md) | Verdict, top 10, distribution, remediation plan | — |
| [01-findings-index.md](01-findings-index.md) | Every finding in one table, with refuted claims | 484 rows |
| [02-protocol-conformance.md](02-protocol-conformance.md) | NICEPAY 인증결제 v2.0.8 conformance: signatures, endpoints, parameters, result codes, encoding | 34 |
| [03-payment-flow-and-money-correctness.md](03-payment-flow-and-money-correctness.md) | Auth → approval → capture → refund lifecycle, state machine, amount integrity | 42 |
| [04-security.md](04-security.md) | Authentication, authorization, injection, SSRF, secrets, abuse resistance | 28 |
| [05-user-experience.md](05-user-experience.md) | Merchant admin UX, customer payment UX, visual design, accessibility, front-end robustness | 126 |
| [06-wordpress-woocommerce-platform.md](06-wordpress-woocommerce-platform.md) | HPOS, Blocks, hooks, capabilities, options, multisite, uninstall, platform conventions | 42 |
| [07-architecture-and-code-quality.md](07-architecture-and-code-quality.md) | Responsibility boundaries, duplication, data layer, error handling, extensibility, testability | 55 |
| [08-internationalization.md](08-internationalization.md) | Catalogue completeness, plural forms, encoding, locale formatting, translation quality | 21 |
| [09-testing.md](09-testing.md) | Coverage of critical paths, test quality, CI gates, and a prioritised backlog of tests to add | 37 |
| [10-documentation.md](10-documentation.md) | Accuracy against the code, completeness, contradictions, usability | 39 |
| [11-build-release-and-repo-hygiene.md](11-build-release-and-repo-hygiene.md) | Packaging, update channel, version consistency, CI/CD, supply chain, repo scaffolding | 21 |
| [12-path-to-first-class.md](12-path-to-first-class.md) | Journey dead-ends, unread surfaces, missing capabilities, and the roadmap to excellence | 40 |

---

*Review conducted against branch `main`, working tree clean, at commit `5855db1`. Every code quotation was read from that tree. Claims about WordPress and WooCommerce core behaviour are documented behaviour, not observed behaviour — no runtime was available. Start with [01-findings-index.md](01-findings-index.md) to navigate, or with §6 above to act.*
