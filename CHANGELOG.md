# Changelog

All notable changes to the NicePay Payment Gateway plugin are documented in this file.

## [2.0.0] - 2026-04-02

Complete rewrite of the plugin with clean architecture and improved reliability.

### Added

- **WooCommerce Refund Support** — Full and partial refunds from order edit screen via `process_refund()`
- **Payment Method Selection** — Radio button UI for choosing between enabled methods at checkout
- **Configurable VBank Expiry** — Virtual account expiry days adjustable from admin (1-30 days)
- **Transaction Database** — Dedicated `wp_nicepay_transactions` table with proper indexing
- **Admin Transaction Panel** — Filterable, searchable transaction history with inline cancel
- **Signature Verification** — SHA-256 verification on all request/response pairs using `hash_equals()`
- **Network Cancel** — Automatic rollback on approval failures (timeout, parse error, signature mismatch)
- **Standalone Payments** — `[nicepay_payment]` shortcode for embedding payment buttons on any page
- **Multi-tab Admin Settings** — General, API Credentials, Payment Methods, Shortcode reference
- **Comprehensive Logging** — Debug logging via WooCommerce logger or `debug.log`
- **Dynamic Currency** — Uses WooCommerce order currency instead of hardcoded values
- **Language Normalization** — Proper KR/KO/EN/CN mapping for NicePay payment window

### Changed

- **Architecture** — Complete rewrite with separated concerns (API, Gateway, Admin, Templates)
- **Code Quality** — Consistent English codebase, WordPress coding standards, proper escaping
- **Form Handling** — Single PayMethod field (fixed duplicate field bug from v1.x)
- **Script Loading** — NicePay JS only loads on checkout and payment pages (not site-wide)
- **Error Handling** — Descriptive error messages with result codes shown to admin

### Removed

- Donation form shortcode (can be reimplemented via `[nicepay_payment]`)
- Mixed Turkish/English comments
- Fallback `window.nicepay` implementation (rely on official NicePay JS)
- Hardcoded USD currency

### Security

- All admin actions require `manage_options` capability
- Nonce verification on all AJAX cancel requests
- Timing-safe signature comparison with `hash_equals()`
- Input sanitization on all POST parameters
- Approval requests are server-to-server only

---

## [1.x] - Previous Versions

Legacy versions with initial NicePay integration. See git history for details.
