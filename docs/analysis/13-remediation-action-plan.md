# NicePay Payment Gateway — Remediation Action Plan

> [!WARNING]
> **Frozen historical plan.** This plan is tied to baseline commit `5855db1` (2026-08-19). It is retained as an audit artifact, not as the current release gate. Use `docs/analysis/pr-review2/17-action-plan.md` and current tests for release decisions.

**Prepared:** 2026-08-19
**Implementation branch:** `development`
**Baseline commit:** `5855db1ac0fffe7c98e0c354158d9071fc32d420`
**Source of truth:** [01-findings-index.md](01-findings-index.md) and the detailed reports 02–12

## 1. Objective

Close every surviving finding in the analysis set without weakening the parts that are already correct, and produce an auditable release candidate that is safe to test with real WooCommerce and NICEPAY sandbox environments.

The 489 findings are not 489 independent code changes. They reduce to the work packages below because the same underlying defect is often reported from protocol, money, security, platform, UX, test, and documentation perspectives. A work package is complete only when every linked finding has one of these evidence-backed closure states:

- `FIXED`: implementation and regression test landed.
- `SUPERSEDED`: a broader implementation removed the affected surface; evidence identifies the replacement.
- `VERIFIED_NOT_APPLICABLE`: the report's consequence was disproved in a supported runtime or vendor fixture.
- `EXTERNAL_BLOCKED`: a vendor/account/policy dependency is recorded; the affected feature remains disabled and is not advertised.

`WONTFIX` is not an acceptable closure state for this program.

## 2. Verified starting state

- Local `development` was created from `origin/development` and fast-forwarded to `main`; both local branches point to `5855db1`.
- `origin/development` is two commits behind locally. No push is part of this plan unless explicitly requested.
- The only pre-existing working-tree change is the untracked `docs/analysis/` review set.
- Baseline in an isolated PHP 8.2 container: **129 tests, 170 assertions, all passing**.
- All repository PHP files pass `php -l` on PHP 8.2.
- The baseline is not a production-safety signal: `phpunit.xml` explicitly excludes both payment orchestration classes and there is no real WordPress/WooCommerce integration suite.

## 3. Non-negotiable safety rules

1. **No production enablement before the P0 gate.** The gateway and incomplete methods default to disabled.
2. **Server is the authority for money and identity.** Browser values may be presentation/input, never authority for amount, currency, MID, order, method, or state.
3. **Inbound messages cannot mutate state before verification and binding.** Unverifiable failure/cancel messages may affect the response shown to the browser, but not the order or ledger.
4. **State transitions are atomic and monotonic.** A replay or concurrent callback cannot re-approve or downgrade a terminal transaction.
5. **Unknown money outcomes remain visible.** They become `needs_reconciliation`; they are never flattened into a generic failure or silently retried.
6. **Migration precedes code that needs new columns.** Fresh-install success is not sufficient; upgrades from 2.0.0 must be repeatable and data-preserving.
7. **Plausible findings require proof.** Vendor, browser, WordPress, WooCommerce, and database behavior is validated before irreversible implementation.
8. **Documentation changes land with behavior.** Documentation cannot promise a future state or retain a known contradiction.
9. **One owner per file in each parallel wave.** No more than two implementation agents plus the lead work concurrently.
10. **Every money-path change has a failing regression test first or in the same change set.**

## 4. Decisions made for the safe default

These decisions minimize risk and can be implemented without business-owner input:

| Decision | Default |
|---|---|
| Gateway activation | Disabled on fresh install; existing explicit merchant choice is preserved during upgrade. |
| Standalone price | Fixed server-side price only. Open/custom amount remains unavailable until explicit minimum, maximum, currency, and abuse rules exist. |
| VBANK | Disabled until the deposit-notification contract and full lifecycle pass sandbox tests. Account issuance alone is not treated as support. |
| Charset | UTF-8 only. The inert EUC-KR option is removed rather than partially implemented. |
| Unsupported currency/method | Fail closed and explain the reason in admin; never send a best-effort request. |
| Shopper cancellation | Leave the order retryable; do not create a failed-order event from unauthenticated browser input. |
| Manual WC order cancellation | Warn about captured funds; do not auto-refund without an explicit merchant action/policy. |
| Ambiguous cancel/net-cancel | Record `needs_reconciliation`; never blind-retry without a vendor idempotency guarantee. |
| Financial data on uninstall | Retain by default. Deletion is explicit, scoped, and documented. Ephemeral secrets/tokens get a short retention policy. |
| Recurring/escrow/tax claims | Not advertised until contract, implementation, tests, and vendor certification all exist. The misleading Subscription preset is removed/renamed immediately. |

## 5. Critical decision gates

The following decisions materially change the implementation and require external evidence or owner approval:

### DG-01 — NICEPAY protocol target

The plugin implements the legacy PG-Web v3 / manual v2.0.8 flow, while NICEPAY's current public documentation describes a different v1 API/JS SDK. Core safety work—server authority, binding, idempotency, audit, safe defaults—can proceed on the current integration. Before certifying VBANK, refunds, net-cancel, timeout semantics, or optional features, obtain written confirmation that the merchant's MID is provisioned for this legacy flow and that its endpoints remain supported. If not, stop feature work and create a separate migration ADR for the current API.

### DG-02 — Vendor fixtures and sandbox access

Required evidence: successful and failed auth returns, approval success/decline, padded amount behavior, net-cancel success/failure/timeout, full and partial cancel, VBANK issuance, VBANK deposit notification, and replay behavior. Fixtures must be sanitized before commit.

### DG-03 — Product/data policy

Owner input is required for open-amount payments, financial-record retention periods, raw response retention, update distribution channel, partial-refund UX, tax/escrow, and recurring billing. Safe defaults above apply until each decision is made.

## 6. Remediation roadmap

### P0 — Production blockers

P0 is complete only when all packages below pass their acceptance gates. If a method is externally blocked, it must remain disabled and absent from product claims.

#### R01 — Protocol contract and golden fixtures

**Purpose:** freeze known-correct cryptographic behavior and prove every uncertain vendor assumption before changing the payment core.

**Work**

- Keep all eight existing signature formulas unchanged.
- Add vendor/golden fixtures for auth, approval, cancel, and net-cancel.
- Record the exact supported host/path/port set, response encoding, HTTP status behavior, timeout rules, result codes, and field byte limits.
- Confirm whether `ReqReserved` is echoed reliably before using it as defense-in-depth binding.
- Confirm legacy protocol support under DG-01.

**Acceptance gate**

- Existing signature contract tests remain byte-for-byte green.
- No alternative amount/signature encoding is accepted without a real failing vendor fixture.
- Unconfirmed methods/currencies/features are disabled.

#### R02 — Versioned schema, repository, and data lifecycle

**Purpose:** create the durable transaction record required by every later safety fix.

**Work**

- Replace activation-only schema creation with a versioned, repeatable migrator that runs on normal plugin load and multisite site creation.
- Remove `IF NOT EXISTS` so `dbDelta()` can compare definitions.
- Add explicit schema maps/format arrays and whitelist insert/update columns.
- Add collision-resistant unique `moid`; define unique/nullable `tid` behavior and useful composite indexes.
- Persist at least: flow/context, MID, mode, currency, captured amount, refunded amount, remaining amount, `approving`/reconciliation state, cancel/net-cancel audit fields, OTID, VBANK expiry/time, wallet/cash-receipt fields where supported.
- Define retention for pending attempts, auth tokens, logs, and raw/allowlisted response data.
- Preserve financial rows on uninstall unless explicit scoped deletion is selected.

**Acceptance gate**

- Fresh install and upgrade from a seeded 2.0.0 schema produce the same target schema.
- Migration is idempotent, preserves existing rows, reports duplicates, and never silently chooses among duplicate Moids.
- Forced DB failures are observable and stop unsafe continuation.
- Real database integration tests cover CRUD, filters, pagination, indexes, and migration rollback guidance.

#### R03 — Server-authoritative payment initialization

**Purpose:** eliminate client-supplied amount signing and stale/frozen authentication payloads.

**Work**

- Resolve standalone amount, goods, currency, allowed method, and policy from a saved configuration ID or a server-signed immutable configuration—not from XHR/DOM values.
- Return authoritative normalized values and overwrite presentation fields before invoking NICEPAY.
- Mint `EdiDate`, `Moid`, `SignData`, and VBANK expiry immediately before each payment attempt, not during page render.
- For WooCommerce, resolve total and currency from the current order and bind the attempt to the order/payment key.
- Normalize amounts once. KRW rounds deterministically; invalid, exponent, locale-ambiguous, non-finite, negative, and unsupported-currency values fail closed.
- Add anonymous throttling, attempt deduplication, expiry/reaping, field limits, and meaningful 400/403/429 responses.
- Treat the public nonce as CSRF/reliability plumbing, not price authorization; make cached-page recovery explicit.

**Acceptance gate**

- Tampering amount, currency, goods, method, MID, hidden fields, or XHR payload cannot change the signed request.
- Unknown/expired configuration and out-of-policy amount create no transaction and no signature.
- Page-cache and nonce-expiry scenarios recover without accepting untrusted authority.
- Missing live credentials and disabled methods render no payable form.

#### R04 — Shared inbound binding and validation processor

**Purpose:** ensure a valid NICEPAY signature can be applied only to the transaction it was created for.

**Work**

- Extract one shared processor used by WooCommerce and standalone adapters.
- Require an existing transaction of the correct flow before approval.
- Before approval, compare stored and posted MID, integer-normalized amount, Moid, flow, method, currency, and for WooCommerce the current order total/currency.
- Use a tamper-evident context binding (`ReqReserved` only after R01 proof); do not rely on Moid prefixes.
- After approval, require response TID/MID/Moid/Amt/PayMethod to match stored expectations.
- Preserve exact response amount bytes for signature verification while using canonical numeric equality for reconciliation.
- Missing/unknown method and cross-flow callbacks fail closed.

**Acceptance gate**

- A valid cheap-payment callback with another order's Moid causes zero approval calls and no order mutation.
- WC/standalone cross-posts, unknown Moids, wrong MID/amount/method/currency, and response mismatches cannot settle an order.
- The two adapters have parity tests for every supported method and failure branch.

#### R05 — Atomic, idempotent, monotonic state machine

**Purpose:** eliminate replay, concurrency, duplicate-attempt, and failure-before-verification corruption.

**Work**

- Add an atomic compare-and-set claim such as `pending -> approving`; transients are not locks.
- Persist TxTid/auth context before approval so crash recovery and net-cancel remain possible.
- Make terminal states monotonic; paid/waiting/refunded/cancelled cannot be overwritten by replay or unauthenticated failure.
- Verify and bind before any write. Unverifiable non-`0000` browser returns produce no persistent mutation.
- Prevent approval when the WC order no longer needs payment.
- Reuse one current attempt or mark replaced attempts `abandoned`; do not overwrite the winning TID/Moid.
- Treat shopper close/cancel as retryable; initialization failure leaves the order pending.

**Acceptance gate**

- Same callback twice invokes approval once and never downgrades an order.
- Two concurrent callbacks yield exactly one owner of the `approving` transition.
- Receipt refresh creates no duplicate active payable row.
- Crash/restart scenarios leave an auditable recoverable state.

#### R06 — HTTP, SSRF, approval abort, and net-cancel safety

**Purpose:** prevent ambiguous network outcomes from becoming invisible money loss.

**Work**

- Inject the HTTP transport and centralize request options.
- Validate HTTPS, normalized exact host, approved port, and vendor-confirmed path; do not hardcode per-transaction URLs that the protocol intentionally returns.
- Disable redirects; check 2xx status before parsing; distinguish transport, HTTP, JSON, required-field, signature, and PG-decline failures.
- Implement the confirmed 5-second connect / 30-second total-read policy without global cURL side effects.
- Attempt net-cancel on every post-authorization merchant abort, including URL rejection and local persistence failure, where the vendor contract requires it.
- Verify TID, amount, result code, and required signature for claimed cancel success.
- Persist confirmed reversal, confirmed rejection, and unknown outcome separately; unknown becomes `needs_reconciliation` and is never blind-retried.
- Log money-state errors in production through the WC logger with recursive PII/secret redaction.

**Acceptance gate**

- Hostile URL corpus, redirect, non-2xx, invalid JSON, timeout, missing fields, bad signature, and local DB failure have deterministic tested outcomes.
- Net-cancel result is never discarded; an unknown outcome creates a durable admin/order warning.
- Logs contain no merchant key, auth token, buyer PII, PAN, account number, or forged newline.

#### R07 — Refund, cancel, and remaining-balance ledger

**Purpose:** make WooCommerce accounting match NICEPAY reversals exactly.

**Work**

- Route WooCommerce-linked admin refunds through one `wc_create_refund(..., refund_payment => true)` flow; never cancel at NICEPAY first and then create a second refund.
- Resolve the transaction from the nonce-covered row ID; never trust a posted TID.
- Generate a unique cancel Moid and persist request/response IDs, OTID, CancelNum/time, requested/actual amount, refunded/remaining amount, and outcome.
- Compare returned cancel amount; require the verified response fields for success.
- Support multiple partial refunds without terminalizing the row after the first partial.
- Enforce `CcPartCl`/wallet restrictions and VBANK refund-account requirements only from vendor-confirmed rules.

**Acceptance gate**

- Full and repeated partial refunds produce one PG request and one matching WC refund each.
- Amount mismatch, missing signature, transport uncertainty, and unsupported partial cancel cannot be booked as refunded.
- Manual WC order cancellation warns about captured funds; it does not silently refund.

#### R08 — Safe defaults, settings, secrets, methods, and currency

**Purpose:** prevent activation/configuration from silently opening a fake or broken payment path.

**Work**

- Default the WC gateway to disabled and remove the misleading Subscription preset.
- Whitelist and clamp every stored setting; unknown mode fails closed.
- Require valid credentials, supported currency, and at least one enabled supported method before rendering or signing.
- Do not print stored merchant keys back into HTML; blank means unchanged with explicit rotate/clear behavior.
- Add persistent test/live/missing-credential/readiness notices and a buyer-facing test-mode marker where a test payment is deliberately allowed.
- Centralize method/status/result/default registries.
- Derive/configure `GoodsCl`, bound `MallUserID`, remove duplicate parameters, and disable unverified methods such as GIFT_CULT until fixtures exist.
- Use `manage_woocommerce` (filterable) for operational pages while preserving administrator access.

**Acceptance gate**

- Fresh activation cannot appear as a usable live payment method.
- Empty credentials/methods and unsupported currencies produce clear admin diagnosis and no public PHP warning.
- Settings HTML never contains a stored merchant key.
- Invalid stored options cannot select live credentials or alter protocol requests.

#### R09 — VBANK lifecycle or complete feature disablement

**Purpose:** close the gap between account issuance and actual deposit settlement.

**Work**

- Keep VBANK disabled until DG-01/DG-02 are resolved.
- Build a plain-permalink-safe server-to-server notification endpoint with vendor-confirmed signature, acknowledgment, retry, and proxy-aware source validation.
- Bind notification MID/TID/Moid/amount/state and transition `waiting/on-hold -> paid` idempotently.
- Persist and show bank, account, exact amount, holder, and localized KST deadline on thank-you, My Account/order, and emails; add safe copy affordance.
- Expire unpaid accounts through cron with an explicit stock policy and audited manual fallback.

**Acceptance gate**

- Valid deposit fixture completes once; replay is a no-op.
- Wrong signature/MID/TID/Moid/amount and expired state cannot complete an order.
- Customer-visible surfaces contain accurate deposit instructions.
- If vendor evidence is unavailable, VBANK remains disabled and all support claims are removed.

#### R10 — WooCommerce Blocks, HPOS, routing, and multisite

**Purpose:** make supported WooCommerce configurations actually work before declaring compatibility.

**Work**

- Add the server-side Blocks integration and client registration using the existing legacy gateway processing contract.
- Test classic and block checkout before declaring `cart_checkout_blocks` compatibility.
- Use WC CRUD/order edit APIs and test HPOS on/off before declaring `custom_order_tables` compatibility.
- Centralize a return URL helper that works with pretty and plain permalinks.
- Provision schema/rewrite rules on network activation and new-site creation.
- Enforce HTTPS for payment availability and use no-store/noindex/referrer-safe result responses.

**Acceptance gate**

- Classic and block checkout list and process NicePay only when configured and supported.
- HPOS on/off, pretty/plain permalinks, single-site/multisite, and mobile return paths pass integration tests.
- Compatibility declarations are added only after the matching matrix is green.

#### R11 — Honest integration tests and release-blocking P0 suite

**Purpose:** make P0 behavior executable rather than dependent on stubs or prose.

**Work**

- Add a real WordPress/WooCommerce integration suite and sanitized PG fixtures.
- Remove payment-file coverage exclusions; establish an honest baseline and ratcheting floor.
- Add order-swap, amount tamper, replay, concurrent claim, wrong flow/method/MID, every net-cancel branch, refund, VBANK, migration, DB failure, SSRF, log-redaction, nonce/capability, HPOS, Blocks, and permalink tests.
- Keep golden signature expectations literal; remove self-derived expectations where they prove only themselves.
- Commit `composer.lock`; add deterministic PHP settings.

**Acceptance gate**

- All fifteen high-value cases in TESTS-36 have executable equivalents.
- Money-path failures are demonstrated by tests that fail against the 2.0.0 baseline.
- P0 suite passes across the selected PHP/WP/WC support matrix.

### P1 — Operability and financial reconciliation

#### R12 — Merchant ledger, reconciliation, privacy, and order visibility

- Add `needs_reconciliation`, review, expired, abandoned, partial-refund, and remaining-balance views.
- Show safe result details, payment/cancel/net-cancel history, method-specific data, test/live marker, and correct per-row currency/timezone.
- Add indexed filters, totals over the active filter, method breakdown, CSV export, order meta box, correct HPOS links, and pending-row reaper.
- Add privacy exporter/eraser support without deleting legally required financial history by default.
- Test query plans at realistic volume and redact every support surface.

#### R13 — Configuration readiness, onboarding, and system report

- Add a readiness panel for gateway state, credentials, methods, currency, HTTPS, return routing, outbound HTTPS, Blocks/HPOS, and cron.
- Add safe test-payment and copyable system-report flows; never include secrets/PII.
- Add save confirmation, unsaved-change protection, actionable field help, and first-run guidance.
- Make constant-backed credentials read-only and accurately documented.

#### R14 — Standalone receipts and failure recovery

- Replace the dead-end standalone result page with durable receipt/reference, confirmation email, print/copy, retry, and safe correlation ID.
- Never expose raw `WP_Error` or PG operator messages to shoppers.
- Survive cross-site/mobile return without relying only on the WC session.
- Use a processing interstitial and `ignore_user_abort(true)` only around the verified critical section.

### P2 — Correctness, UX, accessibility, i18n, documentation, and delivery

#### R15 — Shortcode model, front-end JavaScript, and builder

- Use opaque ASCII IDs and reference-form shortcodes (`[nicepay_payment id="..."]`) so edits propagate.
- Bound/de-autoload malformed saved options; add restore/undo for presets.
- Centralize serialization/defaults/method registry; reject invalid builder saves and quote-breakout cases.
- Extract inline payment logic to versioned JS, scope state per form, support multiple shortcodes, handle popup/vendor-script/clipboard failures, and remove global/named-form collisions.
- Add JS lint and tests.

#### R16 — Accessibility and responsive visual system

- Fix modal labeling, focus trap/restore, inert background, progress/error announcements, visible focus, color-only errors, table caption/scope/semantics, reduced motion, contrast, mobile touch targets, zoom, dark/admin schemes, and browser fallbacks.
- Validate keyboard-only, screen reader, Safari/Firefox, iOS viewport, Turkish expansion, and multiple-form behavior.

#### R17 — UTF-8, KST, localization, and message safety

- Remove the false EUC-KR option and enforce UTF-8 consistently.
- Generate protocol timestamps explicitly in `Asia/Seoul`; freeze clocks in tests.
- Regenerate POT from source with one committed command, merge PO files, compile MO files, and gate freshness.
- Fix plural forms, placeholders, hardcoded JS strings, date/currency formatting, duplicate/dead strings, and translated data persisted as canonical state.
- Map result codes to safe localized buyer messages; retain raw operator text only in protected diagnostics.

#### R18 — CI, release, update channel, and repository governance

- Add PHPCS/WPCS, PHPStan, JS lint/tests, Plugin Check, i18n freshness, link checks, integration matrix, and artifact smoke install.
- Run release from the same required checks; verify tag/header/constant/changelog equality and fail on missing changelog entries.
- Update compatibility headers only after the matching matrix passes.
- Choose and implement a real update channel; add collision-safe `Update URI`, deliberate ZIP contents, checksum/provenance, and working bundled docs links.
- Add `SECURITY.md`, contribution/test instructions, issue/PR templates, Dependabot, CODEOWNERS, `.editorconfig`, and consistent style rules.

#### R19 — Documentation truth and public extension surface

- Give each topic one owner; add docs index, compatibility matrix, troubleshooting, and clear audience paths.
- Distinguish vendor protocol fields from what the plugin actually sends/supports.
- Document real status transitions, refund/cancel consequences, cache/HTTPS/permalink requirements, credential provisioning, logging, privacy, update path, Blocks/HPOS/multisite, and unsupported capabilities.
- Either implement/version the documented hooks/template overrides or remove them; add contract tests for any public hook.

### P3 — Enhancement findings and product expansion

These packages close the enhancement findings only after P0–P2 are stable. They are separate release/certification projects, not opportunistic additions to the money core.

#### R20 — Payment links and standalone payment records

- First-class payment objects with payer identity, human reference, receipt token, email, detail view, webhooks/actions, expiry, usage limits, disable controls, and explicit custom-amount min/max.

#### R21 — Wallets, installments, branding, and method clarity

- Persist wallet code and partial-cancel capability; expose correct wallet/method marks and explanations.
- Add vendor-certified installment constraints and window branding without violating easy-pay incompatibilities.

#### R22 — Korean tax, cash receipt, escrow, and goods classification

- Capture receipt fields; derive and assert `SupplyAmt + GoodsVat + TaxFreeAmt (+ service/cup fields where applicable) == Amt`.
- Add escrow only with merchant contract and lifecycle certification.
- Derive physical/digital goods classification from the cart/configuration.

#### R23 — Recurring billing

- Dedicated billing-key design, WC Subscriptions hooks, scheduled payments, token management, customer payment-method management, failure/retry flows, and vendor/PCI review.
- Do not reintroduce the Subscription preset until the entire acceptance suite and certification are complete.

#### R24 — Mutation testing and excellence gate

- Establish a non-blocking mutation baseline for pure protocol/state functions, then ratchet an agreed MSI floor after honest integration coverage exists.

## 7. Finding coverage and closure ledger

The current evidence-backed implementation status is maintained in
[14-remediation-progress.md](14-remediation-progress.md). It deliberately keeps vendor-,
WooCommerce-, browser-, translation-, and product-policy-dependent items open rather than
claiming closure from unit tests alone.

Coverage is mandatory for every canonical row in [01-findings-index.md](01-findings-index.md):

| Family | Canonical range/count | Primary owners |
|---|---:|---|
| Protocol | PROTOCOL-01…34 | R01, R03–R09, R14, R17, R19, R21–R22 |
| Payment flow | FLOW-01…42 | R02–R09, R12, R14, R19 |
| Security | SECURITY-01…28 | R02–R08, R10–R12, R18–R19 |
| User experience | UX-001…126 | R03, R05, R07–R17, R20–R23 |
| WordPress/WooCommerce | PLATFORM-01…42 | R02–R10, R12–R13, R15, R18–R19 |
| Architecture/code | CODE-01…55 | R02–R08, R10–R19 |
| Internationalization | I18N-01…21 | R09, R12, R16–R17 |
| Testing | TESTS-01…37 | R01–R11, R15, R17–R18, R24 |
| Documentation | DOCS-001…039 | Behavior-owning package plus R19 |
| Build/release | RELEASE-01…21 | R10–R11, R17–R19 |
| Excellence | EXCELLENCE-01…40 | R02–R24 |

During implementation, the canonical index receives a generated closure ledger with `ID`, `owner package`, `status`, `evidence commit`, `test`, and `external dependency`. A release candidate fails if any row is blank, duplicated between owners, or closed without evidence.

## 8. Claims and recommendations that must not become fixes

- Do not alter the eight correct signature concatenation rules.
- Do not hardcode dynamic approval/net-cancel URLs; keep strict validation around the vendor-returned URLs.
- Do not add IP filtering to browser return endpoints.
- Do not accept alternate approval signature preimages without a vendor fixture.
- Do not blindly retry an outcome-unknown net-cancel.
- Do not auto-refund solely because an order was manually moved to `cancelled`.
- Do not treat public sandbox credentials as secret leakage; the defect is default enablement and false production appearance.
- Do not delete financial history by default during uninstall/privacy cleanup.
- Do not rely on the report's stale exact counts for missing i18n strings or current PHP/WP/WC versions; regenerate/revalidate them in CI.
- Preserve the verifier's refutations: `dbDelta` primary-key spacing is accepted; the alleged reachable second-refund comparison bug was disproved; no verified evidence shows a failed return empties the WC cart.

## 9. Controlled parallel execution

The lead agent owns architecture, branch state, integration, and final review. Parallel work is limited to non-overlapping files and bounded packages:

1. **Foundation wave:** R01 fixtures/tests and R02 repository/migration are developed in parallel only where file ownership is disjoint; the lead integrates bootstrap changes.
2. **Money-safety wave:** R03–R06 are implemented sequentially around the shared processor. A second agent may work only on tests/fixtures.
3. **Platform wave:** R08/R10 and the R11 integration matrix may run in parallel after the state/schema contract is stable.
4. **Operational wave:** R07/R09/R12 are split by refund, VBANK, and admin surfaces after the core state machine lands.
5. **Quality/UX wave:** R15–R19 can use two agents with explicit PHP/JS/CSS/docs ownership.
6. **Enhancement wave:** R20–R24 are independently scoped after certification gates.

No agent switches branches, creates a feature branch, rebases, pushes, or edits another owner's files. Each handoff includes changed files, covered finding IDs, tests run, risks, and remaining external gates.

## 10. Release gates

### P0 security candidate

- R01–R08, R10–R11 complete.
- R09 complete or VBANK fully disabled and unadvertised.
- Zero unresolved critical/high money-state findings.
- Tamper, replay, concurrency, transport, cancel, refund, migration, Blocks, HPOS, permalink, and log-redaction tests green.

### Sandbox candidate

- DG-01/DG-02 resolved.
- Certified CARD/BANK/CELLPHONE flows and all enabled methods pass end-to-end sandbox tests.
- Every unknown money outcome is visible in reconciliation.

### Release candidate

- R12–R19 complete.
- Every row in the closure ledger has evidence.
- Supported PHP/WP/WC matrix, static analysis, i18n, link check, artifact install, and version consistency pass.
- Documentation and release ZIP describe exactly the shipped capabilities.

### First-class release

- Selected R20–R24 packages are certified; unselected capabilities remain absent from marketing/settings.
- No external-blocked feature is enabled or advertised.

## 11. Effort and sequencing expectation

These are planning ranges, not delivery promises:

| Horizon | Expected focused effort | Main uncertainty |
|---|---:|---|
| P0 | 3–5 engineer-weeks | Schema/state refactor, real WP/WC tests, vendor fixtures |
| P1 | 3–5 engineer-weeks | VBANK/refund operations, admin reconciliation |
| P2 | 3–5 engineer-weeks | Browser/accessibility/i18n matrix and release automation |
| P3 | Separate 2–8+ week projects each | Vendor contract, compliance, certification, product scope |

The next implementation step is R01 + the test slice of R11, followed by R02. Money-moving controller changes begin only after those foundations make regression and migration behavior observable.
