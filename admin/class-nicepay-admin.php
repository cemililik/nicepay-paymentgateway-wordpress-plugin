<?php
/**
 * NicePay Admin Settings Page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NicePay_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
    }

    public function add_menu() {
        add_menu_page(
            __( 'NicePay', 'nicepay-payment-gateway' ),
            __( 'NicePay', 'nicepay-payment-gateway' ),
            'manage_options',
            'nicepay-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-money-alt',
            58
        );

        add_submenu_page(
            'nicepay-settings',
            __( 'Settings', 'nicepay-payment-gateway' ),
            __( 'Settings', 'nicepay-payment-gateway' ),
            'manage_options',
            'nicepay-settings',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'nicepay-settings',
            __( 'Transactions', 'nicepay-payment-gateway' ),
            __( 'Transactions', 'nicepay-payment-gateway' ),
            'manage_options',
            'nicepay-transactions',
            array( $this, 'render_transactions_page' )
        );
    }

    public function enqueue_admin_styles( $hook ) {
        if ( strpos( $hook, 'nicepay' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'nicepay-shared-css',
            NICEPAY_PLUGIN_URL . 'assets/css/nicepay.css',
            array(),
            NICEPAY_VERSION
        );

        wp_enqueue_style(
            'nicepay-admin-css',
            NICEPAY_PLUGIN_URL . 'assets/css/nicepay-admin.css',
            array( 'nicepay-shared-css' ),
            NICEPAY_VERSION
        );

        wp_enqueue_script(
            'nicepay-admin-js',
            NICEPAY_PLUGIN_URL . 'assets/js/nicepay-admin.js',
            array( 'jquery' ),
            NICEPAY_VERSION,
            true
        );

        wp_localize_script( 'nicepay-admin-js', 'nicepayAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'i18n'    => array(
                'confirm'               => __( 'Confirm', 'nicepay-payment-gateway' ),
                'cancel'                => __( 'Cancel', 'nicepay-payment-gateway' ),
                'cancelTitle'           => __( 'Cancel Transaction', 'nicepay-payment-gateway' ),
                'cancelMessage'         => __( 'This action cannot be undone. The payment will be reversed.', 'nicepay-payment-gateway' ),
                'cancelReasonLabel'     => __( 'Cancellation Reason', 'nicepay-payment-gateway' ),
                'cancelReasonPlaceholder' => __( 'Enter reason for cancellation...', 'nicepay-payment-gateway' ),
                'cancelConfirm'         => __( 'Cancel Transaction', 'nicepay-payment-gateway' ),
                'cancelFailed'          => __( 'Cancellation failed.', 'nicepay-payment-gateway' ),
                'requestFailed'         => __( 'Request failed. Please try again.', 'nicepay-payment-gateway' ),
                'copied'                => __( 'Copied to clipboard!', 'nicepay-payment-gateway' ),
                'statusCancelled'       => __( 'CANCELLED', 'nicepay-payment-gateway' ),
                'shortcodeDeleted'      => __( 'Shortcode deleted.', 'nicepay-payment-gateway' ),
                'deleteConfirmTitle'    => __( 'Delete Shortcode', 'nicepay-payment-gateway' ),
                'deleteConfirmMsg'      => __( 'Are you sure? This cannot be undone.', 'nicepay-payment-gateway' ),
                'delete'                => __( 'Delete', 'nicepay-payment-gateway' ),
            ),
            'shortcodeNonce' => wp_create_nonce( 'nicepay_admin_shortcodes' ),
        ) );
    }

    public function register_settings() {
        // General settings
        register_setting( 'nicepay_general', 'nicepay_mode' );
        register_setting( 'nicepay_general', 'nicepay_language' );
        register_setting( 'nicepay_general', 'nicepay_currency' );
        register_setting( 'nicepay_general', 'nicepay_charset' );

        // API settings
        register_setting( 'nicepay_api', 'nicepay_test_mid' );
        register_setting( 'nicepay_api', 'nicepay_test_merchant_key' );
        register_setting( 'nicepay_api', 'nicepay_live_mid' );
        register_setting( 'nicepay_api', 'nicepay_live_merchant_key' );

        // Payment settings
        register_setting( 'nicepay_payment', 'nicepay_enabled_methods', array(
            'type'              => 'array',
            'sanitize_callback' => function ( $value ) {
                return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
            },
        ) );
        register_setting( 'nicepay_payment', 'nicepay_vbank_expiry_days', array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ) );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
        ?>
        <?php $mode = get_option( 'nicepay_mode', 'test' ); ?>
        <div class="wrap nicepay-admin">
            <h1>
                <?php esc_html_e( 'NicePay Settings', 'nicepay-payment-gateway' ); ?>
                <span class="nicepay-mode-badge nicepay-mode-badge-<?php echo esc_attr( $mode ); ?>">
                    <?php echo esc_html( strtoupper( $mode ) ); ?>
                </span>
            </h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=general' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'General', 'nicepay-payment-gateway' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=api' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'API Credentials', 'nicepay-payment-gateway' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=payment' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'payment' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Payment Methods', 'nicepay-payment-gateway' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=shortcodes' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'shortcodes' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Shortcodes', 'nicepay-payment-gateway' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=shortcode-generator' ) ); ?>"
                   class="nav-tab <?php echo in_array( $active_tab, array( 'shortcode-generator', 'shortcode' ), true ) ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Shortcode Generator', 'nicepay-payment-gateway' ); ?>
                </a>
            </nav>

            <div class="nicepay-settings-content">
                <?php
                switch ( $active_tab ) {
                    case 'api':
                        $this->render_api_tab();
                        break;
                    case 'payment':
                        $this->render_payment_tab();
                        break;
                    case 'shortcodes':
                        $this->render_shortcodes_tab();
                        break;
                    case 'shortcode-generator':
                    case 'shortcode':
                        $this->render_shortcode_generator_tab();
                        break;
                    default:
                        $this->render_general_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_general_tab() {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'nicepay_general' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Mode', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <select name="nicepay_mode">
                            <option value="test" <?php selected( get_option( 'nicepay_mode' ), 'test' ); ?>>
                                <?php esc_html_e( 'Test', 'nicepay-payment-gateway' ); ?>
                            </option>
                            <option value="live" <?php selected( get_option( 'nicepay_mode' ), 'live' ); ?>>
                                <?php esc_html_e( 'Live', 'nicepay-payment-gateway' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Use Test mode for development. Switch to Live for production.', 'nicepay-payment-gateway' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Language', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <select name="nicepay_language">
                            <option value="KO" <?php selected( get_option( 'nicepay_language' ), 'KO' ); ?>>
                                <?php esc_html_e( 'Korean', 'nicepay-payment-gateway' ); ?>
                            </option>
                            <option value="EN" <?php selected( get_option( 'nicepay_language' ), 'EN' ); ?>>
                                <?php esc_html_e( 'English', 'nicepay-payment-gateway' ); ?>
                            </option>
                            <option value="CN" <?php selected( get_option( 'nicepay_language' ), 'CN' ); ?>>
                                <?php esc_html_e( 'Chinese', 'nicepay-payment-gateway' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Currency', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <select name="nicepay_currency">
                            <option value="KRW" <?php selected( get_option( 'nicepay_currency' ), 'KRW' ); ?>>KRW</option>
                            <option value="USD" <?php selected( get_option( 'nicepay_currency' ), 'USD' ); ?>>USD</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Charset', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <select name="nicepay_charset">
                            <option value="utf-8" <?php selected( get_option( 'nicepay_charset' ), 'utf-8' ); ?>>UTF-8</option>
                            <option value="euc-kr" <?php selected( get_option( 'nicepay_charset' ), 'euc-kr' ); ?>>EUC-KR</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_api_tab() {
        $mode = get_option( 'nicepay_mode', 'test' );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'nicepay_api' ); ?>

            <?php if ( $mode === 'test' ) : ?>
                <div class="notice notice-info inline">
                    <p><?php esc_html_e( 'You are currently in Test mode.', 'nicepay-payment-gateway' ); ?></p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning inline">
                    <p><?php esc_html_e( 'You are currently in Live mode. Payments will be processed with real money.', 'nicepay-payment-gateway' ); ?></p>
                </div>
            <?php endif; ?>

            <h3><?php esc_html_e( 'Test Credentials', 'nicepay-payment-gateway' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Test MID', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <input type="text" name="nicepay_test_mid" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'nicepay_test_mid', NICEPAY_TEST_MID ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Test Merchant Key', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <input type="password" name="nicepay_test_merchant_key" class="large-text"
                               value="<?php echo esc_attr( get_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY ) ); ?>"
                               autocomplete="off">
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Live Credentials', 'nicepay-payment-gateway' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Live MID', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <input type="text" name="nicepay_live_mid" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'nicepay_live_mid' ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Live Merchant Key', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <input type="password" name="nicepay_live_merchant_key" class="large-text"
                               value="<?php echo esc_attr( get_option( 'nicepay_live_merchant_key' ) ); ?>"
                               autocomplete="off">
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_payment_tab() {
        $enabled = get_option( 'nicepay_enabled_methods', array( 'CARD' ) );
        $all_methods = NicePay_API::get_available_methods();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'nicepay_payment' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enabled Payment Methods', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <fieldset>
                            <?php foreach ( $all_methods as $code => $label ) : ?>
                                <label class="nicepay-method-checkbox">
                                    <input type="checkbox" name="nicepay_enabled_methods[]"
                                           value="<?php echo esc_attr( $code ); ?>"
                                           <?php checked( in_array( $code, $enabled, true ) ); ?>>
                                    <?php echo nicepay_get_method_icon( $code ); ?>
                                    <span><?php echo esc_html( $label ); ?></span>
                                    <code style="font-size:11px;color:#9ca3af;margin-left:auto;"><?php echo esc_html( $code ); ?></code>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Virtual Account Expiry (days)', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <input type="number" name="nicepay_vbank_expiry_days" min="1" max="30"
                               value="<?php echo esc_attr( get_option( 'nicepay_vbank_expiry_days', 3 ) ); ?>">
                        <p class="description">
                            <?php esc_html_e( 'Number of days before virtual account expires.', 'nicepay-payment-gateway' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_shortcodes_tab() {
        $shortcodes = nicepay_get_all_shortcodes();
        ?>
        <div class="nicepay-shortcodes-page">
            <div class="nicepay-shortcodes-header">
                <p><?php esc_html_e( 'Manage your saved payment shortcodes. Copy and paste them into any page or post.', 'nicepay-payment-gateway' ); ?></p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=shortcode-generator' ) ); ?>" class="button button-primary">
                    + <?php esc_html_e( 'Create New', 'nicepay-payment-gateway' ); ?>
                </a>
            </div>

            <?php if ( empty( $shortcodes ) ) : ?>
                <div class="nicepay-shortcodes-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 22v-4h6v4M12 6v4M10 8h4"/></svg>
                    <p class="nicepay-empty-title"><?php esc_html_e( 'No shortcodes yet', 'nicepay-payment-gateway' ); ?></p>
                    <p class="nicepay-empty-desc"><?php esc_html_e( 'Create your first payment shortcode to get started.', 'nicepay-payment-gateway' ); ?></p>
                </div>
            <?php else : ?>
                <div class="nicepay-shortcodes-grid">
                    <?php foreach ( $shortcodes as $sc ) : ?>
                        <div class="nicepay-sc-card" data-id="<?php echo esc_attr( $sc['id'] ); ?>" id="sc-card-<?php echo esc_attr( $sc['id'] ); ?>">
                            <div class="nicepay-sc-card-header">
                                <h4 class="nicepay-sc-card-name"><?php echo esc_html( $sc['name'] ); ?></h4>
                                <span class="nicepay-sc-card-badge nicepay-sc-card-badge-<?php echo esc_attr( $sc['display_mode'] ); ?>">
                                    <?php echo esc_html( $sc['display_mode'] ); ?>
                                </span>
                            </div>
                            <div class="nicepay-sc-card-body">
                                <div class="nicepay-sc-card-preview">
                                    <button type="button" class="nicepay-pay-button" style="background:<?php echo esc_attr( $sc['button_color'] ?: '#2563eb' ); ?>;pointer-events:none;font-size:13px;padding:8px 20px;">
                                        <?php echo esc_html( $sc['button_text'] ?: 'Pay Now' ); ?>
                                    </button>
                                </div>
                                <div class="nicepay-sc-card-meta">
                                    <span><?php echo esc_html( nicepay_format_amount( $sc['amount'], $sc['currency'] ) ); ?></span>
                                    <span><?php echo esc_html( $sc['goods_name'] ); ?></span>
                                    <?php if ( ! empty( $sc['pay_method'] ) ) : ?>
                                        <span><?php echo esc_html( NicePay_API::get_payment_method_name( $sc['pay_method'] ) ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="nicepay-sc-card-code-wrapper">
                                    <code class="nicepay-sc-card-code"><?php
                                        $sc_parts = array( '[nicepay_payment' );
                                        $sc_parts[] = 'id="' . esc_attr( $sc['id'] ) . '"';
                                        if ( ! empty( $sc['display_mode'] ) && $sc['display_mode'] === 'modal' ) $sc_parts[] = 'display_mode="modal"';
                                        if ( ! empty( $sc['amount'] ) ) $sc_parts[] = 'amount="' . esc_attr( $sc['amount'] ) . '"';
                                        if ( ! empty( $sc['goods_name'] ) ) $sc_parts[] = 'goods_name="' . esc_attr( $sc['goods_name'] ) . '"';
                                        if ( ! empty( $sc['pay_method'] ) ) $sc_parts[] = 'pay_method="' . esc_attr( $sc['pay_method'] ) . '"';
                                        if ( ! empty( $sc['button_text'] ) ) $sc_parts[] = 'button_text="' . esc_attr( $sc['button_text'] ) . '"';
                                        if ( ! empty( $sc['button_color'] ) && $sc['button_color'] !== '#2563eb' ) $sc_parts[] = 'button_color="' . esc_attr( $sc['button_color'] ) . '"';
                                        if ( ! empty( $sc['currency'] ) ) $sc_parts[] = 'currency="' . esc_attr( $sc['currency'] ) . '"';
                                        if ( ! empty( $sc['language'] ) ) $sc_parts[] = 'language="' . esc_attr( $sc['language'] ) . '"';
                                        echo esc_html( implode( ' ', $sc_parts ) . ']' );
                                    ?></code>
                                </div>
                            </div>
                            <div class="nicepay-sc-card-actions">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=shortcode-generator&edit=' . $sc['id'] ) ); ?>" class="nicepay-sc-card-btn">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    <?php esc_html_e( 'Edit', 'nicepay-payment-gateway' ); ?>
                                </a>
                                <button type="button" class="nicepay-sc-card-btn nicepay-sc-card-copy"
                                        data-shortcode="<?php echo esc_attr( implode( ' ', $sc_parts ) . ']' ); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                    <?php esc_html_e( 'Copy', 'nicepay-payment-gateway' ); ?>
                                </button>
                                <button type="button" class="nicepay-sc-card-btn nicepay-sc-card-delete"
                                        data-id="<?php echo esc_attr( $sc['id'] ); ?>"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'nicepay_admin_shortcodes' ) ); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    <?php esc_html_e( 'Delete', 'nicepay-payment-gateway' ); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_shortcode_generator_tab() {
        $enabled_methods = get_option( 'nicepay_enabled_methods', array( 'CARD' ) );

        // Check if editing an existing shortcode
        $edit_id   = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
        $edit_data = $edit_id ? nicepay_get_saved_shortcode( $edit_id ) : null;
        ?>
        <div class="nicepay-sc-builder" data-edit-id="<?php echo esc_attr( $edit_id ); ?>">
            <div class="nicepay-sc-layout">
                <!-- Left: Builder Form -->
                <div class="nicepay-sc-form-panel">
                    <!-- Shortcode Name -->
                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg>
                            <?php esc_html_e( 'Shortcode Name', 'nicepay-payment-gateway' ); ?>
                        </h3>
                        <div class="nicepay-sc-field">
                            <label for="sc-name"><?php esc_html_e( 'Name', 'nicepay-payment-gateway' ); ?> <span class="nicepay-sc-required">*</span></label>
                            <input type="text" id="sc-name" placeholder="<?php esc_attr_e( 'e.g. Quick Payment', 'nicepay-payment-gateway' ); ?>" class="nicepay-sc-input" maxlength="60">
                        </div>
                    </div>

                    <!-- Display Mode -->
                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                            <?php esc_html_e( 'Display Mode', 'nicepay-payment-gateway' ); ?>
                        </h3>
                        <div class="nicepay-sc-display-modes">
                            <label class="nicepay-sc-display-mode is-active" data-value="inline">
                                <input type="radio" name="sc-display-mode" value="inline" checked>
                                <div class="nicepay-sc-display-mode-visual">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="4" width="20" height="16" rx="2"/><rect x="5" y="7" width="14" height="2" rx="1"/><rect x="5" y="11" width="14" height="2" rx="1"/><rect x="7" y="15" width="10" height="3" rx="1.5"/></svg>
                                </div>
                                <div class="nicepay-sc-display-mode-text">
                                    <strong><?php esc_html_e( 'Inline', 'nicepay-payment-gateway' ); ?></strong>
                                    <span><?php esc_html_e( 'Full form shown on page', 'nicepay-payment-gateway' ); ?></span>
                                </div>
                            </label>
                            <label class="nicepay-sc-display-mode" data-value="modal">
                                <input type="radio" name="sc-display-mode" value="modal">
                                <div class="nicepay-sc-display-mode-visual">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="7" y="15" width="10" height="3" rx="1.5"/><rect x="4" y="5" width="16" height="14" rx="2" stroke-dasharray="3 2"/><path d="M12 9v2M12 13h.01"/></svg>
                                </div>
                                <div class="nicepay-sc-display-mode-text">
                                    <strong><?php esc_html_e( 'Modal', 'nicepay-payment-gateway' ); ?></strong>
                                    <span><?php esc_html_e( 'Button opens popup overlay', 'nicepay-payment-gateway' ); ?></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Required Fields -->
                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg>
                            <?php esc_html_e( 'Required', 'nicepay-payment-gateway' ); ?>
                        </h3>
                        <div class="nicepay-sc-field">
                            <label for="sc-amount"><?php esc_html_e( 'Payment Amount', 'nicepay-payment-gateway' ); ?> <span class="nicepay-sc-required">*</span></label>
                            <div class="nicepay-sc-input-group">
                                <input type="number" id="sc-amount" min="1" placeholder="10000" class="nicepay-sc-input">
                                <select id="sc-currency" class="nicepay-sc-select-sm">
                                    <option value="KRW">KRW</option>
                                    <option value="USD">USD</option>
                                </select>
                            </div>
                        </div>
                        <div class="nicepay-sc-field">
                            <label for="sc-goods-name"><?php esc_html_e( 'Product / Service Name', 'nicepay-payment-gateway' ); ?> <span class="nicepay-sc-required">*</span></label>
                            <input type="text" id="sc-goods-name" placeholder="<?php esc_attr_e( 'e.g. Premium Plan', 'nicepay-payment-gateway' ); ?>" class="nicepay-sc-input" maxlength="40">
                        </div>
                    </div>

                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                            <?php esc_html_e( 'Payment Method', 'nicepay-payment-gateway' ); ?>
                        </h3>
                        <div class="nicepay-sc-methods">
                            <label class="nicepay-sc-method-chip nicepay-sc-method-chip-all is-active" data-value="">
                                <input type="radio" name="sc-pay-method" value="" checked>
                                <?php esc_html_e( 'All Enabled', 'nicepay-payment-gateway' ); ?>
                            </label>
                            <?php foreach ( NicePay_API::get_available_methods() as $code => $label ) : ?>
                                <?php if ( in_array( $code, $enabled_methods, true ) ) : ?>
                                <label class="nicepay-sc-method-chip" data-value="<?php echo esc_attr( $code ); ?>">
                                    <input type="radio" name="sc-pay-method" value="<?php echo esc_attr( $code ); ?>">
                                    <?php echo nicepay_get_method_icon( $code ); ?>
                                    <?php echo esc_html( $label ); ?>
                                </label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <?php esc_html_e( 'Buyer Information', 'nicepay-payment-gateway' ); ?>
                            <span class="nicepay-sc-optional"><?php esc_html_e( 'Pre-fill or leave empty', 'nicepay-payment-gateway' ); ?></span>
                        </h3>
                        <p class="nicepay-sc-hint">
                            <?php esc_html_e( 'If left empty, the buyer will fill these fields in the payment form. If provided, the fields will be pre-filled and hidden.', 'nicepay-payment-gateway' ); ?>
                        </p>
                        <div class="nicepay-sc-field-row">
                            <div class="nicepay-sc-field">
                                <label for="sc-buyer-name"><?php esc_html_e( 'Name', 'nicepay-payment-gateway' ); ?></label>
                                <input type="text" id="sc-buyer-name" placeholder="<?php esc_attr_e( 'Buyer fills in', 'nicepay-payment-gateway' ); ?>" class="nicepay-sc-input">
                            </div>
                            <div class="nicepay-sc-field">
                                <label for="sc-buyer-tel"><?php esc_html_e( 'Phone', 'nicepay-payment-gateway' ); ?></label>
                                <input type="text" id="sc-buyer-tel" placeholder="<?php esc_attr_e( 'Buyer fills in', 'nicepay-payment-gateway' ); ?>" class="nicepay-sc-input">
                            </div>
                        </div>
                        <div class="nicepay-sc-field">
                            <label for="sc-buyer-email"><?php esc_html_e( 'Email', 'nicepay-payment-gateway' ); ?></label>
                            <input type="email" id="sc-buyer-email" placeholder="<?php esc_attr_e( 'Buyer fills in', 'nicepay-payment-gateway' ); ?>" class="nicepay-sc-input">
                        </div>
                    </div>

                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                            <?php esc_html_e( 'Appearance', 'nicepay-payment-gateway' ); ?>
                            <span class="nicepay-sc-optional"><?php esc_html_e( 'Optional', 'nicepay-payment-gateway' ); ?></span>
                        </h3>
                        <div class="nicepay-sc-field-row">
                            <div class="nicepay-sc-field">
                                <label for="sc-button-text"><?php esc_html_e( 'Button Text', 'nicepay-payment-gateway' ); ?></label>
                                <input type="text" id="sc-button-text" placeholder="Pay Now" class="nicepay-sc-input">
                            </div>
                            <div class="nicepay-sc-field">
                                <label for="sc-language"><?php esc_html_e( 'Language', 'nicepay-payment-gateway' ); ?></label>
                                <select id="sc-language" class="nicepay-sc-input">
                                    <option value=""><?php esc_html_e( 'Default', 'nicepay-payment-gateway' ); ?></option>
                                    <option value="KO"><?php esc_html_e( 'Korean', 'nicepay-payment-gateway' ); ?></option>
                                    <option value="EN"><?php esc_html_e( 'English', 'nicepay-payment-gateway' ); ?></option>
                                    <option value="CN"><?php esc_html_e( 'Chinese', 'nicepay-payment-gateway' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="nicepay-sc-field-row">
                            <div class="nicepay-sc-field">
                                <label for="sc-button-color"><?php esc_html_e( 'Button Color', 'nicepay-payment-gateway' ); ?></label>
                                <div class="nicepay-sc-color-picker">
                                    <input type="color" id="sc-button-color" value="#2563eb" class="nicepay-sc-color-input">
                                    <div class="nicepay-sc-color-presets">
                                        <button type="button" class="nicepay-sc-color-swatch is-active" data-color="#2563eb" style="background:#2563eb" title="Blue"></button>
                                        <button type="button" class="nicepay-sc-color-swatch" data-color="#111827" style="background:#111827" title="Black"></button>
                                        <button type="button" class="nicepay-sc-color-swatch" data-color="#16a34a" style="background:#16a34a" title="Green"></button>
                                        <button type="button" class="nicepay-sc-color-swatch" data-color="#dc2626" style="background:#dc2626" title="Red"></button>
                                        <button type="button" class="nicepay-sc-color-swatch" data-color="#9333ea" style="background:#9333ea" title="Purple"></button>
                                        <button type="button" class="nicepay-sc-color-swatch" data-color="#ea580c" style="background:#ea580c" title="Orange"></button>
                                    </div>
                                </div>
                            </div>
                            <div class="nicepay-sc-field">
                                <label for="sc-button-class"><?php esc_html_e( 'CSS Class', 'nicepay-payment-gateway' ); ?></label>
                                <input type="text" id="sc-button-class" placeholder="nicepay-pay-button" class="nicepay-sc-input">
                            </div>
                        </div>
                    </div>

                    <!-- Save Button -->
                    <div class="nicepay-sc-save-section">
                        <button type="button" id="sc-save-btn" class="button button-primary button-hero nicepay-sc-save-btn">
                            <?php echo $edit_data ? esc_html__( 'Update Shortcode', 'nicepay-payment-gateway' ) : esc_html__( 'Save Shortcode', 'nicepay-payment-gateway' ); ?>
                        </button>
                        <?php if ( $edit_data ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=shortcode-generator' ) ); ?>" class="nicepay-sc-save-cancel">
                                <?php esc_html_e( 'or create new', 'nicepay-payment-gateway' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Preview & Output -->
                <div class="nicepay-sc-preview-panel">
                    <div class="nicepay-sc-preview-sticky">
                        <!-- Live Preview -->
                        <div class="nicepay-sc-preview-card">
                            <div class="nicepay-sc-preview-label"><?php esc_html_e( 'Live Preview', 'nicepay-payment-gateway' ); ?></div>
                            <div class="nicepay-sc-preview-live">
                                <!-- Product & Amount -->
                                <div class="nicepay-sc-pv-header">
                                    <div class="nicepay-sc-pv-product" id="sc-pv-product"><?php esc_html_e( 'Product Name', 'nicepay-payment-gateway' ); ?></div>
                                    <div class="nicepay-sc-pv-amount" id="sc-pv-amount">0 KRW</div>
                                </div>

                                <!-- Method Selector Preview -->
                                <div class="nicepay-sc-pv-methods" id="sc-pv-methods" style="display:none;">
                                    <div class="nicepay-sc-pv-section-label"><?php esc_html_e( 'Payment Method', 'nicepay-payment-gateway' ); ?></div>
                                    <div class="nicepay-sc-pv-method-options" id="sc-pv-method-options"></div>
                                </div>

                                <!-- Buyer Fields Preview -->
                                <div class="nicepay-sc-pv-fields" id="sc-pv-fields" style="display:none;">
                                    <div class="nicepay-sc-pv-field">
                                        <div class="nicepay-sc-pv-field-label"><?php esc_html_e( 'Name', 'nicepay-payment-gateway' ); ?> *</div>
                                        <div class="nicepay-sc-pv-field-input"></div>
                                    </div>
                                    <div class="nicepay-sc-pv-field">
                                        <div class="nicepay-sc-pv-field-label"><?php esc_html_e( 'Email', 'nicepay-payment-gateway' ); ?> *</div>
                                        <div class="nicepay-sc-pv-field-input"></div>
                                    </div>
                                    <div class="nicepay-sc-pv-field">
                                        <div class="nicepay-sc-pv-field-label"><?php esc_html_e( 'Phone', 'nicepay-payment-gateway' ); ?> *</div>
                                        <div class="nicepay-sc-pv-field-input"></div>
                                    </div>
                                </div>

                                <!-- Button -->
                                <button type="button" class="nicepay-pay-button" id="sc-preview-btn" style="width:100%;text-align:center;">
                                    <?php esc_html_e( 'Pay Now', 'nicepay-payment-gateway' ); ?>
                                </button>
                            </div>
                        </div>

                        <!-- Generated Shortcode -->
                        <div class="nicepay-sc-output-card">
                            <div class="nicepay-sc-output-header">
                                <div class="nicepay-sc-output-label"><?php esc_html_e( 'Generated Shortcode', 'nicepay-payment-gateway' ); ?></div>
                                <button type="button" class="nicepay-sc-copy-btn" id="sc-copy-btn" title="<?php esc_attr_e( 'Copy to clipboard', 'nicepay-payment-gateway' ); ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                    <span><?php esc_html_e( 'Copy', 'nicepay-payment-gateway' ); ?></span>
                                </button>
                            </div>
                            <div class="nicepay-sc-output-code" id="sc-output">
                                <code id="sc-output-code">[nicepay_payment]</code>
                            </div>
                        </div>

                        <!-- Validation Messages -->
                        <div class="nicepay-sc-validation" id="sc-validation" style="display:none;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                            <span id="sc-validation-msg"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var defaultColor = '#2563eb';

            var fields = {
                name:         '#sc-name',
                display_mode: 'input[name="sc-display-mode"]:checked',
                amount:       '#sc-amount',
                goods_name:   '#sc-goods-name',
                pay_method:   'input[name="sc-pay-method"]:checked',
                buyer_name:   '#sc-buyer-name',
                buyer_email:  '#sc-buyer-email',
                buyer_tel:    '#sc-buyer-tel',
                button_text:  '#sc-button-text',
                button_class: '#sc-button-class',
                button_color: '#sc-button-color',
                currency:     '#sc-currency',
                language:     '#sc-language'
            };

            var editId = $('.nicepay-sc-builder').data('edit-id') || '';

            function buildShortcode() {
                var parts = ['[nicepay_payment'];
                var displayMode = $(fields.display_mode).val() || 'inline';
                var amount = $(fields.amount).val().trim();
                var goodsName = $(fields.goods_name).val().trim();
                var payMethod = $(fields.pay_method).val();
                var buyerName = $(fields.buyer_name).val().trim();
                var buyerEmail = $(fields.buyer_email).val().trim();
                var buyerTel = $(fields.buyer_tel).val().trim();
                var buttonText = $(fields.button_text).val().trim();
                var buttonClass = $(fields.button_class).val().trim();
                var buttonColor = $(fields.button_color).val();
                var currency = $(fields.currency).val() || 'KRW';
                var language = $(fields.language).val();

                // If editing a saved shortcode, show id-based shortcode as a short form
                // but also show full shortcode below
                if (editId) parts.push('id="' + editId + '"');

                if (displayMode === 'modal') parts.push('display_mode="modal"');
                if (amount) parts.push('amount="' + amount + '"');
                if (goodsName) parts.push('goods_name="' + goodsName + '"');
                if (payMethod) parts.push('pay_method="' + payMethod + '"');
                if (buyerName) parts.push('buyer_name="' + buyerName + '"');
                if (buyerEmail) parts.push('buyer_email="' + buyerEmail + '"');
                if (buyerTel) parts.push('buyer_tel="' + buyerTel + '"');
                if (buttonText) parts.push('button_text="' + buttonText + '"');
                if (buttonClass && buttonClass !== 'nicepay-pay-button') parts.push('button_class="' + buttonClass + '"');
                if (buttonColor && buttonColor !== defaultColor) parts.push('button_color="' + buttonColor + '"');
                if (currency) parts.push('currency="' + currency + '"');
                if (language) parts.push('language="' + language + '"');

                return parts.join(' ') + ']';
            }

            function updatePreview() {
                var shortcode = buildShortcode();
                $('#sc-output-code').text(shortcode);

                var amount = $(fields.amount).val().trim();
                var currency = $(fields.currency).val() || 'KRW';
                var goodsName = $(fields.goods_name).val().trim();
                var payMethod = $(fields.pay_method).val();
                var btnText = $(fields.button_text).val().trim() || '<?php echo esc_js( __( 'Pay Now', 'nicepay-payment-gateway' ) ); ?>';
                var btnColor = $(fields.button_color).val() || defaultColor;

                // Product & Amount
                $('#sc-pv-product').text(goodsName || '<?php echo esc_js( __( 'Product Name', 'nicepay-payment-gateway' ) ); ?>');
                if (amount) {
                    var formatted = currency === 'KRW'
                        ? parseInt(amount).toLocaleString() + ' ' + currency
                        : parseFloat(amount).toFixed(2) + ' ' + currency;
                    $('#sc-pv-amount').text(formatted);
                } else {
                    $('#sc-pv-amount').text('0 ' + currency);
                }

                // Payment method preview
                if (!payMethod) {
                    var methodsHtml = '';
                    <?php
                    foreach ( NicePay_API::get_available_methods() as $code => $label ) {
                        if ( ! in_array( $code, $enabled_methods, true ) ) {
                            continue;
                        }
                        // Flatten SVG to single line for JS safety
                        $icon_html = preg_replace( '/\s+/', ' ', trim( nicepay_get_method_icon( $code ) ) );
                        $icon_js   = str_replace( array( "'", "\n", "\r" ), array( "\\'", '', '' ), $icon_html );
                        $label_js  = esc_js( $label );
                        echo "methodsHtml += '<div class=\"nicepay-sc-pv-method-option\">{$icon_js} {$label_js}</div>';\n";
                    }
                    ?>
                    $('#sc-pv-method-options').html(methodsHtml);
                    $('#sc-pv-methods').show();
                } else {
                    $('#sc-pv-methods').hide();
                }

                // Buyer fields preview
                var hasBuyerInfo = $(fields.buyer_name).val().trim() && $(fields.buyer_email).val().trim() && $(fields.buyer_tel).val().trim();
                if (hasBuyerInfo) {
                    $('#sc-pv-fields').hide();
                } else {
                    $('#sc-pv-fields').show();
                }

                // Button
                $('#sc-preview-btn').text(btnText).css('background', btnColor);

                // Validation
                var valid = true;
                var msgs = [];
                if (!amount) { msgs.push('<?php echo esc_js( __( 'Amount is required', 'nicepay-payment-gateway' ) ); ?>'); valid = false; }
                if (!goodsName) { msgs.push('<?php echo esc_js( __( 'Product name is required', 'nicepay-payment-gateway' ) ); ?>'); valid = false; }

                if (!valid) {
                    $('#sc-validation').show().find('#sc-validation-msg').text(msgs.join(', '));
                    $('#sc-copy-btn').prop('disabled', true);
                } else {
                    $('#sc-validation').hide();
                    $('#sc-copy-btn').prop('disabled', false);
                }
            }

            // Bind all inputs
            $('.nicepay-sc-builder').on('input change', 'input, select', function() {
                updatePreview();
            });

            // Color swatch selection
            $('.nicepay-sc-color-swatch').on('click', function() {
                var color = $(this).data('color');
                $('#sc-button-color').val(color).trigger('input');
                $('.nicepay-sc-color-swatch').removeClass('is-active');
                $(this).addClass('is-active');
            });

            // Sync color input with swatches
            $('#sc-button-color').on('input', function() {
                var val = $(this).val().toLowerCase();
                $('.nicepay-sc-color-swatch').each(function() {
                    $(this).toggleClass('is-active', $(this).data('color') === val);
                });
            });

            // Method chip selection — listen to radio change (not label click) to avoid double-fire
            $('input[name="sc-pay-method"]').on('change', function() {
                $('.nicepay-sc-method-chip').removeClass('is-active');
                $(this).closest('.nicepay-sc-method-chip').addClass('is-active');
                updatePreview();
            });

            // Copy button
            $('#sc-copy-btn').on('click', function() {
                var code = $('#sc-output-code').text();
                var btn = $(this);
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(code).then(function() {
                        btn.find('span').text('<?php echo esc_js( __( 'Copied!', 'nicepay-payment-gateway' ) ); ?>');
                        btn.addClass('is-copied');
                        setTimeout(function() {
                            btn.find('span').text('<?php echo esc_js( __( 'Copy', 'nicepay-payment-gateway' ) ); ?>');
                            btn.removeClass('is-copied');
                        }, 2000);
                    });
                }
            });

            // Display mode toggle — listen to radio change to avoid label double-fire
            $('input[name="sc-display-mode"]').on('change', function() {
                $('.nicepay-sc-display-mode').removeClass('is-active');
                $(this).closest('.nicepay-sc-display-mode').addClass('is-active');
                updatePreview();
            });

            // Save shortcode
            $('#sc-save-btn').on('click', function() {
                var name = $(fields.name).val().trim();
                if (!name) {
                    $(fields.name).addClass('is-invalid').focus();
                    return;
                }
                $(fields.name).removeClass('is-invalid');

                var btn = $(this);
                btn.prop('disabled', true).text('<?php echo esc_js( __( 'Saving...', 'nicepay-payment-gateway' ) ); ?>');

                $.post(nicepayAdmin.ajaxUrl, {
                    action: 'nicepay_save_shortcode',
                    nonce: nicepayAdmin.shortcodeNonce,
                    edit_id: editId,
                    name: name,
                    display_mode: $(fields.display_mode).val() || 'inline',
                    amount: $(fields.amount).val().trim(),
                    goods_name: $(fields.goods_name).val().trim(),
                    pay_method: $(fields.pay_method).val() || '',
                    buyer_name: $(fields.buyer_name).val().trim(),
                    buyer_email: $(fields.buyer_email).val().trim(),
                    buyer_tel: $(fields.buyer_tel).val().trim(),
                    button_text: $(fields.button_text).val().trim(),
                    button_class: $(fields.button_class).val().trim(),
                    button_color: $(fields.button_color).val(),
                    currency: $(fields.currency).val(),
                    language: $(fields.language).val()
                }, function(resp) {
                    if (resp.success) {
                        if (typeof NicePayToast !== 'undefined') {
                            NicePayToast.show(resp.data.message, 'success');
                        }
                        setTimeout(function() {
                            window.location.href = '<?php echo esc_js( admin_url( 'admin.php?page=nicepay-settings&tab=shortcodes' ) ); ?>';
                        }, 800);
                    } else {
                        btn.prop('disabled', false).text(editId ? '<?php echo esc_js( __( 'Update Shortcode', 'nicepay-payment-gateway' ) ); ?>' : '<?php echo esc_js( __( 'Save Shortcode', 'nicepay-payment-gateway' ) ); ?>');
                        if (typeof NicePayToast !== 'undefined') {
                            NicePayToast.show(resp.data.message || 'Error', 'error');
                        }
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text(editId ? '<?php echo esc_js( __( 'Update Shortcode', 'nicepay-payment-gateway' ) ); ?>' : '<?php echo esc_js( __( 'Save Shortcode', 'nicepay-payment-gateway' ) ); ?>');
                });
            });

            // Pre-populate fields if editing
            <?php if ( $edit_data ) : ?>
            (function() {
                var d = <?php echo wp_json_encode( $edit_data ); ?>;
                if (d.name) $('#sc-name').val(d.name);
                if (d.amount) $('#sc-amount').val(d.amount);
                if (d.goods_name) $('#sc-goods-name').val(d.goods_name);
                if (d.buyer_name) $('#sc-buyer-name').val(d.buyer_name);
                if (d.buyer_email) $('#sc-buyer-email').val(d.buyer_email);
                if (d.buyer_tel) $('#sc-buyer-tel').val(d.buyer_tel);
                if (d.button_text) $('#sc-button-text').val(d.button_text);
                if (d.button_class) $('#sc-button-class').val(d.button_class);
                if (d.button_color) { $('#sc-button-color').val(d.button_color).trigger('input'); }
                if (d.currency) $('#sc-currency').val(d.currency);
                if (d.language) $('#sc-language').val(d.language);
                if (d.display_mode) {
                    $('input[name="sc-display-mode"][value="' + d.display_mode + '"]').prop('checked', true);
                    $('.nicepay-sc-display-mode').removeClass('is-active');
                    $('.nicepay-sc-display-mode[data-value="' + d.display_mode + '"]').addClass('is-active');
                }
                if (d.pay_method) {
                    $('input[name="sc-pay-method"][value="' + d.pay_method + '"]').prop('checked', true);
                    $('.nicepay-sc-method-chip').removeClass('is-active');
                    $('.nicepay-sc-method-chip[data-value="' + d.pay_method + '"]').addClass('is-active');
                }
            })();
            <?php endif; ?>

            updatePreview();
        });
        </script>
        <?php
    }

    public function render_transactions_page() {
        $transactions_page = new NicePay_Transactions();
        $transactions_page->render();
    }
}

new NicePay_Admin();
