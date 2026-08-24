=== NicePay Payment Gateway ===
Tags: woocommerce, payment gateway, credit card, bank transfer, mobile payments
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: MIT
License URI: https://opensource.org/license/mit/

Adds disabled-by-default NICEPAY card, bank-transfer, and mobile-payment flows to WooCommerce and fixed-price forms.

== Description ==

NicePay Payment Gateway connects WordPress to the third-party NICEPAY payment service. It provides:

* A WooCommerce payment gateway that is disabled on a fresh installation.
* Fixed-price standalone payment forms that must be explicitly configured and enabled.
* CARD, BANK, and CELLPHONE payments in KRW.
* Server-side approval, signature verification, refund handling, and reconciliation records.
* WooCommerce High-Performance Order Storage (HPOS) support.
* WordPress personal-data export and erasure integration.

Virtual accounts, recurring or subscription billing, escrow, tax or cash-receipt workflows, open amounts, standalone refunds, and uncertified wallet methods are not supported. Cart and Checkout Blocks compatibility is not declared until the real browser certification matrix is complete.

The gateway targets the legacy NICEPAY PG-Web v3 flow. Before accepting production payments, the merchant must complete NICEPAY provisioning and sandbox certification for the enabled methods. Payments remain disabled until the merchant deliberately configures the plugin.

= External service disclosure =

This plugin relies on NICEPAY, a third-party payment service. When a merchant enables the gateway and a customer chooses it, transaction identifiers, order amount and description, and customer contact details required for payment are sent to NICEPAY. The NICEPAY payment-window JavaScript is also loaded from NICEPAY during that payment flow. The plugin does not send usage analytics or telemetry.

Merchants are responsible for obtaining the required customer notices and consents and for configuring retention according to their applicable laws and contracts.

Local NicePay financial rows are retained indefinitely by default. An administrator can opt into a 1–36,500 day period only after acknowledging the permanent-deletion warning. The bounded daily cleanup protects active, unknown, refund-pending, and reconciliation-required states. It affects only this plugin's ledger/refund-attempt tables; it does not delete WooCommerce orders, backups, logs, exports, or records held by NICEPAY. Deleting a settled local ledger row prevents future refunds through this plugin.

* NICEPAY service: https://www.nicepay.co.kr/
* NICEPAY service terms: https://www.nicepay.co.kr/cs/terms/policy1.do
* NICEPAY privacy policy: https://www.nicepay.co.kr/cs/terms/private.do

This community plugin is not represented as an official NICEPAY product or endorsement. Publication under a NICEPAY-based WordPress.org name or slug remains subject to WordPress.org trademark review and any approval required from the relevant rights holder.

== Installation ==

1. Install and activate WooCommerce if you want to use the checkout gateway. WooCommerce is optional for standalone fixed-price forms.
2. Install and activate the plugin.
3. Open **NicePay > Settings** and select test mode.
4. Enter the merchant MID and merchant key issued for the certified legacy flow.
5. Enable only the methods provisioned for the merchant account: CARD, BANK, or CELLPHONE.
6. Run a complete sandbox payment and refund test before switching to live mode.
7. Enable the WooCommerce gateway separately under **WooCommerce > Settings > Payments** when applicable.

Production mode should not be enabled until NICEPAY has confirmed the merchant's protocol provisioning and the site's payment, callback, cancellation, refund, timeout, and reconciliation scenarios have passed sandbox certification.

== Frequently Asked Questions ==

= Does activating the plugin immediately accept payments? =

No. The WooCommerce gateway and standalone payment forms are disabled by default. Credentials and supported methods must be deliberately configured and enabled.

= Which payment methods are supported? =

New payments are restricted to CARD, BANK, and CELLPHONE in KRW. Virtual accounts, recurring billing, escrow, tax or cash-receipt features, open amounts, standalone refunds, and uncertified wallets are not supported.

= Is WooCommerce required? =

WooCommerce is required for checkout and WooCommerce refund integration. It is optional when only saved, fixed-price standalone forms are used.

= Does the plugin support WooCommerce HPOS and Checkout Blocks? =

HPOS compatibility is declared and covered by the automated legacy/HPOS storage test. A Checkout Blocks adapter is included, but Blocks compatibility is not declared until the real browser, popup, mobile, and accessibility certification matrix passes.

= What personal data is processed? =

For a payment selected by the customer, the plugin processes the buyer name, email address, telephone number, order or transaction references, amount, and payment result data needed for payment and reconciliation. Required payment data is sent to NICEPAY. The plugin integrates with the WordPress personal-data exporter and eraser. Financial and reconciliation references may need to be retained under the merchant's legal and contractual obligations.

= How is the financial retention period chosen? =

The default is indefinite retention. A WordPress administrator may enter a custom 1–36,500 day period after obtaining the legal and accounting approval applicable to the merchant and acknowledging permanent deletion. The plugin cannot determine the correct regulatory period. Back up and export the required data before opting in; extending the period later cannot restore deleted rows.

= Does the plugin collect analytics? =

No. It does not send plugin usage analytics or telemetry.

= Where is the source code? =

Human-readable source, tests, and build automation are available at https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin. Distributed JavaScript and CSS are kept in readable source form.

== Changelog ==

= 2.0.0 =

* Added server-authoritative payment binding, atomic payment and refund claims, durable reconciliation, and versioned database migrations.
* Added recursive log redaction, strict outbound URL policy, response signature validation, and personal-data tools.
* Added WooCommerce HPOS support, Checkout Blocks adapter registration, and real WordPress/WooCommerce integration gates.
* Added deterministic release packaging, dependency audits, translation checks, and expanded PHP and JavaScript tests.
* Added an opt-in, merchant-selected financial retention period with unresolved-state protection and bounded transactional cleanup.
* Added a dry-run-by-default, protected WordPress.org release workflow and validator-clean directory readme.
* Kept all payment entry points disabled by default and limited new payments to certified CARD, BANK, and CELLPHONE methods.

== Upgrade Notice ==

= 2.0.0 =

Review all settings after upgrading. Payment methods remain disabled until explicitly configured, and unsupported legacy methods cannot start new payments.
