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
            ),
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
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=shortcode' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'shortcode' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Shortcode', 'nicepay-payment-gateway' ); ?>
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
                    case 'shortcode':
                        $this->render_shortcode_tab();
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

    private function render_shortcode_tab() {
        ?>
        <div class="nicepay-shortcode-help">
            <h3><?php esc_html_e( 'Payment Button Shortcode', 'nicepay-payment-gateway' ); ?></h3>
            <p><?php esc_html_e( 'Use the following shortcode to embed a payment button on any page:', 'nicepay-payment-gateway' ); ?></p>

            <div class="nicepay-code-block">
                <code>[nicepay_payment amount="10000" goods_name="Product Name" pay_method="CARD" button_text="Pay Now"]</code>
            </div>

            <h4><?php esc_html_e( 'Available Parameters', 'nicepay-payment-gateway' ); ?></h4>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Parameter', 'nicepay-payment-gateway' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'nicepay-payment-gateway' ); ?></th>
                        <th><?php esc_html_e( 'Required', 'nicepay-payment-gateway' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>amount</code></td><td><?php esc_html_e( 'Payment amount', 'nicepay-payment-gateway' ); ?></td><td><?php esc_html_e( 'Yes', 'nicepay-payment-gateway' ); ?></td></tr>
                    <tr><td><code>goods_name</code></td><td><?php esc_html_e( 'Product/service name', 'nicepay-payment-gateway' ); ?></td><td><?php esc_html_e( 'Yes', 'nicepay-payment-gateway' ); ?></td></tr>
                    <tr><td><code>pay_method</code></td><td><?php esc_html_e( 'CARD, BANK, VBANK, CELLPHONE', 'nicepay-payment-gateway' ); ?></td><td><?php esc_html_e( 'No', 'nicepay-payment-gateway' ); ?></td></tr>
                    <tr><td><code>buyer_name</code></td><td><?php esc_html_e( 'Buyer name', 'nicepay-payment-gateway' ); ?></td><td><?php esc_html_e( 'No', 'nicepay-payment-gateway' ); ?></td></tr>
                    <tr><td><code>buyer_email</code></td><td><?php esc_html_e( 'Buyer email', 'nicepay-payment-gateway' ); ?></td><td><?php esc_html_e( 'No', 'nicepay-payment-gateway' ); ?></td></tr>
                    <tr><td><code>buyer_tel</code></td><td><?php esc_html_e( 'Buyer phone', 'nicepay-payment-gateway' ); ?></td><td><?php esc_html_e( 'No', 'nicepay-payment-gateway' ); ?></td></tr>
                    <tr><td><code>button_text</code></td><td><?php esc_html_e( 'Button label text', 'nicepay-payment-gateway' ); ?></td><td><?php esc_html_e( 'No', 'nicepay-payment-gateway' ); ?></td></tr>
                    <tr><td><code>button_class</code></td><td><?php esc_html_e( 'CSS class for the button', 'nicepay-payment-gateway' ); ?></td><td><?php esc_html_e( 'No', 'nicepay-payment-gateway' ); ?></td></tr>
                    <tr><td><code>currency</code></td><td><?php esc_html_e( 'KRW or USD', 'nicepay-payment-gateway' ); ?></td><td><?php esc_html_e( 'No', 'nicepay-payment-gateway' ); ?></td></tr>
                    <tr><td><code>language</code></td><td><?php esc_html_e( 'KO, EN, or CN', 'nicepay-payment-gateway' ); ?></td><td><?php esc_html_e( 'No', 'nicepay-payment-gateway' ); ?></td></tr>
                </tbody>
            </table>

            <h4><?php esc_html_e( 'Example with all parameters', 'nicepay-payment-gateway' ); ?></h4>
            <div class="nicepay-code-block">
                <code>[nicepay_payment amount="50000" goods_name="Premium Plan" pay_method="CARD" buyer_name="John" buyer_email="john@example.com" buyer_tel="01012345678" button_text="Subscribe Now" currency="KRW" language="KO"]</code>
            </div>
        </div>
        <?php
    }

    public function render_transactions_page() {
        $transactions_page = new NicePay_Transactions();
        $transactions_page->render();
    }
}

new NicePay_Admin();
