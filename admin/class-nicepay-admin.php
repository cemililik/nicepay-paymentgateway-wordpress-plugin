<?php
/**
 * NicePay Admin Settings Page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NicePay_Admin_Core {

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

	/** Preserve the public support-report API on the admin facade. */
	public static function get_system_report_data() {
		return NicePay_System_Report::get_system_report_data();
	}

	/** Preserve the public support-report formatter on the admin facade. */
	public static function format_system_report( $report ) {
		return NicePay_System_Report::format_system_report( $report );
	}
}

/** Builds the privacy-safe operational support report. */
class NicePay_System_Report {

    /**
     * Build a strict allowlist of non-secret operational diagnostics.
     *
     * @return array<string,string>
     */
    public static function get_system_report_data() {
		$wordpress_version = self::wordpress_version();
        $woocommerce_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'not_available';
		$mode = self::report_mode();
		$gateway_enabled = self::is_gateway_enabled();
        $methods          = nicepay_get_enabled_methods();
        $methods          = array_values( array_intersect( array_keys( NicePay_API::get_available_methods() ), $methods ) );
        sort( $methods, SORT_STRING );

		$https_status = is_ssl() ? 'yes' : 'no';
		$cron_status  = wp_next_scheduled( 'nicepay_expire_pending_transactions' ) ? 'scheduled' : 'missing';
        $permalink_option = get_option( 'permalink_structure', '' );
        $permalink_status = is_scalar( $permalink_option ) && '' !== (string) $permalink_option ? 'pretty' : 'plain';

		$blocks_status = self::blocks_status();
		$hpos_status   = self::hpos_status();
		$currency      = self::report_currency();
        $retention = NicePay_Retention::get_settings();
		$retention_status = self::retention_status( $retention );
		$retention_cron   = self::retention_cron_status( $retention );

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

	/** @return string */
	private static function wordpress_version() {
		global $wp_version;
		$version = isset( $wp_version ) ? $wp_version : 'unknown';
		return function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : $version;
	}

	/** @return string */
	private static function report_mode() {
		$stored = get_option( 'nicepay_mode', '' );
		$mode   = is_scalar( $stored ) ? strtolower( (string) $stored ) : 'invalid';
		return in_array( $mode, array( 'test', 'live' ), true ) ? $mode : 'invalid';
	}

	/** @return bool */
	public static function is_gateway_enabled() {
		$settings = get_option( 'woocommerce_nicepay_settings', array() );
		return is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
	}

	/** @return string */
	private static function blocks_status() {
		$status = 'not_available';
		if ( class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
			if ( class_exists( 'NicePay_Blocks_Integration', false ) ) {
				$status = 'adapter_loaded';
			} elseif ( function_exists( 'did_action' ) && 0 < did_action( 'woocommerce_blocks_loaded' ) ) {
				$status = 'adapter_not_loaded';
			} else {
				$status = 'awaiting_load';
			}
		}
		return $status;
	}

	/** @return string */
	private static function hpos_status() {
		$order_util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
		$status     = 'not_available';
		if ( class_exists( $order_util ) && is_callable( array( $order_util, 'custom_orders_table_usage_is_enabled' ) ) ) {
			try {
				$status = call_user_func( array( $order_util, 'custom_orders_table_usage_is_enabled' ) ) ? 'enabled' : 'disabled';
			} catch ( Throwable $error ) {
				$status = 'unknown';
			}
		}
		return $status;
	}

	/** @return string */
	private static function report_currency() {
		$currency = get_option( 'nicepay_currency', 'KRW' );
		return is_scalar( $currency ) && nicepay_is_supported_currency( (string) $currency )
			? strtoupper( (string) $currency )
			: 'invalid';
	}

	/** @return string */
	private static function retention_status( array $retention ) {
		return 'custom' === $retention['mode'] ? (string) $retention['days'] . '_days' : 'indefinite';
	}

	/** @return string */
	private static function retention_cron_status( array $retention ) {
		$status = 'custom' === $retention['mode'] ? 'missing' : 'not_required';
		return wp_next_scheduled( NicePay_Retention::CRON_HOOK ) ? 'scheduled' : $status;
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
}

/** Renders the tabbed NicePay settings interface. */
class NicePay_Admin_Settings_View extends NicePay_Admin_Core {
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
		$gateway_enabled = NicePay_System_Report::is_gateway_enabled();
		$checks          = $this->readiness_checks( $gateway_enabled );
		$ready           = ! in_array( false, array_column( $checks, 0 ), true );
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

	/** @return array<int,array{bool,string,string}> */
	private function readiness_checks( $gateway_enabled ) {
		$api             = new NicePay_API();
		$cron_ready      = ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) && (bool) wp_next_scheduled( 'nicepay_expire_pending_transactions' );
		$whole_krw_ready = ! $gateway_enabled ||
			( function_exists( 'wc_get_price_decimals' ) && 0 === (int) wc_get_price_decimals() );
		return array(
			array( NicePay_Installer::is_current(), __( 'Transaction schema', 'nicepay-payment-gateway' ), __( 'Database migration must complete successfully.', 'nicepay-payment-gateway' ) ),
			array( is_ssl(), __( 'HTTPS', 'nicepay-payment-gateway' ), __( 'Checkout, payment forms, AJAX and return URLs must use HTTPS.', 'nicepay-payment-gateway' ) ),
			array( '' !== (string) $api->get_mode(), __( 'Operating mode', 'nicepay-payment-gateway' ), __( 'Select a valid test or live mode.', 'nicepay-payment-gateway' ) ),
			array( '' !== (string) $api->get_mid() && '' !== (string) $api->get_merchant_key(), __( 'Active credentials', 'nicepay-payment-gateway' ), __( 'Configure both the MID and merchant key for the selected mode.', 'nicepay-payment-gateway' ) ),
			array( 'KRW' === strtoupper( (string) get_option( 'nicepay_currency', 'KRW' ) ), __( 'Currency', 'nicepay-payment-gateway' ), __( 'Only KRW is certified for new payment requests.', 'nicepay-payment-gateway' ) ),
			array( $whole_krw_ready, __( 'KRW decimals', 'nicepay-payment-gateway' ), __( 'Set the WooCommerce number of decimals to 0 before accepting NicePay payments.', 'nicepay-payment-gateway' ) ),
			array( ! empty( nicepay_get_enabled_methods() ), __( 'Payment methods', 'nicepay-payment-gateway' ), __( 'Enable at least one certified payment method.', 'nicepay-payment-gateway' ) ),
			array( $cron_ready, __( 'Recovery cron', 'nicepay-payment-gateway' ), __( 'The pending-attempt expiry and stale-approval recovery task must be scheduled.', 'nicepay-payment-gateway' ) ),
		);
	}

    private function render_general_tab() {
        $retention       = NicePay_Retention::get_settings();
        $retention_days  = 'custom' === $retention['mode'] ? (int) $retention['days'] : 2555;
        $retention_count = 'custom' === $retention['mode']
            ? NicePay_Retention::count_eligible( $retention_days )
            : null;
		$retention_next  = wp_next_scheduled( NicePay_Retention::CRON_HOOK );
        $last_run = get_option( NicePay_Retention::LAST_RUN_OPTION, array() );
        include NICEPAY_PLUGIN_DIR . 'admin/views/general-settings.php';
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
        include NICEPAY_PLUGIN_DIR . 'admin/views/shortcode-generator.php';
    }

}

/** Coordinates admin menus, transactions and WooCommerce order panels. */
class NicePay_Admin extends NicePay_Admin_Settings_View {
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
		$context = $this->order_payment_summary_context( $order );
		if ( null === $context ) {
			return;
		}
		$currency      = $context['currency'];
		$captured      = $context['captured'];
		$refunded      = $context['refunded'];
		$remaining     = $context['remaining'];
		$status        = $context['status'];
		$detail_fields = $context['detail_fields'];
		$detail_url    = $context['detail_url'];
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

	/** @return array<string,mixed>|null */
	private function order_payment_summary_context( $order ) {
		$context = null;
		$valid_order = nicepay_current_user_can_manage_payments() && is_object( $order ) &&
			is_callable( array( $order, 'get_id' ) ) && is_callable( array( $order, 'get_meta' ) );
		if ( $valid_order ) {
			$order_id = absint( $order->get_id() );
			$raw_tid  = $order->get_meta( '_nicepay_tid' );
			$tid      = is_scalar( $raw_tid ) ? (string) $raw_tid : '';
			$transaction = $order_id > 0 && '' !== $tid ? nicepay_get_transaction_by_tid( $tid, $order_id ) : null;
			$transaction_id = is_object( $transaction ) && isset( $transaction->id ) && is_scalar( $transaction->id )
				? absint( $transaction->id )
				: 0;
			if ( $transaction_id > 0 ) {
				$currency = isset( $transaction->currency ) && is_scalar( $transaction->currency )
					? strtoupper( (string) $transaction->currency )
					: 'KRW';
				$currency = preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'KRW';
				$context = array(
					'currency'      => $currency,
					'captured'      => self::transaction_scalar( $transaction, 'captured_amount', 0 ),
					'refunded'      => self::transaction_scalar( $transaction, 'refunded_amount', 0 ),
					'remaining'     => self::transaction_scalar( $transaction, 'remaining_amount', 0 ),
					'status'        => (string) self::transaction_scalar( $transaction, 'status', '' ),
					'detail_fields' => NicePay_Transactions::get_safe_detail_fields( $transaction ),
					'detail_url'    => add_query_arg(
						array( 'page' => 'nicepay-transactions', 'transaction_id' => $transaction_id ),
						admin_url( 'admin.php' )
					),
				);
			}
		}
		return $context;
	}

	/** @return mixed */
	private static function transaction_scalar( $transaction, $property, $default ) {
		return isset( $transaction->{$property} ) && is_scalar( $transaction->{$property} )
			? $transaction->{$property}
			: $default;
	}
}

new NicePay_Admin();
