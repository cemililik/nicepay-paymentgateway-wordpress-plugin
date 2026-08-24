# Contributing to NicePay Payment Gateway

Thank you for your interest in contributing to the NicePay Payment Gateway plugin! This document provides guidelines and instructions for contributing.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How to Contribute](#how-to-contribute)
- [Development Setup](#development-setup)
- [Branch Strategy](#branch-strategy)
- [Pull Request Process](#pull-request-process)
- [Coding Standards](#coding-standards)
- [Commit Messages](#commit-messages)
- [Reporting Bugs](#reporting-bugs)
- [Requesting Features](#requesting-features)
- [Security Vulnerabilities](#security-vulnerabilities)

---

## Code of Conduct

This project follows a [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you agree to uphold it. Please report unacceptable behavior via GitHub Issues.

---

## How to Contribute

1. **Fork** the repository
2. **Create a branch** from `development` for your changes
3. **Make your changes** following the coding standards below
4. **Test** your changes thoroughly
5. **Submit a Pull Request** against `development`

> **Note:** Direct commits to `main` are not allowed. All changes must go through a Pull Request and be approved by the maintainer.

---

## Development Setup

### Prerequisites

- PHP 7.4+
- WordPress 5.8+ (local development environment)
- WooCommerce 5.0+ (for gateway testing)
- Node.js 20+ and npm
- Docker (for the disposable WordPress/MariaDB integration test)
- Git

### Local Setup

```bash
# Fork and clone
git clone https://github.com/YOUR_USERNAME/nicepay-paymentgateway-wordpress-plugin.git

# Navigate to your WordPress plugins directory
cd /path/to/wordpress/wp-content/plugins/

# Symlink or copy the plugin
ln -s /path/to/nicepay-paymentgateway-wordpress-plugin nicepay-payment-gateway

# Activate the plugin in WordPress admin
```

### Run the Automated Checks

```bash
composer install --prefer-dist --no-interaction
composer test
composer quality
npm ci --ignore-scripts
npm run quality
bash .github/scripts/check-translations.sh
bash tests/integration/run-schema-migration.sh
bash tests/integration/run-woocommerce-smoke.sh
```

CI repeats the PHPUnit suite on PHP 7.4, 8.0, 8.1, 8.2, and 8.3, runs the
JavaScript/CSS gates and disposable database migration test, then builds and
smoke-checks the release ZIP. Money-path changes must include a regression
test; vendor-dependent behavior must use sanitized fixtures.

### Enable Debug Mode

Add to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

---

## Branch Strategy

```
main                    (protected release branch)
└── development         (integration branch; normal PR target)
```

Repository-wide remediation and release preparation are performed directly on
`development` when the maintainer explicitly requests that workflow. External
contributions may use a short-lived fork branch, but the pull request must target
`development`; `main` receives only reviewed release promotions.

---

## Pull Request Process

1. **One concern per PR** — Don't mix features, fixes, and refactors in a single PR
2. **Description** — Clearly describe what changes and why
3. **Testing** — Describe how you tested the changes
4. **Screenshots** — Include screenshots for UI changes
5. **Breaking changes** — Clearly mark any breaking changes

### PR Template

```markdown
## Summary
Brief description of the changes.

## Type
- [ ] Feature
- [ ] Bug fix
- [ ] Documentation
- [ ] Refactor

## Changes
- Change 1
- Change 2

## Testing
How was this tested?

## Checklist
- [ ] Code follows WordPress coding standards
- [ ] No sensitive data (keys, passwords) in code
- [ ] All strings are translatable
- [ ] Tested with WooCommerce enabled
- [ ] Tested with WooCommerce disabled (standalone mode)
```

### Review Process

- All PRs are reviewed by the project maintainer (@cemililik)
- Only the maintainer can promote reviewed changes from `development` to `main`
- Reviews may request changes — please address all feedback before re-requesting review

---

## Coding Standards

### PHP

Follow the [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/):

- Use four spaces for indentation, as defined by `.editorconfig`
- Opening braces on the same line
- Use `snake_case` for function and variable names
- Use `UPPER_CASE` for constants
- Prefix all global functions with `nicepay_`
- Prefix all classes with `NicePay_` or `WC_Gateway_NicePay`

```php
// Good
function nicepay_format_amount( $amount, $currency = '' ) {
    if ( ! $currency ) {
        $currency = get_option( 'nicepay_currency', 'KRW' );
    }
    return number_format( (int) $amount ) . ' ' . $currency;
}

// Bad
function formatAmount($amount, $currency="") {
    if (!$currency) {
        $currency = get_option('nicepay_currency','KRW');
    }
    return number_format((int)$amount).' '.$currency;
}
```

### JavaScript

- Use `'use strict'` in all JS files
- Wrap in IIFE or module pattern to avoid global scope pollution
- Use `jQuery` with `$` alias inside closures only

### CSS

- Use `.nicepay-` prefix for all class names
- Use BEM-like naming where appropriate (`.nicepay-method-option`, `.nicepay-status-paid`)
- Mobile-first responsive design

### Security

- **Always** sanitize inputs: `sanitize_text_field()`, `absint()`, `esc_url_raw()`
- **Always** escape outputs: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- **Always** use nonces for form submissions and AJAX calls
- **Always** check the narrow capability for the action. Operational payment screens use the filterable `nicepay_manage_transactions_capability` (default `manage_woocommerce`); global settings remain administrator-only.
- **Never** trust data from `$_POST`, `$_GET`, or `$_REQUEST` without sanitization
- **Never** use `eval()`, `extract()`, or `serialize()` with user input
- **Never** commit credentials, API keys, or secrets

### Internationalization

All user-facing strings must be translatable:

```php
// Simple string
__( 'Payment failed.', 'nicepay-payment-gateway' )

// String with HTML output
esc_html__( 'Payment failed.', 'nicepay-payment-gateway' )

// String with placeholders
sprintf(
    /* translators: %s: transaction ID */
    __( 'Transaction ID: %s', 'nicepay-payment-gateway' ),
    $tid
)
```

---

## Commit Messages

Use clear, descriptive commit messages:

```
<type>: <short description>

<optional body with more detail>
```

### Types

| Type | Description |
|---|---|
| `feat` | New feature |
| `fix` | Bug fix |
| `docs` | Documentation changes |
| `refactor` | Code change that doesn't fix a bug or add a feature |
| `style` | Formatting, whitespace (no logic change) |
| `test` | Adding or updating tests |
| `chore` | Build process, dependencies, CI config |

### Examples

```
feat: add deposit notification handler for virtual accounts

fix: correct signature verification for cancel responses

docs: update API reference with new card codes

refactor: extract form generation into separate builder class
```

---

## Reporting Bugs

Use [GitHub Issues](https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin/issues) with the following information:

1. **WordPress version**
2. **WooCommerce version** (if applicable)
3. **PHP version**
4. **Plugin version**
5. **Steps to reproduce**
6. **Expected behavior**
7. **Actual behavior**
8. **Error messages** (from browser console or `debug.log`)
9. **Screenshots** (if applicable)

> **Important:** Never include MID, Merchant Key, or full transaction data in bug reports. Redact sensitive information.

---

## Requesting Features

Open a [GitHub Issue](https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin/issues) with:

1. **Use case** — What problem does this solve?
2. **Proposed solution** — How should it work?
3. **Alternatives considered** — What other approaches did you think about?
4. **NicePay API support** — Is this supported by the NicePay API?

---

## Security Vulnerabilities

**Do NOT open a public issue for security vulnerabilities.**

Instead, please report security issues privately via GitHub's [Security Advisories](https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin/security/advisories) feature, or contact the maintainer directly.

Include:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

---

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
