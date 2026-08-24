# Documentation Review

The NicePay Payment Gateway ships roughly 3,200 lines of Markdown across nine files — README, CHANGELOG, CONTRIBUTING, CODE_OF_CONDUCT and five documents under `docs/` — and that corpus is, on the whole, unusually good for a WordPress gateway plugin: the language discipline is clean, the signature/encryption chapters are cross-verified against both the code and the vendor spec, and the per-method result codes are stated identically in three places and are all correct. This review is therefore not a takedown; it is a precision audit. It records **39 findings** — 2 high, 20 medium, 17 low — dominated by one recurring pattern: the documentation describes an *intended* product, and where the implementation stopped short, the prose did not. The most serious instances are a Virtual Account deposit-completion flow that three documents diagram but no code implements, and a production credential-hardening snippet in `CONFIGURATION.md` that is subtly broken while the *correct* version of the same snippet sits in `DEVELOPER-GUIDE.md`. The second pattern is duplication-driven drift: six topics are documented in three or four files each, and two of those copies have already diverged into outright contradictions.

> **Scope.** This document covers documentation accuracy, completeness and usability only. Code defects are covered in the sibling analysis documents; where a doc fix is best delivered by changing the code instead, that is stated in the recommendation.

---

## What this codebase does well

Before the findings, the things worth protecting. These are verified observations, not courtesies.

| # | Strength | Evidence |
|---|---|---|
| 1 | **Language discipline is genuinely clean.** A non-ASCII scan across all nine Markdown files returns only em-dashes. There is no leftover Turkish or Korean in the English docs, no machine-translated passage, no mixed-language sentence — despite a Turkish-influenced v1.x. | [CHANGELOG.md:49](../../CHANGELOG.md#L49) records "Mixed Turkish/English comments" as *removed*, and the removal held. |
| 2 | **The signature/encryption documentation is accurate and cross-verified.** All three documents state the six plaintext compositions correctly, they match the code exactly, they match the vendor spec table, and the worked example (EdiDate `20200622131021` → `475979a5…46bd`) is reproduced and then asserted in a test. | [API-REFERENCE.md:281-294](../../docs/API-REFERENCE.md#L281), [DEVELOPER-GUIDE.md:161-168](../../docs/DEVELOPER-GUIDE.md#L161), [ARCHITECTURE.md:353-378](../../docs/ARCHITECTURE.md#L353) vs [class-nicepay-api.php:74-125](../../includes/class-nicepay-api.php#L74); example at [API-REFERENCE.md:298-308](../../docs/API-REFERENCE.md#L298), asserted in [NicePaySignatureIntegrationTest.php](../../tests/unit/NicePaySignatureIntegrationTest.php). |
| 3 | **Per-method success codes are stated identically in three places and are all correct** — CARD `3001` / BANK `4000` / VBANK `4100` / CELLPHONE `A000` / SSG_BANK `0000` / GIFT_CULT `0000`, plus cancel codes `2001` **and** the easily-missed `2211`. | [README.md:147-154](../../README.md#L147), [USER-GUIDE.md:470-518](../../docs/USER-GUIDE.md#L470), [API-REFERENCE.md:312-330](../../docs/API-REFERENCE.md#L312) vs [class-nicepay-api.php:400-420](../../includes/class-nicepay-api.php#L400). |
| 4 | **The firewall requirement is documented at all** — which most WordPress gateway plugins omit entirely — with both the outbound host/IP set and the three inbound deposit-notification source IPs, every address matching the vendor spec. | [README.md:176-192](../../README.md#L176): `121.133.126.56`, `211.44.32.56`, `121.133.126.10/11`, `211.33.136.39`. |
| 5 | **`USER-GUIDE.md` is unusually well structured for a plugin doc** — a 38-line linked TOC, a mermaid onboarding flowchart, per-tab admin reference, per-method detail sections, and a Going Live checklist. A large number of its UI claims check out precisely: 5 settings tabs, 4 presets with the right amounts/colours/modes, 6 colour swatches, 1–30 day VBANK expiry, the status filter list, Cancel shown only for paid/waiting rows, live badge update without reload. | [USER-GUIDE.md](../../docs/USER-GUIDE.md) throughout. |
| 6 | **The `:has()` fallback troubleshooting claim is true and verifiable** — someone actually checked this before writing it down. | [USER-GUIDE.md:670](../../docs/USER-GUIDE.md#L670) vs [nicepay.css:116-117](../../assets/css/nicepay.css#L116) (`:has(input:checked)` **and** an `.is-selected` twin) and [nicepay.js:27-30](../../assets/js/nicepay.js#L27) which toggles the class. |
| 7 | **Security posture is described accurately rather than aspirationally.** The SSRF host allowlist, log redaction and `hash_equals()` timing-safe comparison are all real, and the Security Layers table does not overclaim on any of them. | [class-nicepay-api.php:130-152](../../includes/class-nicepay-api.php#L130), [:157-174](../../includes/class-nicepay-api.php#L157), [ARCHITECTURE.md:380-390](../../docs/ARCHITECTURE.md#L380). |
| 8 | **`CONTRIBUTING.md` is thorough on process** — branch naming, PR template, commit-message types, i18n examples, an explicit private security-disclosure route via GitHub Security Advisories, and a well-judged warning never to include MID or Merchant Key in bug reports. | [CONTRIBUTING.md:262](../../CONTRIBUTING.md#L262). |

Finding 2 in particular deserves emphasis: **documentation, code and vendor spec agree on the hardest part of the protocol.** That is the part most integrations get wrong, and it is right here.

---

## 1. Factual-error table

Every claim below was checked against the code cited in the right-hand column. This is the exhaustive list; each row links to the detailed finding that follows.

### 1.1 Claims that describe behaviour the code does not have

| ID | Doc location | The claim | The reality | Code reference |
|---|---|---|---|---|
| [DOCS-001](#docs-001) | USER-GUIDE.md:585-586 | `F[NicePay sends deposit notification]` → `G[Order status: Completed]` | No deposit-notification endpoint exists. Only one rewrite rule is registered. | [class-nicepay-gateway.php:371-378](../../includes/class-nicepay-gateway.php#L371), [nicepay-payment-gateway.php:203-207](../../nicepay-payment-gateway.php#L203) |
| [DOCS-001](#docs-001) | ARCHITECTURE.md:338 | `waiting --> paid : Deposit received` | Nothing ever moves a transaction from `waiting` to `paid`. | [class-nicepay-gateway.php:359](../../includes/class-nicepay-gateway.php#L359) |
| [DOCS-001](#docs-001) | DEVELOPER-GUIDE.md:407-425 | `NP->>WP: Deposit notification (INBOUND)` / "Order status: processing" | Same; the caveat at :432 comes after the diagram. | as above |
| [DOCS-004](#docs-004) | DEVELOPER-GUIDE.md:343-354 | "use the `nicepay_payment_form_template` filter" + working sample | The plugin fires **zero** filters and **zero** actions. `grep apply_filters\|do_action` → no hits. | [class-nicepay-gateway.php:210](../../includes/class-nicepay-gateway.php#L210) (bare `include`) |
| [DOCS-004](#docs-004) | README.md:199 | Developer Guide covers "Hooks, filters, customization" | There are no hooks and no filters. | as above |
| [DOCS-024](#docs-024) | DEVELOPER-GUIDE.md:477 | "Authentication request parameters" are logged | No call site logs the outbound auth form data. | [class-nicepay-gateway.php:179-208](../../includes/class-nicepay-gateway.php#L179) |
| [DOCS-024](#docs-024) | DEVELOPER-GUIDE.md:480 | "Signature verification results" are logged | Only *failures* are logged; successes are silent. | [class-nicepay-api.php:260](../../includes/class-nicepay-api.php#L260) |
| [DOCS-027](#docs-027) | ARCHITECTURE.md:441 | `nicepay_db_version` — "Database schema version" | Written once at activation, read nowhere. No migration mechanism exists. | [nicepay-payment-gateway.php:161](../../nicepay-payment-gateway.php#L161) |
| [DOCS-029](#docs-029) | DEVELOPER-GUIDE.md:83-93 | "After Transaction Saved" hook example | The code block's entire body is two comments; no such action exists. | [nicepay-functions.php:88](../../includes/nicepay-functions.php#L88) |
| [DOCS-036](#docs-036) | CONFIGURATION.md:76 | "Set `GoodsCl` (content/physical)" | `GoodsCl` is hardcoded to `'1'` in three places; there is no setting. | [class-nicepay-gateway.php:202](../../includes/class-nicepay-gateway.php#L202) |

### 1.2 Claims that are wrong about a value, name or mapping

| ID | Doc location | The claim | The reality | Code reference |
|---|---|---|---|---|
| [DOCS-003](#docs-003) | USER-GUIDE.md:550, :314; CONFIGURATION.md:246,258 | Successful order status = `completed` | `payment_complete()` resolves to `processing` for any order containing a shippable product. | [class-nicepay-gateway.php:380](../../includes/class-nicepay-gateway.php#L380) |
| [DOCS-005](#docs-005) | README.md:112 | `pay_method` default = "First enabled" | Default is empty → **all** enabled methods, buyer chooses. USER-GUIDE.md:420 says "All enabled". | [standalone-payment-form.php:38-39](../../templates/standalone-payment-form.php#L38) |
| [DOCS-005](#docs-005) | README.md / USER-GUIDE.md param tables | `currency` default = `KRW`; `button_color` default = `#2563eb` | `currency` default is `get_option('nicepay_currency','KRW')`; `button_color` code default is empty (`#2563eb` is the CSS token fallback). | [nicepay-payment-gateway.php:242-243](../../nicepay-payment-gateway.php#L242), [nicepay.css:9](../../assets/css/nicepay.css#L9) |
| [DOCS-007](#docs-007) | ARCHITECTURE.md:340-341 | `paid --> refunded : Full refund` / `paid --> cancelled : Full cancel` | Inverted. A **full** refund writes `cancelled`; only a **partial** refund writes `refunded`. DEVELOPER-GUIDE.md:226-227 has it right. | [class-nicepay-gateway.php:439,464-466](../../includes/class-nicepay-gateway.php#L439) |
| [DOCS-008](#docs-008) | API-REFERENCE.md:32-36 | All three phases use encoding "EUC-KR" | The plugin's default is `utf-8`, and this contradicts the same file's line 82. | [nicepay-payment-gateway.php:160](../../nicepay-payment-gateway.php#L160) |
| [DOCS-023](#docs-023) | README.md:63; USER-GUIDE.md:285; CONFIGURATION.md:216 | Find "**NicePay Payment**" in WooCommerce → Payments | That list shows `method_title` = "**NicePay**". "NicePay Payment" is the customer-facing checkout title. | [class-nicepay-gateway.php:19](../../includes/class-nicepay-gateway.php#L19) vs [:27](../../includes/class-nicepay-gateway.php#L27) |
| [DOCS-013](#docs-013) | API-REFERENCE.md:243 | Cancel `Moid` — "Cancel order ID (should be unique)" | The code passes the **original payment** Moid; repeated cancels reuse an identical value. | [class-nicepay-gateway.php:430,445](../../includes/class-nicepay-gateway.php#L430), [class-nicepay-transactions.php:207](../../admin/class-nicepay-transactions.php#L207) |
| [DOCS-014](#docs-014) | CHANGELOG.md:33 | Tabs: "General, API Credentials, Payment Methods, Shortcode reference" | Five tabs, and no tab is named "Shortcode reference": General, API Credentials, Payment Methods, **Shortcodes**, **Shortcode Generator**. | [class-nicepay-admin.php:140-161](../../admin/class-nicepay-admin.php#L140) |
| [DOCS-025](#docs-025) | ARCHITECTURE.md:148 | `NicePay_Admin` has `-render_shortcode_tab()` | No such method. The real ones are `render_shortcodes_tab()` and `render_shortcode_generator_tab()`. | [class-nicepay-admin.php:348,431](../../admin/class-nicepay-admin.php#L348) |
| [DOCS-026](#docs-026) | CONFIGURATION.md:387-389 | Three translation files (ko_KR, en_US, zh_CN) | Four ship; `tr_TR.po`/`.mo` are present and README.md:18 advertises Turkish. | `languages/` directory listing |
| [DOCS-034](#docs-034) | USER-GUIDE.md:449 | Phone "must be 7-20 digits (allows dashes, spaces, parentheses)" | `/^[\d\-+() ]{7,20}$/` bounds **total characters**, not digits, and also accepts `+`. | [standalone-payment-form.php:214](../../templates/standalone-payment-form.php#L214) |
| [DOCS-037](#docs-037) | README.md:172; USER-GUIDE.md:242 | Search by "TID, order ID, buyer name, product name" | The SQL searches `tid`, `moid`, `buyer_name`, `goods_name`. `wc_order_id` is **not** in the clause. | [nicepay-functions.php:186](../../includes/nicepay-functions.php#L186) |

### 1.3 Claims that overstate a guarantee

| ID | Doc location | The claim | The reality | Code reference |
|---|---|---|---|---|
| [DOCS-002](#docs-002) | CONFIGURATION.md:154-160 | Production credential-hardening recipe | The callback takes no `$value` and hard-returns `''`, permanently masking the stored option. DEVELOPER-GUIDE.md:450-456 has the correct form. | [class-nicepay-api.php:30-31](../../includes/class-nicepay-api.php#L30), [class-nicepay-gateway.php:81-83](../../includes/class-nicepay-gateway.php#L81) |
| [DOCS-009](#docs-009) | API-REFERENCE.md:389-403 | "Connection Timeout 5 seconds" alongside "the plugin uses `wp_remote_post()`" | Only `'timeout' => 30` (an overall/read budget) is set at all three call sites; no connect timeout is configured anywhere. | [class-nicepay-api.php:214,306,361](../../includes/class-nicepay-api.php#L214) |
| [DOCS-018](#docs-018) | README.md:14; USER-GUIDE.md:565 | Net cancel "prevent[s] the customer from being charged" | Fire-and-forget: the return value is discarded at all four call sites, never checked, never retried, never recorded. | [class-nicepay-api.php:226,239,252,263](../../includes/class-nicepay-api.php#L226) |
| [DOCS-032](#docs-032) | README.md:17 | Shortcode Generator "one-click copy" | The generator's handler has no fallback and no `.catch()`; on a non-HTTPS admin it does nothing at all, silently. | [class-nicepay-admin.php:830-841](../../admin/class-nicepay-admin.php#L830) |
| [DOCS-038](#docs-038) | USER-GUIDE.md:64,88; CONFIGURATION.md:42-44 | "Go to Settings → Permalinks and click Save Changes (this registers the payment return URL)" | `activate()` already does exactly this, in the correct order. The step is redundant — and the requirement that *does* matter is unstated. | [nicepay-payment-gateway.php:92-98](../../nicepay-payment-gateway.php#L92) |

### 1.4 Silent omissions with a concrete failure attached

| ID | Topic | Where it should be | Why it bites |
|---|---|---|---|
| [DOCS-006](#docs-006) | Pretty permalinks are **required** for the standalone endpoint | USER-GUIDE Requirements, CONFIGURATION Server Requirements | On "Plain" permalinks the buyer is authenticated and then lands on a 404. |
| [DOCS-010](#docs-010) | The approval-response `Amt` is **zero-padded** | API-REFERENCE.md:154,157 | "Normalising" it breaks every signature — and a signature failure fires a **net cancel**. |
| [DOCS-012](#docs-012) | `docs/` is not copied into the release ZIP | release.yml:47-58 | All five README documentation links are dead inside the distributed artefact. |
| [DOCS-013](#docs-013) | `OTID` is absent from the Cancel Response table | API-REFERENCE.md:257-273 | 2nd-and-later partial cancels have no documented path. |
| [DOCS-015](#docs-015) | `MallUserID` (REQUIRED for GIFT_CULT) has no row anywhere | API-REFERENCE.md | The one Culture Cash requirement that breaks a payment is prose-only. |
| [DOCS-031](#docs-031) | The standalone form embeds a nonce → full-page caching breaks it | USER-GUIDE / CONFIGURATION troubleshooting | Intermittent "Invalid request." with no documented cause. |
| [DOCS-020](#docs-020) | The required format of the `amount` attribute | All three parameter tables | `amount="10.000"` silently charges **10 KRW**. |
| [DOCS-021](#docs-021) | The SSRF host allowlist and its error string | ARCHITECTURE Security Layers, API-REFERENCE | A new NICEPAY host fails every approval with an error found in no document. |
| [DOCS-022](#docs-022) | Admin Cancel sets the WC order to `cancelled` with **no refund record** | USER-GUIDE.md:269-276 | Store reports still count the sale; stock is not restored. |
| [DOCS-039](#docs-039) | SSL/HTTPS requirement | README Requirements table | The bundled, first-read file omits a mandatory requirement. |

---

## 2. Detailed findings

**Severity legend:** `🔴 HIGH` · `🟠 MEDIUM` · `🟡 LOW`. Every finding below carries a CONFIRMED verdict — the quoted text and the cited code were both re-read during verification. Where a *consequence* could not be executed in this environment, a **Verification note** says so explicitly.

---

### High

<a id="docs-001"></a>
#### DOCS-001 · 🔴 HIGH — Three documents diagram an automatic VBANK deposit-completion path that does not exist

**Files:** [docs/USER-GUIDE.md:585-586](../../docs/USER-GUIDE.md#L585) · [docs/ARCHITECTURE.md:338](../../docs/ARCHITECTURE.md#L338) · [docs/DEVELOPER-GUIDE.md:407-432](../../docs/DEVELOPER-GUIDE.md#L407)

**Problem.** The "Virtual Account Special Flow" flowchart states:

```
    E -->|Yes, within deadline| F[NicePay sends deposit notification]
    F --> G[Order status: Completed]
```

`ARCHITECTURE.md:338` declares `waiting --> paid : Deposit received`, and the DEVELOPER-GUIDE sequence diagram does the same (`NP->>WP: Deposit notification (INBOUND)` / `Note over WP: Order status: processing<br/>Transaction status: paid`), walking it back only in a soft note *after* the diagram at line 432 ("the deposit notification handler needs to be configured with NicePay separately").

No deposit-notification endpoint exists. The plugin registers exactly one rewrite rule:

```php
add_rewrite_rule( '^nicepay-return/?$', 'index.php?nicepay_return=1', 'top' );   // nicepay-payment-gateway.php:203-207
```

The only VBANK branch terminates at `on-hold` and has no other outcome:

```php
$order->update_status( 'on-hold', sprintf( /* ... */ 'Awaiting virtual account deposit. Bank: %1$s, Account: %2$s, Expires: %3$s' /* ... */ ) );
// includes/class-nicepay-gateway.php:371-378
```

`status => 'paid'` is written only on the synchronous approval path ([class-nicepay-gateway.php:359](../../includes/class-nicepay-gateway.php#L359)), and `grep -rn "apply_filters\|do_action" --include='*.php'` returns zero hits, so no third party can hook it either.

**Impact.** A merchant who enables Virtual Account on the strength of the flowchart never receives a payment-received signal. Orders sit at On-Hold and transactions at `waiting` indefinitely; reconciliation must be done manually against NicePay's dashboard, and no merchant-facing document says so. Money is not lost — the deposit still reaches the merchant — so the damage is unfulfilled paid orders plus a support burden. *Merchant scenario:* a physical-goods store enables VBANK after reading the flowchart and discovers weeks later that deposited orders were never moved out of On-Hold.

**Recommendation.**
- `USER-GUIDE.md:585-586` → replace with `Merchant confirms deposit in NicePay merchant admin` / `Merchant marks the order paid manually`.
- Add a boxed warning to the Virtual Account section (`USER-GUIDE.md:486`) and the Payment Methods table row (`USER-GUIDE.md:146`): *"v2.0.0 issues virtual accounts but does NOT receive deposit notifications. Orders stay On-Hold until you complete them manually."*
- `ARCHITECTURE.md:338` → `waiting --> paid : Manual completion (no automatic deposit handler in v2.0.0)`.
- `DEVELOPER-GUIDE.md:420-421` → mark the INBOUND arrow **"NOT IMPLEMENTED — merchant must build this endpoint"** *inside* the diagram, not only in the note below it.
- Add the same one-liner to `README.md:11`.

---

<a id="docs-002"></a>
#### DOCS-002 · 🔴 HIGH — CONFIGURATION's credential-hardening filter drops the stored option and can silently disable the gateway

**Files:** [docs/CONFIGURATION.md:154-160](../../docs/CONFIGURATION.md#L154) vs [docs/DEVELOPER-GUIDE.md:450-456](../../docs/DEVELOPER-GUIDE.md#L450)

**Problem.** Two documents ship contradictory versions of the same recipe, and the broken one is in the guide merchants follow for go-live.

```php
// CONFIGURATION.md:154-156 — BROKEN
add_filter( 'option_nicepay_live_mid', function() {
    return defined( 'NICEPAY_LIVE_MID' ) ? NICEPAY_LIVE_MID : '';
} );

// DEVELOPER-GUIDE.md:450-451 — CORRECT
add_filter( 'option_nicepay_live_mid', function( $value ) {
    return defined( 'NICEPAY_LIVE_MID' ) ? NICEPAY_LIVE_MID : $value;
} );
```

The CONFIGURATION version takes no `$value` and hard-returns `''` when the constant is absent, permanently masking whatever is in the database.

**Impact.** If the constant is missing — a typo, a host-replaced `wp-config.php`, a staging clone, a definition placed below the "stop editing" line — then `NicePay_API::__construct` reads empty credentials ([class-nicepay-api.php:30-31](../../includes/class-nicepay-api.php#L30)) and:

```php
if ( ! $this->api->get_mid() || ! $this->api->get_merchant_key() ) { return false; }   // class-nicepay-gateway.php:81-83
```

`is_available()` returns false, so NicePay disappears from checkout with **no admin notice and no log entry**. Additionally, because the recipe uses `option_*` rather than `pre_option_*`, the constant's value is rendered into the Live MID input and written back to the database on the next Settings save — defeating the stated purpose of keeping credentials out of the DB:

```php
value="<?php echo esc_attr( get_option( 'nicepay_live_mid' ) ); ?>"   // admin/class-nicepay-admin.php:290, inside <form action="options.php">
```

Neither document mentions this round-trip.

**Recommendation.** Fix `CONFIGURATION.md:154-160` to accept and fall back to `$value`. Then make **one** document canonical (DEVELOPER-GUIDE) and have CONFIGURATION link to it. In the canonical snippet, switch to `pre_option_*` so the admin field cannot round-trip the secret:

```php
add_filter( 'pre_option_nicepay_live_mid', function ( $pre ) {
    return defined( 'NICEPAY_LIVE_MID' ) ? NICEPAY_LIVE_MID : $pre;
} );

add_filter( 'pre_option_nicepay_live_merchant_key', function ( $pre ) {
    return defined( 'NICEPAY_LIVE_MERCHANT_KEY' ) ? NICEPAY_LIVE_MERCHANT_KEY : $pre;
} );
```

State explicitly that with `option_*` the credential is echoed into the input at `admin/class-nicepay-admin.php:290` and re-saved.

---

### Medium

<a id="docs-003"></a>
#### DOCS-003 · 🟠 MEDIUM — Three docs say a successful order becomes "Completed"; it becomes "Processing" for any shippable order

**Files:** [docs/USER-GUIDE.md:550](../../docs/USER-GUIDE.md#L550), [:314](../../docs/USER-GUIDE.md#L314) · [docs/CONFIGURATION.md:246,258](../../docs/CONFIGURATION.md#L258) · [docs/ARCHITECTURE.md:206](../../docs/ARCHITECTURE.md#L206)

**Problem.**

```
docs/USER-GUIDE.md:550   - Order status set to **Completed** (or **On-Hold** for virtual accounts)
docs/USER-GUIDE.md:314       N -->|CARD/BANK/CELL| O[Order completed - Thank you page]
docs/CONFIGURATION.md:258   | CARD/BANK/CELL success | `completed` | Payment received |
```

The success path calls `$order->payment_complete( $tid );` ([class-nicepay-gateway.php:380](../../includes/class-nicepay-gateway.php#L380)) and never sets a status explicitly — only the VBANK branch does. WooCommerce's `payment_complete()` resolves to `processing` unless every line item needs no processing (virtual/downloadable), and the outcome is filterable via `woocommerce_payment_complete_order_status`.

**Impact.** Merchants selling physical goods see every paid order land on Processing and conclude the integration is half-broken. CONFIGURATION's "Order Status Mapping" table is the artefact an operations person builds a fulfilment workflow on, and it is wrong for the majority case. `DEVELOPER-GUIDE.md:98-104` compounds it by documenting the filter as a way to return `'processing'` *"Instead of 'completed'"*.

**Recommendation.**
- `USER-GUIDE.md:550` → *"Order status set by WooCommerce's `payment_complete()`: **Processing** for orders containing shippable products, **Completed** for fully virtual/downloadable orders, **On-Hold** for virtual accounts."*
- `USER-GUIDE.md:314` → `O[Order processing - Thank you page]`.
- `CONFIGURATION.md:258` → `| CARD/BANK/CELL success | processing (completed for virtual/downloadable orders) | Payment received |`, and update the mermaid node at 246-249 to match.
- `DEVELOPER-GUIDE.md:102` → change the comment from `// Instead of 'completed'` to `// Force a specific status regardless of item types`.

> **Verification note (needs confirmation on one point).** The doc text and the code path are confirmed. WooCommerce could not be executed here, so the exact resolved status depends on WC internals and on any `woocommerce_payment_complete_order_status` filter present on the site. The semantics quoted are standard and long-established.

---

<a id="docs-004"></a>
#### DOCS-004 · 🟠 MEDIUM — DEVELOPER-GUIDE presents `nicepay_payment_form_template` as a working filter; the plugin fires no filters or actions at all

**Files:** [docs/DEVELOPER-GUIDE.md:343-356](../../docs/DEVELOPER-GUIDE.md#L343) · [README.md:199](../../README.md#L199)

**Problem.** Line 343: *"The plugin uses a direct `include` to load `templates/payment-form.php`. To override it, use the `nicepay_payment_form_template` filter…"*, followed by an eight-line "Option 1" sample beginning `add_filter( 'nicepay_payment_form_template', function( $template_path ) {`.

```
$ grep -rn "apply_filters\|do_action" --include='*.php' .
(no output)
```

The plugin exposes **no filters and no actions**. The template load is bare:

```php
include NICEPAY_PLUGIN_DIR . 'templates/payment-form.php';   // includes/class-nicepay-gateway.php:210
```

The doc's own hedge at line 356 ("This filter needs the gateway to apply it") is placed *after* the sample and is ambiguous enough to read as "the gateway applies it". `README.md:199` advertises the Developer Guide as covering *"Hooks, filters, customization"*.

**Impact.** A developer copies the filter, ships it, and the template override silently never applies — they debug WordPress rather than the plugin. Anyone evaluating the plugin for a customised store believes an extension surface exists that does not.

**Recommendation.** *Preferred — make the doc true:*

```php
$template = apply_filters( 'nicepay_payment_form_template', NICEPAY_PLUGIN_DIR . 'templates/payment-form.php', $order );
include $template;
```

plus the equivalent for the standalone template, then document a hook table (`nicepay_payment_form_template`, `nicepay_auth_form_data`, `nicepay_transaction_saved`, `nicepay_approval_result`). *Otherwise:* delete lines 343-354, replace with an "Extension points" section stating plainly that v2.0.0 exposes no plugin-defined hooks and listing the WordPress/WooCommerce hooks that are the only seams, and change `README.md:199` to *"Customization, API class usage, and extension points"*.

---

<a id="docs-005"></a>
#### DOCS-005 · 🟠 MEDIUM — README and USER-GUIDE give contradictory defaults for `pay_method`

**Files:** [README.md:112](../../README.md#L112) vs [docs/USER-GUIDE.md:420](../../docs/USER-GUIDE.md#L420)

**Problem.**

```
README.md:112       | `pay_method` | No | First enabled | `CARD`, `BANK`, `VBANK`, `CELLPHONE`, `SSG_BANK`, `GIFT_CULT` |
USER-GUIDE.md:420   | `pay_method` | No | All enabled   | Specific method: … |
```

The code agrees with USER-GUIDE — `'pay_method' => ''` ([nicepay-payment-gateway.php:239](../../nicepay-payment-gateway.php#L239)) and:

```php
$show_method_selector = empty( $pay_method ) && count( $enabled_methods ) > 1;   // templates/standalone-payment-form.php:38
$default_method       = $pay_method ? $pay_method : $enabled_methods[0];         // :39
```

**Impact.** README is the first document read and the only one bundled into the release ZIP. A merchant either adds a redundant `pay_method` attribute or designs a page around a single-method form and gets a method selector instead.

**Recommendation.** Fix `README.md:112` to `| \`pay_method\` | No | All enabled (buyer chooses) | … |`. Eliminate the duplication: keep the canonical table in `USER-GUIDE.md:415-429` and reduce README to a three-row teaser plus a link. While there, fix two more drifted defaults in **both** tables: `currency` is not literally `KRW` but `get_option('nicepay_currency','KRW')` ([nicepay-payment-gateway.php:243](../../nicepay-payment-gateway.php#L243)), and `button_color`'s code default is empty ([:242](../../nicepay-payment-gateway.php#L242)) — `#2563eb` is the CSS token fallback ([nicepay.css:9](../../assets/css/nicepay.css#L9)), not the attribute default.

---

<a id="docs-006"></a>
#### DOCS-006 · 🟠 MEDIUM — No document states that pretty permalinks are required; the standalone return endpoint 404s under "Plain"

**Files:** [docs/USER-GUIDE.md:64](../../docs/USER-GUIDE.md#L64), [:88](../../docs/USER-GUIDE.md#L88) · [docs/CONFIGURATION.md:42-44](../../docs/CONFIGURATION.md#L42)

**Problem.** Every setup path says only:

```
5. Go to **Settings > Permalinks** and click **Save Changes** (this registers the payment return URL)
```

None says the site must use a permalink structure other than **Plain**. The standalone return URL is a rewrite rule ([nicepay-payment-gateway.php:203-207](../../nicepay-payment-gateway.php#L203)), and both the form `action` and the `ReturnURL` posted to NicePay are `home_url( '/nicepay-return/' )`:

```php
<input type="hidden" name="ReturnURL" value="<?php echo esc_url( home_url( '/nicepay-return/' ) ); ?>">
// templates/standalone-payment-form.php:136 (form action at :127)
```

When `permalink_structure` is empty, `WP_Rewrite::rewrite_rules()` returns an empty array and no rewrite rule is evaluated.

**Impact.** On a Plain-permalink site the buyer completes card authentication — the authorisation exists at the issuer — and lands on a 404. Approval never runs, net-cancel never runs, and the transaction row stays `pending` with no error surfaced. The documented remedy ("flush rewrite rules") cannot fix it, because flushing does not create a permalink structure. Note the **WooCommerce checkout path is unaffected** on Plain permalinks: `WC()->api_request_url()` falls back to `?wc-api=nicepay_return`.

**Recommendation.**
- Add a row to the Requirements table (`USER-GUIDE.md:51-56`) and to CONFIGURATION's Server Requirements (`:415-421`): `Permalinks | Any structure except "Plain" | The /nicepay-return/ standalone endpoint is a rewrite rule`.
- Rewrite `USER-GUIDE.md:64` / `CONFIGURATION.md:44` to *"Confirm Settings → Permalinks is set to any structure other than Plain, then click Save Changes."*
- Add a troubleshooting row (`USER-GUIDE.md:665-675`): *"Buyer sees a 404 after authenticating (shortcode payments) → permalinks are set to Plain."*
- Optional code fix worth documenting: fall back to `home_url( '/?nicepay_return=1' )` when `get_option('permalink_structure')` is empty — the `nicepay_return` query var is already registered at [nicepay-payment-gateway.php:208-211](../../nicepay-payment-gateway.php#L208), so that URL would work.

> **Verification note (needs confirmation on exposure).** The documentation gap is confirmed — no `.md` file anywhere mentions the permalink *structure*. WordPress could not be executed here; the 404 conclusion follows from `WP_Rewrite` returning no rules when `permalink_structure` is empty. Exposure is narrower than it first appears: since WP 4.2, new installs select a pretty structure automatically when the server supports rewrites, so the risk is limited to sites explicitly on Plain or without rewrite support.

---

<a id="docs-007"></a>
#### DOCS-007 · 🟠 MEDIUM — ARCHITECTURE's status lifecycle inverts refunded/cancelled and contradicts DEVELOPER-GUIDE

**Files:** [docs/ARCHITECTURE.md:340-341](../../docs/ARCHITECTURE.md#L340) vs [docs/DEVELOPER-GUIDE.md:226-227](../../docs/DEVELOPER-GUIDE.md#L226)

**Problem.**

```
docs/ARCHITECTURE.md:340    paid --> refunded : Full refund
docs/ARCHITECTURE.md:341    paid --> cancelled : Full cancel
```

The code does the opposite for refunds:

```php
$is_partial = ( (float) $cancel_amt < (float) $total_amt );   // includes/class-nicepay-gateway.php:439
nicepay_update_transaction( $transaction->id, array(
    'status' => $is_partial ? 'refunded' : 'cancelled',       // :464-466
) );
```

A **full** WooCommerce refund writes `cancelled`; only a **partial** refund writes `refunded`. `DEVELOPER-GUIDE.md:226-227` documents it correctly.

**Impact.** Anyone filtering the Transactions list or writing reconciliation queries from ARCHITECTURE mis-classifies every full refund: full refunds vanish from a "refunded" report and inflate a "cancelled" report. Two documents give opposite answers for the same column.

**Recommendation.** `ARCHITECTURE.md:340-341` → `paid --> cancelled : Full refund or admin cancel` and `paid --> refunded : Partial refund`. Designate `DEVELOPER-GUIDE.md:220-228` the single canonical status table and have ARCHITECTURE link to it. Note in both that `cancelled` is **overloaded** — full refund and admin cancel are indistinguishable; a `refunded_amount` column would make the Transactions screen materially more useful.

---

<a id="docs-008"></a>
#### DOCS-008 · 🟠 MEDIUM — Charset is documented as a site-encoding choice, but the plugin never transcodes

**Files:** [docs/USER-GUIDE.md:117](../../docs/USER-GUIDE.md#L117) · [docs/CONFIGURATION.md:55](../../docs/CONFIGURATION.md#L55) · [docs/API-REFERENCE.md:32-36](../../docs/API-REFERENCE.md#L32)

**Problem.** Both guides present the option as an ordinary site-encoding toggle:

```
USER-GUIDE.md:117    | **Charset** | UTF-8 / EUC-KR | Character encoding. Use UTF-8 unless your site specifically requires EUC-KR. |
CONFIGURATION.md:55  | Charset | `utf-8` | Use `euc-kr` only if your site uses EUC-KR encoding |
```

`grep -rni "mb_convert_encoding|iconv" --include='*.php' .` returns zero hits. The option only sets a **label** — the `CharSet` body field, the `Content-Type: …; charset=` header on the three server-side POSTs, and the `accept-charset` attribute on the two browser forms:

```php
'headers'   => array(
    'Content-Type' => 'application/x-www-form-urlencoded; charset=' . $this->charset,
),   // includes/class-nicepay-api.php:216-218 (also :318-320, :363-365)
```

Separately, `API-REFERENCE.md:32-36` asserts all three phases use "EUC-KR", contradicting its own optional-parameter table at line 82 (`CharSet | 10 | euc-kr (default) / utf-8`) and the plugin's actual default of `utf-8` ([nicepay-payment-gateway.php:160](../../nicepay-payment-gateway.php#L160)).

**Impact.** Selecting EUC-KR makes the **server-side cancel request** declare EUC-KR while sending UTF-8 bytes, so a Korean `CancelMsg` reaches NicePay as mojibake and appears corrupted in the merchant admin. Because `SignData` covers only amount/date fields, signatures still verify and cancels still succeed, so nothing surfaces the corruption. The API-REFERENCE encoding table is simply wrong about what the plugin sends.

**Recommendation.** Rewrite the Charset row in both guides: *"Leave this on UTF-8. The plugin does not transcode payloads — selecting EUC-KR only relabels UTF-8 data and will corrupt Korean text in server-side fields such as the cancel reason. Use EUC-KR only for a MID explicitly provisioned for it, and only with a transcoding patch."* Fix `API-REFERENCE.md:32-36` to read `utf-8 (plugin default — the value of the Charset setting is sent as CharSet); NicePay's protocol default is euc-kr`. Long term: either apply `mb_convert_encoding( $value, 'EUC-KR', 'UTF-8' )` to the outbound body when euc-kr is selected, or remove the option.

> **Scope correction.** `GoodsName`/`BuyerName` are **not** affected: they are submitted by the browser from forms carrying `accept-charset="<charset>"` ([payment-form.php:51](../../templates/payment-form.php#L51), [standalone-payment-form.php:128](../../templates/standalone-payment-form.php#L128)), and browsers honour that. The genuine mislabelling is confined to the PHP-built server-side requests, whose only free-text field is `CancelMsg` ([class-nicepay-api.php:346](../../includes/class-nicepay-api.php#L346)).

**Spec reference.** NICEPAY-SPEC-REF §4: `CharSet(10: utf-8 / euc-kr default)`; §6 approval-request encoding is EUC-KR unless `CharSet` says otherwise.

---

<a id="docs-009"></a>
#### DOCS-009 · 🟠 MEDIUM — API-REFERENCE states NicePay's 5s connect-timeout requirement, then shows an implementation that sets none

**File:** [docs/API-REFERENCE.md:389-403](../../docs/API-REFERENCE.md#L389)

**Problem.**

```
## Timeout Configuration

| Connection Timeout | Read Timeout |
|---|---|
| 5 seconds | 30 seconds |

The plugin uses WordPress `wp_remote_post()` with a 30-second timeout for all API calls:
```

The juxtaposition reads as a compliance statement. The plugin sets only an overall budget, at all three call sites, and configures nothing connect-specific:

```php
$response = wp_remote_post( $next_app_url, array(
    'timeout'   => 30,
    'sslverify' => true,
// includes/class-nicepay-api.php:213-215 — identical at :305-307 and :360-362
```

No `http_api_curl` / Requests connect-timeout hook exists anywhere in the plugin.

**Impact.** An integrator reading this believes the plugin meets the vendor's mandated timeouts. It does not: a TCP-level outage at a NicePay data centre blocks a PHP worker far longer than the spec allows, in precisely the window where net-cancel must run promptly.

**Recommendation.** Split the section into **"NicePay requirement"** and **"What this plugin does"**: state that only a 30-second overall timeout is set, name the three call sites, and state that the effective connect timeout is whatever the active WordPress HTTP transport defaults to — **not 5 seconds**. Then give the remedy, or implement it in `NicePay_API` and claim compliance truthfully:

```php
add_action( 'http_api_curl', function ( $handle, $args, $url ) {
    if ( false !== strpos( $url, '.nicepay.co.kr' ) ) {
        curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 5 );
    }
}, 10, 3 );
```

> **Mechanism correction.** The effective connect timeout is not necessarily 30s: modern WordPress routes through the Requests library, whose own `connect_timeout` default (10s) applies unless overridden, while the legacy cURL transport set `CURLOPT_CONNECTTIMEOUT` equal to `timeout`. Either way it is not the mandated 5s.

**Spec reference.** NICEPAY-SPEC-REF §2: *"Timeouts: Connection 5 sec, Receive(Read) 30 sec"* (MUST).

---

<a id="docs-010"></a>
#### DOCS-010 · 🟠 MEDIUM — The zero-padded approval-response `Amt` — the known NicePay signature trap — is documented nowhere

**Files:** [docs/API-REFERENCE.md:154](../../docs/API-REFERENCE.md#L154), [:157](../../docs/API-REFERENCE.md#L157) · [docs/DEVELOPER-GUIDE.md:161-168](../../docs/DEVELOPER-GUIDE.md#L161)

**Problem.**

```
API-REFERENCE.md:154   | `Amt` | 12 | Transaction amount |
API-REFERENCE.md:157   | `Signature` | 500 | `hex(sha256(TID + MID + Amt + MerchantKey))` |
```

Nothing says NicePay returns `Amt` **zero-padded** (`000000001004`), and nothing says which `Amt` participates in the signature. `grep -in "pad" docs/API-REFERENCE.md` returns nothing; DEVELOPER-GUIDE's signature table says only "TID + MID + Amt + Key". The code resolves the ambiguity correctly but silently:

```php
$amt = isset( $result['Amt'] ) ? $result['Amt'] : $auth_data['Amt'];              // includes/class-nicepay-api.php:258
if ( ! $this->verify_approval_signature( $result['TID'], $amt, $result['Signature'] ) ) {   // :259
```

**Impact.** This is the classic NicePay integration failure. A maintainer who "normalises" the amount — casts to int, or reuses the request `Amt` — makes every approval signature fail, and per [class-nicepay-api.php:260-266](../../includes/class-nicepay-api.php#L260) a signature failure fires a **net cancel**, reversing a payment that actually succeeded. The one landmine in the protocol is absent from the reference whose job is to flag it.

**Recommendation.** Annotate `API-REFERENCE.md:154` as `| \`Amt\` | 12 | Transaction amount, zero-padded to 12 digits (e.g. \`000000001004\`) |` and add a callout under **both** signature tables:

> `Amt` in the approval-response signature is the value **exactly as returned by NicePay** (zero-padded). Do not trim, cast, or substitute the amount you sent. The auth-response signature, by contrast, uses the **unpadded** `Amt` you submitted. See `includes/class-nicepay-api.php:258`.

Add a padded-`Amt` case to `tests/unit/NicePaySignatureIntegrationTest.php` and reference it from the doc.

**Spec reference.** NICEPAY-SPEC-REF §3 NOTE: the padding discrepancy between signature examples (unpadded `"1004"`) and the approval-response field (`"000000001004"`) is a known integration trap.

---

<a id="docs-011"></a>
#### DOCS-011 · 🟠 MEDIUM — CONTRIBUTING documents no way to run the CI-gated test suite and mandates tabs no file uses

**Files:** [CONTRIBUTING.md:47-60](../../CONTRIBUTING.md#L47), [:122-128](../../CONTRIBUTING.md#L122), [:144](../../CONTRIBUTING.md#L144) · [docs/DEVELOPER-GUIDE.md:491-509](../../docs/DEVELOPER-GUIDE.md#L491)

**Problem.** The repo ships `composer.json` with `test` / `test-coverage` / `test-filter` scripts, `phpunit.xml`, four test files, and a PHP 7.4–8.3 CI matrix that gates PRs on `composer test` ([tests.yml:46](../../.github/workflows/tests.yml#L46)). Yet:

```
$ grep -rn -i "composer\|phpunit\|unit test" --include='*.md' .
(no matches)
```

CONTRIBUTING's Local Setup (47-60) is clone + symlink only; its PR checklist (122-128) has no "tests pass" item; DEVELOPER-GUIDE's section titled "Testing" (491-509) covers NicePay **test credentials**, not the test suite. Separately, `CONTRIBUTING.md:144` mandates *"Use tabs for indentation"* while `grep -rlP '\t' --include='*.php' .` returns **no files** — the entire PHP codebase uses 4 spaces.

**Impact.** A contributor follows CONTRIBUTING exactly, opens a PR, and CI fails on a suite they were never told exists and cannot run locally. Obeying the tab rule reformats every line they touch and conflicts with the whole codebase. Both frictions are manufactured by the docs.

**Recommendation.** Add a "Running the tests" block to CONTRIBUTING's Development Setup:

```bash
composer install
composer test                       # phpunit --configuration phpunit.xml
composer test-coverage              # HTML report in tests/coverage/
composer test-filter NicePaySignature
find . -name '*.php' -not -path './vendor/*' -not -path './tests/*' -print0 | xargs -0 -n1 php -l   # mirrors CI lint job, tests.yml:77-79
```

Add `- [ ] \`composer test\` passes` to the checklist at `CONTRIBUTING.md:122-128`. Change `:144` to *"Use 4 spaces for indentation (matches the existing codebase)"* and ship a `phpcs.xml` so the rule is enforced rather than aspirational. Rename `DEVELOPER-GUIDE.md:491` to **"NicePay Test Environment"** and add a real "Unit Tests" section covering `tests/bootstrap/wp-stubs.php`.

> **Verification note.** PHP/Composer were unavailable in this environment, so whether `composer test` currently passes is untested. The documentation gap is independent of that.

---

<a id="docs-012"></a>
#### DOCS-012 · 🟠 MEDIUM — The release ZIP omits `docs/`, so all five documentation links in the shipped README are dead

**Files:** [.github/workflows/release.yml:47-58](../../.github/workflows/release.yml#L47) · [README.md:194-200](../../README.md#L194)

**Problem.** The workflow copies `admin/ assets/ includes/ templates/ languages/`, the main PHP file, `README.md`, `LICENSE` and `CHANGELOG.md` into the build directory. **`docs/` is not copied.** `README.md:196-200` links to all five `docs/*.md` files as *relative* paths — all broken inside the distributed artefact, with no fallback to GitHub. `README.md:31-44` directs merchants to install exactly that way.

**Impact.** The primary installation path yields a plugin whose only in-package documentation is a README of five dead links and no route to the ~2,600 lines of guidance that exist. `git tag -l` is empty, so the release workflow (triggered on `v*` tags) has **never run**, and "Download the latest release" currently points at nothing.

**Recommendation.** Add `cp -r docs/ build/${PLUGIN_SLUG}/` after `release.yml:55`. Change `README.md:196-200` to absolute URLs so the links survive in any context. Cut the first tag (`git tag v2.0.0 && git push --tags`) so the documented install path exists, and add a Releases link to README's Installation section.

---

<a id="docs-013"></a>
#### DOCS-013 · 🟠 MEDIUM — API-REFERENCE's Cancel Response omits `OTID`, and its "Moid should be unique" rule is not what the plugin does

**Files:** [docs/API-REFERENCE.md:243](../../docs/API-REFERENCE.md#L243), [:257-273](../../docs/API-REFERENCE.md#L257) · [docs/USER-GUIDE.md:349-357](../../docs/USER-GUIDE.md#L349)

**Problem.** Two related gaps.

1. The Cancel Response table ends at `RemainAmt` with no `OTID` row and no `PayMethod` / `MallReserved`, although the spec returns `OTID` for mobile partial cancel/refund and requires it as the TID for the 2nd and later partial cancels. `grep -rn "OTID" --include='*.php' .` returns nothing — the plugin never reads or stores it, and neither the reference nor USER-GUIDE's Partial Refund section mentions the constraint.
2. `API-REFERENCE.md:243` documents cancel `Moid` as *"Cancel order ID (should be unique)"*, but the code passes the original **payment** Moid:

```php
$moid   = $order->get_meta( '_nicepay_moid' );                                   // class-nicepay-gateway.php:430
$result = $this->api->request_cancel( $tid, $cancel_amt, $reason, $moid, $is_partial );   // :445
```

and `admin/class-nicepay-transactions.php:207` passes `$transaction->moid`. Repeated cancels therefore reuse an identical Moid.

**Impact.** A merchant attempting a second partial refund has no documented warning and, if NicePay enforces the OTID rule, hits an opaque error explained in no document. The reused cancel Moid also breaks per-cancel addressability on NicePay's side, which the reference itself says is required.

**Recommendation.** Add to the Cancel Response table: `| \`OTID\` | 30 | Returned for mobile partial cancel/refund; must be used as the TID for the 2nd and later partial cancels |`, plus `PayMethod` and `MallReserved`. Add a bold caution to `USER-GUIDE.md:349-357`: *"v2.0.0 supports a single partial refund per transaction. Additional partial refunds require OTID handling, which is not implemented — issue them from the NicePay merchant admin."* Fix the code to mint a fresh cancel Moid (`$this->api->generate_moid( 'CX' . $order->get_id() )`) and persist `OTID` from the cancel response, then update both documents to match.

> **Verification note (needs confirmation on consequence).** Both doc omissions and both code sites are confirmed. Whether NICEPAY actually rejects a second partial cancel without `OTID`, or rejects a reused `Moid`, is not verifiable from the repo — the spec digest scopes OTID to mobile partial cancel/refund, so the failure may be method-specific.

**Spec reference.** NICEPAY-SPEC-REF §9: `Moid(64)` is a merchant-issued **unique CANCEL** order no; response includes `OTID(30)`, and 2nd+ partial cancels MUST use it.

---

<a id="docs-014"></a>
#### DOCS-014 · 🟠 MEDIUM — CHANGELOG misnames the admin tabs, omits the two most recent feature commits, and points at a 1.x history that does not exist

**File:** [CHANGELOG.md:1-5](../../CHANGELOG.md#L1), [:33](../../CHANGELOG.md#L33), [:63-65](../../CHANGELOG.md#L63)

**Problem.** Four defects.

1. Line 33: `- **Multi-tab Admin Settings** — General, API Credentials, Payment Methods, Shortcode reference`. There are **five** tabs and no tab named "Shortcode reference": General, API Credentials, Payment Methods, **Shortcodes**, **Shortcode Generator** ([class-nicepay-admin.php:140-161](../../admin/class-nicepay-admin.php#L140)).
2. `git log --oneline -- CHANGELOG.md` shows the last touch was `891c7cc`, while `fcde0c6` ("optimize shortcode generation and enhance accessibility in admin UI" — touching the main file, both admin classes, `nicepay-functions.php`, the standalone template and USER-GUIDE.md) and `3bf38d6` ("enhance loading state handling and improve notice display logic") landed **after** it with no entry and no version bump. Still `2.0.0` in the header, in `NICEPAY_VERSION`, and in the `.pot`.
3. No Keep a Changelog / SemVer statement, no `[Unreleased]` section, no compare links.
4. Lines 63-65: `## [1.x] - Previous Versions` … *"See git history for details"* — the repo has 14 commits on one day and no 1.x history.

**Impact.** The changelog is what users read before upgrading, and `release.yml` extracts release-note bodies from it by matching `^## \[VERSION\]`, so every inaccuracy propagates verbatim into GitHub release bodies.

**Recommendation.** (1) Fix line 33 to the five real tab names. (2) Add `## [Unreleased]` capturing `fcde0c6` and `3bf38d6`, or cut 2.0.1 and bump the header, `NICEPAY_VERSION` ([nicepay-payment-gateway.php:22](../../nicepay-payment-gateway.php#L22)) and the `.pot` `Project-Id-Version` together. (3) Add the standard Keep-a-Changelog header line plus `[2.0.0]: …/releases/tag/v2.0.0` link definitions. (4) Replace the 1.x stub with *"2.0.0 is the first public release; earlier iterations were never tagged"*, or delete it. (5) Add an Upgrade Notes entry for the removed donation shortcode (line 48) — a breaking change currently shipped with no migration instruction.

---

<a id="docs-015"></a>
#### DOCS-015 · 🟠 MEDIUM — API-REFERENCE claims to describe the plugin but never marks which parameters it sends, and omits the required GIFT_CULT parameter

**File:** [docs/API-REFERENCE.md:3](../../docs/API-REFERENCE.md#L3), [:69-104](../../docs/API-REFERENCE.md#L69), [:239-255](../../docs/API-REFERENCE.md#L239)

**Problem.** Line 3 frames the document as *"the NicePay Authenticated Payment API (v2.0.8) **as implemented in this plugin**"*. It then documents parameters the plugin never sends — `ReqReserved`, `LogoImage`, `SkinType`, `ConnWithIframe`, `SelectQuota`, `SelectCardCode`, `ShopInterest`, `QuotaInterest`, `EdiType`, `SupplyAmt`, `GoodsVat`, `ServiceAmt`, `TaxFreeAmt`, `RefundAcctNo`/`RefundBankCd`/`RefundAcctNm` — with nothing to distinguish them from the ~13 fields actually emitted. `grep -rn "SelectQuota\|ShopInterest\|LogoImage\|SkinType" --include='*.php' .` returns no matches; `ReqReserved` appears only as an ignored inbound read at [class-nicepay-gateway.php:231](../../includes/class-nicepay-gateway.php#L231).

Conversely there is **no GIFT_CULT section at all**, so `MallUserID` — REQUIRED by the spec and actually sent — has no row anywhere in the parameter reference:

```php
// Culture Cash requires MallUserID
if ( in_array( 'GIFT_CULT', $enabled_methods, true ) ) { $form_data['MallUserID'] = $order->get_billing_email(); }
// includes/class-nicepay-gateway.php:205-208 (also standalone-payment-form.php:153)
```

It appears only as prose at `USER-GUIDE.md:517`.

**Impact.** A developer reads the `SelectQuota` row, assumes installments are wired up, and discovers only by reading source that nothing sends it. Meanwhile the one Culture Cash requirement that will break a payment has no entry in the reference.

**Recommendation.** Add a **`Sent by plugin`** column (Yes / No — extension point) to every request-parameter table; mark VBANK's `VbankExpDate` and CELLPHONE's `GoodsCl` as Yes and the CARD extras as No. Add a "Culture Cash (GIFT_CULT) Extra Parameters" section (`MallUserID` 20, **Required** — set to the buyer email; `UserCI` optional) and an SSG_BANK note. Add a short "Not yet supported by this plugin" list at the top. Also add the approval-response fields the reference omits: `BuyerName`/`BuyerTel`/`BuyerEmail`, `GoodsName`, `CartData`, `MallReserved`, and the CARD extras `CouponAmt`, `PointAppAmt`, `Multi*`.

**Spec reference.** NICEPAY-SPEC-REF §4 GIFT_CULT extras: `MallUserID` REQUIRED.

---

<a id="docs-016"></a>
#### DOCS-016 · 🟠 MEDIUM — No documentation of HPOS, block checkout, multisite, uninstall, schema migration, or ResultCode troubleshooting

**Files:** [README.md:22-27](../../README.md#L22) · [docs/DEVELOPER-GUIDE.md:40-52](../../docs/DEVELOPER-GUIDE.md#L40) · [docs/API-REFERENCE.md:338](../../docs/API-REFERENCE.md#L338)

**Problem.** Six topics are absent from all ~2,600 lines of docs.

| # | Topic | Evidence |
|---|---|---|
| 1 | **HPOS** | `grep -rn "declare_compatibility\|before_woocommerce_init\|custom_order_tables" --include='*.php'` → nothing. WooCommerce will flag the plugin incompatible, and the Transactions screen links orders via `admin_url( 'post.php?post=' . $item->wc_order_id . '&action=edit' )` ([class-nicepay-transactions.php:126](../../admin/class-nicepay-transactions.php#L126)), which does not resolve under HPOS. |
| 2 | **Checkout Blocks** | No `AbstractPaymentMethodType` registration exists; no doc says the classic checkout is required. |
| 3 | **Multisite** | `create_tables()` ([nicepay-payment-gateway.php:104-148](../../nicepay-payment-gateway.php#L104)) creates the table for the current site only; network activation is undocumented. |
| 4 | **Uninstall** | No `uninstall.php`. The only mention is one aside at `DEVELOPER-GUIDE.md:52`, with no list of what is left behind. |
| 5 | **Migrations** | `nicepay_db_version` is written at [:161](../../nicepay-payment-gateway.php#L161) and read nowhere. |
| 6 | **ResultCode troubleshooting** | `API-REFERENCE.md:338` defers to *"the separate 'Result Code' document provided by NicePay"* with no link, while admins are shown raw codes ([class-nicepay-gateway.php:399-404](../../includes/class-nicepay-gateway.php#L399)). |

**Impact.** HPOS and Blocks are the two most common reasons a modern WooCommerce store cannot use a gateway, and a merchant can only discover them by installing and failing. Without an uninstall procedure the plugin leaves a table and twelve options behind permanently. Without a ResultCode table, the message an admin actually sees ("Code: F111") cannot be turned into an action.

**Recommendation.** Add a **Compatibility** table to README: *HPOS — not declared, use legacy post storage; Cart/Checkout Blocks — not supported, use the classic `[woocommerce_checkout]` shortcode; Multisite — activate per site, not network-wide.* Add a DEVELOPER-GUIDE "Uninstalling" section naming the exact table (`{$wpdb->prefix}nicepay_transactions`) and all twelve option keys from `set_default_options()` ([:150-163](../../nicepay-payment-gateway.php#L150)), and ship an `uninstall.php` gated on a documented `nicepay_delete_data_on_uninstall` option. Add an "Upgrades & migrations" note stating `nicepay_db_version` is currently write-only and what the intended contract is — noting that `CREATE TABLE IF NOT EXISTS` ([:110](../../nicepay-payment-gateway.php#L110)) prevents `dbDelta` from altering an existing table. Add a "Troubleshooting by ResultCode" table to USER-GUIDE linking `https://github.com/nicepayments/nicepay-manual-eng/blob/main/code/nicepay-code.md`.

> **Verification note (needs confirmation on one sub-claim).** The documentation silence and every grep are confirmed. Whether a gateway with no Blocks integration renders *at all* in the Checkout block depends on the WooCommerce Blocks version (a legacy fallback existed historically), so that consequence is **PLAUSIBLE**; the documentation gap is CONFIRMED.

---

<a id="docs-017"></a>
#### DOCS-017 · 🟠 MEDIUM — Five overlapping docs with no index, reading order, or audience labels — duplication has already produced live contradictions

**Files:** [README.md:194-200](../../README.md#L194) · `docs/` (no index file)

**Problem.** `ls docs/` shows five sibling `.md` files and **no README/index**. `README.md:194-200` lists them with one-line blurbs but gives no reading order and no audience marking, so a merchant cannot tell ARCHITECTURE and API-REFERENCE are not for them, and USER-GUIDE and CONFIGURATION overlap so heavily that neither is obviously the entry point.

Verified duplication:

| Topic | Copies | Status |
|---|---|---|
| Test credentials | README:53-55, USER-GUIDE:93-94, CONFIGURATION:102-104, DEVELOPER-GUIDE:497-498 | 4× |
| Go-live checklist | USER-GUIDE:636-650 (11 items) vs CONFIGURATION:129-139 (9 items) | drifted — CONFIGURATION omits the **inbound-firewall** item |
| Firewall tables | README:176-192 (hostnames + IPs) vs CONFIGURATION:352-367 (IPs only) | drifted content |
| Shortcode parameter table | README:105-120 vs USER-GUIDE:415-429 | **contradictory** (`pay_method`, DOCS-005) |
| Signature rules | DEVELOPER-GUIDE:161-168, API-REFERENCE:281-294, ARCHITECTURE:353-378 | 3×, all correct |
| Credential-hardening recipe | CONFIGURATION:154-160 vs DEVELOPER-GUIDE:450-456 | **contradictory**, one version broken (DOCS-002) |
| Troubleshooting | README, USER-GUIDE, CONFIGURATION | 3× |

**Impact.** Two duplications have already diverged into outright contradictions — the predicted failure mode arriving early. Every future edit must be applied in three or four places or the docs decay further, and no file presents itself as the front door.

**Recommendation.** Create `docs/README.md` as an index with a labelled reading order — *"Merchants: USER-GUIDE (start here) → CONFIGURATION. Developers: ARCHITECTURE → DEVELOPER-GUIDE → API-REFERENCE. Contributors: CONTRIBUTING."* Put an audience banner at the top of each file. De-duplicate by assigning **one owner per topic** and replacing copies with links:

| Topic | Canonical owner |
|---|---|
| Test credentials | USER-GUIDE |
| Firewall | CONFIGURATION (README keeps a pointer) |
| Go-live checklist | USER-GUIDE |
| Shortcode parameter table | USER-GUIDE |
| Signature rules | API-REFERENCE |
| Credential hardening | DEVELOPER-GUIDE |

Reduce README to positioning, requirements, a five-minute quick start, and links.

---

<a id="docs-018"></a>
#### DOCS-018 · 🟠 MEDIUM — Docs present Network Cancel as a guarantee; the result is never checked, retried, or recorded

**Files:** [README.md:14](../../README.md#L14) · [docs/USER-GUIDE.md:565](../../docs/USER-GUIDE.md#L565) · [CHANGELOG.md:31](../../CHANGELOG.md#L31)

**Problem.**

```
README.md:14        - **Network Cancel** — Automatic rollback on approval failures
USER-GUIDE.md:565   **Automatic recovery:** If the approval request fails (timeout, network error), the plugin
                    automatically sends a **network cancel** request to prevent the customer from being charged
                    without your knowledge.
```

The implementation is fire-and-forget — the return value is discarded at all four call sites:

```php
if ( ! empty( $auth_data['NetCancelURL'] ) ) {
    $this->request_net_cancel( $auth_data );
}   // includes/class-nicepay-api.php:225-227, identical at :238-240, :251-253, :262-264
```

`is_cancel_success()` is never applied to it, there is no retry, no transaction-row annotation, no admin notice — and the only trace is `nicepay_log()`, which returns immediately unless `WP_DEBUG` is true:

```php
if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) { return; }   // includes/nicepay-functions.php:17-19
```

**Impact.** The documented guarantee fails in the case that matters: if the net-cancel itself times out or returns a non-success code, the customer stays charged, the order is marked failed, and on a normal production site (`WP_DEBUG` off) **no record exists anywhere**. A merchant relying on the README's promise will not reconcile.

**Recommendation.** Reword all three places to:

> **Best-effort automatic rollback** — when approval fails the plugin issues one network-cancel request. The result is logged but not verified or retried; if the rollback itself fails, the authorisation may remain live. Always reconcile failed orders against the NicePay merchant admin.

Add a matching USER-GUIDE troubleshooting row. Then strengthen the code so the doc can be strengthened: check `is_cancel_success( $result['ResultCode'] )` at the call sites, write the outcome into the transaction row's `result_msg`, and log the failure **unconditionally** at `error` level rather than behind `WP_DEBUG`. Also fix `API-REFERENCE.md:229` ("Same as [Payment Cancel Response]") — the spec gives `2001` **only** for net cancel, while the cancel-response table accepts `2001` **or** `2211`.

**Spec reference.** NICEPAY-SPEC-REF §8: net-cancel response `ResultCode` `2001` = success, else failure.

---

<a id="docs-019"></a>
#### DOCS-019 · 🟠 MEDIUM — No document explains how to obtain a live MID/Merchant Key, or that each payment method must be provisioned on the MID

**Files:** [docs/USER-GUIDE.md:131-132](../../docs/USER-GUIDE.md#L131), [:638](../../docs/USER-GUIDE.md#L638) · [docs/CONFIGURATION.md:131](../../docs/CONFIGURATION.md#L131)

**Problem.** Every go-live path reduces the hardest prerequisite to five words:

```
USER-GUIDE.md:131   | **Live MID** | Merchant ID for production (obtain from NicePay sales team) |
USER-GUIDE.md:638   - [ ] Obtain Live MID and Merchant Key from NicePay sales team
```

A full URL inventory of all `.md` files contains **exactly one** corporate NICEPAY link (`README.md:3`) and no sales contact, no statement of what NICEPAY requires (Korean business registration, signed contract), no lead time, and no note that individual methods (SSG_BANK, GIFT_CULT, USD, escrow, installments) must each be provisioned on the MID before they work.

**Impact.** Merchant-facing documentation stops exactly where the merchant's real work begins: a reader can go from install to a working *test* payment and then have no idea what to do next. The Payment Methods settings screen lets them tick SSG Bank Account and Culture Cash with no warning that those require separate MID provisioning, so they enable them and get failures the docs do not explain.

**Recommendation.** Add a "Getting a NICEPAY merchant account" section to USER-GUIDE ahead of "Going Live": link `https://www.nicepay.co.kr/`, give the technical contact (`it@nicepay.co.kr`) and note that commercial onboarding runs through NICEPAY sales, list the prerequisites (Korean business registration, signed contract), and state that the MID is provisioned **per payment method and per currency**. Add a note under the Payment Methods table (`USER-GUIDE.md:142-149`) that enabling a method here does not enable it on your MID. Add *"Confirm each enabled payment method is provisioned on the live MID"* and *"Confirm the MID supports your store currency"* to both pre-launch checklists.

**Spec reference.** NICEPAY-SPEC-REF §12: *"Only documented params are guaranteed; available pay methods depend on MID configuration."*

---

<a id="docs-020"></a>
#### DOCS-020 · 🟠 MEDIUM — No document states the required format of the `amount` attribute; a decimal-style thousands separator silently charges a fraction of the price

**Files:** [docs/USER-GUIDE.md:418](../../docs/USER-GUIDE.md#L418) · [README.md:110](../../README.md#L110) · [templates/standalone-payment-form.php:24-25](../../templates/standalone-payment-form.php#L24) · [includes/nicepay-functions.php:248-257](../../includes/nicepay-functions.php#L248)

**Problem.** Every parameter table describes `amount` as simply "Payment amount", with no format rule. The template normalises it by stripping commas but **keeping dots**, then truncates:

```php
$raw_amount = preg_replace( '/[^0-9.]/', '', sanitize_text_field( $atts['amount'] ) );   // standalone-payment-form.php:24
$amount     = nicepay_get_amount( $raw_amount, $currency );                              // :25

if ( $currency === 'KRW' ) {
    return (string) (int) $amount;                                                        // nicepay-functions.php:252-255
}
```

So `amount="10.000"` — standard thousands notation in Turkish and much of Europe, and this plugin ships a `tr_TR` translation — silently becomes **`10`**. `amount="10000.50"` silently becomes `10000`. No document mentions any of this, and no admin-side warning exists for hand-written shortcodes. (The Shortcode Generator uses `<input type="number">`, so it is unaffected.)

**Impact.** A merchant hand-writing `[nicepay_payment amount="10.000" goods_name="…"]` charges the buyer **10 KRW instead of 10,000 KRW**, with a fully successful payment and no error anywhere. Revenue is lost silently and the only written spec of the attribute gives the merchant no way to know.

**Recommendation.** Add a format rule to the `amount` row in `USER-GUIDE.md:418` and `README.md:110`:

> Digits only, no thousands separators. For KRW the value is truncated to a whole number — `10.000` is read as **10**, not 10,000. For USD use a decimal point (e.g. `19.99`).

Add a matching note to CONFIGURATION's Standalone Payment Setup examples. Better still, reject a KRW amount containing a `.` at `templates/standalone-payment-form.php:24-27` with the existing "Invalid payment amount." notice rather than silently truncating.

> **Note.** The comma case is safe — commas are stripped, so `50,000` → `50000`. The **dot** is the live hazard.

---

<a id="docs-021"></a>
#### DOCS-021 · 🟠 MEDIUM — The SSRF host allowlist and its failure mode are documented nowhere, and the Security Layers table omits both non-standard controls

**Files:** [docs/ARCHITECTURE.md:379-389](../../docs/ARCHITECTURE.md#L379) · [includes/class-nicepay-api.php:129-151](../../includes/class-nicepay-api.php#L129)

**Problem.** `NicePay_API::validate_nicepay_url()` rejects any `NextAppURL`/`NetCancelURL` whose scheme is not https or whose host is not one of exactly four hardcoded values:

```php
private static $allowed_hosts = array(
    'dc1-api.nicepay.co.kr', 'dc2-api.nicepay.co.kr',
    'pg-api.nicepay.co.kr',  'pg-web.nicepay.co.kr',
);   // includes/class-nicepay-api.php:130-135

if ( ! $this->validate_nicepay_url( $next_app_url ) ) {
    nicepay_log( 'Approval URL validation failed', $next_app_url );
    return new WP_Error( 'nicepay_url_error', __( 'Invalid approval URL.', 'nicepay-payment-gateway' ) );
}   // :186-190
```

`grep -rni "ssrf|allowlist|allowed host|Invalid approval URL|redact" --include='*.md' .` finds nothing except one general sentence about log redaction (`USER-GUIDE.md:690`). ARCHITECTURE's "Security Layers" table lists seven layers — Transport, Data Integrity, Authentication, Authorization, CSRF Protection, Input Validation, Timing-safe Compare — and omits **both** the SSRF allowlist and `redact_for_log()`, i.e. the two controls the plugin implements beyond stock WordPress practice.

**Impact.** NicePay explicitly warns that `NextAppURL` is returned dynamically and must not be hardcoded. If NICEPAY routes a transaction to a host outside the four in the array (a new data centre, a regional endpoint, a MID-specific host), every affected payment fails with an error string that appears in no document, and the operator has no documented remedy or even a place to look. A maintainer reading the Security Architecture section also cannot see the control exists, so it is likely to be broken or bypassed by future edits.

**Recommendation.** Add two rows to the Security Layers table:

```
| **SSRF Protection** | Approval/net-cancel URLs are validated against a fixed allowlist of NicePay hosts and must be https (NicePay_API::validate_nicepay_url) |
| **Log Redaction**   | AuthToken, SignData, Signature, MerchantKey, CardNo, VbankNum truncated before logging (NicePay_API::redact_for_log) |
```

List the four allowed hosts in API-REFERENCE alongside the Base URLs table (42-47), and add a DEVELOPER-GUIDE note: *"If NICEPAY provisions a new API host, it must be added to `NicePay_API::$allowed_hosts` (includes/class-nicepay-api.php:130-135) or approvals will fail with 'Invalid approval URL.'"* Add a troubleshooting row for that exact error string to USER-GUIDE.

> **Verification note.** The control, the error string and the documentation silence are confirmed directly. Whether NICEPAY ever returns a host outside the four is not knowable from the repo — the spec digest lists only dc1/dc2 today — so the failure scenario is a forward-looking risk while the documentation gap is present-tense fact.

---

<a id="docs-022"></a>
#### DOCS-022 · 🟠 MEDIUM — "Cancelling a Transaction" never says the WooCommerce order is set to Cancelled with no refund record and no partial option

**Files:** [docs/USER-GUIDE.md:269-276](../../docs/USER-GUIDE.md#L269) · [admin/class-nicepay-transactions.php:216-231](../../admin/class-nicepay-transactions.php#L216)

**Problem.** The walkthrough runs six steps and ends at *"6. The status badge updates immediately (no page reload needed)"*. It never states three consequences the code does produce:

1. The linked WooCommerce order is forced to **`cancelled`**, not `refunded`:

```php
if ( $transaction->wc_order_id ) {
    $order = wc_get_order( $transaction->wc_order_id );
    if ( $order ) {
        $order->update_status( 'cancelled', __( 'Cancelled via NicePay admin.', … ) . ' ' . $reason );
    }
}   // admin/class-nicepay-transactions.php:224-230
```

2. **No WooCommerce refund record is created**, so reports and analytics still count the sale as revenue and stock is not restored.
3. The cancel is always for the **full** stored amount — `$partial` is left at its `false` default:

```php
$result = $api->request_cancel( $tid, $cancel_amount, $reason, $transaction->moid );   // :207
```

Nothing in USER-GUIDE or CONFIGURATION tells a merchant which of the two reversal paths to use or how they differ.

**Impact.** A merchant who reverses a payment from the Transactions screen believes they have refunded the order. WooCommerce's own reporting disagrees: revenue is not reduced, no refund line exists on the order, and stock is not restored — a books-vs-gateway discrepancy that surfaces only at reconciliation time. It is also the only place a merchant can cancel a **standalone** (non-WooCommerce) transaction, so the distinction genuinely matters.

**Recommendation.** Add to `USER-GUIDE.md:269-276`:

> Cancelling from this screen reverses the **full** transaction amount at NicePay and, for WooCommerce orders, sets the order status to **Cancelled**. It does **not** create a WooCommerce refund record — store reports will still count the original sale, and stock is not restored. To refund a WooCommerce order properly (including partial amounts), use **Refund** on the order edit screen instead. Use this screen for standalone shortcode transactions, or when no WooCommerce order exists.

Add the same distinction to CONFIGURATION's Order Status Mapping section (`:241-261`), which currently shows `Admin Cancel --> cancelled` with no explanation.

> **Verification note (needs confirmation on one point).** The handler was read in full and all three undocumented consequences are confirmed against the code. The claim that WooCommerce reports exclude cancelled-but-unrefunded orders from refund totals follows from standard WooCommerce behaviour (a refund object is required for a refund line), which could not be executed here.


---

### Low

<a id="docs-023"></a>
#### DOCS-023 · 🟡 LOW — Three docs tell merchants to look for "NicePay Payment" in WooCommerce → Payments; that list shows "NicePay"

**Files:** [README.md:63](../../README.md#L63) · [docs/USER-GUIDE.md:285](../../docs/USER-GUIDE.md#L285) · [docs/CONFIGURATION.md:216](../../docs/CONFIGURATION.md#L216)

**Problem.** All three enabling instructions name "**NicePay Payment**" (`USER-GUIDE.md:285`: `2. Find **NicePay Payment** in the list`). WooCommerce's Payments table renders `get_method_title()`:

```php
$this->method_title = __( 'NicePay', 'nicepay-payment-gateway' );          // class-nicepay-gateway.php:19
$this->title        = $this->get_option( 'title', __( 'NicePay Payment', … ) );   // :27  ← customer-facing
```

**Impact.** A first-time merchant scanning the table for the exact bold string does not find it verbatim. Minor friction at step 9 of an 11-step walkthrough — the strings share a prefix, so most readers will still find the row.

**Recommendation.** Change all three to *"Find **NicePay** in the list (customers see **NicePay Payment** at checkout; change it under Set up → Title)."* Also soften `USER-GUIDE.md:286` "Toggle it **On**" to "ensure the toggle is **On**", since the gateway ships `'default' => 'yes'` for `enabled` ([class-nicepay-gateway.php:41-46](../../includes/class-nicepay-gateway.php#L41)) and will usually already be on.

---

<a id="docs-024"></a>
#### DOCS-024 · 🟡 LOW — DEVELOPER-GUIDE's log inventory promises two entry types the plugin never writes

**File:** [docs/DEVELOPER-GUIDE.md:475-483](../../docs/DEVELOPER-GUIDE.md#L475)

**Problem.** The "Log Entries" list claims the plugin logs *"Authentication request parameters (excluding sensitive data)"* (:477) and *"Signature verification results"* (:480). An inventory of the 27 non-test `nicepay_log()` call sites shows **no** authentication-request logging at all — nothing logs the outbound auth form data built at [class-nicepay-gateway.php:179-208](../../includes/class-nicepay-gateway.php#L179) or [standalone-payment-form.php:129-154](../../templates/standalone-payment-form.php#L129) — and signature verification is logged **only on failure**:

```php
nicepay_log( 'Approval signature verification failed' );   // class-nicepay-api.php:260 — no success counterpart
```

(also `:388`, `class-nicepay-gateway.php:276`, `class-nicepay-return-handler.php:71`). "Approval request URL and parameters" is also overstated: [class-nicepay-api.php:207-211](../../includes/class-nicepay-api.php#L207) logs only url, TID and Amt.

**Impact.** An integrator debugging a SIGNDATA failure enables `WP_DEBUG` expecting the promised auth-request parameters, finds none, and concludes logging is broken or the request never fired — in exactly the scenario (wrong MID/EdiDate) where that log would matter most.

**Recommendation.** Correct :475-483 to the real inventory: approval request (url, TID, Amt only), approval response (redacted), approval parse/signature **failures**, net-cancel request and response, cancel request and response, auth failures, standalone return summary (AuthResultCode/Moid/PayMethod), and DB insert failures — with an explicit note that signature **successes** are not logged. Better: add the missing entry at `class-nicepay-gateway.php:209` (`nicepay_log( 'Auth request', $this->api->redact_for_log( $form_data ) );`, exposing `redact_for_log` as public) so the documented behaviour becomes true. Also state that `nicepay_log()` writes at WooCommerce's `debug` level. *(No fix needed for the `WP_DEBUG` prerequisite — `DEVELOPER-GUIDE.md:463-470` already documents it.)*

---

<a id="docs-025"></a>
#### DOCS-025 · 🟡 LOW — ARCHITECTURE's class diagram names a method that does not exist and its directory tree omits the whole test/CI surface

**File:** [docs/ARCHITECTURE.md:58-82](../../docs/ARCHITECTURE.md#L58), [:101-124](../../docs/ARCHITECTURE.md#L101), [:141-149](../../docs/ARCHITECTURE.md#L141)

**Problem.** The `NicePay_Admin` box lists `-render_shortcode_tab()` (:148). No such method exists; the real private renderers are `render_shortcodes_tab()` ([class-nicepay-admin.php:348](../../admin/class-nicepay-admin.php#L348)) and `render_shortcode_generator_tab()` ([:431](../../admin/class-nicepay-admin.php#L431)), and the public `enqueue_admin_styles()` (:48) and `render_transactions_page()` (:933) are absent. The `NicePay_API` box omits `is_test_mode()` ([class-nicepay-api.php:52](../../includes/class-nicepay-api.php#L52)) and `get_vbank_exp_date()` ([:426](../../includes/class-nicepay-api.php#L426)) — even though `DEVELOPER-GUIDE.md:117` calls `is_test_mode()` in an example. The Directory Structure tree ends at `languages/` and `docs/`, omitting `tests/`, `composer.json`, `phpunit.xml`, `.github/workflows/`, `CONTRIBUTING.md` and `CHANGELOG.md`.

**Impact.** ARCHITECTURE is the orientation document for a new maintainer: its class graph sends them after a method that isn't there, hides the admin's two largest render paths, and its structure tree gives them a mental model of the repo with **no tests and no CI in it**.

**Recommendation.** Regenerate the class diagram from source: replace `-render_shortcode_tab()` with `-render_shortcodes_tab()` and `-render_shortcode_generator_tab()`; add `+enqueue_admin_styles()` and `+render_transactions_page()`; add `+is_test_mode() bool` and `+get_vbank_exp_date() string` to `NicePay_API`, plus `-validate_nicepay_url() bool` and `-redact_for_log() array` — both are load-bearing security controls in a document that has a Security Architecture section (see [DOCS-021](#docs-021)). Extend the tree with `tests/{bootstrap,unit}/`, `composer.json`, `phpunit.xml`, `.github/workflows/{tests,release}.yml`, `CONTRIBUTING.md`, `CHANGELOG.md`, and add a short "Test Architecture" subsection explaining `tests/bootstrap/wp-stubs.php`.

---

<a id="docs-026"></a>
#### DOCS-026 · 🟡 LOW — CONFIGURATION's translation file list omits Turkish

**File:** [docs/CONFIGURATION.md:387-389](../../docs/CONFIGURATION.md#L387)

**Problem.** "Plugin Text Translation" lists exactly three files — `ko_KR`, `en_US`, `zh_CN` — while `README.md:18` advertises *"Korean, English, Chinese, Turkish translations included"*, `USER-GUIDE.md:614-618` lists all four, and `languages/` ships `nicepay-payment-gateway-tr_TR.po` **and** `.mo`. CONFIGURATION also never names the `.pot` template that `USER-GUIDE.md:626` correctly identifies as the translation base, nor mentions the `.mo` compilation step.

**Impact.** A Turkish merchant consulting the Configuration guide concludes their language is unsupported and either abandons it or redoes work that already exists — and the omission undercuts confidence in the document's other lists.

**Recommendation.** Add `- \`nicepay-payment-gateway-tr_TR.po\` — Turkish` after :389 and `- \`nicepay-payment-gateway.pot\` — translation template` as the first entry. Better: delete the list and link to USER-GUIDE's "Multi-Language Support" section (`:606-630`), which already covers the same ground including the Poedit/Loco workflow and locale naming.

---

<a id="docs-027"></a>
#### DOCS-027 · 🟡 LOW — No document lists the plugin's own constants, and ARCHITECTURE describes `nicepay_db_version` as a schema version nothing reads

**Files:** [docs/ARCHITECTURE.md:441](../../docs/ARCHITECTURE.md#L441) · `docs/DEVELOPER-GUIDE.md` (no constants section)

**Problem.** The plugin defines nine constants at [nicepay-payment-gateway.php:22-34](../../nicepay-payment-gateway.php#L22) — `NICEPAY_VERSION`, `NICEPAY_PLUGIN_FILE`/`DIR`/`URL`/`BASENAME`, `NICEPAY_JS_URL`, `NICEPAY_CANCEL_URL`, `NICEPAY_TEST_MID`, `NICEPAY_TEST_MERCHANT_KEY`. `grep -rn "NICEPAY_" --include='*.md' .` returns **only** the two constants the docs ask the *user* to invent (`NICEPAY_LIVE_MID`, `NICEPAY_LIVE_MERCHANT_KEY`). `API-REFERENCE.md:42-47` lists the base URLs as literal strings without naming `NICEPAY_JS_URL`/`NICEPAY_CANCEL_URL`. Separately:

```
docs/ARCHITECTURE.md:441   | `nicepay_db_version` | string | Database schema version |
```

implies a migration mechanism; the option is written once at activation ([:161](../../nicepay-payment-gateway.php#L161)) and read nowhere.

**Impact.** Extension authors hardcode paths and URLs the constants already provide, and a reader reasonably assumes an upgrade routine keys off `nicepay_db_version` — there is none, and `CREATE TABLE IF NOT EXISTS` ([:110](../../nicepay-payment-gateway.php#L110)) prevents `dbDelta` from altering an existing table, so a future schema change has no documented or implemented path.

**Recommendation.** Add a **Constants** table to DEVELOPER-GUIDE listing all nine with value and intended use, and cross-reference `NICEPAY_JS_URL`/`NICEPAY_CANCEL_URL` from API-REFERENCE's Base URLs table. Change `ARCHITECTURE.md:441` to `| \`nicepay_db_version\` | string | Written at activation; reserved for future migrations — not currently read |`, and add a "Schema upgrades" note describing the intended contract (compare on `plugins_loaded`, run `dbDelta` with a plain `CREATE TABLE`, then update the option).

---

<a id="docs-028"></a>
#### DOCS-028 · 🟡 LOW — DEVELOPER-GUIDE and CONFIGURATION tables of contents each omit the Shortcode section that follows them

**Files:** [docs/DEVELOPER-GUIDE.md:5-16](../../docs/DEVELOPER-GUIDE.md#L5) vs [:231](../../docs/DEVELOPER-GUIDE.md#L231) · [docs/CONFIGURATION.md:5-16](../../docs/CONFIGURATION.md#L5) vs [:165](../../docs/CONFIGURATION.md#L165)

**Problem.** DEVELOPER-GUIDE's TOC lists ten entries and omits `## Shortcode Management` (:231), a ~105-line section covering the saved-shortcode data shape, helper functions, ID resolution, display modes and the AJAX init sequence. CONFIGURATION's TOC lists ten entries and omits `## Shortcode Manager` (:165), a ~45-line section. In both cases the missing heading sits **between two listed ones**, so a TOC-driven reader never learns it exists.

**Impact.** The shortcode system is the plugin's main differentiator, and its documentation is invisible from the table of contents in both files.

**Recommendation.** Insert `- [Shortcode Management](#shortcode-management)` after the Transaction Management entry (`DEVELOPER-GUIDE.md:10`) and `- [Shortcode Manager](#shortcode-manager)` after the Production Environment entry (`CONFIGURATION.md:9`). Add a CI step or pre-commit hook that regenerates TOCs (`markdown-toc`) so they cannot drift again.

---

<a id="docs-029"></a>
#### DOCS-029 · 🟡 LOW — DEVELOPER-GUIDE's "Option 2" snippet indexes an array key that may not exist, and "After Transaction Saved" is an empty placeholder

**File:** [docs/DEVELOPER-GUIDE.md:358-365](../../docs/DEVELOPER-GUIDE.md#L358), [:83-93](../../docs/DEVELOPER-GUIDE.md#L83)

**Problem.** Two shipped samples do not work.

```php
// :362 — unguarded index into an availability-filtered list
remove_action( 'woocommerce_receipt_nicepay', array( WC()->payment_gateways()->get_available_payment_gateways()['nicepay'], 'receipt_page' ) );
```

`get_available_payment_gateways()` returns only gateways whose `is_available()` is true — false whenever credentials are unset or the gateway is disabled ([class-nicepay-gateway.php:81-83](../../includes/class-nicepay-gateway.php#L81)) — so the `['nicepay']` index can be missing, producing an "Undefined array key" warning and a `remove_action` call that silently removes nothing.

```php
// :89-92 — "After Transaction Saved", body is entirely comments
add_action( 'init', function() {
    // Check periodically for new transactions
    // Or use a custom action fired after nicepay_save_transaction
});
```

No such action exists.

**Impact.** Copy-pasting sample 1 yields a warning and a silent no-op that a developer will blame on WordPress; sample 2 is documentation-shaped filler occupying the slot where a real extension point belongs, so a reader concludes there is a way to hook transaction saves when there is not.

**Recommendation.** Replace sample 1 with a guarded form using the full registry:

```php
add_action( 'wp_loaded', function () {
    $gateways = WC()->payment_gateways()->payment_gateways();
    if ( isset( $gateways['nicepay'] ) ) {
        remove_action( 'woocommerce_receipt_nicepay', array( $gateways['nicepay'], 'receipt_page' ) );
        add_action( 'woocommerce_receipt_nicepay', 'my_custom_nicepay_receipt' );
    }
} );
```

Delete sample 2 and either add a real `do_action( 'nicepay_transaction_saved', $insert_id, $data )` after [nicepay-functions.php:88](../../includes/nicepay-functions.php#L88) and document it, or state plainly that no such hook exists.

> **Cause correction.** The defect is **not** a hook-timing problem: the gateway registry *is* built by `init`, because the plugin adds its `woocommerce_payment_gateways` filter on `plugins_loaded` priority 11 ([nicepay-payment-gateway.php:70](../../nicepay-payment-gateway.php#L70)). Nor is a fatal possible — `_wp_filter_build_unique_id` treats `array( null, 'receipt_page' )` as a string callback and the removal simply fails. The real defect is the unguarded index on an availability-filtered list.

---

<a id="docs-030"></a>
#### DOCS-030 · 🟡 LOW — Docs tell developers to restyle the pay button in CSS, but saved shortcodes emit an inline background that overrides it

**Files:** [docs/DEVELOPER-GUIDE.md:367-382](../../docs/DEVELOPER-GUIDE.md#L367) · [docs/USER-GUIDE.md:453-466](../../docs/USER-GUIDE.md#L453)

**Problem.** `DEVELOPER-GUIDE.md:369` says *"The payment button uses the class `nicepay-pay-button`. Override in your theme CSS"* and shows a rule setting `background: #ff6b35`. Whenever `button_color` is non-empty the template emits an inline style that beats any stylesheet rule short of `!important`:

```php
<?php if ( ! empty( $atts['button_color'] ) ) : ?>
    style="background:<?php echo esc_attr( $atts['button_color'] ); ?>"
<?php endif; ?>   // templates/standalone-payment-form.php:158-160 (also :55)
```

All four built-in presets set `button_color` ([nicepay-functions.php:340,359,378,397](../../includes/nicepay-functions.php#L340)), so every `[nicepay_payment id="…"]` shortcode using a preset carries the inline style. Neither document explains the interaction, and neither mentions the CSS custom properties at [nicepay.css:8-30](../../assets/css/nicepay.css#L8).

**Impact.** A developer follows the documented customisation path, sees padding/radius/font-size apply but the colour refuse to change, and has nothing in the docs to explain why.

**Recommendation.** Add to both sections:

> `button_color` is emitted as an inline `style="background:…"` and overrides theme CSS. To control the colour from your stylesheet, omit `button_color` from the shortcode (and clear it in the Shortcode Generator) so the button falls back to the `--nicepay-primary` custom property, which you can override globally with `:root { --nicepay-primary: #ff6b35; }`.

Document the design-token set at `assets/css/nicepay.css:8-30` as the recommended customisation route — no document currently mentions it.

> **Scope note.** The Shortcode Generator omits `button_color` from generated markup when it equals the default ([class-nicepay-admin.php:377](../../admin/class-nicepay-admin.php#L377) and [:725](../../admin/class-nicepay-admin.php#L725) both compare against `#2563eb`), so hand-written shortcodes without the attribute are unaffected. The collision bites shortcodes that use `id=` (presets always carry a colour) or an explicit non-default colour.

---

<a id="docs-031"></a>
#### DOCS-031 · 🟡 LOW — No document warns that the standalone shortcode embeds a nonce, so full-page caching breaks payments once it expires

**Files:** [docs/USER-GUIDE.md:660-674](../../docs/USER-GUIDE.md#L660) · [docs/CONFIGURATION.md:468-476](../../docs/CONFIGURATION.md#L468)

**Problem.** The standalone form inlines a freshly minted nonce into the page markup **at render time**:

```php
xhr.send('action=nicepay_init_payment&nonce=<?php echo esc_js( wp_create_nonce( 'nicepay_init_payment' ) ); ?>&amount=…
// templates/standalone-payment-form.php:305
```

and the AJAX handler rejects a stale nonce with a generic message:

```php
if ( ! wp_verify_nonce( …, 'nicepay_init_payment' ) ) {
    wp_send_json_error( array( 'message' => __( 'Invalid request.', 'nicepay-payment-gateway' ) ) );
    return;
}   // nicepay-payment-gateway.php:314-320
```

WordPress nonces last 24 hours (and are already in their second tick after 12). `grep -n -i cach docs/*.md` finds **one** mention (`CONFIGURATION.md:475`), and it is about the WooCommerce return handler, not the shortcode.

**Impact.** A merchant puts a payment shortcode on a cached landing page; it works during testing and starts failing a day later with a message that names no cause and appears in no troubleshooting table. Cache purges make it work again, so the failure looks intermittent — the hardest class of bug to report.

**Recommendation.** Add to USER-GUIDE's troubleshooting table (`:665-675`):

```
| **Standalone payment shows "Invalid request."** | Page served from a full-page cache with an expired security nonce | Exclude pages containing `[nicepay_payment]` from full-page caching, or keep the cache TTL under 12 hours. |
```

Add the same to CONFIGURATION's Troubleshooting section with concrete exclusion syntax for WP Rocket / LiteSpeed / Cloudflare APO. Longer term, fetch the nonce through the AJAX call itself so the constraint disappears, and document that instead.

---

<a id="docs-032"></a>
#### DOCS-032 · 🟡 LOW — Docs advertise "one-click copy" in the Shortcode Generator, whose handler silently does nothing on a non-HTTPS admin

**Files:** [README.md:17](../../README.md#L17) · [docs/USER-GUIDE.md:219-221](../../docs/USER-GUIDE.md#L219)

**Problem.** `README.md:17` advertises the generator's *"one-click copy"* and USER-GUIDE describes the Generated Shortcode panel with no caveat. The generator's copy handler is guarded with **no fallback and no rejection handler**:

```js
if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(code).then(function() { … });
}   // admin/class-nicepay-admin.php:832-841 — no else, no catch
```

In a non-secure context (a plain-HTTP staging or LAN admin, where `navigator.clipboard` is undefined) the button does nothing at all: no copy, no error, no toast. The Shortcodes **card** copy button in the same plugin *does* implement the fallback:

```js
var temp = $('<textarea>').val(text).appendTo('body').select();
var ok = document.execCommand('copy');   // assets/js/nicepay-admin.js:199-204
```

**Impact.** On a staging site the merchant clicks Copy, receives no feedback of any kind, and pastes a stale clipboard value into their page. Nothing in the documentation gives them reason to suspect the button.

**Recommendation.** *Preferred:* mirror the working fallback from `assets/js/nicepay-admin.js:199-204` into the generator handler and add a `.catch()` that surfaces a failure toast, so the documented behaviour becomes universally true. *Otherwise:* add to `USER-GUIDE.md:219-221` — *"Clipboard copy requires an HTTPS admin; on plain-HTTP sites select the shortcode text and copy manually."*

---

<a id="docs-033"></a>
#### DOCS-033 · 🟡 LOW — README carries no version identity and `composer.json` declares no version

**Files:** [README.md:1-27](../../README.md#L1) · [composer.json:1-14](../../composer.json#L1)

**Problem.** Version `2.0.0` appears in three agreeing places — the plugin header ([nicepay-payment-gateway.php:6](../../nicepay-payment-gateway.php#L6)), `NICEPAY_VERSION` ([:22](../../nicepay-payment-gateway.php#L22)) and the `.pot` `Project-Id-Version` — plus the CHANGELOG heading. It appears **nowhere in README.md**: no badge, no version line, no releases link. `composer.json` has no `version` key, and `git tag -l` is empty so there is nothing to infer it from. README's Requirements table also omits the `WC tested up to: 9.0` the plugin header claims.

**Impact.** A reader cannot tell which version the README documents — which matters because the README ships inside the release ZIP as the only visible metadata. A repo with a working five-version PHP test matrix also surfaces no CI/licence/version badge, so an existing quality signal is invisible.

**Recommendation.** Add a badge row under the README title: version, PHP 7.4+, WordPress 5.0+, WooCommerce 5.0–9.0, MIT licence, and the Tests workflow badge (`…/actions/workflows/tests.yml/badge.svg`). Add `"version": "2.0.0"` to `composer.json`, or state that versioning is tag-driven. Add a `WooCommerce tested up to | 9.0` row to `README.md:23-27`. Cut the `v2.0.0` tag so both the release workflow and the "Download the latest release" instruction become real.

---

<a id="docs-034"></a>
#### DOCS-034 · 🟡 LOW — USER-GUIDE describes phone validation as "7-20 digits"; the regex bounds total characters and also accepts `+`

**File:** [docs/USER-GUIDE.md:447-449](../../docs/USER-GUIDE.md#L447)

**Problem.**

```
USER-GUIDE.md:449   - Phone: must be 7-20 digits (allows dashes, spaces, parentheses)
```

The actual rule bounds **total characters**, not digits, and accepts `+` unmentioned:

```js
} else if (input.type === 'tel' && !/^[\d\-+() ]{7,20}$/.test(value)) {   // templates/standalone-payment-form.php:214
```

So `+82 10-1234-5678` (17 chars, 12 digits) passes while a longer international number with separators fails despite having fewer than 20 digits. The neighbouring lines are also vaguer than the code: email is `/^[^\s@]+@[^\s@]+\.[^\s@]+$/` (:212) and Name is only checked for non-emptiness (:210-211).

**Impact.** Minor, but this is the only written statement of the validation contract, so a merchant supporting international buyers cannot predict which numbers are rejected. NicePay's own `BuyerTel` field is 20 bytes, so the limit has a real origin worth stating correctly.

**Recommendation.** Change :449 to: *"Phone: 7-20 characters total, using digits and any of `+`, `-`, `(`, `)` and spaces — separators count toward the 20-character limit (NicePay's `BuyerTel` field is 20 bytes)."* Replace "must be a valid email format" with the actual pattern, and state that Name is only checked for non-emptiness.

---

<a id="docs-035"></a>
#### DOCS-035 · 🟡 LOW — No document links the official NICEPAY manuals or the result-code table, and the merchant admin is given as unlinkable bare text

**Files:** [docs/API-REFERENCE.md:338](../../docs/API-REFERENCE.md#L338) · [docs/USER-GUIDE.md:654](../../docs/USER-GUIDE.md#L654) · [docs/CONFIGURATION.md:120](../../docs/CONFIGURATION.md#L120) · [docs/DEVELOPER-GUIDE.md:508](../../docs/DEVELOPER-GUIDE.md#L508)

**Problem.** A full URL inventory across every `.md` file contains **no link** to `github.com/nicepayments/nicepay-manual` or `nicepay-manual-eng` — the canonical public sources for the protocol this plugin implements. The inventory is: 4× `github.com/cemililik` issues, 2× loco-translate, 2× poedit, the NicePay API endpoint URLs, 1× `https://www.nicepay.co.kr/`, 1× security/advisories, 2× repo clone URLs, 1× developer.wordpress.org — and zero `nicepayments/*` links.

```
API-REFERENCE.md:338   > For the full list of error codes, refer to the separate "Result Code" document provided by NicePay.
USER-GUIDE.md:654      Access your NicePay merchant dashboard at `npg.nicepay.co.kr`:
```

The merchant admin is referenced three times as bare text with no scheme, so it is not clickable. `API-REFERENCE.md:3` cites the manual version (v2.0.8) without saying where to get it.

**Impact.** Every question the plugin's docs cannot answer — the full error-code list, newly added response fields, per-method provisioning — requires a source the docs never point to. For a payments integration where the vendor spec is ground truth, that is a structural gap.

**Recommendation.** Add a **References** section to API-REFERENCE linking `https://github.com/nicepayments/nicepay-manual`, `https://github.com/nicepayments/nicepay-manual-eng` and the result-code table `https://github.com/nicepayments/nicepay-manual-eng/blob/main/code/nicepay-code.md`, naming the spec version this document tracks (인증결제 v2.0.8, 2024-10-31). Make the three merchant-admin references proper `https://npg.nicepay.co.kr` links. Add a line noting NicePay may add response fields over time and that integrations must tolerate unknown keys.

> **Verification note.** No URL was fetched during this review; reachability of the suggested links is taken from the spec digest.

---

<a id="docs-036"></a>
#### DOCS-036 · 🟡 LOW — CONFIGURATION implies `GoodsCl` is something the merchant configures; it is hardcoded to `1` in three places

**File:** [docs/CONFIGURATION.md:76](../../docs/CONFIGURATION.md#L76)

**Problem.**

```
CONFIGURATION.md:76   | Mobile Payment | Charge to mobile bill | Set `GoodsCl` (content/physical) |
```

reads as an instruction to configure something. There is no such setting — the value is hardcoded to `'1'` (physical goods) in three places:

```php
if ( in_array( 'CELLPHONE', $enabled_methods, true ) ) {
    $form_data['GoodsCl'] = '1'; // Physical goods
}   // includes/class-nicepay-gateway.php:201-203

<input type="hidden" name="GoodsCl" value="1">   // templates/standalone-payment-form.php:149 (also payment-form.php:62)
```

`USER-GUIDE.md:504` states it correctly ("Automatically set to 'physical goods' (GoodsCl=1)"), so the two documents disagree on whether the merchant has a choice.

**Impact.** A merchant selling digital content (the `GoodsCl=0` case) hunts the settings screens for a content/physical toggle, finds none, and has no documented path forward. Sending `GoodsCl=1` for digital content misdeclares the goods type to the carrier, which affects billing limits and refund rules.

**Recommendation.** Change :76 to `| Mobile Payment | Charge to mobile bill | \`GoodsCl\` is fixed to \`1\` (physical goods); digital content needs \`GoodsCl=0\`, not yet configurable |`. Then make it real: add a `nicepay_goods_cl` option to the Payment Methods tab and a `goods_cl` shortcode attribute, and update both documents to describe an actual choice.

**Spec reference.** NICEPAY-SPEC-REF §4 CELLPHONE extras: `GoodsCl` REQUIRED (`0` = contents, `1` = physical goods).

---

<a id="docs-037"></a>
#### DOCS-037 · 🟡 LOW — Transaction search is documented as searching "order ID"; the query searches `moid`

**Files:** [README.md:172](../../README.md#L172) · [docs/USER-GUIDE.md:242](../../docs/USER-GUIDE.md#L242)

**Problem.** Both docs describe the Transactions search as *"Search by TID, order ID, buyer name, or goods/product name"*. The SQL searches four columns, and neither `order_id` nor `wc_order_id` is among them:

```php
$where[] = '(tid LIKE %s OR moid LIKE %s OR buyer_name LIKE %s OR goods_name LIKE %s)';   // includes/nicepay-functions.php:186
```

Because moid is generated as `WC{order_id}_{YmdHis}_{rand}`:

```php
return $prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );   // includes/class-nicepay-api.php:67
```

searching "42" substring-matches order 42's moid — and also orders 142 and 420, any moid whose timestamp contains 42, and any random suffix containing 42.

**Impact.** An admin searching for a specific WooCommerce order number gets a noisy result set with no explanation, or misses the row if they search a format the moid does not contain.

**Recommendation.** Change both to *"Search by TID, Moid (merchant order ID), buyer name, or product name. Note this does not search the WooCommerce order number directly — a bare number substring-matches the Moid."* Better: add `OR wc_order_id = %d` to the clause at `includes/nicepay-functions.php:186` when the search term is numeric, so the documented behaviour becomes the real one.

---

<a id="docs-038"></a>
#### DOCS-038 · 🟡 LOW — Docs prescribe a manual permalink flush that activation already performs, while omitting the requirement that matters

**Files:** [docs/USER-GUIDE.md:64](../../docs/USER-GUIDE.md#L64), [:88](../../docs/USER-GUIDE.md#L88) · [docs/CONFIGURATION.md:42-44](../../docs/CONFIGURATION.md#L42)

**Problem.** Both setup flows make *"go to Settings → Permalinks and click Save Changes"* an explicit step, described as the action that "registers the payment return URL". `activate()` already does exactly that, in the correct order:

```php
public function activate() {
    $this->create_tables();
    $this->set_default_options();
    // Register endpoints before flushing so the rewrite rules are persisted
    $this->register_endpoints();
    flush_rewrite_rules();
}   // nicepay-payment-gateway.php:92-98
```

The manual step is therefore redundant for a normally activated plugin — while the requirement that *does* matter (that the permalink structure not be "Plain", see [DOCS-006](#docs-006)) goes unstated in every document.

**Impact.** Costs every merchant a step and, worse, gives false confidence: having performed the documented permalink action, they will not suspect permalinks when the standalone return 404s. Both troubleshooting tables prescribe the same flush for "Order stuck in Pending".

**Recommendation.** Rewrite as a verification: *"Confirm **Settings → Permalinks** uses any structure other than **Plain** — the plugin flushes rewrite rules automatically on activation. If you later change the structure, click Save Changes to re-register the `/nicepay-return/` endpoint."* Update `USER-GUIDE.md:668` and `CONFIGURATION.md:471-476` to check the permalink **structure** first rather than prescribing a flush.

> **Correction.** A flush is *not* always useless for a WooCommerce-side pending order: under pretty permalinks `WC()->api_request_url()` returns `/wc-api/nicepay_return`, which relies on WooCommerce's own `wc-api` rewrite endpoint. Only under Plain permalinks does it degrade to the rewrite-independent `?wc-api=` query form.

---

<a id="docs-039"></a>
#### DOCS-039 · 🟡 LOW — README's Requirements table omits the SSL/HTTPS requirement both other setup documents call mandatory

**File:** [README.md:23-27](../../README.md#L23)

**Problem.** README's Requirements table lists only PHP, WordPress and WooCommerce:

```
| Requirement | Version |
|---|---|
| PHP | 7.4+ |
| WordPress | 5.0+ |
| WooCommerce | 5.0+ (optional, for checkout integration) |
```

`USER-GUIDE.md:56` lists `| SSL Certificate | Required | NicePay requires HTTPS |`, and `CONFIGURATION.md:401-409` has a whole "SSL/HTTPS Requirements" section stating the site *"**must** have a valid SSL certificate"* and that without HTTPS *"the payment window may not load"*. README is the file bundled into the release ZIP and the first one read.

**Impact.** A merchant who checks only README's requirements table can install on a plain-HTTP site, reach the payment window, and fail there with no requirement having been stated in the document they consulted.

**Recommendation.** Add `| SSL Certificate | Required (NicePay requires HTTPS) |`, and add the WooCommerce upper bound the plugin header already claims (`WC tested up to: 9.0`, [nicepay-payment-gateway.php:14](../../nicepay-payment-gateway.php#L14)) so the table matches the header.


---

## 3. Missing topics

Topics that appear in **none** of the nine Markdown files, ranked by how expensive their absence is. Each is either a full finding above or a gap surfaced by one.

| # | Missing topic | Why it matters | Finding |
|---|---|---|---|
| 1 | **VBANK deposit notification is not implemented** | The single most consequential omission: three documents assert the opposite. A merchant enabling Virtual Account has no way to learn that no deposit signal will ever arrive. | [DOCS-001](#docs-001) |
| 2 | **HPOS (High-Performance Order Storage) compatibility** | Not declared in code; WooCommerce flags the plugin incompatible, and the Transactions screen's `post.php?post=` order links do not resolve. Nothing in the docs prepares a merchant for either. | [DOCS-016](#docs-016) |
| 3 | **Cart/Checkout Blocks support** | No `AbstractPaymentMethodType` registration exists. Blocks is the WooCommerce default checkout on new stores; the docs never say the classic checkout is required. | [DOCS-016](#docs-016) |
| 4 | **Permalink structure requirement** | The `/nicepay-return/` endpoint is rewrite-only; on "Plain" the buyer is authenticated and then 404s. Documented nowhere, and the documented remedy cannot fix it. | [DOCS-006](#docs-006) |
| 5 | **`amount` attribute format** | `amount="10.000"` silently charges 10 KRW. There is no stated format rule anywhere. | [DOCS-020](#docs-020) |
| 6 | **How to obtain a live MID, and per-method MID provisioning** | The docs stop exactly where the merchant's real work begins. Enabling SSG_BANK or GIFT_CULT in settings does not enable them on the MID. | [DOCS-019](#docs-019) |
| 7 | **Uninstall / data removal** | No `uninstall.php`, and no doc lists the table and twelve options left behind. Data-retention questions are a compliance issue for a payment plugin. | [DOCS-016](#docs-016) |
| 8 | **Schema migration contract** | `nicepay_db_version` is written and never read, and `CREATE TABLE IF NOT EXISTS` blocks `dbDelta` from altering an existing table. A future column addition has no path. | [DOCS-016](#docs-016), [DOCS-027](#docs-027) |
| 9 | **ResultCode troubleshooting table** | Admins are shown raw codes ("Code: F111"); the reference defers to an unlinked vendor document. The code cannot be turned into an action. | [DOCS-016](#docs-016), [DOCS-035](#docs-035) |
| 10 | **SSRF allowlist and its error string** | A NICEPAY host outside the hardcoded four fails every approval with "Invalid approval URL." — a string that appears in no document. | [DOCS-021](#docs-021) |
| 11 | **Full-page caching vs the embedded nonce** | Produces an intermittent, hard-to-report "Invalid request." failure. Not in any troubleshooting table. | [DOCS-031](#docs-031) |
| 12 | **Admin Cancel ≠ WooCommerce Refund** | No refund record, no stock restoration, no partial option — none of it documented, and it silently diverges the books from the gateway. | [DOCS-022](#docs-022) |
| 13 | **How to run the test suite** | `composer`, `phpunit` and "unit test" appear zero times in the entire Markdown corpus, while CI gates every PR on `composer test`. | [DOCS-011](#docs-011) |
| 14 | **Multisite behaviour** | `create_tables()` is per-site; network activation is undefined and undocumented. | [DOCS-016](#docs-016) |
| 15 | **The plugin's own constants** | Nine constants exist; the docs name only the two the *user* is asked to define. Extension authors hardcode what already exists. | [DOCS-027](#docs-027) |
| 16 | **Zero-padded approval-response `Amt`** | The protocol's one landmine, absent from the reference whose job is to flag it — and getting it wrong triggers a net cancel of a successful payment. | [DOCS-010](#docs-010) |
| 17 | **`OTID` and repeat partial cancels** | The second partial refund has no documented path, and the plugin never reads or stores `OTID`. | [DOCS-013](#docs-013) |
| 18 | **CSS design tokens** | `assets/css/nicepay.css:8-30` defines a custom-property set that is the *correct* customisation route; no document mentions it, and the documented route is overridden by inline styles. | [DOCS-030](#docs-030) |
| 19 | **Links to the official NICEPAY manuals** | Zero `nicepayments/*` links in the entire corpus, for a plugin whose ground truth is a vendor spec. | [DOCS-035](#docs-035) |
| 20 | **`readme.txt` (WordPress.org format)** | The repo has no `readme.txt` — no `Stable tag`, `Tested up to`, `Requires PHP`, `Short Description` or FAQ block. If distribution ever moves to WordPress.org, or a marketplace scanner inspects the ZIP, there is no machine-readable metadata at all. | §6 |

---

## 4. Version consistency

Version and compatibility metadata across every artefact that carries it.

| Artefact | Field | Value | Verdict |
|---|---|---|---|
| [nicepay-payment-gateway.php:6](../../nicepay-payment-gateway.php#L6) | `Version:` header | `2.0.0` | ✅ canonical |
| [nicepay-payment-gateway.php:22](../../nicepay-payment-gateway.php#L22) | `NICEPAY_VERSION` | `2.0.0` | ✅ agrees |
| [languages/…pot:5](../../languages/nicepay-payment-gateway.pot#L5) | `Project-Id-Version` | `NicePay Payment Gateway 2.0.0` | ✅ agrees |
| [CHANGELOG.md:5](../../CHANGELOG.md#L5) | Latest heading | `## [2.0.0] - 2026-04-03` | ⚠️ stale — two feature commits landed after it ([DOCS-014](#docs-014)) |
| [README.md](../../README.md) | any version string | *absent* | ❌ no version, no badge, no releases link ([DOCS-033](#docs-033)) |
| [composer.json](../../composer.json) | `version` | *absent* | ❌ no key, and no tag to infer from |
| `git tag -l` | tags | *empty* | ❌ release workflow (`v*`) has never run ([DOCS-012](#docs-012)) |
| [nicepay-payment-gateway.php:11-14](../../nicepay-payment-gateway.php#L11) | `Requires at least` / `Requires PHP` / `WC requires at least` / `WC tested up to` | `5.0` / `7.4` / `5.0` / `9.0` | ✅ internally consistent |
| [README.md:23-27](../../README.md#L23) | Requirements table | PHP 7.4+, WP 5.0+, WC 5.0+ | ⚠️ omits `WC tested up to: 9.0` and the SSL requirement ([DOCS-033](#docs-033), [DOCS-039](#docs-039)) |
| [docs/USER-GUIDE.md:51-56](../../docs/USER-GUIDE.md#L51) | Requirements table | + SSL Certificate row | ⚠️ omits the permalink-structure requirement ([DOCS-006](#docs-006)) |
| [docs/CONFIGURATION.md:415-421](../../docs/CONFIGURATION.md#L415) | Server Requirements | — | ⚠️ same omission |
| [docs/API-REFERENCE.md:3](../../docs/API-REFERENCE.md#L3) | Vendor spec version | 인증결제 `v2.0.8` | ⚠️ correct, but unlinked and undated ([DOCS-035](#docs-035)) |
| `readme.txt` | `Stable tag` | *file absent* | ❌ no WordPress.org metadata exists |
| [.github/workflows/tests.yml](../../.github/workflows/tests.yml) | PHP matrix | 7.4 – 8.3 | ✅ matches `Requires PHP: 7.4`; **undocumented** ([DOCS-011](#docs-011)) |

**Net assessment:** the *code-side* version identity is clean and three-way consistent. The failure is entirely on the distribution side — no tag, no README version, no `composer.json` version, no `readme.txt`, and a CHANGELOG that has already fallen behind `main`.

---

## 5. Docs as a product — usability findings

These are not factual errors; they are the reasons a correct document still fails its reader.

| # | Observation | Consequence | Fix |
|---|---|---|---|
| U1 | **No front door.** `docs/` has five sibling files and no index; README lists them with blurbs but no reading order and no audience labels. | A merchant opens ARCHITECTURE; a developer starts in USER-GUIDE. Neither knows which document owns their question. | `docs/README.md` index + an audience banner at the top of each file ([DOCS-017](#docs-017)). |
| U2 | **Six topics duplicated 2–4×.** Test credentials 4×, signature rules 3×, troubleshooting 3×, firewall 2×, go-live checklist 2×, shortcode table 2×. | Two copies have **already** contradicted each other ([DOCS-005](#docs-005), [DOCS-002](#docs-002)). Every future edit needs 3–4 applications. | One canonical owner per topic; replace copies with links ([DOCS-017](#docs-017)). |
| U3 | **Caveats placed after the sample they invalidate.** The hook caveat sits at `DEVELOPER-GUIDE.md:356`, *below* a copy-pasteable "Option 1"; the VBANK caveat sits at `:432`, *below* the sequence diagram. | Readers copy the sample and never reach the caveat. | Put the limitation **before** the code, or inside the diagram ([DOCS-001](#docs-001), [DOCS-004](#docs-004)). |
| U4 | **Two TOCs silently omit a section that sits between two listed ones.** | The shortcode system — the plugin's main differentiator — is unreachable via navigation in both files. | Regenerate TOCs in CI ([DOCS-028](#docs-028)). |
| U5 | **Aspirational voice.** Several passages describe the intended product ("filter", "deposit notification", "5 second connect timeout", "prevent the customer from being charged"). | The reader cannot distinguish roadmap from behaviour, which is the root cause of 6 of the 39 findings. | Adopt a house rule: every capability sentence is present-tense and points at a file:line. Add a **Not yet supported** section to README and API-REFERENCE. |
| U6 | **No "which path do I use?" guidance.** WooCommerce checkout vs standalone shortcode; order Refund vs Transactions Cancel; preset vs hand-written shortcode. | Merchants pick the wrong path and get consequences no document warned them about ([DOCS-022](#docs-022)). | A one-page decision matrix in USER-GUIDE. |
| U7 | **Error strings are undocumented.** "Invalid approval URL.", "Invalid request.", "Invalid payment amount.", raw ResultCodes. | The exact text a user sees appears in **no** searchable document, so search fails at the moment of failure. | An "Error messages" appendix in USER-GUIDE keyed by literal string → cause → fix. |
| U8 | **No screenshots or annotated UI captures** anywhere in a corpus whose largest document is a UI walkthrough. | Every admin instruction is a string-match exercise — which is why [DOCS-023](#docs-023) ("NicePay" vs "NicePay Payment") can trip a reader at all. | Add captures for the 5 settings tabs, the Shortcode Generator, and the Transactions list. |
| U9 | **`docs/` is absent from the release ZIP,** while README links to it relatively. | Five dead links in the artefact merchants actually install. | Copy `docs/` in `release.yml`; use absolute URLs in README ([DOCS-012](#docs-012)). |
| U10 | **Contributor path is untested.** CONTRIBUTING's setup + checklist do not mention the suite CI gates on, and mandate an indentation style no file uses. | First PR fails CI; first formatting pass conflicts with the whole codebase. | Add a "Running the tests" block and correct the indentation rule ([DOCS-011](#docs-011)). |

---

## 6. Recommended documentation set for a first-class plugin

What exists today is **9 files / ~3,200 lines**, with good bones and no front door. The target below keeps every existing file, adds four, and reassigns ownership so no topic lives in more than one place.

### 6.1 Files to add

| File | Purpose | Why it is required for "first-class" |
|---|---|---|
| `readme.txt` | WordPress.org plugin header: `Stable tag`, `Tested up to`, `Requires PHP`, `Requires at least`, short description, FAQ, changelog excerpt, screenshots list. | The only machine-readable metadata format the WordPress ecosystem reads. Its absence blocks WordPress.org distribution outright and leaves marketplace/security scanners with nothing to parse. |
| `docs/README.md` | Index with labelled reading order and audience per file. | Fixes U1; the single highest-leverage addition in this list. |
| `docs/TROUBLESHOOTING.md` | Consolidates the three scattered troubleshooting sections, keyed by the **literal error string** the user sees, plus a ResultCode table linking the vendor code list. | Fixes U7 and absorbs [DOCS-016](#docs-016)/[DOCS-035](#docs-035); troubleshooting is the most-read page of any payment plugin. |
| `docs/COMPATIBILITY.md` | HPOS, Cart/Checkout Blocks, multisite, permalink structure, SSL, caching plugins, PHP/WP/WC ranges, known-incompatible setups. | Turns three separate discover-by-failure paths ([DOCS-006](#docs-006), [DOCS-016](#docs-016), [DOCS-031](#docs-031)) into a pre-install check. |

### 6.2 Ownership after de-duplication

| Topic | Canonical owner | Everywhere else |
|---|---|---|
| Quick start & positioning | `README.md` | — |
| Merchant setup, admin UI, payment methods, go-live checklist, shortcode parameters, test credentials | `docs/USER-GUIDE.md` | link only |
| Production environment, firewall, SSL, currency, order-status mapping | `docs/CONFIGURATION.md` | link only |
| System design, class diagram, data model, security layers, status lifecycle | `docs/ARCHITECTURE.md` | link only |
| Extension points, constants, logging, credential hardening, schema upgrades, unit tests | `docs/DEVELOPER-GUIDE.md` | link only |
| Protocol parameters, signature rules, result codes, timeouts, vendor references | `docs/API-REFERENCE.md` | link only |
| Every user-visible error string, ResultCode lookup | `docs/TROUBLESHOOTING.md` *(new)* | link only |
| HPOS / Blocks / multisite / permalinks / caching | `docs/COMPATIBILITY.md` *(new)* | link only |
| Contribution process, running tests, code style | `CONTRIBUTING.md` | — |
| Release history, upgrade notes, breaking changes | `CHANGELOG.md` | — |

### 6.3 Editorial rules worth adopting

1. **Present tense, and only for what ships.** Every capability sentence names a file:line. Anything planned goes under an explicit **Not yet supported** heading. This one rule would have prevented [DOCS-001](#docs-001), [DOCS-004](#docs-004), [DOCS-009](#docs-009), [DOCS-018](#docs-018), [DOCS-021](#docs-021) and [DOCS-024](#docs-024) — six of the 39 findings.
2. **Caveats precede the code they qualify.** Never place a limitation below a copy-pasteable sample (U3).
3. **One owner per topic.** A second copy is a link, never a paste (U2).
4. **Mark every parameter table with "sent by this plugin".** Protocol completeness and implementation coverage are different claims ([DOCS-015](#docs-015)).
5. **Document by literal string.** Users search for the text they see, not for the concept (U7).
6. **Automate what drifts:** TOC regeneration, a link checker over relative paths, and a CI check that the version in the plugin header, `NICEPAY_VERSION`, the `.pot`, `composer.json` and the newest CHANGELOG heading all agree.

---

## Appendix — Finding index

| ID | Sev | Title |
|---|---|---|
| [DOCS-001](#docs-001) | 🔴 | VBANK deposit-completion path diagrammed in three docs, implemented in none |
| [DOCS-002](#docs-002) | 🔴 | CONFIGURATION's credential filter drops the stored option and can disable the gateway |
| [DOCS-003](#docs-003) | 🟠 | "Completed" order status vs `payment_complete()`'s `processing` |
| [DOCS-004](#docs-004) | 🟠 | `nicepay_payment_form_template` documented; the plugin fires no hooks at all |
| [DOCS-005](#docs-005) | 🟠 | Contradictory `pay_method` default (README vs USER-GUIDE) |
| [DOCS-006](#docs-006) | 🟠 | Pretty-permalink requirement undocumented; standalone return 404s on "Plain" |
| [DOCS-007](#docs-007) | 🟠 | ARCHITECTURE inverts refunded/cancelled |
| [DOCS-008](#docs-008) | 🟠 | Charset documented as a real encoding choice; nothing transcodes |
| [DOCS-009](#docs-009) | 🟠 | 5s connect-timeout requirement stated next to an implementation that sets none |
| [DOCS-010](#docs-010) | 🟠 | Zero-padded approval-response `Amt` documented nowhere |
| [DOCS-011](#docs-011) | 🟠 | No way to run the CI-gated test suite; tabs mandated, spaces used |
| [DOCS-012](#docs-012) | 🟠 | Release ZIP omits `docs/`; five README links dead in the artefact |
| [DOCS-013](#docs-013) | 🟠 | Cancel Response omits `OTID`; cancel `Moid` is reused, not unique |
| [DOCS-014](#docs-014) | 🟠 | CHANGELOG: wrong tab list, two missing commits, phantom 1.x history |
| [DOCS-015](#docs-015) | 🟠 | API-REFERENCE never marks what the plugin sends; required `MallUserID` absent |
| [DOCS-016](#docs-016) | 🟠 | No HPOS, Blocks, multisite, uninstall, migration or ResultCode documentation |
| [DOCS-017](#docs-017) | 🟠 | Five overlapping docs, no index, duplication already contradicting itself |
| [DOCS-018](#docs-018) | 🟠 | Network Cancel presented as a guarantee; result never checked or recorded |
| [DOCS-019](#docs-019) | 🟠 | No guidance on obtaining a live MID or per-method MID provisioning |
| [DOCS-020](#docs-020) | 🟠 | `amount` format unstated; `10.000` silently charges 10 KRW |
| [DOCS-021](#docs-021) | 🟠 | SSRF allowlist and its error string undocumented; Security Layers table incomplete |
| [DOCS-022](#docs-022) | 🟠 | Admin Cancel's real consequences (WC cancelled, no refund record, full amount only) undocumented |
| [DOCS-023](#docs-023) | 🟡 | "NicePay Payment" vs the "NicePay" row in WooCommerce → Payments |
| [DOCS-024](#docs-024) | 🟡 | Log inventory promises two entry types never written |
| [DOCS-025](#docs-025) | 🟡 | Class diagram names a non-existent method; tree omits tests and CI |
| [DOCS-026](#docs-026) | 🟡 | CONFIGURATION's translation list omits Turkish |
| [DOCS-027](#docs-027) | 🟡 | Constants undocumented; `nicepay_db_version` described as a live schema version |
| [DOCS-028](#docs-028) | 🟡 | Two TOCs omit the Shortcode section between two listed ones |
| [DOCS-029](#docs-029) | 🟡 | "Option 2" snippet indexes a possibly-absent key; "After Transaction Saved" is empty |
| [DOCS-030](#docs-030) | 🟡 | Documented CSS override is defeated by the inline `button_color` style |
| [DOCS-031](#docs-031) | 🟡 | Embedded nonce vs full-page caching not warned about |
| [DOCS-032](#docs-032) | 🟡 | "One-click copy" does nothing, silently, on a non-HTTPS admin |
| [DOCS-033](#docs-033) | 🟡 | README carries no version identity; `composer.json` has no version |
| [DOCS-034](#docs-034) | 🟡 | Phone validation described as "7-20 digits"; regex bounds characters |
| [DOCS-035](#docs-035) | 🟡 | No links to the official NICEPAY manuals or result-code table |
| [DOCS-036](#docs-036) | 🟡 | `GoodsCl` implied configurable; hardcoded to `1` in three places |
| [DOCS-037](#docs-037) | 🟡 | Transaction search documented as "order ID"; the query searches `moid` |
| [DOCS-038](#docs-038) | 🟡 | Manual permalink flush prescribed though activation already does it |
| [DOCS-039](#docs-039) | 🟡 | README Requirements omits the mandatory SSL/HTTPS requirement |
