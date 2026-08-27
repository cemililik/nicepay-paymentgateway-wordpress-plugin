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
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order_payment_summary' ) );
    }

    public function add_menu() {
        add_menu_page(
            __( 'NicePay', 'nicepay-payment-gateway' ),
            __( 'NicePay', 'nicepay-payment-gateway' ),
            nicepay_manage_transactions_capability(),
            'nicepay-transactions',
            array( $this, 'render_transactions_page' ),
            'dashicons-money-alt',
            58
        );

        add_submenu_page(
            'nicepay-transactions',
            __( 'Transactions', 'nicepay-payment-gateway' ),
            __( 'Transactions', 'nicepay-payment-gateway' ),
            nicepay_manage_transactions_capability(),
            'nicepay-transactions',
            array( $this, 'render_transactions_page' )
        );

        add_submenu_page(
            'nicepay-transactions',
            __( 'Settings', 'nicepay-payment-gateway' ),
            __( 'Settings', 'nicepay-payment-gateway' ),
            'manage_options',
            'nicepay-settings',
            array( $this, 'render_settings_page' )
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
				'refundTitle'           => __( 'Refund payment', 'nicepay-payment-gateway' ),
				/* translators: %s: formatted refundable amount */
				'refundMessage'         => __( 'Refund %s? The provider action cannot be undone.', 'nicepay-payment-gateway' ),
				'refundReasonLabel'     => __( 'Refund reason', 'nicepay-payment-gateway' ),
				'refundReasonPlaceholder' => __( 'Enter the refund reason...', 'nicepay-payment-gateway' ),
				'refundReasonRequired'  => __( 'A refund reason is required.', 'nicepay-payment-gateway' ),
				'refundConfirm'         => __( 'Refund payment', 'nicepay-payment-gateway' ),
				'refundFailed'          => __( 'Refund failed.', 'nicepay-payment-gateway' ),
                'requestFailed'         => __( 'Request failed. Please try again.', 'nicepay-payment-gateway' ),
                'copied'                => __( 'Copied to clipboard!', 'nicepay-payment-gateway' ),
				'copyFailed'            => __( 'Copy failed.', 'nicepay-payment-gateway' ),
				'statusRefunded'        => __( 'Refunded', 'nicepay-payment-gateway' ),
				'reconcileReversedTitle'=> __( 'Confirm provider reversal', 'nicepay-payment-gateway' ),
				'reconcileCapturedTitle'=> __( 'Confirm captured funds', 'nicepay-payment-gateway' ),
				/* translators: %s: formatted transaction amount */
				'reconcileReversedMessage' => __( 'Record that NICEPAY confirms reversal of %s. Verify the merchant console before continuing.', 'nicepay-payment-gateway' ),
				/* translators: %s: formatted transaction amount */
				'reconcileCapturedMessage' => __( 'Record that NICEPAY confirms capture of %s. Verify the merchant console before continuing.', 'nicepay-payment-gateway' ),
				'reconcileReasonLabel'  => __( 'Evidence and reason', 'nicepay-payment-gateway' ),
				'reconcileReasonPlaceholder' => __( 'Enter the console reference and verification details...', 'nicepay-payment-gateway' ),
				'reconcileReasonRequired' => __( 'Reconciliation evidence and a reason are required.', 'nicepay-payment-gateway' ),
				'reconcileConfirm'      => __( 'Record decision', 'nicepay-payment-gateway' ),
				'reconcileFailed'       => __( 'Reconciliation decision could not be recorded.', 'nicepay-payment-gateway' ),
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
        register_setting( 'nicepay_general', 'nicepay_mode', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_mode' ),
            'default'           => 'test',
        ) );
        register_setting( 'nicepay_general', 'nicepay_language', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_language' ),
            'default'           => 'KO',
        ) );
        register_setting( 'nicepay_general', 'nicepay_currency', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_currency' ),
            'default'           => 'KRW',
        ) );
        register_setting( 'nicepay_general', NicePay_Retention::SETTINGS_OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array( 'NicePay_Retention', 'sanitize_settings' ),
            'default'           => NicePay_Retention::default_settings(),
        ) );
		register_setting( 'nicepay_general', 'nicepay_delete_data_on_uninstall', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			'default'           => 'no',
		) );

        // API settings
        register_setting( 'nicepay_api', 'nicepay_test_mid', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_mid' ),
        ) );
        register_setting( 'nicepay_api', 'nicepay_test_merchant_key', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_test_merchant_key' ),
        ) );
        register_setting( 'nicepay_api', 'nicepay_live_mid', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_mid' ),
        ) );
        register_setting( 'nicepay_api', 'nicepay_live_merchant_key', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_live_merchant_key' ),
        ) );

        // Payment settings
        register_setting( 'nicepay_payment', 'nicepay_standalone_enabled', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
            'default'           => 'no',
        ) );
        register_setting( 'nicepay_payment', 'nicepay_enabled_methods', array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize_enabled_methods' ),
            'default'           => nicepay_default_enabled_methods(),
        ) );
        register_setting( 'nicepay_payment', 'nicepay_vbank_expiry_days', array(
            'type'              => 'integer',
            'sanitize_callback' => array( $this, 'sanitize_expiry_days' ),
            'default'           => 3,
        ) );
    }

    public function sanitize_mode( $value ) {
        return in_array( $value, array( 'test', 'live' ), true ) ? $value : 'test';
    }

    public function sanitize_language( $value ) {
        return in_array( $value, array( 'KO', 'EN', 'CN' ), true ) ? $value : 'KO';
    }

    public function sanitize_currency( $value ) {
        return true === nicepay_is_supported_currency( $value ) ? strtoupper( $value ) : 'KRW';
    }

    public function sanitize_mid( $value ) {
        $value = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
        return substr( $value, 0, 20 );
    }

    public function sanitize_test_merchant_key( $value ) {
        return $this->sanitize_merchant_key( $value, 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY, 'nicepay_clear_test_merchant_key' );
    }

    public function sanitize_live_merchant_key( $value ) {
		if ( defined( 'NICEPAY_LIVE_MERCHANT_KEY' ) ) {
			// Never copy a wp-config.php secret into the options table.
			return '';
		}
        return $this->sanitize_merchant_key( $value, 'nicepay_live_merchant_key', '', 'nicepay_clear_live_merchant_key' );
    }

    private function sanitize_merchant_key( $value, $option_name, $default, $clear_field ) {
        $clear = isset( $_POST[ $clear_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $clear_field ] ) ) : 'no';
        if ( 'yes' === $clear ) {
            return '';
        }

        $value = trim( sanitize_text_field( (string) $value ) );
        if ( '' === $value ) {
            return (string) get_option( $option_name, $default );
        }

        return substr( $value, 0, 512 );
    }

    public function sanitize_enabled_methods( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $allowed = array_keys( NicePay_API::get_certified_methods() );
        $value   = array_filter( $value, 'is_string' );
        $value   = array_map( 'sanitize_text_field', $value );
        return array_values( array_unique( array_intersect( $allowed, $value ) ) );
    }

    public function sanitize_checkbox( $value ) {
        return 'yes' === $value ? 'yes' : 'no';
    }

    public function sanitize_expiry_days( $value ) {
        return max( 1, min( 30, absint( $value ) ) );
    }

    /**
     * Build a strict allowlist of non-secret operational diagnostics.
     *
     * @return array<string,string>
     */
    public static function get_system_report_data() {
        global $wp_version;

        $wordpress_version = function_exists( 'get_bloginfo' )
            ? get_bloginfo( 'version' )
            : ( isset( $wp_version ) ? $wp_version : 'unknown' );
        $woocommerce_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'not_available';
        $stored_mode = get_option( 'nicepay_mode', '' );
        $mode = is_scalar( $stored_mode ) ? strtolower( (string) $stored_mode ) : 'invalid';
        $mode = in_array( $mode, array( 'test', 'live' ), true ) ? $mode : 'invalid';

        $gateway_settings = get_option( 'woocommerce_nicepay_settings', array() );
        $gateway_enabled  = is_array( $gateway_settings ) && isset( $gateway_settings['enabled'] ) && 'yes' === $gateway_settings['enabled'];
        $methods          = nicepay_get_enabled_methods();
        $methods          = array_values( array_intersect( array_keys( NicePay_API::get_available_methods() ), $methods ) );
        sort( $methods, SORT_STRING );

		$https_status = is_ssl() ? 'yes' : 'no';
		$cron_status  = wp_next_scheduled( 'nicepay_expire_pending_transactions' ) ? 'scheduled' : 'missing';
        $permalink_option = get_option( 'permalink_structure', '' );
        $permalink_status = is_scalar( $permalink_option ) && '' !== (string) $permalink_option ? 'pretty' : 'plain';

        $blocks_base = class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' );
        if ( ! $blocks_base ) {
            $blocks_status = 'not_available';
        } elseif ( class_exists( 'NicePay_Blocks_Integration', false ) ) {
            $blocks_status = 'adapter_loaded';
        } elseif ( function_exists( 'did_action' ) && 0 < did_action( 'woocommerce_blocks_loaded' ) ) {
            $blocks_status = 'adapter_not_loaded';
        } else {
            $blocks_status = 'awaiting_load';
        }

        $order_util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
        if ( true === class_exists( $order_util ) && true === is_callable( array( $order_util, 'custom_orders_table_usage_is_enabled' ) ) ) {
            try {
                $hpos_status = true === call_user_func( array( $order_util, 'custom_orders_table_usage_is_enabled' ) ) ? 'enabled' : 'disabled';
            } catch ( Throwable $error ) {
                $hpos_status = 'unknown';
            }
        } else {
            $hpos_status = 'not_available';
        }

        $currency_option = get_option( 'nicepay_currency', 'KRW' );
        $currency = is_scalar( $currency_option ) && nicepay_is_supported_currency( (string) $currency_option )
            ? strtoupper( (string) $currency_option )
            : 'invalid';
        $retention = NicePay_Retention::get_settings();
        $retention_status = 'custom' === $retention['mode']
            ? (string) $retention['days'] . '_days'
            : 'indefinite';
		$retention_cron = wp_next_scheduled( NicePay_Retention::CRON_HOOK )
            ? 'scheduled'
            : ( 'custom' === $retention['mode'] ? 'missing' : 'not_required' );

        $report = array(
            'nicepay_plugin_version'    => self::sanitize_version_value( defined( 'NICEPAY_VERSION' ) ? NICEPAY_VERSION : 'unknown' ),
            'wordpress_version'         => self::sanitize_version_value( $wordpress_version ),
            'woocommerce_version'       => self::sanitize_version_value( $woocommerce_version ),
            'php_version'               => self::sanitize_version_value( PHP_VERSION ),
            'schema_required'           => self::sanitize_version_value( NicePay_Installer::schema_version() ),
            'schema_installed'          => self::sanitize_version_value( get_option( NicePay_Installer::VERSION_OPTION, 'not_installed' ) ),
            'schema_ready'              => NicePay_Installer::is_current() ? 'yes' : 'no',
            'mode'                      => $mode,
            'gateway_enabled'           => $gateway_enabled ? 'yes' : 'no',
            'standalone_forms_enabled'  => 'yes' === get_option( 'nicepay_standalone_enabled', 'no' ) ? 'yes' : 'no',
            'enabled_payment_methods'   => empty( $methods ) ? 'none' : implode( ',', $methods ),
            'currency'                  => $currency,
            'https'                     => $https_status,
            'recovery_cron'             => $cron_status,
            'financial_retention'       => $retention_status,
            'financial_retention_cron'  => $retention_cron,
            'permalinks'                => $permalink_status,
            'woocommerce_blocks'        => $blocks_status,
            'woocommerce_hpos'          => $hpos_status,
        );

        foreach ( $report as $key => $value ) {
            $report[ $key ] = self::sanitize_report_value( $value );
        }

        return $report;
    }

    /**
     * Convert an allowlisted system report to copyable plain text.
     *
     * @param array<string,string> $report Report data.
     * @return string
     */
    public static function format_system_report( $report ) {
        $lines = array( 'NicePay System Report' );
        $report = is_array( $report ) ? $report : array();
        foreach ( $report as $key => $value ) {
            if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9_]{1,50}$/', $key ) ) {
                continue;
            }
            $lines[] = $key . ': ' . self::sanitize_report_value( $value );
        }
        return implode( "\n", $lines );
    }

    /** @return string */
    private static function sanitize_report_value( $value ) {
        if ( ! is_scalar( $value ) && null !== $value ) {
            return 'unknown';
        }
        $value = sanitize_text_field( (string) $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return nicepay_utf8_byte_cut( null === $value ? 'unknown' : trim( $value ), 100 );
    }

    /** @return string */
    private static function sanitize_version_value( $value ) {
        if ( ! is_scalar( $value ) ) {
            return 'unknown';
        }
        $value = trim( (string) $value );
        return preg_match( '/^[A-Za-z0-9._+\-]{1,30}$/', $value ) ? $value : 'unknown';
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

			<?php settings_errors(); ?>

            <?php $this->render_readiness_panel(); ?>

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
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=system-report' ) ); ?>"
                   class="nav-tab <?php echo 'system-report' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'System Report', 'nicepay-payment-gateway' ); ?>
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
                    case 'system-report':
                        $this->render_system_report_tab();
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

    /**
     * Show a non-secret operational readiness summary on every settings tab.
     */
    private function render_readiness_panel() {
        $api              = new NicePay_API();
        $methods          = nicepay_get_enabled_methods();
        $gateway_settings = get_option( 'woocommerce_nicepay_settings', array() );
        $gateway_enabled  = is_array( $gateway_settings ) && isset( $gateway_settings['enabled'] ) && 'yes' === $gateway_settings['enabled'];
		$https_ready      = is_ssl();
		$cron_ready       = ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) && (bool) wp_next_scheduled( 'nicepay_expire_pending_transactions' );
		$whole_krw_ready  = ! $gateway_enabled ||
			( function_exists( 'wc_get_price_decimals' ) && 0 === (int) wc_get_price_decimals() );
        $checks = array(
            array( NicePay_Installer::is_current(), __( 'Transaction schema', 'nicepay-payment-gateway' ), __( 'Database migration must complete successfully.', 'nicepay-payment-gateway' ) ),
            array( $https_ready, __( 'HTTPS', 'nicepay-payment-gateway' ), __( 'Checkout, payment forms, AJAX and return URLs must use HTTPS.', 'nicepay-payment-gateway' ) ),
            array( '' !== (string) $api->get_mode(), __( 'Operating mode', 'nicepay-payment-gateway' ), __( 'Select a valid test or live mode.', 'nicepay-payment-gateway' ) ),
            array( '' !== (string) $api->get_mid() && '' !== (string) $api->get_merchant_key(), __( 'Active credentials', 'nicepay-payment-gateway' ), __( 'Configure both the MID and merchant key for the selected mode.', 'nicepay-payment-gateway' ) ),
            array( 'KRW' === strtoupper( (string) get_option( 'nicepay_currency', 'KRW' ) ), __( 'Currency', 'nicepay-payment-gateway' ), __( 'Only KRW is certified for new payment requests.', 'nicepay-payment-gateway' ) ),
			array( $whole_krw_ready, __( 'KRW decimals', 'nicepay-payment-gateway' ), __( 'Set the WooCommerce number of decimals to 0 before accepting NicePay payments.', 'nicepay-payment-gateway' ) ),
            array( ! empty( $methods ), __( 'Payment methods', 'nicepay-payment-gateway' ), __( 'Enable at least one certified payment method.', 'nicepay-payment-gateway' ) ),
            array( $cron_ready, __( 'Recovery cron', 'nicepay-payment-gateway' ), __( 'The pending-attempt expiry and stale-approval recovery task must be scheduled.', 'nicepay-payment-gateway' ) ),
        );
        $ready = true;
        foreach ( $checks as $check ) {
            $ready = $ready && $check[0];
        }
        ?>
        <div class="notice <?php echo $ready ? 'notice-success' : 'notice-warning'; ?> inline">
            <p><strong><?php echo esc_html( $ready ? __( 'Core readiness checks passed.', 'nicepay-payment-gateway' ) : __( 'NicePay is not ready for production payments.', 'nicepay-payment-gateway' ) ); ?></strong></p>
            <ul>
                <?php foreach ( $checks as $check ) : ?>
                    <li>
                        <span aria-hidden="true"><?php echo $check[0] ? '✓' : '✕'; ?></span>
                        <strong><?php echo esc_html( $check[1] ); ?>:</strong>
                        <?php echo esc_html( $check[0] ? __( 'Ready', 'nicepay-payment-gateway' ) : $check[2] ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ( $gateway_enabled && ! $ready ) : ?>
                <p><strong><?php esc_html_e( 'The WooCommerce gateway is enabled but will remain unavailable until these checks pass.', 'nicepay-payment-gateway' ); ?></strong></p>
            <?php endif; ?>
            <p><?php esc_html_e( 'Vendor sandbox certification, Blocks checkout and HPOS require their separate integration matrix before compatibility is declared.', 'nicepay-payment-gateway' ); ?></p>
        </div>
        <?php
    }

    private function render_general_tab() {
        $retention       = NicePay_Retention::get_settings();
        $retention_days  = 'custom' === $retention['mode'] ? (int) $retention['days'] : 2555;
        $retention_count = 'custom' === $retention['mode']
            ? NicePay_Retention::count_eligible( $retention_days )
            : null;
		$retention_next  = wp_next_scheduled( NicePay_Retention::CRON_HOOK );
        $last_run = get_option( NicePay_Retention::LAST_RUN_OPTION, array() );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'nicepay_general' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nicepay-mode"><?php esc_html_e( 'Mode', 'nicepay-payment-gateway' ); ?></label></th>
                    <td>
                        <select id="nicepay-mode" name="nicepay_mode">
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
                    <th scope="row"><label for="nicepay-language"><?php esc_html_e( 'Language', 'nicepay-payment-gateway' ); ?></label></th>
                    <td>
                        <select id="nicepay-language" name="nicepay_language">
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
                    <th scope="row"><label for="nicepay-currency"><?php esc_html_e( 'Currency', 'nicepay-payment-gateway' ); ?></label></th>
                    <td>
                        <select id="nicepay-currency" name="nicepay_currency">
                            <option value="KRW" <?php selected( get_option( 'nicepay_currency' ), 'KRW' ); ?>>KRW</option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'New payment requests are limited to KRW until another currency has a verified vendor fixture.', 'nicepay-payment-gateway' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Financial record retention', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e( 'Financial record retention', 'nicepay-payment-gateway' ); ?></legend>
                            <label>
                                <input type="radio" name="<?php echo esc_attr( NicePay_Retention::SETTINGS_OPTION ); ?>[mode]" value="indefinite" <?php checked( $retention['mode'], 'indefinite' ); ?>>
                                <?php esc_html_e( 'Retain indefinitely (recommended until your policy is approved)', 'nicepay-payment-gateway' ); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="<?php echo esc_attr( NicePay_Retention::SETTINGS_OPTION ); ?>[mode]" value="custom" <?php checked( $retention['mode'], 'custom' ); ?>>
                                <?php esc_html_e( 'Permanently delete eligible NicePay financial records after', 'nicepay-payment-gateway' ); ?>
                                <input type="number"
                                       name="<?php echo esc_attr( NicePay_Retention::SETTINGS_OPTION ); ?>[days]"
                                       value="<?php echo esc_attr( $retention_days ); ?>"
                                       min="<?php echo esc_attr( NicePay_Retention::MIN_DAYS ); ?>"
                                       max="<?php echo esc_attr( NicePay_Retention::MAX_DAYS ); ?>"
                                       step="1"
                                       class="small-text">
                                <?php esc_html_e( 'days', 'nicepay-payment-gateway' ); ?>
                            </label>

                            <div class="notice notice-warning inline">
                                <p><strong><?php esc_html_e( 'This is a permanent, legally significant deletion policy.', 'nicepay-payment-gateway' ); ?></strong></p>
                                <ul>
                                    <li><?php esc_html_e( 'NicePay cannot determine which tax, accounting, payment or privacy rules apply to your organization. Obtain legal and accounting approval for the period you enter.', 'nicepay-payment-gateway' ); ?></li>
                                    <li><?php esc_html_e( 'The daily job deletes eligible transactions and refund-attempt history only from this plugin. It does not delete WooCommerce orders, backups, logs or records held by NICEPAY.', 'nicepay-payment-gateway' ); ?></li>
                                    <li><?php esc_html_e( 'Pending, approving, reconciliation-required and unknown cancel/refund records are always protected from automatic deletion.', 'nicepay-payment-gateway' ); ?></li>
                                    <li><?php esc_html_e( 'Deleting a paid or partially refunded ledger record prevents future refunds through this plugin. Export the filtered CSV and verify a recoverable backup before enabling deletion.', 'nicepay-payment-gateway' ); ?></li>
                                </ul>
                            </div>

                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( NicePay_Retention::SETTINGS_OPTION ); ?>[acknowledged]" value="yes" <?php checked( $retention['acknowledged'], 'yes' ); ?>>
                                <strong><?php esc_html_e( 'I understand that eligible NicePay ledger records will be permanently deleted after this period.', 'nicepay-payment-gateway' ); ?></strong>
                            </label>

                            <?php if ( 'custom' === $retention['mode'] ) : ?>
                                <p class="description">
                                    <?php
                                    if ( null === $retention_count ) {
                                        esc_html_e( 'The current eligible-record count could not be calculated.', 'nicepay-payment-gateway' );
                                    } else {
                                        printf(
                                            /* translators: %s: number of records currently eligible for deletion */
                                            esc_html__( 'Records currently eligible: %s.', 'nicepay-payment-gateway' ),
                                            esc_html( number_format_i18n( $retention_count ) )
                                        );
                                    }
                                    ?>
                                    <?php if ( false !== $retention_next ) : ?>
                                        <?php
                                        printf(
                                            /* translators: %s: localized date/time of next scheduled cleanup */
                                            esc_html__( 'Next scheduled cleanup: %s.', 'nicepay-payment-gateway' ),
                                            esc_html( wp_date( 'Y-m-d H:i:s T', $retention_next ) )
                                        );
                                        ?>
                                    <?php else : ?>
                                        <?php esc_html_e( 'The cleanup schedule is not currently registered; save the settings again or check WP-Cron.', 'nicepay-payment-gateway' ); ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>

                            <?php if ( is_array( $last_run ) && ! empty( $last_run['completed_at'] ) ) : ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %1$s: last UTC run timestamp, %2$d: records deleted, %3$s: error code or none */
                                        esc_html__( 'Last cleanup (UTC): %1$s; deleted: %2$d; error: %3$s.', 'nicepay-payment-gateway' ),
                                        esc_html( $last_run['completed_at'] ),
                                        (int) ( isset( $last_run['deleted'] ) ? $last_run['deleted'] : 0 ),
                                        esc_html( ! empty( $last_run['error_code'] ) ? $last_run['error_code'] : __( 'none', 'nicepay-payment-gateway' ) )
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </fieldset>
                    </td>
                </tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugin uninstall', 'nicepay-payment-gateway' ); ?></th>
					<td>
						<input type="hidden" name="nicepay_delete_data_on_uninstall" value="no">
						<label>
							<input type="checkbox" name="nicepay_delete_data_on_uninstall" value="yes" <?php checked( get_option( 'nicepay_delete_data_on_uninstall', 'no' ), 'yes' ); ?>>
							<strong><?php esc_html_e( 'Permanently delete NicePay tables and settings when the plugin is uninstalled.', 'nicepay-payment-gateway' ); ?></strong>
						</label>
						<div class="notice notice-error inline">
							<p><?php esc_html_e( 'Leave this disabled unless you have exported the ledger and verified a recoverable backup. Uninstall deletion cannot be undone and can remove records needed for refunds, reconciliation, accounting or legal retention.', 'nicepay-payment-gateway' ); ?></p>
						</div>
					</td>
				</tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render a copyable support report without credentials, PII or host details.
     */
    private function render_system_report_tab() {
        $report      = self::get_system_report_data();
        $report_text = self::format_system_report( $report );
        ?>
        <div class="nicepay-system-report">
            <h2><?php esc_html_e( 'NicePay System Report', 'nicepay-payment-gateway' ); ?></h2>
            <p><?php esc_html_e( 'This report contains operational status only. It excludes merchant credentials, payment tokens, customer data, paths, URLs and server headers.', 'nicepay-payment-gateway' ); ?></p>
            <label class="screen-reader-text" for="nicepay-system-report"><?php esc_html_e( 'NicePay System Report', 'nicepay-payment-gateway' ); ?></label>
            <textarea id="nicepay-system-report" class="large-text code" rows="20" readonly><?php echo esc_textarea( $report_text ); ?></textarea>
            <p>
                <button type="button" class="button button-secondary nicepay-copy-btn"
                        data-copy="<?php echo esc_attr( $report_text ); ?>"
                        aria-controls="nicepay-system-report">
                    <?php esc_html_e( 'Copy system report', 'nicepay-payment-gateway' ); ?>
                </button>
            </p>
        </div>
        <?php
    }

    private function render_api_tab() {
        $mode = get_option( 'nicepay_mode', 'test' );
		$live_mid_external = defined( 'NICEPAY_LIVE_MID' );
		$live_key_external = defined( 'NICEPAY_LIVE_MERCHANT_KEY' );
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
                    <th scope="row"><label for="nicepay-test-mid"><?php esc_html_e( 'Test MID', 'nicepay-payment-gateway' ); ?></label></th>
                    <td>
                        <input type="text" id="nicepay-test-mid" name="nicepay_test_mid" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'nicepay_test_mid', NICEPAY_TEST_MID ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nicepay-test-merchant-key"><?php esc_html_e( 'Test Merchant Key', 'nicepay-payment-gateway' ); ?></label></th>
                    <td>
                        <input type="password" id="nicepay-test-merchant-key" name="nicepay_test_merchant_key" class="large-text"
                               value="" autocomplete="new-password"
                               placeholder="<?php esc_attr_e( 'Configured — leave blank to keep unchanged', 'nicepay-payment-gateway' ); ?>">
                        <label>
                            <input type="checkbox" id="nicepay-clear-test-merchant-key" name="nicepay_clear_test_merchant_key" value="yes">
                            <?php esc_html_e( 'Clear the stored test merchant key', 'nicepay-payment-gateway' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Live Credentials', 'nicepay-payment-gateway' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nicepay-live-mid"><?php esc_html_e( 'Live MID', 'nicepay-payment-gateway' ); ?></label></th>
                    <td>
						<?php if ( $live_mid_external ) : ?>
							<input type="hidden" name="nicepay_live_mid" value="">
							<input type="text" id="nicepay-live-mid" class="regular-text" value="<?php esc_attr_e( 'Managed in wp-config.php', 'nicepay-payment-gateway' ); ?>" readonly>
						<?php else : ?>
							<input type="text" id="nicepay-live-mid" name="nicepay_live_mid" class="regular-text"
								value="<?php echo esc_attr( get_option( 'nicepay_live_mid' ) ); ?>">
						<?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nicepay-live-merchant-key"><?php esc_html_e( 'Live Merchant Key', 'nicepay-payment-gateway' ); ?></label></th>
                    <td>
						<?php if ( $live_key_external ) : ?>
							<input type="hidden" name="nicepay_live_merchant_key" value="">
							<input type="text" id="nicepay-live-merchant-key" class="large-text" value="<?php esc_attr_e( 'Managed in wp-config.php; the secret is not stored in WordPress.', 'nicepay-payment-gateway' ); ?>" readonly>
						<?php else : ?>
							<input type="password" id="nicepay-live-merchant-key" name="nicepay_live_merchant_key" class="large-text"
								value="" autocomplete="new-password"
								placeholder="<?php echo get_option( 'nicepay_live_merchant_key', '' ) ? esc_attr__( 'Configured — leave blank to keep unchanged', 'nicepay-payment-gateway' ) : esc_attr__( 'Enter live merchant key', 'nicepay-payment-gateway' ); ?>">
							<label>
								<input type="checkbox" id="nicepay-clear-live-merchant-key" name="nicepay_clear_live_merchant_key" value="yes">
								<?php esc_html_e( 'Clear the stored live merchant key', 'nicepay-payment-gateway' ); ?>
							</label>
						<?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_payment_tab() {
        $enabled = nicepay_get_enabled_methods();
        $all_methods = NicePay_API::get_certified_methods();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'nicepay_payment' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Standalone payment forms', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="nicepay_standalone_enabled" value="no">
                            <input type="checkbox" name="nicepay_standalone_enabled" value="yes"
                                   <?php checked( 'yes', get_option( 'nicepay_standalone_enabled', 'no' ) ); ?>>
                            <?php esc_html_e( 'Enable saved NicePay shortcode payment forms', 'nicepay-payment-gateway' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Enable only after reviewing credentials, currency, fixed prices, and return routing.', 'nicepay-payment-gateway' ); ?>
                        </p>
                    </td>
                </tr>
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
                                    <code class="nicepay-method-code"><?php echo esc_html( $code ); ?></code>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
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
                        <?php
                        // The saved configuration is the single source of
                        // truth; reference-only shortcodes inherit later edits.
                        $full_shortcode = '[nicepay_payment id="' . esc_attr( $sc['id'] ) . '"]';
                        $preview_background = isset( $sc['button_color'] ) && '' !== $sc['button_color'] ? $sc['button_color'] : '#2563eb';
                        $preview_text       = nicepay_get_contrast_color( $preview_background );
                        ?>
                        <div class="nicepay-sc-card" data-id="<?php echo esc_attr( $sc['id'] ); ?>" id="sc-card-<?php echo esc_attr( $sc['id'] ); ?>">
                            <div class="nicepay-sc-card-header">
                                <h4 class="nicepay-sc-card-name"><?php echo esc_html( $sc['name'] ); ?></h4>
                                <span class="nicepay-sc-card-badge nicepay-sc-card-badge-<?php echo esc_attr( $sc['display_mode'] ); ?>">
                                    <?php echo esc_html( $sc['display_mode'] ); ?>
                                </span>
                            </div>
                            <div class="nicepay-sc-card-body">
                                <div class="nicepay-sc-card-preview">
                                    <button type="button" class="nicepay-pay-button" style="--nicepay-button-background:<?php echo esc_attr( $preview_background ); ?>;--nicepay-button-text:<?php echo esc_attr( $preview_text ); ?>;pointer-events:none;font-size:13px;padding:8px 20px;">
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
                                    <code class="nicepay-sc-card-code"><?php echo esc_html( $full_shortcode ); ?></code>
                                </div>
                            </div>
                            <div class="nicepay-sc-card-actions">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=shortcode-generator&edit=' . $sc['id'] ) ); ?>" class="nicepay-sc-card-btn">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    <?php esc_html_e( 'Edit', 'nicepay-payment-gateway' ); ?>
                                </a>
                                <button type="button" class="nicepay-sc-card-btn nicepay-sc-card-copy"
                                        data-shortcode="<?php echo esc_attr( $full_shortcode ); ?>">
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
        $enabled_methods = nicepay_get_enabled_methods();

        wp_enqueue_script(
            'nicepay-shortcode-admin-js',
            NICEPAY_PLUGIN_URL . 'assets/js/nicepay-shortcode-admin.js',
            array( 'jquery', 'nicepay-admin-js' ),
            NICEPAY_VERSION,
            true
        );

        // Check if editing an existing shortcode
        $edit_id   = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
        $edit_data = $edit_id ? nicepay_get_saved_shortcode( $edit_id ) : null;
        $method_options = array();
        foreach ( NicePay_API::get_available_methods() as $code => $label ) {
            if ( ! in_array( $code, $enabled_methods, true ) ) {
                continue;
            }

            $method_options[] = array(
                'label' => $label,
            );
        }

        wp_localize_script( 'nicepay-shortcode-admin-js', 'nicepayShortcodeAdmin', array(
            'editId'         => $edit_id,
            'editData'       => $edit_data,
            'enabledMethods' => $method_options,
            'redirectUrl'    => admin_url( 'admin.php?page=nicepay-settings&tab=shortcodes' ),
            'i18n'           => array(
                'referenceShortcode' => __( 'Save the configuration to generate its reference shortcode.', 'nicepay-payment-gateway' ),
                'payNow'             => __( 'Pay Now', 'nicepay-payment-gateway' ),
                'productName'        => __( 'Product Name', 'nicepay-payment-gateway' ),
                'amountRequired'     => __( 'Amount is required', 'nicepay-payment-gateway' ),
                'productNameRequired' => __( 'Product name is required', 'nicepay-payment-gateway' ),
                'copy'               => __( 'Copy', 'nicepay-payment-gateway' ),
                'copied'             => __( 'Copied!', 'nicepay-payment-gateway' ),
                'copyFailed'         => __( 'Copy failed', 'nicepay-payment-gateway' ),
                'saving'             => __( 'Saving...', 'nicepay-payment-gateway' ),
                'saveShortcode'      => __( 'Save Shortcode', 'nicepay-payment-gateway' ),
                'updateShortcode'    => __( 'Update Shortcode', 'nicepay-payment-gateway' ),
                'saved'              => __( 'Saved.', 'nicepay-payment-gateway' ),
                'error'              => __( 'Error', 'nicepay-payment-gateway' ),
                'requestFailed'      => __( 'Request failed. Please try again.', 'nicepay-payment-gateway' ),
            ),
        ) );
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
                                <label class="screen-reader-text" for="sc-currency"><?php esc_html_e( 'Currency', 'nicepay-payment-gateway' ); ?></label>
                                <select id="sc-currency" class="nicepay-sc-select-sm">
                                    <option value="KRW">KRW</option>
                                </select>
                            </div>
                        </div>
                        <div class="nicepay-sc-field">
                            <label for="sc-goods-name"><?php esc_html_e( 'Product / Service Name', 'nicepay-payment-gateway' ); ?> <span class="nicepay-sc-required">*</span></label>
                            <input type="text" id="sc-goods-name" placeholder="<?php esc_attr_e( 'e.g. Premium Plan', 'nicepay-payment-gateway' ); ?>" class="nicepay-sc-input" maxlength="40">
                        </div>
                        <div class="nicepay-sc-field">
                            <label for="sc-goods-class"><?php esc_html_e( 'Mobile Payment Goods Type', 'nicepay-payment-gateway' ); ?> <span class="nicepay-sc-required">*</span></label>
                            <select id="sc-goods-class" class="nicepay-sc-input">
                                <option value="0"><?php esc_html_e( 'Digital content or service', 'nicepay-payment-gateway' ); ?></option>
                                <option value="1"><?php esc_html_e( 'Physical goods', 'nicepay-payment-gateway' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Required by the carrier when mobile payment is selected.', 'nicepay-payment-gateway' ); ?></p>
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

        <?php
    }

    public function render_transactions_page() {
        $transactions_page = new NicePay_Transactions();
        $transactions_page->render();
    }

    /**
     * Render a privacy-safe NicePay ledger summary in WooCommerce order admin.
     *
     * WooCommerce emits this hook for both the legacy and HPOS order editors,
     * avoiding screen-ID assumptions while keeping the query scoped to one order.
     *
     * @param object $order WooCommerce order object.
     */
    public function render_order_payment_summary( $order ) {
        if ( ! nicepay_current_user_can_manage_payments() || ! is_object( $order ) ||
            ! is_callable( array( $order, 'get_id' ) ) || ! is_callable( array( $order, 'get_meta' ) ) ) {
            return;
        }

        $order_id = absint( $order->get_id() );
        $tid      = $order->get_meta( '_nicepay_tid' );
        $tid      = is_scalar( $tid ) ? (string) $tid : '';
        if ( $order_id < 1 || '' === $tid ) {
            return;
        }

        $transaction = nicepay_get_transaction_by_tid( $tid, $order_id );
        if ( ! $transaction ) {
            return;
        }

        $transaction_id = isset( $transaction->id ) && is_scalar( $transaction->id ) ? absint( $transaction->id ) : 0;
        if ( $transaction_id < 1 ) {
            return;
        }

        $currency = isset( $transaction->currency ) && is_scalar( $transaction->currency )
            ? strtoupper( (string) $transaction->currency )
            : 'KRW';
        $currency = preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'KRW';
        $captured = isset( $transaction->captured_amount ) && is_scalar( $transaction->captured_amount ) ? $transaction->captured_amount : 0;
        $refunded = isset( $transaction->refunded_amount ) && is_scalar( $transaction->refunded_amount ) ? $transaction->refunded_amount : 0;
        $remaining = isset( $transaction->remaining_amount ) && is_scalar( $transaction->remaining_amount ) ? $transaction->remaining_amount : 0;
        $status = isset( $transaction->status ) && is_scalar( $transaction->status ) ? (string) $transaction->status : '';
        $detail_fields = NicePay_Transactions::get_safe_detail_fields( $transaction );
        $detail_url = add_query_arg(
            array(
                'page'           => 'nicepay-transactions',
                'transaction_id' => $transaction_id,
            ),
            admin_url( 'admin.php' )
        );
        ?>
        <div class="nicepay-order-payment-summary">
            <h4><?php esc_html_e( 'NicePay payment', 'nicepay-payment-gateway' ); ?></h4>
            <p>
                <strong><?php esc_html_e( 'Ledger status:', 'nicepay-payment-gateway' ); ?></strong>
                <?php echo esc_html( nicepay_get_status_label( $status ) ); ?>
            </p>
            <p>
                <strong><?php esc_html_e( 'Captured:', 'nicepay-payment-gateway' ); ?></strong>
                <?php echo esc_html( nicepay_format_amount( $captured, $currency ) ); ?><br>
                <strong><?php esc_html_e( 'Refunded:', 'nicepay-payment-gateway' ); ?></strong>
                <?php echo esc_html( nicepay_format_amount( $refunded, $currency ) ); ?><br>
                <strong><?php esc_html_e( 'Remaining:', 'nicepay-payment-gateway' ); ?></strong>
                <?php echo esc_html( nicepay_format_amount( $remaining, $currency ) ); ?>
            </p>
            <?php if ( ! empty( $detail_fields ) ) : ?>
                <dl>
                    <?php foreach ( $detail_fields as $detail ) : ?>
                        <dt><strong><?php echo esc_html( $detail['label'] ); ?></strong></dt>
                        <dd><?php echo esc_html( $detail['value'] ); ?></dd>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url( $detail_url ); ?>">
                    <?php esc_html_e( 'View NicePay transaction', 'nicepay-payment-gateway' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
}

new NicePay_Admin();
