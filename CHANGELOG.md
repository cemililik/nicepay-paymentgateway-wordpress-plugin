# Changelog

All notable changes to the NicePay Payment Gateway plugin are documented in this file.

## [2.0.0] - 2026-04-03

Complete rewrite of the plugin with clean architecture, modern UI, and improved reliability.

### Added

- **Shortcode Manager** — Admin UI to create, save, edit, and delete payment shortcodes with card grid view
- **Shortcode Generator** — Interactive builder with live preview, color picker, display mode selector, and save/update functionality
- **Display Modes** — `inline` (form on page) and `modal` (button opens popup overlay) via `display_mode` parameter
- **Saved Shortcodes** — Store shortcode configs in database, reference by ID: `[nicepay_payment id="quick-payment"]`
- **Default Presets** — 4 built-in shortcode templates (Quick Payment, Donation, Product Purchase, Subscription)
- **Button Color Customization** — `button_color` parameter with hex color picker and 6 preset swatches
- **Payment Method SVG Icons** — Inline SVG icons for all 6 payment methods in checkout and admin
- **Interactive Buyer Fields** — When buyer info not pre-filled, form shows input fields with client-side validation
- **AJAX Payment Init** — Transaction record created only when buyer clicks pay (not on page load)
- **Modal Cancel Dialog** — Accessible modal replaces `prompt()/alert()` for transaction cancellation
- **Toast Notifications** — Slide-in notifications replace `alert()` for admin feedback
- **TID Copy to Clipboard** — One-click copy with visual confirmation in transaction list
- **Loading States** — Full-screen overlay with spinner, button spinner animation during payment
- **Animated Result Page** — Fade+slide entrance, icon pop animation, special VBank deposit card
- **WooCommerce Refund Support** — Full and partial refunds from order edit screen via `process_refund()`
- **Payment Method Selection** — Custom radio UI with icons for choosing between enabled methods
- **Configurable VBank Expiry** — Virtual account expiry days adjustable from admin (1-30 days)
- **Transaction Database** — Dedicated `wp_nicepay_transactions` table with proper indexing
- **Admin Transaction Panel** — Filterable, searchable transaction history with styled empty states
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
