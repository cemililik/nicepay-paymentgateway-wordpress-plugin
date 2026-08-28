# Changelog

All notable changes to the NicePay Payment Gateway plugin are documented in this file.

## [2.0.1] - 2026-08-27

### Breaking

- Test-mode checkout is now hidden by default. Development sites must explicitly opt in with the `nicepay_allow_test_mode_checkout` filter; approved sandbox orders remain `on-hold` and are visibly marked as test transactions.
- KRW stores must use zero WooCommerce price decimals before the gateway becomes available. Fractional KRW totals are rejected instead of rounded.
- Reusable standalone configurations no longer pre-fill buyer PII. Name, email, and phone are collected from the customer at payment time.
- Database schema downgrade is not supported. Back up the database before updating from 1.x.

### Fixed

- Added a real published-1.x migration fixture, explicit nullable-TID conversion, idempotent lifecycle backfill, migration locking, and post-dbDelta column/index verification before advancing the schema version.
- Standardized new ledger timestamps on explicit UTC writes; existing rows are not offset-adjusted because their historical database session timezone cannot be determined safely.
- Preserved legacy payment audit payloads during automatic migration; irreversible payload scrubbing now requires an explicit operator call.
- Built approval and net-cancel transport context from the claimed local ledger rather than browser-supplied reversal fields.
- Released stale approval locks during recovery and retained unknown outcomes for reconciliation.
- Accepted signed fixed-width response amounts for numeric binding and declared JSON response encoding on approval/cancel calls.
- Added customer-facing checkout errors, early buyer validation, standalone native form semantics, XHR timeouts, and a payment-window watchdog.
- Added the WordPress.org contributor header, synchronized release versions, and switched all license metadata to GPL-2.0-or-later.
- Made the distribution channel explicit: GitHub packages retain their takeover-protection `Update URI`, while verified WordPress.org candidates remove it automatically before the final artifact smoke check.
- Disabled persisted checkout credentials in CI and added a high-severity npm advisory gate.

## [2.0.0] - 2026-08-20

### Added

- Versioned transaction and refund-attempt schemas with migration checks, unique identifiers, active-attempt locking, and reconciliation state.
- Server-authoritative fixed-price standalone offers, anonymous rate limiting, cached-nonce recovery, durable receipt links, and safe receipt email delivery.
- Shared inbound binding for MID, Moid, amount, currency, flow, payment method, TID, and signed approval responses.
- Atomic approval/refund claims, append-only refund-attempt history, stale-approval escalation, and merchant-visible `needs_reconciliation` records.
- WooCommerce Checkout Blocks adapter. HPOS compatibility is declared after the real legacy/HPOS storage matrix; Checkout Blocks compatibility remains undeclared until its browser matrix passes.
- PHP 7.4–8.3 CI, dependency audit, version/translation-source checks, deterministic packaging, artifact smoke checks, and SHA-256 release checksums.
- Real WordPress/MariaDB fresh-install, additive-upgrade, legacy scrub, missing-table repair, and query-index integration gate.
- Real WooCommerce 11.0.1 smoke gate for gateway registration, fail-closed defaults, Blocks adapter registration, and order CRUD in both legacy and HPOS storage modes.
- WordPress personal-data exporter and eraser integration that anonymizes buyer contact data while retaining the minimum financial ledger.
- Opt-in 1–36,500 day financial-record retention with an indefinite default, explicit permanent-deletion acknowledgement, unresolved-state protection, bounded transactional cleanup, operational status, and real MariaDB coverage.
- Filter-consistent reconciliation totals, bounded formula-safe CSV export, a secret-free system report, and an HPOS-safe NicePay ledger summary on WooCommerce order screens.
- ESLint, CSS parsing, and browser-like multi-shortcode DOM regression tests.
- Security policy, CODEOWNERS, Dependabot, issue/PR templates, editor settings, packaged user/developer documentation, and an explicit GitHub `Update URI` to prevent WordPress.org slug takeover.
- A manual, dry-run-by-default WordPress.org candidate/deployment workflow with immutable-tag checks, protected environment gates, exact publish confirmation, SVN preflight, and a validator-clean `readme.txt`.

### Changed

- Fresh WooCommerce and standalone payment entry points are disabled by default. When the method option is absent, only `CARD` is selected conservatively.
- New payments are restricted to KRW, UTF-8, and gated `CARD`, `BANK`, and `CELLPHONE` methods. `VBANK`, `SSG_BANK`, and `GIFT_CULT` remain blocked.
- Approval, cancel, and net-cancel HTTP calls require approved HTTPS hosts, reject redirects/non-2xx/malformed responses, validate signatures, and bind returned identity/amount fields.
- NicePay HTTP calls use an independently scoped 5-second connection timeout and 30-second total timeout without leaving global cURL hooks behind.
- Signed fixed-width response amounts retain exact bytes for signature verification and are canonicalized separately for numeric binding.
- WooCommerce refunds use one CAS-protected gateway flow and retain confirmed remote refunds even if local bookkeeping later requires reconciliation.
- Refund consistency checks follow WooCommerce's real in-flight `WC_Order_Refund` lifecycle, and post-approval binding anomalies remain locked for manual reconciliation even when the original authorization is reversed.
- Standalone forms support multiple independent shortcode instances without inline global handlers; commercial values always come from the saved server-side configuration.
- Custom standalone button classes retain the required payment behavior class, and custom background colors receive an automatically selected high-contrast text color.
- Transaction operations default to the filterable `manage_woocommerce` capability while global configuration stays administrator-only.
- Merchant keys are not rendered back into settings HTML; blank saves keep the existing key unless explicit clearing is requested.
- Translation sources and all four bundled catalogs were regenerated with all 511 active entries complete. Fuzzy and obsolete entries are forbidden, and CI verifies source freshness, placeholders, HTML, certified-feature claims, completion, and compiled catalogs.
- The minimum WordPress version is 5.8 so the explicit GitHub `Update URI` protection is honored by every supported installation.

### Security

- Removed fresh-schema PAN storage and scrubbed spent legacy PAN/token fields after the upgraded schema is verified. Legacy audit payload deletion requires explicit operator confirmation.
- Added recursive production-log redaction and allowlisted persisted/provider response fields.
- Added byte-accurate buyer field validation, site-wide test/live configuration warnings, and strict card/simple-pay partial-refund capability gates.
- Added fail-closed handling for unknown modes, malformed protocol types, duplicate callbacks, concurrent payment attempts, ambiguous reversals, and uncertain refunds.
- Manual WooCommerce order cancellation warns about captured funds and never silently issues a refund.

### Not supported in this release

- Virtual-account deposit lifecycle, recurring/subscription billing, escrow, tax/cash-receipt workflows, custom/open amounts, standalone refunds, and certified repeated partial mobile refunds.
- Production certification of the legacy PG-Web v3/manual v2.0.8 protocol until merchant provisioning and sanitized vendor sandbox fixtures are available.

---

## [1.x] - Previous Versions

Legacy versions with the initial NicePay integration. See Git history for details.
