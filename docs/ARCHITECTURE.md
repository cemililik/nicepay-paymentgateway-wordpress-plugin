# Architecture Overview

This document describes the system architecture, class relationships, data flow, and design decisions of the NicePay Payment Gateway WordPress plugin.

> **Supported execution envelope:** New payments are restricted to certified `CARD`, `BANK`, and `CELLPHONE` flows, KRW, and UTF-8. The WooCommerce gateway and the separate standalone surface both default disabled. Standalone payment commercial data resolves from a required saved configuration ID; shortcode attributes cannot override amount, goods, currency, or method policy.

> The protocol adapter targets legacy PG-Web v3/manual v2.0.8. DG-01 vendor confirmation and DG-02 sanitized fixtures remain prerequisites for broader certification. `VBANK`, `SSG_BANK`, `GIFT_CULT`, recurring/subscription, escrow, and tax features are outside the supported architecture.

## Table of Contents

- [High-Level Architecture](#high-level-architecture)
- [Directory Structure](#directory-structure)
- [Class Diagram](#class-diagram)
- [Payment Flows](#payment-flows)
- [Database Schema](#database-schema)
- [Security Architecture](#security-architecture)
- [WordPress Integration Points](#wordpress-integration-points)

---

## High-Level Architecture

The plugin operates as a bridge between WordPress/WooCommerce and the NicePay payment gateway. It follows NicePay's **authenticated payment** model: a two-phase flow where the customer authenticates on NicePay's hosted page, then the merchant's server sends a server-to-server approval request.

```mermaid
graph TB
    subgraph WordPress["WordPress / WooCommerce"]
        Plugin["NicePay Plugin"]
        Gateway["WC_Gateway_NicePay"]
        ReturnHandler["Return Handler"]
        AdminPanel["Admin Panel"]
        DB[(wp_nicepay_transactions)]
    end

    subgraph NicePay["NicePay PG"]
        AuthPage["Payment Window<br/>(nicepay-pgweb.js)"]
        ApprovalAPI["Approval API<br/>(pay_process.jsp)"]
        CancelAPI["Cancel API<br/>(cancel_process.jsp)"]
    end

    subgraph Partners["Partners"]
        CardCompany["Card Companies"]
        Banks["Banks"]
        Mobile["Mobile Carriers"]
    end

    Plugin --> Gateway
    Plugin --> ReturnHandler
    Plugin --> AdminPanel
    Gateway --> DB
    ReturnHandler --> DB

    Gateway -->|"Auth Request"| AuthPage
    AuthPage -->|"Auth via"| Partners
    Gateway -->|"Approval (Server-to-Server)"| ApprovalAPI
    AdminPanel -->|"Cancel/Refund"| CancelAPI
    
    ApprovalAPI --> Partners
    CancelAPI --> Partners
```

## Directory Structure

```
nicepay-payment-gateway/
├── nicepay-payment-gateway.php      # Plugin entry point, bootstrap, AJAX handlers
├── includes/
│   ├── class-nicepay-api.php        # NicePay API communication layer
│   ├── class-nicepay-gateway.php    # WooCommerce payment gateway
│   ├── class-nicepay-return-handler.php  # Standalone payment return handler
│   ├── class-nicepay-offer-resolver.php  # Server-authoritative saved standalone offers
│   ├── class-nicepay-inbound-validator.php # Return/approval binding policy
│   ├── class-nicepay-installer.php        # Versioned dbDelta schema installer
│   ├── class-nicepay-transaction-schema.php # Pure schema/write map
│   ├── nicepay-functions.php        # Helper functions, DB operations, shortcode helpers
│   └── nicepay-icons.php            # SVG icons for payment methods
├── admin/
│   ├── class-nicepay-admin.php      # Admin settings, shortcode manager, generator
│   └── class-nicepay-transactions.php  # Transaction management UI
├── templates/
│   ├── payment-form.php             # WooCommerce receipt page form
│   └── standalone-payment-form.php  # Shortcode payment form (inline + modal)
├── assets/
│   ├── js/nicepay.js                # Frontend JavaScript (payment, loading)
│   ├── js/nicepay-admin.js          # Admin JavaScript (modal, toast, card actions)
│   ├── css/nicepay.css              # Frontend styles
│   └── css/nicepay-admin.css        # Admin styles (cards, builder, modal)
├── languages/                       # Translation files
└── docs/                            # Documentation
```

## Class Diagram

```mermaid
classDiagram
    class NicePay_Payment_Gateway {
        -instance$ : NicePay_Payment_Gateway
        +instance()$ NicePay_Payment_Gateway
        -includes()
        -init_hooks()
        +activate()
        +deactivate()
        +enqueue_scripts()
        +register_endpoints()
        +handle_payment_return()
        +render_payment_shortcode()
    }

    class NicePay_API {
        -mid : string
        -merchant_key : string
        -is_test_mode : bool
        -charset : string
        +get_mid() string
        +get_merchant_key() string
        +generate_edi_date() string
        +generate_moid() string
        +create_auth_sign_data() string
        +verify_auth_signature() bool
        +create_approval_sign_data() string
        +verify_approval_signature() bool
        +create_cancel_sign_data() string
        +verify_cancel_signature() bool
        +request_approval() array|WP_Error
        +request_net_cancel() array|WP_Error
        +request_cancel() array|WP_Error
        +is_success_code() bool
        +is_cancel_success() bool
        +get_payment_method_name()$ string
        +get_available_methods()$ array
        +get_nicepay_lang()$ string
    }

    class WC_Gateway_NicePay {
        -api : NicePay_API
        +process_payment() array
        +receipt_page()
        -generate_payment_form()
        +handle_return()
        +process_refund() bool|WP_Error
    }

    class NicePay_Return_Handler {
        -api : NicePay_API
        +process()
        -render_result_page()
    }

    class NicePay_Admin {
        +add_menu()
        +register_settings()
        +render_settings_page()
        -render_general_tab()
        -render_api_tab()
        -render_payment_tab()
        -render_shortcode_tab()
    }

    class NicePay_Transactions {
        +render()
        +ajax_cancel_transaction()
    }

    NicePay_Payment_Gateway --> NicePay_API : creates
    NicePay_Payment_Gateway --> NicePay_Return_Handler : creates
    WC_Gateway_NicePay --> NicePay_API : uses
    NicePay_Return_Handler --> NicePay_API : uses
    NicePay_Transactions --> NicePay_API : uses
    NicePay_Admin --> NicePay_Transactions : renders
    WC_Gateway_NicePay --|> WC_Payment_Gateway : extends
```

## Payment Flows

### WooCommerce Checkout Flow

This is the primary flow when a customer pays through the WooCommerce checkout page.

```mermaid
sequenceDiagram
    participant C as Customer
    participant WC as WooCommerce
    participant G as WC_Gateway_NicePay
    participant API as NicePay_API
    participant NP as NicePay Server
    participant P as Card/Bank

    C->>WC: Place order
    WC->>G: process_payment($order_id)
    G-->>WC: Redirect to receipt page

    WC->>G: receipt_page($order_id)
    G->>G: generate_payment_form()
    Note over G: Create form with SignData<br/>hex(sha256(EdiDate+MID+Amt+Key))
    G-->>C: Render payment form

    C->>NP: nicepayStart() opens payment window
    NP->>P: Customer authenticates
    P-->>NP: Auth result
    NP-->>C: Auth response (AuthToken, Signature, NextAppURL)

    Note over C: PC: nicepaySubmit() callback<br/>Mobile: ReturnURL redirect

    C->>G: POST auth data to handle_return()
    G->>G: Verify auth signature<br/>hex(sha256(AuthToken+MID+Amt+Key))

    G->>API: request_approval(auth_data)
    API->>NP: POST to NextAppURL (server-to-server)
    NP-->>API: Approval response (TID, ResultCode)
    API->>API: Verify approval signature<br/>hex(sha256(TID+MID+Amt+Key))
    API-->>G: Return result

    alt Success
        G->>WC: payment_complete(TID)
        G-->>C: Redirect to thank-you page
    else Failure
        G->>API: request_net_cancel() if needed
        G->>WC: update_status('failed')
        G-->>C: Redirect to checkout with error
    end
```

### Standalone Payment Flow

Used only when standalone forms are explicitly enabled and `[nicepay_payment id="saved-config"]` is embedded on a page. Rendering or initialization fails closed without the saved ID. Amount, goods, KRW currency, and method policy come from that saved record; custom/open amount is unavailable.

```mermaid
sequenceDiagram
    participant C as Customer
    participant WP as WordPress Page
    participant RH as NicePay_Return_Handler
    participant API as NicePay_API
    participant NP as NicePay Server

    C->>WP: Visit page with saved-config shortcode
    WP->>WP: Resolve fixed server-side offer by id
    WP-->>C: Render payment form if standalone enabled

    C->>NP: nicepayStart() opens payment window
    NP-->>C: Auth response

    C->>RH: POST to /nicepay-return/
    RH->>RH: Verify auth signature
    RH->>API: request_approval()
    API->>NP: Server-to-server approval
    NP-->>API: Result
    RH->>RH: Update transaction in DB
    RH-->>C: Render result page
```

### Refund / Cancel Flow

```mermaid
sequenceDiagram
    participant Admin
    participant G as WC_Gateway_NicePay
    participant API as NicePay_API
    participant NP as NicePay Server

    alt WooCommerce Refund
        Admin->>G: process_refund($order_id, $amount)
        G->>API: request_cancel(TID, amount, reason)
    else Transactions screen Refund
        Admin->>G: wc_create_refund() for linked WC order
        G->>API: process_refund() -> request_cancel()
    end

    API->>API: Create SignData<br/>hex(sha256(MID+CancelAmt+EdiDate+Key))
    API->>NP: POST to cancel_process.jsp
    NP-->>API: Cancel response
    API->>API: Verify cancel signature<br/>hex(sha256(TID+MID+CancelAmt+Key))

    alt ResultCode 2001 or 2211
        API-->>Admin: Cancel successful
    else Other codes
        API-->>Admin: Cancel failed with error message
    end
```

### Network Cancel Flow

After authorization, applicable approval failures attempt network cancel and verify its outcome. A failed, missing, or ambiguous network-cancel result does not prove rollback: the transaction becomes `needs_reconciliation` and requires comparison with the NICEPAY merchant record. Blind retry is intentionally avoided.

```mermaid
flowchart TD
    A[Approval Request] --> B{Connection OK?}
    B -->|Yes| C{Parse Response?}
    B -->|No| D[Network Cancel]
    C -->|Yes| E{Signature Valid?}
    C -->|No| D
    E -->|Yes| F{Success Code?}
    E -->|No| D
    F -->|Yes| G[Payment Complete]
    F -->|No| H[Payment Failed]
    D --> I[POST to NetCancelURL]
    I --> J[Log cancel result]
```

## Database Schema

### `wp_nicepay_transactions`

Stores all transaction records regardless of source (WooCommerce or standalone).

```mermaid
erDiagram
    nicepay_transactions {
        bigint id PK "Auto-increment"
        varchar tid "NicePay Transaction ID"
        varchar order_id "Merchant order ID"
        bigint wc_order_id FK "WooCommerce order ID (nullable)"
        varchar moid "Merchant Order ID (Moid)"
        decimal amount "Transaction amount"
        varchar payment_method "CARD, BANK, CELLPHONE for new payments"
        varchar pay_method_name "Display name"
        varchar status "pending, approving, paid, failed, partially_refunded, refunded, needs_reconciliation"
        varchar result_code "NicePay result code"
        text result_msg "Result message"
        varchar auth_token "Temporary; retained only for unresolved reconciliation"
        varchar buyer_name "Buyer name"
        varchar buyer_email "Buyer email"
        varchar buyer_tel "Buyer phone"
        varchar goods_name "Product name"
        varchar card_code "Card company code"
        varchar card_name "Card company name"
        varchar card_quota "Installment months"
        char cc_part_cl "Provider partial-refund capability"
        varchar clickpay_cl "Simple-pay service code"
        varchar card_type "Personal/corporate/overseas card type"
        varchar bank_code "Bank code"
        varchar bank_name "Bank name"
        varchar vbank_num "Virtual account number"
        varchar vbank_exp_date "VBank expiry date"
        longtext payment_data "Allowlisted reconciliation fields only"
        datetime created_at "Creation timestamp"
        datetime updated_at "Last update timestamp"
    }

    wc_orders ||--o{ nicepay_transactions : "wc_order_id"
```

### Transaction Status Lifecycle

```mermaid
stateDiagram-v2
    [*] --> pending : Transaction created
    pending --> approving : Atomic approval claim
    approving --> paid : Bound approval success (CARD, BANK, CELLPHONE)
    pending --> failed : Known authentication decline
    approving --> failed : Known approval decline / confirmed rollback
    approving --> needs_reconciliation : Approval or net-cancel outcome unknown
    paid --> partially_refunded : Partial WC refund
    partially_refunded --> partially_refunded : Additional partial WC refund
    paid --> refunded : Full WC refund
    partially_refunded --> refunded : Remaining balance refunded
    refunded --> [*]
    needs_reconciliation --> [*] : Merchant review required
    failed --> [*]
```

## Security Architecture

### Signature Verification

Every request and response is verified using SHA-256 hashing. The merchant key is never sent over the network — it's only used to compute signatures locally.

```mermaid
flowchart LR
    subgraph "Auth Request"
        A1[EdiDate + MID + Amt + MerchantKey] --> A2[SHA-256] --> A3[SignData]
    end

    subgraph "Auth Response Verify"
        B1[AuthToken + MID + Amt + MerchantKey] --> B2[SHA-256] --> B3[Compare with Signature]
    end

    subgraph "Approval Request"
        C1[AuthToken + MID + Amt + EdiDate + MerchantKey] --> C2[SHA-256] --> C3[SignData]
    end

    subgraph "Approval Response Verify"
        D1[TID + MID + Amt + MerchantKey] --> D2[SHA-256] --> D3[Compare with Signature]
    end

    subgraph "Cancel Request"
        E1[MID + CancelAmt + EdiDate + MerchantKey] --> E2[SHA-256] --> E3[SignData]
    end

    subgraph "Cancel Response Verify"
        F1[TID + MID + CancelAmt + MerchantKey] --> F2[SHA-256] --> F3[Compare with Signature]
    end
```

### Security Layers

| Layer | Implementation |
|---|---|
| **Transport** | All API calls use HTTPS (port 443) |
| **Data Integrity** | SHA-256 signature on every request/response |
| **Authentication** | MID + MerchantKey pair identifies the merchant |
| **Authorization** | WordPress `manage_options` capability for admin actions |
| **CSRF Protection** | WordPress nonce verification on admin AJAX actions |
| **Input Validation** | `sanitize_text_field()`, `esc_attr()`, `absint()` on all inputs |
| **Timing-safe Compare** | `hash_equals()` for signature comparison (prevents timing attacks) |

## WordPress Integration Points

### Hooks Used

| Hook | Type | Purpose |
|---|---|---|
| `plugins_loaded` | Action | Initialize plugin, load text domain, register gateway |
| `init` | Action | Register rewrite rules for return endpoint |
| `template_redirect` | Action | Handle payment return requests |
| `wp_enqueue_scripts` | Action | Load NicePay JS and CSS |
| `admin_menu` | Action | Add NicePay admin menu |
| `admin_init` | Action | Register settings |
| `admin_enqueue_scripts` | Action | Load admin CSS/JS on NicePay pages |
| `woocommerce_payment_gateways` | Filter | Register NicePay gateway |
| `woocommerce_receipt_nicepay` | Action | Display payment form on receipt page |
| `woocommerce_api_nicepay_return` | Action | Handle WC payment return |
| `plugin_action_links_*` | Filter | Add settings link to plugins page |
| `wp_ajax_nicepay_init_payment` | Action | AJAX: Initialize standalone payment |
| `wp_ajax_nopriv_nicepay_init_payment` | Action | AJAX: Same (public) |
| `wp_ajax_nicepay_save_shortcode` | Action | AJAX: Save/update shortcode config |
| `wp_ajax_nicepay_delete_shortcode` | Action | AJAX: Delete shortcode config |
| `wp_ajax_nicepay_cancel_transaction` | Action | Legacy action name; initiates one WooCommerce refund flow for an eligible linked order |

### WooCommerce API Endpoints

| Endpoint | Handler | Purpose |
|---|---|---|
| `/?wc-api=nicepay_return` | `WC_Gateway_NicePay::handle_return()` | Receives auth response from NicePay |

### Custom Rewrite Endpoints

| URL | Handler | Purpose |
|---|---|---|
| `/nicepay-return/` | `NicePay_Return_Handler::process()` | Standalone payment return |

### WordPress Options

| Option Key | Type | Description |
|---|---|---|
| `nicepay_mode` | string | `test` or `live` |
| `nicepay_test_mid` | string | Test merchant ID |
| `nicepay_test_merchant_key` | string | Test merchant key |
| `nicepay_live_mid` | string | Live merchant ID |
| `nicepay_live_merchant_key` | string | Live merchant key |
| `nicepay_enabled_methods` | array | List of enabled payment method codes |
| `nicepay_language` | string | Payment window language (`KO`, `EN`, `CN`) |
| `nicepay_currency` | string | Default currency code |
| `nicepay_vbank_expiry_days` | int | Virtual account expiry in days |
| `nicepay_retention_settings` | array | Fail-closed `indefinite` policy or acknowledged custom `days` value |
| `nicepay_retention_last_run` | array | Non-sensitive UTC completion time, deleted count, and error code |
| Protocol charset | fixed | UTF-8 only; no persisted EUC-KR setting |
| `nicepay_db_version` | string | Database schema version |
| `nicepay_saved_shortcodes` | array | Saved shortcode configurations (includes presets) |
| `nicepay_transactions_schema_version` | string | Independent migrator version; current target `2026.08.24.7` |

WordPress privacy exporter/eraser callbacks are implemented by `NicePay_Privacy`. The eraser anonymizes buyer contact and receipt/payload fields but deliberately retains the financial identity and amount ledger.

`NicePay_Retention` implements the separate merchant-selected lifecycle. Its default is indefinite. When a custom period is explicitly acknowledged, a daily bounded job selects old eligible rows with `FOR UPDATE`, deletes child refund-attempt history and parent rows in one database transaction, and rolls back if the locked parent set changes. Active/ambiguous/reconciliation states are excluded. WooCommerce orders and external/provider storage are outside this cleanup boundary.
