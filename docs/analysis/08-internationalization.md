# Internationalization & Localization

> [!WARNING]
> **Frozen historical snapshot.** This review describes baseline commit `5855db1` (2026-08-19), before the 2.0 remediation work. Its line references and present-tense security claims do not describe the current code. Use `docs/analysis/pr-review2/` and current tests for release decisions.

This document audits the i18n/l10n layer of NicePay Payment Gateway v2.0.0 across all ten non-test PHP files, both JavaScript bundles, and the five gettext catalogs (`.pot` plus `en_US`, `ko_KR`, `tr_TR`, `zh_CN`). The mechanical hygiene is genuinely strong — every one of the 263 translation-function call sites uses the literal text domain, there are zero placeholder mismatches between msgid and msgstr in any locale, and all four `.mo` files are byte-identical to a fresh `msgfmt` of their `.po`. The problem is not *how* strings are translated but *which* strings ever reach a translator: the shipped `.pot` was generated before the last two feature commits and is missing **74 of the 209 translatable units that exist in the code (35%)**, while simultaneously carrying 20 dead msgids from a removed feature. Every catalog therefore reports "156 translated messages, 0 fuzzy" — a perfect score against a catalog that describes roughly two thirds of the product. Layered on top are two Korean-text leaks into non-Korean customer screens (raw `ResultMsg` pass-through, and a Korean-by-default payment window), a `_n()` plural pair that no catalog can ever match, timezone-naive date generation, and a `EUC-KR` charset option that would break the gateway outright if a merchant selected it.

**21 findings:** 3 high · 6 medium · 10 low · 2 enhancement.

---

## What this codebase does well

Before the defects, the things that are done right — several of them are things this audit was specifically sent to hunt for and did not find.

| # | Strength | Evidence |
|---|---|---|
| 1 | **Text domain hygiene is perfect.** All 263 grep-matched translation-function call sites use the literal string `'nicepay-payment-gateway'`. Zero calls use a variable, a constant, or a different domain. | The only non-matching hits are default-parameter values inside the PHPUnit stub definitions in `tests/bootstrap/wp-stubs.php` — not real calls. |
| 2 | **Zero placeholder-count mismatches.** All 4 locales × 156 entries were checked programmatically for `%s` / `%d` / `%N$s` counts between msgid and msgstr. No mismatches. | No `sprintf`/`printf` runtime crashes of the classic "translator dropped a `%s`" kind exist anywhere in this plugin. |
| 3 | **Multi-placeholder strings are consistently numbered.** Where a string carries more than one placeholder it uses `%1$s`/`%2$s`/`%3$s`, never bare repeated `%s` — correctly anticipating that Korean and Turkish reorder sentence elements. 5 of the 7 such strings also carry a `/* translators: */` comment. | [includes/class-nicepay-gateway.php:260](../../includes/class-nicepay-gateway.php#L260), [:374](../../includes/class-nicepay-gateway.php#L374), [:383](../../includes/class-nicepay-gateway.php#L383), [:401](../../includes/class-nicepay-gateway.php#L401), [:456](../../includes/class-nicepay-gateway.php#L456) |
| 4 | **No stale compiled binaries.** All 4 shipped `.mo` files are byte-for-byte identical to what `msgfmt` produces fresh from their `.po` (verified with `cmp`). | A surprisingly common shipping bug that this repo does not have. |
| 5 | **Within the catalog's scope, translation is complete.** All 4 locales are 100% translated: 0 fuzzy, 0 empty `msgstr`. Verified with both `msgfmt --statistics` and a custom parser. | See the completion table below. |
| 6 | **ko_KR terminology is industry-correct.** 신용카드 (Credit Card), 계좌이체 (Bank Transfer), 가상계좌 (Virtual Account), 휴대폰 결제 (Mobile Payment), 취소 (Cancel), 입금기한 (Deposit Deadline) all match official NICEPAY / Korean payments vocabulary. This is the most consequential locale given the stated primary market and it is in genuinely good shape. | `languages/nicepay-payment-gateway-ko_KR.po` |
| 7 | **JS strings emitted from PHP templates are wrapped properly.** Validation and error strings inside inline `<script>` blocks in [templates/standalone-payment-form.php](../../templates/standalone-payment-form.php) go through `__()` + `esc_js()` rather than being left as raw JS literals — a sound pattern that avoids needing `wp_set_script_translations` infrastructure. (See I18N-19 for the one edge case it does not survive.) | [templates/standalone-payment-form.php:211-215](../../templates/standalone-payment-form.php#L211) |

---

## 1. Completion status per locale

### 1.1 As the catalogs report it

Measured with `msgfmt --statistics` on each `.po` and confirmed with an independent parser.

| Locale | Plural-Forms | msgids in catalog | Translated | Untranslated | Fuzzy | Reported % |
|---|---|---|---|---|---|---|
| `en_US` | `nplurals=2; plural=(n != 1);` | 156 | 156 | 0 | 0 | **100%** |
| `ko_KR` | `nplurals=1; plural=0;` | 156 | 156 | 0 | 0 | **100%** |
| `tr_TR` | `nplurals=2; plural=(n != 1);` | 156 | 156 | 0 | 0 | **100%** |
| `zh_CN` | `nplurals=1; plural=0;` | 156 | 156 | 0 | 0 | **100%** |

### 1.2 As it actually is

The catalog itself is the problem. Re-extracting every `__` / `_e` / `_x` / `_n` / `_nx` / `esc_html__` / `esc_attr__` / `esc_html_e` / `esc_attr_e` / `esc_html_x` / `esc_attr_x` call (including both `_n()` plural arguments) from the 10 non-test PHP files with a PHP-string-literal-aware parser yields **209 unique translatable units**.

| Measure | Count | Share |
|---|---|---|
| Translatable units present in code | 209 | 100% |
| …of which present in the `.pot` | 135 | **64.6%** |
| …of which **absent** from the `.pot` (I18N-01) | 74 | **35.4%** |
| msgids in the `.pot` | 156 | — |
| …live (reachable from code) | 135 | 86.5% |
| …dead / orphaned (I18N-10) | 20 | 12.8% |
| …plugin `Name` header, legitimately not a code call | 1 | 0.6% |
| `_n()` calls in code | 2 | — |
| `msgid_plural` entries in any catalog (I18N-04) | **0** | — |
| Hardcoded, never-wrapped user-facing strings (I18N-08, I18N-09, I18N-16) | 13 | — |

**Effective real-world coverage is therefore ~65%, not 100%**, and the two `_n()` strings sit outside even that number because no catalog can match them.

---

## 2. Hardcoded-string inventory

Strings that reach a user but are not wrapped in any translation function, and therefore cannot be translated at all — distinct from the 74 units of I18N-01, which *are* wrapped but are missing from the catalog.

| # | File:line | String | Context | Finding |
|---|---|---|---|---|
| 1 | [admin/class-nicepay-admin.php:562](../../admin/class-nicepay-admin.php#L562) | `Pay Now` | `placeholder` on the Button Text input, Shortcode Generator | I18N-16 |
| 2 | [admin/class-nicepay-admin.php:580](../../admin/class-nicepay-admin.php#L580) | `Blue` | `title` on colour swatch | I18N-16 |
| 3 | [admin/class-nicepay-admin.php:581](../../admin/class-nicepay-admin.php#L581) | `Black` | `title` on colour swatch | I18N-16 |
| 4 | [admin/class-nicepay-admin.php:582](../../admin/class-nicepay-admin.php#L582) | `Green` | `title` on colour swatch | I18N-16 |
| 5 | [admin/class-nicepay-admin.php:583](../../admin/class-nicepay-admin.php#L583) | `Red` | `title` on colour swatch | I18N-16 |
| 6 | [admin/class-nicepay-admin.php:584](../../admin/class-nicepay-admin.php#L584) | `Purple` | `title` on colour swatch | I18N-16 |
| 7 | [admin/class-nicepay-admin.php:585](../../admin/class-nicepay-admin.php#L585) | `Orange` | `title` on colour swatch | I18N-16 |
| 8 | [admin/class-nicepay-admin.php:619](../../admin/class-nicepay-admin.php#L619) | `0 KRW` | Live Preview amount seed value | I18N-16 |
| 9 | [nicepay-payment-gateway.php:394](../../nicepay-payment-gateway.php#L394) | `Pay Now` | AJAX save fallback, **persisted to DB** | I18N-16 |
| 10 | [assets/js/nicepay-admin.js:198](../../assets/js/nicepay-admin.js#L198) | `Copy failed` | clipboard-API rejection toast | I18N-08 |
| 11 | [assets/js/nicepay-admin.js:204](../../assets/js/nicepay-admin.js#L204) | `Copy failed` | `execCommand` fallback toast | I18N-08 |
| 12 | [assets/js/nicepay-admin.js:234](../../assets/js/nicepay-admin.js#L234) | `Error` | delete-failure toast fallback | I18N-08 |
| 13 | [assets/js/nicepay-admin.js:257](../../assets/js/nicepay-admin.js#L257) | `Copy failed` | shortcode-copy rejection toast | I18N-08 |
| 14 | [assets/js/nicepay-admin.js:268](../../assets/js/nicepay-admin.js#L268) | `Copy failed` | shortcode-copy fallback toast | I18N-08 |
| 15 | [assets/js/nicepay.js:54](../../assets/js/nicepay.js#L54) | `Payment error occurred.` | fallback when `nicepayParams` missing | I18N-09 |
| 16 | [assets/js/nicepay.js:61](../../assets/js/nicepay.js#L61) | `Payment system unavailable.` | fallback when `nicepayStart` undefined | I18N-09 |
| 17 | [includes/nicepay-functions.php:285-303](../../includes/nicepay-functions.php#L285) | 22 card-issuer names (`KB Kookmin`, `Shinhan`, `Hyundai`…) | `nicepay_get_card_name()` | I18N-20 |
| 18 | [includes/nicepay-functions.php:305-323](../../includes/nicepay-functions.php#L305) | 22 bank names (`Suhyup`, `Gwangju`, `Post Office`, `Kakao Bank`…) | `nicepay_get_bank_name()` | I18N-20 |
| 19 | [nicepay-payment-gateway.php:5](../../nicepay-payment-gateway.php#L5) | Plugin `Description` header | Plugins list screen | I18N-18 |

One string, [admin/class-nicepay-admin.php:591](../../admin/class-nicepay-admin.php#L591) `placeholder="nicepay-pay-button"`, is a CSS class name and is **correctly** left untranslated.

---

## 3. Placeholder integrity — and the pluralization bugs that replaced them

**There are no placeholder-count mismatches.** Every `msgid`/`msgstr` pair in all 4 locales was diffed for `%s`, `%d` and `%N$s` occurrence counts: zero discrepancies, therefore **zero `sprintf` runtime crashes of that class**. That is the good news, and it is worth stating plainly because it is the defect this audit was sent to hunt.

What *does* break at runtime is the plural machinery. Both `_n()` calls in the codebase are unreachable by gettext because no catalog contains a single `msgid_plural` entry, and the flat entries that exist are keyed by the wrong string. This is written up in full as **I18N-04** below; the summary table:

| Call site | Singular passed (the lookup key) | Catalog contains | Result |
|---|---|---|---|
| [admin/class-nicepay-transactions.php:74](../../admin/class-nicepay-transactions.php#L74) | `Total: %d transaction` | `msgid "Total: %d transactions"` (the **plural**) | lookup misses → English |
| [includes/class-nicepay-gateway.php:137](../../includes/class-nicepay-gateway.php#L137) | ` and %d more item` | `msgid " and %d more"` (**neither** form) | lookup misses → English, leaked into `GoodsName` sent to NicePay |

---

## 4. Findings

### High

---

#### I18N-01 · 🔴 HIGH · `i18n-completeness`
**POT catalog is stale: 74 of 209 translatable units in code (35%) are absent from the `.pot` and therefore untranslatable in every locale**

**Files:** [languages/nicepay-payment-gateway.pot](../../languages/nicepay-payment-gateway.pot) · [admin/class-nicepay-admin.php:155-655](../../admin/class-nicepay-admin.php#L155) · [templates/standalone-payment-form.php:64-303](../../templates/standalone-payment-form.php#L64) · [includes/nicepay-functions.php:330-395](../../includes/nicepay-functions.php#L330) · [nicepay-payment-gateway.php:318](../../nicepay-payment-gateway.php#L318)

**Problem.** An independent re-extraction of every gettext call from the 10 non-test PHP files finds 209 unique translatable units. The shipped `.pot` contains 156 non-empty msgids. **74 of the 209 are absent entirely.** The affected surfaces are whole features, not stragglers:

| Surface | Representative missing strings |
|---|---|
| Shortcode Generator tab (entire) | `Shortcodes` :155, `Shortcode Generator` :159, `Create New` :355, `No shortcodes yet` :362, `Edit` :409, `Copy` :414, `Shortcode Name` / `e.g. Quick Payment` :446-450, `Display Mode` / `Inline` / `Modal` / `Full form shown on page` / `Button opens popup overlay` :458-478, `Payment Amount` / `Product / Service Name` :491-502, `Buyer Information` / `Pre-fill or leave empty` / `Phone` / `Email` / `Appearance` / `Button Text` / `Default` / `Button Color` / `CSS Class` :531-590, `Save Shortcode` / `Update Shortcode` :599, `Live Preview` / `Product Name` :614-618, `Generated Shortcode` :654, `Amount is required` / `Product name is required` :788-789, `Copied!` :834, `Saving...` :861 |
| Standalone payment form (buyer fields + all client-side validation) | `Close` :64, `Enter your name` :103, `Enter your email` :111, `Enter your phone number` :119, `This field is required.` :211, `Please enter a valid email address.` :213, `Please enter a valid phone number.` :215, `An unexpected error occurred.` :292, `Connection error. Please check your internet.` :303 |
| All 4 built-in presets | `Quick Payment` :330, `Donation` / `Donate` :349/:357, `Product Purchase` / `Buy Now` :368/:376, `Subscription` / `Monthly Subscription` / `Subscribe` :387/:390/:395 |
| AJAX responses | `Invalid request.` :318, `Payment initialization failed.` :350, `Shortcode name is required.` :375, `Shortcode not found.` :412, `Shortcode saved!` / `Shortcode updated!` :435, `Shortcode deleted.` :459 |

Git confirms the cause. The `.pot` was last written in `891c7cc` at **2026-04-03 13:53:33 +0300**; `admin/class-nicepay-admin.php`, `templates/standalone-payment-form.php` and `includes/nicepay-functions.php` were rewritten in `fcde0c6` at **2026-04-03 15:25:39 +0300** — 92 minutes later — and the extractor was never re-run.

**Impact.** A translator using the standard WordPress pipeline (open the `.pot`, translate, `msgmerge` into a `.po`) literally cannot see these 74 units — they are not in the catalog. On every `ko_KR` / `tr_TR` / `zh_CN` site the entire Shortcode Generator admin tab and the customer-facing standalone payment form's placeholders and inline validation errors render in English, directly contradicting [README.md:18](../../README.md#L18) *"**Multi-language** — Korean, English, Chinese, Turkish translations included"*. All four `.po` files report `156 translated messages` from `msgfmt --statistics`, so the catalog looks 100% complete while a third of the product is untranslatable.

**User scenario.** A Korean merchant on a `ko_KR` site opens **NicePay → Shortcodes**: the tab heading, every field label (`Buyer Information`, `Display Mode`, `Button Color`), the Live Preview panel and the validation strip are all in English, while the Settings tab beside it is fully Korean. The inconsistency reads as a half-finished plugin.

**Evidence.**
```php
// templates/standalone-payment-form.php:111
placeholder="<?php esc_attr_e( 'Enter your email', 'nicepay-payment-gateway' ); ?>"

// includes/nicepay-functions.php:376
'button_text'  => __( 'Buy Now', 'nicepay-payment-gateway' ),
```
`grep 'msgid "Enter your email"' languages/*` and `grep 'msgid "Buy Now"' languages/*` both return nothing.

**Recommendation.** Regenerate with a tool that understands all gettext call forms, `msgmerge` each locale, translate the ~74 new entries (ko_KR first), and gate it in CI so it cannot regress.

```bash
wp i18n make-pot . languages/nicepay-payment-gateway.pot \
  --domain=nicepay-payment-gateway --exclude=tests,vendor,node_modules

for loc in en_US ko_KR tr_TR zh_CN; do
  msgmerge --update --backup=none \
    languages/nicepay-payment-gateway-$loc.po \
    languages/nicepay-payment-gateway.pot
  msgfmt -o languages/nicepay-payment-gateway-$loc.mo \
    languages/nicepay-payment-gateway-$loc.po
done
```

Add a job to `.github/workflows/tests.yml` that runs `make-pot` into a temp file and fails if `diff` against the committed `.pot` is non-empty (ignoring the `POT-Creation-Date` header).

**Effort:** medium

---

#### I18N-02 · 🔴 HIGH · `i18n-completeness`
**NicePay's Korean `ResultMsg` is the entire customer-facing message on the standalone result page, and is concatenated into translated WooCommerce notices**

**Files:** [includes/class-nicepay-return-handler.php:216](../../includes/class-nicepay-return-handler.php#L216) · [:65](../../includes/class-nicepay-return-handler.php#L65) · [:113](../../includes/class-nicepay-return-handler.php#L113) · [:149](../../includes/class-nicepay-return-handler.php#L149) · [:157](../../includes/class-nicepay-return-handler.php#L157) · [includes/class-nicepay-gateway.php:266](../../includes/class-nicepay-gateway.php#L266) · [:406-409](../../includes/class-nicepay-gateway.php#L406)

**Problem.** The standalone (non-WooCommerce) payment result page renders `$message` as its entire body text, and every caller passes NicePay's raw response text straight through.

```php
// includes/class-nicepay-return-handler.php:216
<p class="result-message"><?php echo esc_html( $message ); ?></p>

// :113
$result_msg    = isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '';
// :149  (SUCCESS)
$this->render_result_page( true, $result_msg, $result );
// :157  (approval failure)
$this->render_result_page( false, $result_msg );
// :65   (auth failure — $auth_result_msg comes from $_POST['AuthResultMsg'] at :33)
$this->render_result_page( false, $auth_result_msg );
```

NicePay is a Korean PG and these fields carry Korean merchant-facing text. The plugin never maps `ResultCode` to a localized message even though it already owns a code table in `NicePay_API::is_success_code()` ([includes/class-nicepay-api.php:399-417](../../includes/class-nicepay-api.php#L399)).

The WooCommerce path does something equally wrong but differently — it string-concatenates the foreign text onto a translated sentence, which also denies translators any control over ordering, spacing or punctuation:

```php
// includes/class-nicepay-gateway.php:266
__( 'Payment authentication failed. Please try again.', 'nicepay-payment-gateway' ) . ' (' . $auth_result_msg . ')'

// includes/class-nicepay-gateway.php:406-409
wc_add_notice( __( 'Payment failed.', 'nicepay-payment-gateway' ) . ' ' . $result_msg, 'error' );
```

**Impact.** On a Turkish, English or Chinese store using `[nicepay_payment]`, the message under the big green "Payment Successful" heading is Korean text the customer cannot read — on the confirmation screen for a completed payment. On failure the same happens under "Payment Failed", leaving the customer no comprehensible reason. The plugin's own headings *are* correctly translated, which makes the mismatch look like a rendering bug rather than a design choice.

> **Note:** `NpLang` controls only the payment-window UI, **not** API response text — configuring the Language setting does not mitigate this.

**User scenario.** A Turkish customer uses a `[nicepay_payment]` button, completes card authentication, and lands on a page headed **"Ödeme Başarılı"** whose entire explanatory sentence is Korean.

**Recommendation.** Add a `nicepay_get_result_message( $result_code, $pay_method )` helper alongside `nicepay_get_status_label()` in `includes/nicepay-functions.php` mapping the codes the plugin already knows (`0000` auth OK, `3001` CARD, `4000` BANK, `4100` VBANK, `A000` CELLPHONE, `2001` cancel OK) plus common failure codes to `__()`-wrapped sentences, and use it for the message passed to `render_result_page()` at :65, :149 and :157. Keep the raw `ResultMsg` in `nicepay_log()` and the `result_msg` DB column for merchant diagnostics, or append it via a translatable format string rather than bare concatenation:

```php
printf(
    /* translators: 1: localized message, 2: gateway message */
    __( '%1$s (%2$s)', 'nicepay-payment-gateway' ),
    $localized,
    $result_msg
);
```

**Verification note.** The pass-through and the concatenation are verified verbatim from code. The claim that `ResultMsg` text is *Korean* rests on the vendor being a Korean PG whose manual documents these as Korean merchant messages — no live response was observed, so the exact wording is inferred while the pass-through itself is certain.

**Effort:** medium

---

#### I18N-03 · 🔴 HIGH · `encoding` · ⚠️ **PLAUSIBLE — needs confirmation**
**Selecting the offered EUC-KR charset sends UTF-8 bytes under a EUC-KR label and makes `json_decode` fail, triggering a net-cancel on every payment**

**Files:** [includes/class-nicepay-api.php:212-241](../../includes/class-nicepay-api.php#L212) · [:300](../../includes/class-nicepay-api.php#L300) · [:349](../../includes/class-nicepay-api.php#L349) · [:374](../../includes/class-nicepay-api.php#L374) · [admin/class-nicepay-admin.php:236-241](../../admin/class-nicepay-admin.php#L236)

**Problem.** The admin offers a Charset setting with UTF-8 and EUC-KR options:

```php
// admin/class-nicepay-admin.php:239-240
<option value="utf-8" ...>UTF-8</option>
<option value="euc-kr" ...>EUC-KR</option>
```

The value flows into `$this->charset` ([includes/class-nicepay-api.php:24](../../includes/class-nicepay-api.php#L24)) and is used three ways: as the `CharSet` API parameter (:204, :300, :349), as the HTTP `Content-Type` charset on all three server-to-server calls (:217, :309, :364), and as `accept-charset` on the browser forms ([templates/payment-form.php:51](../../templates/payment-form.php#L51), [templates/standalone-payment-form.php:128](../../templates/standalone-payment-form.php#L128)). **No transcoding happens anywhere** — `grep -rn 'mb_convert_encoding\|iconv' --include='*.php' .` returns zero matches outside tests.

Two consequences when EUC-KR is selected:

1. **Request side.** The cancel call sends `'CancelMsg' => $cancel_msg` (:344) — free text the admin typed in the cancellation modal — as UTF-8 bytes labelled EUC-KR. Mojibake in NicePay's records.
2. **Response side (the serious one).** Responses are parsed with `json_decode( $body, true )` with no decoding step. PHP's `json_decode` requires UTF-8 and returns `NULL` on invalid UTF-8, so an EUC-KR body (whose `ResultMsg` carries Korean bytes) yields `$result === null`, hits `if ( ! $result )`, and on the approval path **calls `request_net_cancel()`**:

```php
// includes/class-nicepay-api.php:212-241
$response = wp_remote_post( $next_app_url, array(
    'timeout'   => 30,
    'sslverify' => true,
    'headers'   => array(
        'Content-Type' => 'application/x-www-form-urlencoded; charset=' . $this->charset,
    ),
    'body'      => $params,
) );
...
$body   = wp_remote_retrieve_body( $response );
$result = json_decode( $body, true );
if ( ! $result ) {
    nicepay_log( 'Approval response parse error' );
    if ( ! empty( $auth_data['NetCancelURL'] ) ) {
        $this->request_net_cancel( $auth_data );
    }
```

**Impact.** A merchant who picks EUC-KR from a dropdown the plugin itself offers would have **every successfully authenticated payment reported as a parse failure and immediately net-cancelled** — the whole gateway stops working, with no diagnostic beyond `Approval response parse error` in the log. EUC-KR is a plausible choice for a Korean PG integration and nothing in the UI warns against it. Even at the default UTF-8 setting, the missing transcoding means the EUC-KR option is dead code that should not be exposed.

**User scenario.** A Korean merchant integrating against a PG whose manual documents EUC-KR as the default charset selects EUC-KR in NicePay → Settings. Customers authenticate successfully at NicePay, then every approval is net-cancelled and the order fails with no useful error.

**Recommendation.** Either **(a)** remove the EUC-KR option from [admin/class-nicepay-admin.php:240](../../admin/class-nicepay-admin.php#L240) and hard-code `utf-8`, which is what the rest of the code assumes; or **(b)** implement it properly — transcode every non-ASCII outbound value with `mb_convert_encoding( $v, 'EUC-KR', 'UTF-8' )` before building the request body at :204/:300/:349, and transcode the response with `mb_convert_encoding( $body, 'UTF-8', 'EUC-KR' )` before `json_decode` at :231 and :374 whenever `$this->charset === 'euc-kr'`. **Option (a) is strongly preferable** — the spec accepts `utf-8` and every other part of the plugin is UTF-8.

**Verification note (why PLAUSIBLE).** Code-verifiable and certain: no transcoding exists, the charset value is used as an HTTP header label, and `json_decode` is applied to the raw body. The residual uncertainty is whether NicePay honours the `CharSet` parameter for the *response* encoding (the vendor manual describes it as governing both directions, but no live call was exercised). If it does not, only the request-side `CancelMsg` mojibake applies and severity drops to low.

**Effort:** small

---

### Medium

---

#### I18N-04 · 🟠 MEDIUM · `pluralization`
**Both `_n()` calls are unreachable by gettext: no `msgid_plural` exists in any catalog, and the `.mo` entries are keyed by the wrong string**

**Files:** [admin/class-nicepay-transactions.php:74](../../admin/class-nicepay-transactions.php#L74) · [includes/class-nicepay-gateway.php:137](../../includes/class-nicepay-gateway.php#L137) · [languages/nicepay-payment-gateway.pot:189](../../languages/nicepay-payment-gateway.pot#L189) · [:583](../../languages/nicepay-payment-gateway.pot#L583) · [languages/nicepay-payment-gateway-ko_KR.po:143](../../languages/nicepay-payment-gateway-ko_KR.po#L143) · [:434](../../languages/nicepay-payment-gateway-ko_KR.po#L434)

**Problem.** `grep -n 'msgid_plural' languages/*.po languages/*.pot` returns **zero matches across all five catalogs**, yet the code has two `_n()` calls:

```php
// admin/class-nicepay-transactions.php:74
_n( 'Total: %d transaction', 'Total: %d transactions', $total, 'nicepay-payment-gateway' )

// includes/class-nicepay-gateway.php:137
_n( ' and %d more item', ' and %d more items', $extra, 'nicepay-payment-gateway' )
```

The catalogs instead carry plain singular-style entries keyed by the wrong text:

```
# languages/nicepay-payment-gateway.pot:187-190
#. translators: %d: number of additional items
#: includes/class-nicepay-gateway.php
msgid " and %d more"
msgstr ""

# languages/nicepay-payment-gateway-ko_KR.po:143-144
msgid " and %d more"
msgstr " 외 %d건"
```

`pot:583` / `ko_KR.po:434` carry `msgid "Total: %d transactions"` — the **plural** form, translated `전체: %d건`. WordPress's `Translations::translate_plural()` builds the lookup key from the **singular** argument, so both lookups miss the `.mo` and `_n()` returns the raw English source.

**Impact.** Two user-visible strings render in English on every non-English site with no possible fix through translation: the Transactions list header `Total: N transactions`, and — more consequentially — the multi-item goods-name suffix concatenated into `$goods_name` at [class-nicepay-gateway.php:137](../../includes/class-nicepay-gateway.php#L137) and then sent to NicePay as the `GoodsName` form field (truncated to 40 bytes at :145). **A Korean customer paying for a 3-item order sees the product description inside the NicePay authentication window as `Widget and 2 more items`** — English text embedded in an otherwise Korean payment window.

**User scenario.** A Korean merchant opens **NicePay → Transactions** and reads `Total: 12 transactions` in English, even though `전체: %d건` visibly sits in `ko_KR.po` — the entry is simply never reached because it is keyed by the plural string, not the singular the code passes.

**Recommendation.** Regenerate with `wp i18n make-pot`, which emits proper plural entries (`msgid` = singular, `msgid_plural` = plural, `msgstr[0..n-1]`). Fill `msgstr[0..n-1]` per locale honouring each catalog's `Plural-Forms` header — `ko_KR` and `zh_CN` declare `nplurals=1; plural=0;` (one form), `en_US` and `tr_TR` declare `nplurals=2; plural=(n != 1);`. Delete the two orphan flat entries (`pot:189` ` and %d more`, `pot:583` `Total: %d transactions`). Do **not** reuse the tool that produced these files (`X-Generator: NicePay Payment Gateway`) — it does not understand `_n()`.

**Effort:** small

---

#### I18N-05 · 🟠 MEDIUM · `i18n-completeness`
**Preset shortcode labels are frozen into the DB as translated text at activation, permanently locking them to one locale with no reset path**

**Files:** [includes/nicepay-functions.php:325-403](../../includes/nicepay-functions.php#L325) · [:428-434](../../includes/nicepay-functions.php#L428) · [nicepay-payment-gateway.php:69](../../nicepay-payment-gateway.php#L69) · [:162](../../nicepay-payment-gateway.php#L162) · [:479-482](../../nicepay-payment-gateway.php#L479)

**Problem.** `nicepay_get_default_presets()` wraps every preset display string in `__()` — e.g. `:376` `'button_text' => __( 'Buy Now', 'nicepay-payment-gateway' ),` — and its whole return value is written verbatim into a single option:

```php
// nicepay-payment-gateway.php:162  (inside set_default_options(), called from activate())
add_option( 'nicepay_saved_shortcodes', nicepay_get_default_presets() );
```

Two independent defects follow.

**(a) Locale freezing — certain from the code alone.** Whatever text `__()` resolves to at seed time is persisted forever. `nicepay_get_all_shortcodes()` re-seeds only when the option is `null`:

```php
// includes/nicepay-functions.php:428-431
$shortcodes = get_option( 'nicepay_saved_shortcodes', null );
if ( $shortcodes === null ) {
    $shortcodes = nicepay_get_default_presets();
    update_option( ... );
}
```

…which is never true after activation created the option. `ajax_delete_shortcode()` ([:451-461](../../nicepay-payment-gateway.php#L451)) writes back an array, not `null`, so even deleting every preset does not re-seed. And there is **no reset-to-defaults UI anywhere** — `grep -in 'reset\|restore' admin/*.php includes/*.php nicepay-payment-gateway.php` finds no such path.

**(b) Very likely English seeding on first install.** `load_textdomain()` is registered at `nicepay-payment-gateway.php:69` on `plugins_loaded`, but on first activation WordPress has already fired `plugins_loaded` before `activate_plugin()` includes this file, so the hook never runs in that request and the bundled `languages/` directory is never registered — leaving `__()` to return English.

**Impact.** The 4 preset cards in NicePay → Shortcodes (`Quick Payment`, `Donation`, `Product Purchase`, `Subscription`) and, crucially, the **button labels they render on live pages** (`Pay Now`, `Donate`, `Buy Now`, `Subscribe`) plus the goods names sent to NicePay are locked to the seeding locale. A merchant cannot correct them except by deleting each preset and hand-recreating it. Today the symptom is entirely subsumed by I18N-01 (none of these strings exist in any catalog), which makes this **a latent trap inside the fix for I18N-01**.

**User scenario.** A merchant runs a bilingual site, or switches the site language from English to Turkish after setup. Settings and Transactions switch to Turkish; the four preset cards and the payment buttons those presets render on live pages stay in the original language with no UI to fix them.

**Recommendation.** Stop persisting translated text. Keep stored preset rows keyed only by their stable slugs (`quick-payment`, `donation`, `product-purchase`, `subscription`) and resolve `name` / `goods_name` / `button_text` through a `nicepay_get_preset_labels( $id )` lookup wrapped in `__()` **at render time** — in the admin card loop (around [:396](../../admin/class-nicepay-admin.php#L396)) and in the shortcode renderer — falling back to the stored value only for user-created (`is_preset === false`) entries. If storage must stay as-is, at minimum drop line 162 from `set_default_options()` so seeding happens lazily during a normal request (where the textdomain is loaded), and add a **"Restore default presets"** button to the Shortcodes tab.

**Verification note.** Part (a) is CONFIRMED from code alone. Part (b), the activation-hook ordering, rests on documented WordPress core lifecycle (`activate_plugin()`'s `include_once` happens long after `plugins_loaded` fired) and could not be runtime-traced here.

**Effort:** medium

---

#### I18N-06 · 🟠 MEDIUM · `i18n-completeness`
**NicePay window language never derives from the site locale; unmapped locales silently fall back to Korean**

**Files:** [includes/class-nicepay-api.php:464-479](../../includes/class-nicepay-api.php#L464) · [nicepay-payment-gateway.php:157](../../nicepay-payment-gateway.php#L157) · [admin/class-nicepay-admin.php:211-222](../../admin/class-nicepay-admin.php#L211) · [:565-571](../../admin/class-nicepay-admin.php#L565)

**Problem.** `NicePay_API::get_nicepay_lang()` resolves `NpLang` from exactly two sources — the `nicepay_language` option or an explicit shortcode `language` attribute:

```php
// includes/class-nicepay-api.php:464-479
public static function get_nicepay_lang( $lang = '' ) {
    if ( ! $lang ) {
        $lang = get_option( 'nicepay_language', 'KO' );
    }
    $map = array(
        'KO' => 'KO', 'KR' => 'KO', 'EN' => 'EN', 'CN' => 'CN', 'ZH' => 'CN',
    );
    $lang = strtoupper( $lang );
    return isset( $map[ $lang ] ) ? $map[ $lang ] : 'KO';
}
```

`get_locale()`, `determine_locale()` and `get_user_locale()` appear **nowhere** in the plugin (grep across all non-test PHP: zero matches). The activation default is Korean (`nicepay-payment-gateway.php:157` `add_option( 'nicepay_language', 'KO' );`) and the fallback branch is **also** Korean. Both selectors that expose the setting — the global one at `admin/class-nicepay-admin.php:213-221` and the per-shortcode one at `:566-571` — offer only Korean/English/Chinese, with **no description text anywhere** explaining that NicePay's window supports no other language and that a Turkish, Japanese or German storefront should therefore pick EN.

**Impact.** On a `tr_TR` (or any non-KO/EN/CN) store, a merchant who never opens NicePay → Settings → General gets the NicePay authentication window rendered **entirely in Korean for every customer** — on the one screen where the customer enters card or bank credentials. The site's own locale, which the plugin already knows, is never consulted. This is the most trust-sensitive step of checkout and the failure is silent.

**User scenario.** A Turkish merchant sets up a Turkish WooCommerce store, relies on the shipped `tr_TR` translation for the whole checkout, and never touches NicePay → Settings → General because nothing indicates it is required. Every customer who clicks Pay gets a Korean-language authentication popup.

**Recommendation.**
1. In `set_default_options()` ([nicepay-payment-gateway.php:157](../../nicepay-payment-gateway.php#L157)) seed from the site locale: map `get_locale()` `ko_*` → `KO`, `zh_*` → `CN`, everything else → `EN`.
2. Change the fallback at [includes/class-nicepay-api.php:478](../../includes/class-nicepay-api.php#L478) from `'KO'` to `'EN'` so an unrecognised code degrades to the universally readable option.
3. Add a `<p class="description">` under the Language select at [admin/class-nicepay-admin.php:221](../../admin/class-nicepay-admin.php#L221): *"NicePay's payment window supports Korean, English and Chinese only. If your store is in another language, choose English."*

**Spec reference.** NICEPAY spec §5 confirms `NpLang(2: EN/CN/KO default)`.

**Effort:** small

---

#### I18N-07 · 🟠 MEDIUM · `translation-quality`
**`tr_TR` catalog has systematic missing Turkish diacritics plus a typo in the real-money Live-mode warning**

**File:** [languages/nicepay-payment-gateway-tr_TR.po](../../languages/nicepay-payment-gateway-tr_TR.po)

**Problem.** The file is valid UTF-8 and many strings *are* correctly accented (`Kredi Kartı`, `yapılandırın`, `Sipariş bulunamadı.`), so this is inconsistent human editing, not an encoding failure.

| Line | Current `msgstr` | Should be |
|---|---|---|
| [303](../../languages/nicepay-payment-gateway-tr_TR.po#L303) | `Su anda Canli modddasınız. Ödemeler gercek para ile işlenecektir.` | `Şu anda Canlı modundasınız. Ödemeler gerçek para ile işlenecektir.` |
| [300](../../languages/nicepay-payment-gateway-tr_TR.po#L300) | `Su anda Test modundasiniz.` | `Şu anda Test modundasınız.` |
| [117](../../languages/nicepay-payment-gateway-tr_TR.po#L117) | `Etkinleştir/Devre Disi Bırak` | `Etkinleştir/Devre Dışı Bırak` |
| [135](../../languages/nicepay-payment-gateway-tr_TR.po#L135) | `API Ayarlari` | `API Ayarları` |
| [138](../../languages/nicepay-payment-gateway-tr_TR.po#L138) | `MID, Isyeri Anahtarı ve diger API ayarlarini … sayfasindan yapılandırın.` | `İşyeri`, `diğer`, `ayarlarını`, `sayfasından` |
| [189](../../languages/nicepay-payment-gateway-tr_TR.po#L189) | `Isyeri tarafindan iade talebi` | `İşyeri tarafından iade talebi` |
| [210](../../languages/nicepay-payment-gateway-tr_TR.po#L210) | `Yatirma Bilgileri` | `Yatırma Bilgileri` |
| [216](../../languages/nicepay-payment-gateway-tr_TR.po#L216) | `Hesap Numarasi` | `Hesap Numarası` |
| [246](../../languages/nicepay-payment-gateway-tr_TR.po#L246) | `Iade Edildi` | `İade Edildi` |
| [351](../../languages/nicepay-payment-gateway-tr_TR.po#L351) | `Hayir` | `Hayır` |
| [393](../../languages/nicepay-payment-gateway-tr_TR.po#L393), [435](../../languages/nicepay-payment-gateway-tr_TR.po#L435) | `islem` | `işlem` |
| [462](../../languages/nicepay-payment-gateway-tr_TR.po#L462) | `islemler` | `işlemler` |
| [483](../../languages/nicepay-payment-gateway-tr_TR.po#L483) | `yonetim panelinden` | `yönetim panelinden` |

`Canli` recurs at lines 276, 279, 303, 315, 318, 321 and should be `Canlı` throughout. `Isyeri` recurs at 135, 138, 189, 312, 321.

**Impact.** To a native Turkish reader — the author's own language, and one of only four shipped locales — `Su anda Canli modddasınız` reads as visibly broken machine output. `modddasınız` is a real typo (three `d`s, and `moddasınız` is not a Turkish word). It is the **safety banner shown when Live mode is selected**, i.e. the one string whose job is to make a merchant pause before real customer money starts moving; garbled text there undermines exactly the seriousness it is meant to convey. `Hesap Numarasi` is a core label on the VBANK deposit-info page seen by **paying customers**, and `Isyeri Anahtarı` labels the API credential field.

**User scenario.** A Turkish store owner switches Mode from Test to Live and the safety banner reads `Su anda Canli modddasınız. Ödemeler gercek para ile işlenecektir.` — visibly typo'd Turkish on the screen meant to signal that real money is now at stake.

**Recommendation.** Native Turkish proofread of the whole file in a Turkish-aware editor (Poedit with Turkish spellcheck). Fix `modddasınız` → `modundasınız` first. Then sweep the recurring patterns: `Canli`→`Canlı`, `Isyeri`→`İşyeri`, `islem`→`işlem`, `-lari`→`-ları`, `-sindan`→`-sından`, `diger`→`diğer`, `Su anda`→`Şu anda`, `gercek`→`gerçek`, `Iade`→`İade`, `Hayir`→`Hayır`, `Disi`→`Dışı`, `yonetim`→`yönetim`. Recompile the `.mo`.

**Effort:** small

---

#### I18N-08 · 🟠 MEDIUM · `locale-formatting`
**VBank deposit deadline shown as an unformatted 8-digit number with the time silently dropped; transaction timestamps rendered raw in the MySQL server's timezone**

**Files:** [includes/class-nicepay-return-handler.php:234-237](../../includes/class-nicepay-return-handler.php#L234) · [admin/class-nicepay-transactions.php:141](../../admin/class-nicepay-transactions.php#L141) · [includes/nicepay-functions.php:43-96](../../includes/nicepay-functions.php#L43) · [:175-180](../../includes/nicepay-functions.php#L175) · [nicepay-payment-gateway.php:136](../../nicepay-payment-gateway.php#L136)

**Problem.** `date_i18n()`, `wp_date()` and `current_time()` appear **nowhere** in the plugin (grep across all non-test PHP: zero matches). Two concrete consequences.

**(1) The VBank deadline is printed raw:**
```php
// includes/class-nicepay-return-handler.php:234-237
<?php if ( ! empty( $data['VbankExpDate'] ) ) : ?>
    <dt><?php esc_html_e( 'Deadline', 'nicepay-payment-gateway' ); ?></dt>
    <dd><?php echo esc_html( $data['VbankExpDate'] ); ?></dd>
<?php endif; ?>
```
Per the spec the approval response carries `VbankExpDate` as 8 chars (`yyyyMMdd`) and the hour/minute cutoff as a **separate** `VbankExpTime` (6, `HHmmss`) field. `grep -rn 'VbankExpTime' --include='*.php' .` returns **zero matches** — the time component is never read, never stored (the DB column is only `vbank_exp_date varchar(20)`) and never displayed.

**(2) Transaction timestamps are raw MySQL server time:**
```php
// admin/class-nicepay-transactions.php:141
<td><?php echo esc_html( $item->created_at ); ?></td>
```
`nicepay_save_transaction()` never supplies `created_at`, so the value comes from the column default at [nicepay-payment-gateway.php:136](../../nicepay-payment-gateway.php#L136) `created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` — the **MySQL server's** timezone, unrelated to the WordPress Settings → General timezone. The same column is compared against admin-entered dates in the filter query at [includes/nicepay-functions.php:175-180](../../includes/nicepay-functions.php#L175).

**Impact.** Every virtual-account customer sees their payment deadline as a bare 8-digit run of digits (`20260901`) in every locale including English, and has **no way to learn the actual hourly cutoff** because `VbankExpTime` is discarded — a customer could deposit after the real cutoff believing they had until midnight. Separately, admins see transaction timestamps offset from the rest of wp-admin by the MySQL server's UTC offset, never adapting to the site's date format, and the From/To filter is subtly off near day boundaries.

**User scenario.** A customer picks virtual-account payment, completes authentication, and lands on the result page reading **"Deadline: 20260901"** — not obviously even a date, with no indication of what hour the deposit window closes.

**Recommendation.** Capture `VbankExpTime` alongside `VbankExpDate` at [includes/class-nicepay-gateway.php:350-354](../../includes/class-nicepay-gateway.php#L350) and [includes/class-nicepay-return-handler.php:135-139](../../includes/class-nicepay-return-handler.php#L135), widen `vbank_exp_date` or add a `vbank_exp_time` column, and render:

```php
echo esc_html(
    date_i18n(
        get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
        strtotime( $exp_date . $exp_time )
    )
);
```

In `nicepay_save_transaction()` set `'created_at' => current_time( 'mysql' )` explicitly, and render it at [admin/class-nicepay-transactions.php:141](../../admin/class-nicepay-transactions.php#L141) through `date_i18n()` likewise.

**Spec reference.** §5 `VbankExpDate` (8 = `YYYYMMDD` or 12 = `YYYYMMDDHHMI` on the request); §6.4.3 the approval response carries `VbankExpDate(8, yyyyMMdd)` and `VbankExpTime(6, HHmmss)` as separate fields.

**Effort:** medium

---

#### I18N-09 · 🟠 MEDIUM · `locale-formatting`
**VBank deposit deadline and `EdiDate` are generated with bare `date()` in UTC, not the store's or Korea's timezone**

**File:** [includes/class-nicepay-api.php:426-429](../../includes/class-nicepay-api.php#L426) · [:60](../../includes/class-nicepay-api.php#L60) · [:67](../../includes/class-nicepay-api.php#L67)

**Problem.**
```php
// includes/class-nicepay-api.php:426-429
public function get_vbank_exp_date() {
    $days = (int) get_option( 'nicepay_vbank_expiry_days', 3 );
    return date( 'YmdHi', strtotime( '+' . $days . ' days' ) );
}

// :59-61
public function generate_edi_date() { return date( 'YmdHis' ); }

// :67
return $prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
```

WordPress calls `date_default_timezone_set( 'UTC' )` during bootstrap, so PHP's bare `date()` renders **UTC** regardless of the site's Settings → General timezone and regardless of Korea Standard Time (UTC+9), which is the timezone NicePay interprets the `YYYYMMDDHHMI` value in. A 3-day expiry generated at 20:00 UTC yields `202608222000`, but from a KST perspective the clock already reads 05:00 the next day — the real deposit window is **2 days 15 hours, not 3**. A grep for `date(` / `gmdate(` across `includes/`, `admin/`, `templates/` and the bootstrap returns exactly these three sites; no timezone-aware alternative is used anywhere.

**Impact.** Every VBANK customer gets a deposit window up to 9 hours shorter than the `nicepay_vbank_expiry_days` setting promises, and the shortfall is invisible to the merchant. Combined with I18N-08 (deadline shown as a raw number, `VbankExpTime` never displayed), a customer can reasonably believe they still have time and lose the payment. WordPress coding standards flag bare `date()` for exactly this reason.

**User scenario.** A Korean customer selects virtual-account payment at 20:00 KST on the 19th expecting to deposit by the 22nd. The plugin, running in UTC, stamps the deadline at a moment effectively 9 hours earlier in Korean terms; the customer deposits on the morning of the 22nd and the account has already expired.

**Recommendation.**
```php
public function get_vbank_exp_date() {
    $days = (int) get_option( 'nicepay_vbank_expiry_days', 3 );
    $dt   = new DateTime( 'now', new DateTimeZone( 'Asia/Seoul' ) );
    $dt->modify( '+' . $days . ' days' );
    return $dt->format( 'YmdHi' );
}

public function generate_edi_date() {
    return ( new DateTime( 'now', new DateTimeZone( 'Asia/Seoul' ) ) )->format( 'YmdHis' );
}
```
Switch the Moid component at `:67` to `gmdate()` since it only needs uniqueness. Surface the resulting deadline with `date_i18n()` per I18N-08.

**Verification note.** That WordPress forces PHP's default timezone to UTC is documented core behaviour (`wp-settings.php`), and the three call sites are verified verbatim. The exact KST interpretation on NicePay's side is inferred from the vendor being a Korean PG; regardless, generating a merchant-facing deadline in UTC while the site has a configured timezone is a defect on its own.

**Effort:** small

---

### Low

---

#### I18N-10 · 🟡 LOW · `locale-formatting`
**`nicepay_format_amount()` hardcodes en-US number separators and an ISO-code suffix, ignoring WooCommerce and site locale in all 6 display sites**

**Files:** [includes/nicepay-functions.php:229-239](../../includes/nicepay-functions.php#L229) · [admin/class-nicepay-transactions.php:133](../../admin/class-nicepay-transactions.php#L133) · [admin/class-nicepay-admin.php:396](../../admin/class-nicepay-admin.php#L396) · [templates/standalone-payment-form.php:73](../../templates/standalone-payment-form.php#L73) · [includes/class-nicepay-return-handler.php:232](../../includes/class-nicepay-return-handler.php#L232) · [:249](../../includes/class-nicepay-return-handler.php#L249) · [includes/class-nicepay-gateway.php:457](../../includes/class-nicepay-gateway.php#L457)

**Problem.**
```php
// includes/nicepay-functions.php:229-239
function nicepay_format_amount( $amount, $currency = '' ) {
    if ( ! $currency ) { $currency = get_option( 'nicepay_currency', 'KRW' ); }
    if ( $currency === 'KRW' ) {
        return number_format( (int) $amount ) . ' ' . $currency;
    }
    return number_format( (float) $amount, 2 ) . ' ' . $currency;
}
```
PHP's two- and three-argument `number_format()` **always** uses `,` for thousands and `.` for decimals regardless of locale, and the currency is appended as a bare ISO code rather than a symbol in the store's configured position. WooCommerce's own settings (thousand separator, decimal separator, currency position, decimal count) are never consulted, nor is `number_format_i18n()`. This output appears in six places, two of them customer-facing: the amount on the standalone payment form **before** the customer pays, and the amount on the post-payment result page.

**Impact.** Amounts render in a different style from the rest of the store — WooCommerce cart, checkout and order emails all use `wc_price()` and honour the merchant's separator and symbol-position settings. A store configured for `1.004 ₩` still gets `1,004 KRW` from every NicePay-rendered amount. Cosmetic; the value is never ambiguous or wrong.

**Recommendation.** Keep the function returning **plain text** — all six call sites wrap it in `esc_html()` — but make it store-aware:

```php
function nicepay_format_amount( $amount, $currency = '' ) {
    if ( ! $currency ) { $currency = get_option( 'nicepay_currency', 'KRW' ); }
    $decimals = ( $currency === 'KRW' ) ? 0 : 2;
    if ( function_exists( 'wc_get_price_thousand_separator' ) ) {
        return number_format(
            (float) $amount,
            $decimals,
            wc_get_price_decimal_separator(),
            wc_get_price_thousand_separator()
        ) . ' ' . $currency;
    }
    return number_format_i18n( (float) $amount, $decimals ) . ' ' . $currency;
}
```

> ⚠️ **Do not** substitute `wc_price()` naively. It returns HTML and every call site wraps the result in `esc_html()`, so a drop-in would print literal `<span class="woocommerce-Price-amount">` markup to users. If you want `wc_price()`, all six call sites must switch to `wp_kses_post()` or a direct echo in the same change.

Also update the 8 assertions in [tests/unit/NicePayFunctionsTest.php:30-56](../../tests/unit/NicePayFunctionsTest.php#L30) that pin the current en-US output, e.g. `$this->assertEquals( '10,000 KRW', nicepay_format_amount( 10000, 'KRW' ) );`.

**Effort:** small

---

#### I18N-11 · 🟡 LOW · `i18n-completeness`
**Four hardcoded English strings in `nicepay-admin.js` bypass the `wp_localize_script` i18n object entirely**

**Files:** [assets/js/nicepay-admin.js:198](../../assets/js/nicepay-admin.js#L198), [:204](../../assets/js/nicepay-admin.js#L204), [:234](../../assets/js/nicepay-admin.js#L234), [:257](../../assets/js/nicepay-admin.js#L257), [:268](../../assets/js/nicepay-admin.js#L268) · [admin/class-nicepay-admin.php:75-94](../../admin/class-nicepay-admin.php#L75)

**Problem.** Every other user-facing string in this file follows `nicepayAdmin.i18n.<key> || 'English fallback'` with the localized value as the primary path. But:

```js
// :198
NicePayToast.show('Copy failed', 'error', 2000);
// :204
NicePayToast.show(ok ? (nicepayAdmin.i18n.copied || 'Copied!') : 'Copy failed', ok ? 'success' : 'error', 2000);
// :234
NicePayToast.show(resp.data.message || 'Error', 'error');
// :257, :268 repeat :198
```

None of `Copy failed` or `Error` has a key in the `i18n` array localized at `admin/class-nicepay-admin.php:77-92`, which defines only: `confirm`, `cancel`, `cancelTitle`, `cancelMessage`, `cancelReasonLabel`, `cancelReasonPlaceholder`, `cancelConfirm`, `cancelFailed`, `requestFailed`, `copied`, `statusCancelled`, `shortcodeDeleted`, `deleteConfirmTitle`, `deleteConfirmMsg`, `delete`.

**Secondary inconsistency:** several fallback literals do not match the localized string they stand in for — `:195` falls back to `Copied!` while `:87` defines `Copied to clipboard!`; `:218` falls back to `Are you sure?` while `:90` defines `Are you sure? This cannot be undone.`

**Impact.** The copy-to-clipboard failure toast — shown whenever `navigator.clipboard.writeText` rejects or `execCommand('copy')` returns false — always renders as English, as does the generic delete-error toast. Small surface, but it breaks the otherwise consistent localization of every other toast and modal on the same screen.

**Recommendation.** Add to the i18n array at [admin/class-nicepay-admin.php:77-92](../../admin/class-nicepay-admin.php#L77):
```php
'copyFailed'   => __( 'Copy failed', 'nicepay-payment-gateway' ),
'genericError' => __( 'Error', 'nicepay-payment-gateway' ),
```
then reference `nicepayAdmin.i18n.copyFailed` at :198, :204, :257, :268 and `nicepayAdmin.i18n.genericError` at :234. While there, align the JS fallback literals with their localized counterparts so the two never diverge.

**Effort:** trivial

---

#### I18N-12 · 🟡 LOW · `i18n-completeness`
**`nicepay.js` has two hardcoded English fallbacks with no matching `wp_localize_script` keys**

**Files:** [assets/js/nicepay.js:54](../../assets/js/nicepay.js#L54), [:61](../../assets/js/nicepay.js#L61) · [nicepay-payment-gateway.php:297-307](../../nicepay-payment-gateway.php#L297)

**Problem.**
```js
// assets/js/nicepay.js:58-63
} else {
    this.hideLoading();
    this.showNotice(
        typeof nicepayParams !== 'undefined' ? nicepayParams.i18n.error : 'Payment system unavailable.',
        'error'
    );
}
```
```php
// nicepay-payment-gateway.php:301-306
'i18n' => array(
    'processing'   => __( 'Processing payment...', ... ),
    'error'        => __( 'Payment error occurred. Please try again.', ... ),
    'selectMethod' => __( 'Please select a payment method.', ... ),
),
```
There is no key for the specific "`nicepayStart` is not a function" condition that the `:61` branch exists to handle, so it shows either the translated-but-generic `error` string or the untranslatable English literal. **The standalone form does this correctly** at [templates/standalone-payment-form.php:281](../../templates/standalone-payment-form.php#L281), where the same condition emits a properly wrapped `__( 'Payment system is currently unavailable. Please try again later.', ... )` — the two code paths for the identical failure disagree.

**Impact.** Edge-case: fires only when NicePay's externally loaded `pgweb` JS fails to define `nicepayStart`. When it does, the customer gets a generic "Payment error occurred. Please try again." rather than the accurate message the standalone path already provides in their language.

**Recommendation.** Add `'unavailable' => __( 'Payment system is currently unavailable. Please try again later.', 'nicepay-payment-gateway' )` to the array at [nicepay-payment-gateway.php:302-306](../../nicepay-payment-gateway.php#L302) — reusing the **exact string** already at `standalone-payment-form.php:281` so both paths share one msgid — and reference `nicepayParams.i18n.unavailable` at `nicepay.js:61`.

**Effort:** trivial

---

#### I18N-13 · 🟡 LOW · `i18n-completeness`
**20 dead msgids remain in the `.pot`, inflating the apparent translation workload and masking the real gap**

**Files:** [languages/nicepay-payment-gateway.pot:189](../../languages/nicepay-payment-gateway.pot#L189) and 19 further entries · all four `.po` files

**Problem.** Diffing the `.pot`'s 156 non-empty msgids against the 209 units reachable in code leaves **20 msgids with no live call site**:

| Dead msgid | Note |
|---|---|
| ` and %d more` | mismatched remnant of the `_n()` plural (I18N-04) — matches neither form |
| `Available Parameters`, `Example with all parameters`, `Payment Button Shortcode`, `Use the following shortcode to embed a payment button on any page:` | removed static shortcode-reference section |
| `CSS class for the button`, `Button label text`, `Buyer email`, `Buyer name`, `Buyer phone`, `Payment amount`, `Product/service name` | parameter descriptions from the removed table |
| `Parameter`, `Shortcode`, `Yes`, `No` | table headers / cell values |
| `KRW or USD`, `KO, EN, or CN`, `CARD, BANK, VBANK, CELLPHONE` | parameter value hints |
| `Copy TID` | superseded by the parameterized `Copy TID %s` at [admin/class-nicepay-transactions.php:116](../../admin/class-nicepay-transactions.php#L116) |

These are the remains of the static shortcode-reference table replaced by the interactive Shortcode Generator (per [CHANGELOG.md](../../CHANGELOG.md)).

> ⚠️ A 21st apparent orphan, `NicePay Payment Gateway`, is **legitimate** — it is the plugin's `Name` header, translated by WordPress via `_get_plugin_data_markup_translate()`, and must be kept.

**Impact.** Every translator sees 156 entries where only 136 are reachable, so 13% of the nominal effort is wasted on strings no user will ever see. Worse, all four `.po` files report `156 translated messages`, which reads as 100% complete while 74 real strings are missing from the catalog altogether (I18N-01).

**Recommendation.** A correct `wp i18n make-pot` regeneration (the I18N-01 fix) drops these automatically since it extracts only from live source. When `msgmerge`-ing the `.po` files afterwards, review the resulting `#~` obsolete block and **delete** it rather than leaving dead entries. Verify `NicePay Payment Gateway` survives — `make-pot` picks up plugin headers when run against the plugin root.

**Effort:** trivial

---

#### I18N-14 · 🟡 LOW · `dead-ui-text`
**The transaction date filters have no accessible label at all — the translated `From`/`To` placeholders never render on `<input type="date">`**

**File:** [admin/class-nicepay-transactions.php:63-65](../../admin/class-nicepay-transactions.php#L63)

**Problem.**
```php
<input type="date" name="date_from" value="<?php echo esc_attr( $args['date_from'] ); ?>" placeholder="<?php esc_attr_e( 'From', 'nicepay-payment-gateway' ); ?>">
<input type="date" name="date_to"   value="<?php echo esc_attr( $args['date_to'] ); ?>"   placeholder="<?php esc_attr_e( 'To', 'nicepay-payment-gateway' ); ?>">
<input type="search" name="s"       value="<?php echo esc_attr( $args['search'] ); ?>"    placeholder="<?php esc_attr_e( 'Search...', 'nicepay-payment-gateway' ); ?>">
```
The HTML specification restricts the `placeholder` attribute to the Text, Search, URL, Telephone, Email, Password and Number input states — **Date is not among them**, so browsers ignore the attribute and show their own locale-derived format hint instead. Neither date input carries a `<label>`, an `aria-label`, or an `aria-labelledby`; nor does the adjacent search input. Reading lines 42-68 in full confirms no labelling of any kind exists in the filter form.

**Impact.** Two of the 156 catalog entries are translated in all four locales and can never be seen by any user. More importantly, a screen-reader user hears **three unlabeled form controls** in the filter row, and a sighted user in any language sees two bare date pickers with no indication which is the start and which is the end of the range.

**Recommendation.** Add real labels rather than relying on `placeholder`:
```php
<label class="screen-reader-text" for="nicepay-date-from"><?php esc_html_e( 'From', 'nicepay-payment-gateway' ); ?></label>
<input type="date" id="nicepay-date-from" name="date_from" value="<?php echo esc_attr( $args['date_from'] ); ?>">
```
Same for `To` and `Search...`; drop the `placeholder` attributes from the two date inputs. If a visible cue is wanted, render `From`/`To` as inline text before each picker.

**Effort:** trivial

---

#### I18N-15 · 🟡 LOW · `i18n-completeness`
**Seven hardcoded English strings in the Shortcode Generator are not wrapped in any translation function**

**Files:** [admin/class-nicepay-admin.php:562](../../admin/class-nicepay-admin.php#L562), [:580-585](../../admin/class-nicepay-admin.php#L580), [:619](../../admin/class-nicepay-admin.php#L619) · [nicepay-payment-gateway.php:394](../../nicepay-payment-gateway.php#L394)

**Problem.** Unlike the 74 strings of I18N-01 (correctly wrapped, missing from the catalog), these have **no `__()` at all** and can never be translated:

```php
// admin/class-nicepay-admin.php:562
<input type="text" id="sc-button-text" placeholder="Pay Now" class="nicepay-sc-input">

// :580-585
title="Blue"  title="Black"  title="Green"  title="Red"  title="Purple"  title="Orange"

// :619
<div class="nicepay-sc-pv-amount" id="sc-pv-amount">0 KRW</div>

// nicepay-payment-gateway.php:394  — persisted to the DB
'button_text' => isset( $_POST['button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['button_text'] ) ) : 'Pay Now',
```

Note that `Pay Now` is **already a translated msgid in all four catalogs**, so the placeholder at :562 is untranslated for no reason.

**Impact.** On a Korean or Turkish admin screen the Button Text field shows an English hint and all six colour tooltips read Blue/Black/Green/Red/Purple/Orange. Small, but these sit inside the same panel as the 74 already-broken strings, so a translator who fixes the catalog will still be left with visible English here and no way to find it.

**Recommendation.** Wrap all seven: `placeholder="<?php esc_attr_e( 'Pay Now', 'nicepay-payment-gateway' ); ?>"` at :562 (reusing the existing msgid), `title="<?php esc_attr_e( 'Blue', 'nicepay-payment-gateway' ); ?>"` and so on at :580-585. At :619 render the seed through `nicepay_format_amount( 0, 'KRW' )` instead of a literal. At `nicepay-payment-gateway.php:394` use `__( 'Pay Now', ... )` as the fallback — or better, **reject** an empty `button_text` rather than inventing an English default that will be persisted.

**Effort:** trivial

---

#### I18N-16 · 🟡 LOW · `locale-formatting`
**Shortcode Generator's Live Preview formats amounts with the browser locale while the saved-shortcode card beside it uses fixed en-US formatting**

**Files:** [admin/class-nicepay-admin.php:744-751](../../admin/class-nicepay-admin.php#L744) · [:396](../../admin/class-nicepay-admin.php#L396) · [includes/nicepay-functions.php:229-239](../../includes/nicepay-functions.php#L229)

**Problem.**
```js
// admin/class-nicepay-admin.php:744-751 (inline JS)
if (amount) {
    var formatted = currency === 'KRW'
        ? parseInt(amount).toLocaleString() + ' ' + currency
        : parseFloat(amount).toFixed(2) + ' ' + currency;
    $('#sc-pv-amount').text(formatted);
} else {
    $('#sc-pv-amount').text('0 ' + currency);
}
```
`Number.prototype.toLocaleString()` with no locale argument uses the **browser's** locale for separators. The saved-shortcode cards rendered directly below on the same screen use the PHP helper at `:396` `nicepay_format_amount( $sc['amount'], $sc['currency'] )`, hardcoded to en-US via `number_format()` — and so does the actual front-end form at [templates/standalone-payment-form.php:73](../../templates/standalone-payment-form.php#L73). The two never agree outside en-US-style browsers.

**Impact.** On a `de-DE`, `tr-TR` or `fr-FR` browser the same 10000 KRW amount reads `10.000 KRW` in the Live Preview and `10,000 KRW` on the card immediately below **and on the live page the shortcode produces**. The Live Preview's entire purpose is to show the merchant what visitors will see, and on any non-English browser it shows something different.

**User scenario.** A German-locale merchant types `10000` into Amount; the Live Preview shows `10.000 KRW` but the card they save, and the button visitors see, both show `10,000 KRW`.

**Recommendation.** Make the preview match the renderer. Either force the JS to en-US to mirror the PHP (`parseInt(amount).toLocaleString('en-US')`), or — alongside making `nicepay_format_amount()` locale-aware per I18N-10 — pass the store's separators into the script via `wp_localize_script` (`wc_get_price_thousand_separator()` / `wc_get_price_decimal_separator()`) and format with an explicit separator join, so preview and render share one definition.

**Effort:** trivial

---

#### I18N-17 · 🟡 LOW · `i18n-completeness`
**The plugin `Description` header is absent from the catalog, so the Plugins list shows English on every locale while the `Name` is translated**

**Files:** [nicepay-payment-gateway.php:5](../../nicepay-payment-gateway.php#L5) · [languages/nicepay-payment-gateway.pot](../../languages/nicepay-payment-gateway.pot)

**Problem.**
```
// nicepay-payment-gateway.php:5
Description: NicePay Payment Gateway integration for WooCommerce. Supports credit card, bank transfer, virtual account, and mobile payments.
```
WordPress translates **both** the `Name` and `Description` plugin headers through `_get_plugin_data_markup_translate()` when the text domain is loaded, and the catalogs do carry the `Name`:

| Locale | `msgid "NicePay Payment Gateway"` → |
|---|---|
| ko_KR (`.po:21`) | `NicePay 결제 게이트웨이` |
| tr_TR (`.po:21`) | `NicePay Ödeme Geçidi` |
| zh_CN (`.po:21`) | `NicePay 支付网关` |

The `Description` string appears in **none** of the five catalogs — `grep -c 'NicePay Payment Gateway integration for WooCommerce' languages/*` returns 0 in every file.

**Impact.** On the wp-admin Plugins screen a Korean, Turkish or Chinese admin sees a translated plugin name directly above an untranslated English description — a visible half-localized row on the first screen where the plugin is encountered.

**Recommendation.** Include the `Description` in the regenerated catalog (`wp i18n make-pot` extracts plugin headers automatically when run from the plugin root) and translate it in all four `.po` files. After regeneration verify that **both** `msgid "NicePay Payment Gateway"` and the Description msgid are present with their `#. Plugin Name of the plugin` / `#. Description of the plugin` comments — otherwise the already-translated `Name` entry will be silently dropped too.

**Effort:** trivial

---

#### I18N-18 · 🟡 LOW · `i18n-completeness` · ⚠️ **PLAUSIBLE — needs confirmation**
**Translated strings injected into inline JS via `esc_js()` will be HTML-entity-mangled if a translation contains an ampersand or double quote**

**Files:** [templates/standalone-payment-form.php:211](../../templates/standalone-payment-form.php#L211), [:213](../../templates/standalone-payment-form.php#L213), [:215](../../templates/standalone-payment-form.php#L215), [:281](../../templates/standalone-payment-form.php#L281), [:288](../../templates/standalone-payment-form.php#L288), [:292](../../templates/standalone-payment-form.php#L292), [:297](../../templates/standalone-payment-form.php#L297), [:303](../../templates/standalone-payment-form.php#L303) · [admin/class-nicepay-admin.php:740](../../admin/class-nicepay-admin.php#L740), [:743](../../admin/class-nicepay-admin.php#L743), [:786-787](../../admin/class-nicepay-admin.php#L786), [:834](../../admin/class-nicepay-admin.php#L834), [:837](../../admin/class-nicepay-admin.php#L837), [:861](../../admin/class-nicepay-admin.php#L861)

**Problem.** All fifteen translated strings that reach inline JavaScript go through `esc_js()`:
```php
// templates/standalone-payment-form.php:213
errorMsg = '<?php echo esc_js( __( 'Please enter a valid email address.', 'nicepay-payment-gateway' ) ); ?>';

// admin/class-nicepay-admin.php:834
btn.find('span').text('<?php echo esc_js( __( 'Copied!', 'nicepay-payment-gateway' ) ); ?>');
```
WordPress's `esc_js()` runs `_wp_specialchars( $text, ENT_COMPAT )` first, converting `&`→`&amp;`, `<`→`&lt;`, `>`→`&gt;`, `"`→`&quot;`; only single quotes are restored afterwards. Because these values are then assigned with `.text()` / `textContent`, the entities are **shown literally** rather than decoded.

No shipped translation currently trips this — scanning all four `.po` files for msgstrs containing `&`, `"`, `<` or `>` returns only the "Configure MID…" string, which is not used in a JS context. **This is latent, not active.**

**Impact.** A future translation such as German `Name & E-Mail erforderlich`, or any msgstr using a typographic double quote, renders to the user as `Name &amp; E-Mail erforderlich` inside the standalone form's validation errors or the admin's copy toast. Silent, locale-specific, hard to attribute.

**Recommendation.** Replace `esc_js( __( ... ) )` with `wp_json_encode( __( ... ) )` at all fifteen sites and drop the surrounding single quotes:
```php
errorMsg = <?php echo wp_json_encode( __( 'Please enter a valid email address.', 'nicepay-payment-gateway' ) ); ?>;
```
The file **already uses this correct pattern** for method labels at [admin/class-nicepay-admin.php:766-768](../../admin/class-nicepay-admin.php#L766):
```php
$icon_json  = wp_json_encode( $icon_html );
$label_json = wp_json_encode( $label );
echo "methodsHtml += '<div class=\"nicepay-sc-pv-method-option\">' + {$icon_json} + ' ' + {$label_json} + '</div>';\n";
```
so this is a consistency fix as much as a correctness one. Better still, move these strings into the existing `wp_localize_script` payloads, which handle encoding correctly by construction.

**Verification note (why PLAUSIBLE).** The call sites and the absence of any currently-affected translation are verified from the repo. The `esc_js()` internals (`_wp_specialchars` with `ENT_COMPAT`, then a regex restoring only `&#39;`/`&#x27;`) are WordPress core, which is not present in this checkout, so the exact entity behaviour was not executed here.

**Effort:** small

---

#### I18N-19 · 🟡 LOW · `i18n-completeness`
**Card and bank company names are hardcoded Latin romanizations with no translation path**

**File:** [includes/nicepay-functions.php:285-303](../../includes/nicepay-functions.php#L285) · [:305-323](../../includes/nicepay-functions.php#L305)

**Problem.** `nicepay_get_card_name()` and `nicepay_get_bank_name()` map NicePay's numeric issuer codes to hardcoded English romanizations with **no `__()` wrapper** on any of the 22 card entries or 22 bank entries:
```php
// includes/nicepay-functions.php:292-296
'01' => 'BC', '02' => 'KB Kookmin', '03' => 'Hana(KEB)', '04' => 'Samsung',
'06' => 'Shinhan', '07' => 'Hyundai', ... '14' => 'Shinheup', '15' => 'Woori BC',
```
This directly contrasts with `nicepay_get_status_label()` immediately above at [:266-277](../../includes/nicepay-functions.php#L266) and `NicePay_API::get_payment_method_name()` at [includes/class-nicepay-api.php:434-458](../../includes/class-nicepay-api.php#L434), **both** of which correctly wrap every label in `__()` — ruling out a deliberate project-wide convention.

**Impact.** Korean merchants and customers — the primary market — see `KB Kookmin` and `Suhyup` where they expect 국민 and 수협, and no locale can change that. Several romanizations are also questionable: `Shinheup` at both `:294` and `:313` appears to be a typo for `Sinhyup` / 신협, and `iM Bank(Daegu)` mixes brand styles. A translation layer would let each locale correct these independently.

**User scenario.** A Korean merchant reviews NicePay → Transactions on a `ko_KR` site: the Status and Method columns are Korean, but the card and bank names read `KB Kookmin` and `Suhyup` in Latin script.

**Recommendation.** Wrap every value in both maps in `__( ..., 'nicepay-payment-gateway' )` with a translator comment noting these are Korean card issuer and bank brand names, so ko_KR can supply native forms (국민, 신한, 현대, 수협, 우체국, 카카오뱅크) while other locales keep the romanizations. Fix `Shinheup` → `Sinhyup` at :294 and :313 at the same time.

**Effort:** small

---

### Enhancement

---

#### I18N-20 · 🔵 ENHANCEMENT · `i18n-completeness`
**WooCommerce gateway title and description are persisted as translated text on first save, freezing the checkout label to the saving admin's locale**

**File:** [includes/class-nicepay-gateway.php:27-28](../../includes/class-nicepay-gateway.php#L27) · [:46-60](../../includes/class-nicepay-gateway.php#L46)

**Problem.**
```php
// includes/class-nicepay-gateway.php:27-28
$this->title       = $this->get_option( 'title', __( 'NicePay Payment', 'nicepay-payment-gateway' ) );
$this->description = $this->get_option( 'description', __( 'Pay securely via NicePay.', 'nicepay-payment-gateway' ) );

// :50 / :58 — matching __()-wrapped form-field defaults
'default' => __( 'NicePay Payment', 'nicepay-payment-gateway' ),
'default' => __( 'Pay securely via NicePay (Credit Card, Bank Transfer, Virtual Account, Mobile).', 'nicepay-payment-gateway' ),
```
WooCommerce's `process_admin_options()` persists whatever is in those inputs into `woocommerce_nicepay_settings` the first time the merchant saves the gateway screen — capturing the translated text as it appeared in the admin's language at that moment. From then on `get_option()` returns the stored string and `__()` is never consulted again.

**Impact.** On a multilingual store, or after a locale change, every shopper sees the payment method labelled in whatever language the admin happened to be using when they first pressed Save — including shoppers browsing in a different language. This is standard WooCommerce settings behaviour rather than a plugin bug, but a plugin that ships four locales and advertises multi-language support should not leave it unaddressed and undocumented.

**User scenario.** A Korean admin configures the gateway and saves; the store then serves English-speaking customers, who see `나이스페이 결제` as the payment method name at checkout.

**Recommendation.** Document the behaviour in [docs/CONFIGURATION.md](../CONFIGURATION.md) and add a `desc_tip` on the Title field explaining that the value is stored literally and should be translated with a string-translation plugin (WPML String Translation / Polylang) on multilingual stores. If you want it to follow the shopper, hook `woocommerce_gateway_title` and `woocommerce_gateway_description` for `$gateway_id === 'nicepay'` and return the `__()` value whenever the stored setting still equals the shipped English default.

**Verification note.** Code verified verbatim. The persistence step happens inside WooCommerce's `WC_Settings_API::process_admin_options()`, which is not in this repo, so the timing is from documented WooCommerce behaviour. Kept at *enhancement* precisely because it is conventional for every WooCommerce gateway — a polish gap for a multi-locale plugin, not a defect.

**Effort:** small

---

#### I18N-21 · 🔵 ENHANCEMENT · `layout-i18n` · ⚠️ **PLAUSIBLE — needs confirmation**
**Turkish labels run 85-150% longer than their English sources; the WooCommerce settings-table row and the transaction filter row need a visual check**

**Files:** [languages/nicepay-payment-gateway-tr_TR.po:117](../../languages/nicepay-payment-gateway-tr_TR.po#L117) · [:216](../../languages/nicepay-payment-gateway-tr_TR.po#L216) · [:297](../../languages/nicepay-payment-gateway-tr_TR.po#L297) · [:351](../../languages/nicepay-payment-gateway-tr_TR.po#L351) · [:423](../../languages/nicepay-payment-gateway-tr_TR.po#L423) · [:426](../../languages/nicepay-payment-gateway-tr_TR.po#L426) · [assets/css/nicepay.css:480-492](../../assets/css/nicepay.css#L480) · [includes/class-nicepay-gateway.php:42](../../includes/class-nicepay-gateway.php#L42) · [admin/class-nicepay-transactions.php:63-64](../../admin/class-nicepay-transactions.php#L63)

**Problem.** Comparing short (≤14 char) English msgids against their `tr_TR` msgstrs:

| msgid | len | tr_TR msgstr | len | Δ |
|---|---:|---|---:|---:|
| `Enable/Disable` | 14 | `Etkinleştir/Devre Disi Bırak` | 28 | +100% |
| `Account` | 7 | `Hesap Numarasi` | 14 | +100% |
| `Charset` | 7 | `Karakter Seti` | 13 | +86% |
| `No` | 2 | `Hayir` | 5 | +150% |
| `From` | 4 | `Başlangıç` | 9 | +125% |
| `To` | 2 | `Bitiş` | 5 | +150% |

The plugin's own components mitigate this well — `.nicepay-status` is `display: inline-flex` with `white-space: nowrap` and **no fixed width**, so status badges grow rather than truncate:
```css
/* assets/css/nicepay.css:480-492 */
.nicepay-status {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: var(--nicepay-radius-full);
    font-size: 0.6875rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.04em; line-height: 1; white-space: nowrap;
}
```
The residual risk is in containers this plugin does **not** style: `Enable/Disable` is emitted as a WooCommerce core settings-table `<th>` via [includes/class-nicepay-gateway.php:42](../../includes/class-nicepay-gateway.php#L42), whose column width is governed by WooCommerce's CSS, and the three filter inputs at [admin/class-nicepay-transactions.php:63-65](../../admin/class-nicepay-transactions.php#L63) sit in a flow row.

**Impact.** Probably minor given the flexible plugin CSS, but it could not be ruled out from static analysis for the WooCommerce-owned settings table, where a 28-character label in a fixed-proportion `<th>` may wrap awkwardly or crowd the adjacent control.

**Recommendation.** One manual visual pass with the admin language set to Turkish: **WooCommerce → Settings → Payments → NicePay** (check the `Enable/Disable` row and the `desc_tip` tooltips) and **NicePay → Transactions** (check the filter row and the status badge column). If the settings-table label wraps badly, shorten the Turkish msgstr to `Etkinleştir` or `Aç/Kapat`.

**Verification note (why PLAUSIBLE).** The string-length observation is accurate and read directly from the file, but no browser rendering was performed and layout behaviour inside WooCommerce core's settings table cannot be determined from this repo.

**Effort:** trivial

---

## 5. Terminology consistency per locale

| Locale | Verdict | Notes |
|---|---|---|
| **ko_KR** | ✅ Strong | Precisely matches official NICEPAY / Korean payments industry vocabulary: 신용카드 (Credit Card), 계좌이체 (Bank Transfer), 가상계좌 (Virtual Account), 휴대폰 결제 (Mobile Payment), 취소 (Cancel), 입금기한 (Deposit Deadline). This is the most consequential locale given the stated primary market and it is in genuinely good shape terminology-wise. Two caveats sit **outside** the catalog: hardcoded Latin card/bank names (I18N-19) and the two unreachable `_n()` strings (I18N-04). |
| **tr_TR** | ⚠️ Needs a proofread | Terminology choices are sound (`Kredi Kartı`, `Sanal Hesap`, `İptal`) but diacritics are applied inconsistently across ~22 lines, and the Live-mode safety banner carries a real typo (`modddasınız`). Word length also runs 85-150% over English on short labels. See **I18N-07** and **I18N-21**. |
| **zh_CN** | ✅ No issues found | 0 fuzzy, 0 empty, placeholders intact, `Plural-Forms: nplurals=1` correctly declared. No terminology defects surfaced by this audit. Subject to the same 35% catalog gap as every other locale (I18N-01). |
| **en_US** | ✅ Complete but redundant | A full `en_US` catalog duplicating the source strings is harmless and occasionally useful for copy-editing without touching code, but it doubles the maintenance surface: every `.pot` regeneration must merge into it too. Note it declares `nplurals=2`, which is correct. |
| **All four** | ✅ Placeholders clean | Zero `%s`/`%d`/`%N$s` count mismatches. Zero fuzzy. Zero empty msgstr. All `.mo` files match a fresh `msgfmt`. |

---

## 6. Action list to reach 100%

Ordered so that each step unblocks the next. Steps 1-3 are the load-bearing ones — without them, translating anything else is wasted effort.

| # | Action | Fixes | Effort |
|---|---|---|---|
| 1 | Replace the custom extractor (`X-Generator: NicePay Payment Gateway`) with `wp i18n make-pot`, regenerate the `.pot` from the plugin root so plugin headers are captured. | I18N-01, I18N-04, I18N-13, I18N-17 | M |
| 2 | Wrap the 13 never-wrapped strings **before** step 1's extraction so they are captured in the same pass: 7 in the Shortcode Generator, 2 JS fallback keys, 4 admin-JS keys. | I18N-11, I18N-12, I18N-15 | S |
| 3 | `msgmerge --update --backup=none` each of the 4 `.po` files against the new `.pot`; delete the resulting `#~` obsolete block; translate the ~74 new entries and both plural sets, ko_KR first. Recompile `.mo` with `msgfmt`. | I18N-01, I18N-04, I18N-13 | M |
| 4 | Add a CI job to `.github/workflows/tests.yml` that runs `make-pot` into a temp file and fails on a non-empty `diff` against the committed `.pot` (ignoring `POT-Creation-Date`). This is what prevents the whole problem recurring. | I18N-01 | S |
| 5 | Stop persisting translated preset labels — resolve slugs to `__()` labels at render time; add a "Restore default presets" button. **Do this before step 3 ships**, or the newly-translated preset strings freeze on first activation. | I18N-05 | M |
| 6 | Localize the payment result message: add `nicepay_get_result_message( $code, $method )` and stop passing raw `ResultMsg` to `render_result_page()`; replace the two concatenations with `sprintf()` format strings. | I18N-02 | M |
| 7 | Either remove the `EUC-KR` charset option or implement `mb_convert_encoding` on both request and response paths. Removal is strongly preferred. | I18N-03 | S |
| 8 | Seed `nicepay_language` from `get_locale()`; change the `get_nicepay_lang()` fallback from `KO` to `EN`; add the "Korean, English and Chinese only" description under both selectors. | I18N-06 | S |
| 9 | Native Turkish proofread of `tr_TR.po`; fix `modddasınız` and the recurring diacritic patterns; recompile. | I18N-07 | S |
| 10 | Make dates timezone-correct: `Asia/Seoul` for `EdiDate` and the VBank deadline, `current_time('mysql')` for `created_at`, `date_i18n()` for all display. Capture and display `VbankExpTime`. | I18N-08, I18N-09 | M |
| 11 | Make `nicepay_format_amount()` store-aware (keeping plain-text output), update the 8 pinned test assertions, and align the Live Preview JS with it. | I18N-10, I18N-16 | S |
| 12 | Replace `esc_js( __() )` with `wp_json_encode( __() )` at all 15 inline-JS injection sites. | I18N-18 | S |
| 13 | Wrap the 44 card/bank names in `__()`; fix `Shinheup` → `Sinhyup`; supply Korean forms in `ko_KR`. | I18N-19 | S |
| 14 | Add `screen-reader-text` labels and `id`s to the three transaction filter controls; drop the inert `placeholder` attributes from the two date inputs. | I18N-14 | S |
| 15 | Document the WooCommerce title/description persistence behaviour in `docs/CONFIGURATION.md` and add a `desc_tip`. | I18N-20 | S |
| 16 | One manual visual pass in Turkish over the WooCommerce settings table and the transactions filter row. | I18N-21 | S |

**Definition of done for "100%":** `wp i18n make-pot` produces a `.pot` byte-identical (modulo `POT-Creation-Date`) to the committed one; `msgfmt --statistics` reports `N translated messages, 0 fuzzy, 0 untranslated` for all four locales where `N` equals the live msgid count; `grep -c msgid_plural languages/*.po` returns 2 for every file; and a grep for user-facing string literals outside `__()` returns only CSS class names.
