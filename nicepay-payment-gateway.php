<?php
/**
 * Plugin Name: NicePay Payment Gateway
 * Plugin URI: https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin
 * Update URI: https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin
 * Description: NicePay Payment Gateway integration for WooCommerce. Payment methods remain disabled until explicitly configured and validated.
 * Version: 2.0.1
 * Author: cemililik
 * Author URI: https://github.com/cemililik
 * Text Domain: nicepay-payment-gateway
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NICEPAY_VERSION', '2.0.1' );
define( 'NICEPAY_PLUGIN_FILE', __FILE__ );
define( 'NICEPAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NICEPAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NICEPAY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// NicePay API endpoints
define( 'NICEPAY_JS_URL', 'https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js' );
define( 'NICEPAY_CANCEL_URL', 'https://pg-api.nicepay.co.kr/webapi/cancel_process.jsp' );

// Default test credentials
define( 'NICEPAY_TEST_MID', 'nicepay00m' );
define( 'NICEPAY_TEST_MERCHANT_KEY', 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==' );

/**
 * Declare only the WooCommerce feature that has passed the real storage
 * matrix. Checkout Blocks compatibility remains intentionally undeclared
 * until the browser checkout matrix passes.
 */
function nicepay_declare_woocommerce_compatibility() {
    if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            NICEPAY_PLUGIN_FILE,
            true
        );
    }
}
add_action( 'before_woocommerce_init', 'nicepay_declare_woocommerce_compatibility' );

/**
 * Main NicePay Plugin Class
 */
final class NicePay_Payment_Gateway {

    private static $instance = null;

    /** @var WP_Error|null Last schema installation error for this request. */
    private $schema_error = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-installer.php';
        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-retention.php';
        require_once NICEPAY_PLUGIN_DIR . 'includes/nicepay-functions.php';
        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-api.php';
        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-offer-resolver.php';
        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-inbound-validator.php';
        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-privacy.php';
        require_once NICEPAY_PLUGIN_DIR . 'includes/nicepay-icons.php';

        if ( is_admin() ) {
            require_once NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-admin.php';
            require_once NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-transactions.php';
        }
    }

    private function init_hooks() {
        // Activation/deactivation hooks registered at file scope (see bottom of file)

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'plugins_loaded', array( $this, 'maybe_install_schema' ), 5 );
        add_action( 'plugins_loaded', array( $this, 'init_woocommerce_gateway' ), 11 );
        add_action( 'woocommerce_blocks_loaded', array( $this, 'init_woocommerce_blocks' ) );
        add_action( 'wp_initialize_site', array( $this, 'install_new_site' ), 20, 1 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Payment return handler
        add_action( 'init', array( $this, 'register_endpoints' ) );
        add_action( 'template_redirect', array( $this, 'handle_payment_return' ) );
        add_action( 'template_redirect', array( $this, 'handle_payment_receipt' ) );
        add_action( 'nicepay_expire_pending_transactions', 'nicepay_expire_pending_transactions' );
        add_action( NicePay_Retention::CRON_HOOK, array( 'NicePay_Retention', 'run_scheduled' ) );
        add_action( 'update_option_' . NicePay_Retention::SETTINGS_OPTION, array( 'NicePay_Retention', 'settings_updated' ), 10, 2 );
        add_action( 'woocommerce_order_status_cancelled', 'nicepay_warn_cancelled_order_with_captured_funds', 10, 1 );
        add_filter( 'wp_privacy_personal_data_exporters', array( 'NicePay_Privacy', 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( 'NicePay_Privacy', 'register_eraser' ) );
		add_filter( 'wpmu_drop_tables', array( $this, 'multisite_drop_tables' ), 10, 2 );

        // Shortcodes
        add_shortcode( 'nicepay_payment', array( $this, 'render_payment_shortcode' ) );

        // AJAX handlers
        add_action( 'wp_ajax_nicepay_init_payment', array( $this, 'ajax_init_payment' ) );
        add_action( 'wp_ajax_nopriv_nicepay_init_payment', array( $this, 'ajax_init_payment' ) );
        add_action( 'wp_ajax_nicepay_refresh_nonce', array( $this, 'ajax_refresh_payment_nonce' ) );
        add_action( 'wp_ajax_nopriv_nicepay_refresh_nonce', array( $this, 'ajax_refresh_payment_nonce' ) );
        add_action( 'wp_ajax_nicepay_save_shortcode', array( $this, 'ajax_save_shortcode' ) );
        add_action( 'wp_ajax_nicepay_delete_shortcode', array( $this, 'ajax_delete_shortcode' ) );

        // Admin hooks
        if ( is_admin() ) {
            add_filter( 'plugin_action_links_' . NICEPAY_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
            add_action( 'admin_notices', array( $this, 'schema_error_notice' ) );
            add_action( 'admin_notices', array( $this, 'configuration_notice' ) );
        }
    }

    public function activate( $network_wide = false ) {
        if ( is_multisite() && $network_wide ) {
			$errors = array();
			$offset = 0;
			do {
				$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( $site_id );
					try {
						$result = $this->activate_current_site();
					} finally {
						restore_current_blog();
					}
					if ( is_wp_error( $result ) ) {
						$errors[] = sprintf( 'Site %d: %s', (int) $site_id, $result->get_error_message() );
					}
				}
				$offset += count( $site_ids );
			} while ( 100 === count( $site_ids ) );

			if ( ! empty( $errors ) ) {
				wp_die( esc_html( implode( ' | ', $errors ) ) );
			}
            return;
        }

        $result = $this->activate_current_site();
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }
    }

    /**
     * Provision one site's schema, defaults, and rewrite rules.
     */
    private function activate_current_site() {
        $result = NicePay_Installer::maybe_install();
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->set_default_options();
		// Invalidate this site's rules. WordPress regenerates them using this
		// site's own permalink structure on the next request.
        $this->register_endpoints();
        if ( ! wp_next_scheduled( 'nicepay_expire_pending_transactions' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'nicepay_expire_pending_transactions' );
        }
        NicePay_Retention::sync_schedule();
		delete_option( 'rewrite_rules' );
        return true;
    }

    public function deactivate( $network_wide = false ) {
        if ( is_multisite() && $network_wide ) {
			$offset = 0;
			do {
				$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( $site_id );
					try {
						$this->deactivate_current_site();
					} finally {
						restore_current_blog();
					}
				}
				$offset += count( $site_ids );
			} while ( 100 === count( $site_ids ) );
            return;
        }

        $this->deactivate_current_site();
    }

    private function deactivate_current_site() {
        wp_clear_scheduled_hook( 'nicepay_expire_pending_transactions' );
        wp_clear_scheduled_hook( NicePay_Retention::CRON_HOOK );
		delete_option( 'rewrite_rules' );
    }

	/**
	 * Include per-site NicePay tables in WordPress multisite site deletion.
	 *
	 * @param string[] $tables  Fully qualified table names to drop.
	 * @param int      $blog_id Site being deleted.
	 * @return string[]
	 */
	public function multisite_drop_tables( $tables, $blog_id ) {
		global $wpdb;

		$tables = is_array( $tables ) ? $tables : array();
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_blog_prefix' ) ) {
			return $tables;
		}

		$prefix   = $wpdb->get_blog_prefix( (int) $blog_id );
		$tables[] = $prefix . 'nicepay_transactions';
		$tables[] = $prefix . 'nicepay_refund_attempts';
		$tables[] = $prefix . 'nicepay_reconciliation_audit';
		return array_values( array_unique( $tables ) );
	}

    /**
     * Run repeatable schema upgrades during normal plugin loading.
     *
     * @return array|WP_Error
     */
    public function maybe_install_schema() {
        $result = NicePay_Installer::maybe_install();

        if ( is_wp_error( $result ) ) {
            $this->schema_error = $result;
            nicepay_log( 'NicePay schema upgrade blocked: ' . $result->get_error_code(), null, 'error' );
        } elseif ( ! wp_next_scheduled( 'nicepay_expire_pending_transactions' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'nicepay_expire_pending_transactions' );
        }
        if ( ! is_wp_error( $result ) ) {
            NicePay_Retention::sync_schedule();
        }

        return $result;
    }

    /**
     * Provision NicePay storage and safe defaults for newly-created sites.
     *
     * @param WP_Site $new_site Newly created site.
     */
    public function install_new_site( $new_site ) {
        if ( ! is_multisite() || ! is_object( $new_site ) || empty( $new_site->blog_id ) ) {
            return;
        }

        switch_to_blog( (int) $new_site->blog_id );
        try {
            $result = $this->activate_current_site();
        } finally {
            restore_current_blog();
        }

        if ( is_wp_error( $result ) ) {
            nicepay_log( 'NicePay could not provision a new multisite site', $result->get_error_code(), 'error' );
        }
    }

    /**
     * Explain a blocked migration without exposing raw database details.
     */
    public function schema_error_notice() {
        if ( ! is_wp_error( $this->schema_error ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html__(
            'NicePay payments are unavailable because the transaction database could not be upgraded safely. Review duplicate transaction references and the server error log.',
            'nicepay-payment-gateway'
        ) . '</p></div>';
    }

    /**
     * Show site-wide payment-mode and credential warnings to administrators.
     */
    public function configuration_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings_url = add_query_arg( 'page', 'nicepay-settings', admin_url( 'admin.php' ) );
        foreach ( nicepay_get_configuration_warnings() as $warning ) {
            $class = 'error' === $warning['type'] ? 'notice-error' : 'notice-warning';
            echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $warning['message'] ) . ' ';
            echo '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Review NicePay settings', 'nicepay-payment-gateway' ) . '</a>';
            echo '</p></div>';
        }
    }

    private function set_default_options() {
        add_option( 'nicepay_mode', 'test' );
        add_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
        add_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );
        add_option( 'nicepay_live_mid', '' );
        add_option( 'nicepay_live_merchant_key', '' );
        add_option( 'nicepay_standalone_enabled', 'no' );
        add_option( 'nicepay_enabled_methods', nicepay_default_enabled_methods() );
        add_option( 'nicepay_language', 'KO' );
        add_option( 'nicepay_currency', 'KRW' );
        add_option( 'nicepay_vbank_expiry_days', 3 );
        add_option( NicePay_Retention::SETTINGS_OPTION, NicePay_Retention::default_settings() );
        add_option( 'nicepay_db_version', NICEPAY_VERSION );
        add_option( 'nicepay_saved_shortcodes', nicepay_get_default_presets() );
		add_option( 'nicepay_delete_data_on_uninstall', 'no' );
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'nicepay-payment-gateway',
            false,
            dirname( NICEPAY_PLUGIN_BASENAME ) . '/languages/'
        );
    }

    public function init_woocommerce_gateway() {
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }

        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-gateway.php';

        add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
            $gateways[] = 'WC_Gateway_NicePay';
            return $gateways;
        } );
    }

    /**
     * Register the optional Checkout Blocks adapter when WooCommerce exposes
     * its payment-method integration contract.
     */
    public function init_woocommerce_blocks() {
        if ( ! class_exists( '\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
            return;
        }

        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-blocks-integration.php';
        add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_woocommerce_blocks_payment_method' ) );
    }

    /**
     * @param object $registry WooCommerce Blocks payment method registry.
     */
    public function register_woocommerce_blocks_payment_method( $registry ) {
        if ( is_object( $registry ) && method_exists( $registry, 'register' ) && class_exists( 'NicePay_Blocks_Integration' ) ) {
            $registry->register( new NicePay_Blocks_Integration() );
        }
    }

    public function enqueue_scripts() {
        $load = $this->is_payment_page();

        if ( ! $load && function_exists( 'is_checkout_pay_page' ) ) {
            $load = is_checkout_pay_page();
        }

        if ( $load ) {
            $this->enqueue_payment_assets();
        }
    }

    public function register_endpoints() {
        add_rewrite_rule(
            '^nicepay-return/?$',
            'index.php?nicepay_return=1',
            'top'
        );
        add_filter( 'query_vars', function ( $vars ) {
            $vars[] = 'nicepay_return';
            $vars[] = 'nicepay_receipt';
            return $vars;
        } );
    }

    public function handle_payment_return() {
        if ( ! get_query_var( 'nicepay_return' ) ) {
            return;
        }

        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-return-handler.php';
        $handler = new NicePay_Return_Handler();
        $handler->process();
        exit;
    }

    /**
     * Render a durable standalone receipt identified by a bearer token.
     */
    public function handle_payment_receipt() {
        $token = get_query_var( 'nicepay_receipt' );
        if ( ! is_string( $token ) || '' === $token ) {
            return;
        }

        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        header( 'X-Robots-Tag: noindex, nofollow', true );
        header( 'Referrer-Policy: no-referrer', true );
        header( 'X-Frame-Options: DENY', true );

        $transaction = nicepay_get_standalone_receipt( $token );
        if ( ! $transaction ) {
            wp_die( esc_html__( 'Payment receipt not found.', 'nicepay-payment-gateway' ), 'NicePay', array( 'response' => 404 ) );
            return;
        }

        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-return-handler.php';
        $handler = new NicePay_Return_Handler();
        $handler->render_saved_receipt( $transaction );
        exit;
    }

    public function render_payment_shortcode( $atts ) {
        if ( 'yes' !== get_option( 'nicepay_standalone_enabled', 'no' ) ) {
            return '<p class="nicepay-error">' . esc_html__( 'Standalone NicePay payments are not enabled.', 'nicepay-payment-gateway' ) . '</p>';
        }

        if ( ! NicePay_Installer::is_current() ) {
            return '<p class="nicepay-error">' . esc_html__( 'NicePay payments are temporarily unavailable.', 'nicepay-payment-gateway' ) . '</p>';
        }

        $this->enqueue_payment_assets();

        $raw_atts = (array) $atts;

        $config_id = isset( $raw_atts['id'] ) ? sanitize_text_field( $raw_atts['id'] ) : '';
        if ( '' === $config_id ) {
            return '<p class="nicepay-error">' . esc_html__( 'A saved payment configuration is required.', 'nicepay-payment-gateway' ) . '</p>';
        }

        $saved = nicepay_get_saved_shortcode( $config_id );
        if ( ! is_array( $saved ) ) {
            return '<p class="nicepay-error">' . esc_html__( 'Payment configuration not found.', 'nicepay-payment-gateway' ) . '</p>';
        }

		if ( ! is_ssl() ) {
            return '<p class="nicepay-error">' . esc_html__( 'NicePay payments require a secure HTTPS page.', 'nicepay-payment-gateway' ) . '</p>';
        }

        $api = new NicePay_API();
        if ( '' === (string) $api->get_mode() || '' === (string) $api->get_mid() || '' === (string) $api->get_merchant_key() ) {
			nicepay_log( 'Standalone payment form is unavailable because active credentials are incomplete.', null, 'warning' );
			return '<p class="nicepay-error">' . esc_html__( 'NicePay payments are temporarily unavailable.', 'nicepay-payment-gateway' ) . '</p>';
        }

        $enabled_methods = nicepay_get_enabled_methods();
        $readiness_method = ! empty( $saved['pay_method'] )
            ? (string) $saved['pay_method']
            : ( isset( $enabled_methods[0] ) ? (string) $enabled_methods[0] : '' );
        $offer = NicePay_Offer_Resolver::resolve_standalone( $config_id, $readiness_method );
        if ( is_wp_error( $offer ) ) {
            return '<p class="nicepay-error">' . esc_html__( 'This saved payment configuration is not ready for use.', 'nicepay-payment-gateway' ) . '</p>';
        }

        // Default values
        $defaults = array(
            'id'           => '',
            'display_mode' => 'inline',
            'amount'       => '',
            'goods_name'   => '',
            'goods_class'  => '',
            'pay_method'   => '',
            'button_text'  => __( 'Pay Now', 'nicepay-payment-gateway' ),
            'button_class' => 'nicepay-pay-button',
            'button_color' => '',
            'currency'     => get_option( 'nicepay_currency', 'KRW' ),
            'language'     => get_option( 'nicepay_language', 'KO' ),
        );

        foreach ( array_keys( $defaults ) as $key ) {
            if ( isset( $saved[ $key ] ) && '' !== $saved[ $key ] ) {
                $defaults[ $key ] = $saved[ $key ];
            }
        }

        $atts = shortcode_atts( $defaults, $raw_atts, 'nicepay_payment' );

		// Commercial fields are always sourced from the saved server-side
		// configuration. Buyer PII is deliberately collected from the customer
		// at payment time and is never embedded in public shortcode HTML.
		foreach ( array( 'amount', 'goods_name', 'goods_class', 'pay_method', 'currency' ) as $key ) {
            $atts[ $key ] = isset( $saved[ $key ] ) ? $saved[ $key ] : '';
        }
		$atts['buyer_name']  = '';
		$atts['buyer_email'] = '';
		$atts['buyer_tel']   = '';
        $atts['id'] = $config_id;

        ob_start();
        include NICEPAY_PLUGIN_DIR . 'templates/standalone-payment-form.php';
        return ob_get_clean();
    }

    /**
     * Enqueue payment assets (can be called from shortcode or enqueue_scripts hook)
     */
    public function enqueue_payment_assets() {
        if ( wp_script_is( 'nicepay-pgweb', 'enqueued' ) ) {
            return;
        }

        wp_enqueue_style(
            'nicepay-css',
            NICEPAY_PLUGIN_URL . 'assets/css/nicepay.css',
            array(),
            NICEPAY_VERSION
        );

        wp_enqueue_script(
            'nicepay-pgweb',
            NICEPAY_JS_URL,
            array(),
            null,
            true
        );

        wp_enqueue_script(
            'nicepay-js',
            NICEPAY_PLUGIN_URL . 'assets/js/nicepay.js',
            array( 'jquery', 'nicepay-pgweb' ),
            NICEPAY_VERSION,
            true
        );

        wp_localize_script( 'nicepay-js', 'nicepayParams', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'returnUrl'  => nicepay_get_standalone_return_url(),
            'initNonce'  => wp_create_nonce( 'nicepay_init_payment' ),
			'xhrTimeout' => 20000,
			'pgTimeout'  => 120000,
            'i18n'       => array(
                'processing'   => __( 'Processing payment...', 'nicepay-payment-gateway' ),
                'error'        => __( 'Payment error occurred. Please try again.', 'nicepay-payment-gateway' ),
                'selectMethod' => __( 'Please select a payment method.', 'nicepay-payment-gateway' ),
                'requiredField'=> __( 'This field is required.', 'nicepay-payment-gateway' ),
                'invalidEmail' => __( 'Please enter a valid email address.', 'nicepay-payment-gateway' ),
                'invalidPhone' => __( 'Please enter a valid phone number.', 'nicepay-payment-gateway' ),
                'fieldTooLong' => __( 'This field exceeds the payment provider limit.', 'nicepay-payment-gateway' ),
                'sessionExpired' => __( 'Payment session expired. Please refresh the page and try again.', 'nicepay-payment-gateway' ),
                'connectionError' => __( 'Connection error. Please check your internet.', 'nicepay-payment-gateway' ),
                'systemUnavailable' => __( 'Payment system is currently unavailable. Please try again later.', 'nicepay-payment-gateway' ),
                'initializationFailed' => __( 'Payment initialization failed.', 'nicepay-payment-gateway' ),
                'unexpectedError' => __( 'An unexpected error occurred.', 'nicepay-payment-gateway' ),
                'paymentInProgress' => __( 'Another payment is already in progress.', 'nicepay-payment-gateway' ),
				'paymentTimeout' => __( 'The payment window did not respond. Close it if necessary and try again.', 'nicepay-payment-gateway' ),
				'rateLimited' => __( 'Too many payment attempts were made. Please wait and try again.', 'nicepay-payment-gateway' ),
            ),
        ) );
    }

    /**
     * AJAX handler: initialize standalone payment
     * Resolves a server-side offer, creates its transaction snapshot, and
     * returns authoritative fields required to open the NicePay window.
     */
    public function ajax_init_payment() {
        if ( 'yes' !== get_option( 'nicepay_standalone_enabled', 'no' ) ) {
            wp_send_json_error( array( 'message' => __( 'Standalone NicePay payments are not enabled.', 'nicepay-payment-gateway' ) ), 403 );
            return;
        }

        if ( ! NicePay_Installer::is_current() ) {
            wp_send_json_error( array( 'message' => __( 'Payment service is temporarily unavailable.', 'nicepay-payment-gateway' ) ), 503 );
            return;
        }

		if ( ! is_ssl() ) {
            wp_send_json_error( array( 'message' => __( 'NicePay payments require HTTPS.', 'nicepay-payment-gateway' ) ), 503 );
            return;
        }

        if ( ! wp_verify_nonce(
            isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '',
            'nicepay_init_payment'
        ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'nicepay-payment-gateway' ) ), 403 );
            return;
        }

        if ( ! nicepay_check_public_rate_limit( 'standalone_init' ) ) {
            wp_send_json_error( array( 'message' => __( 'Too many payment attempts. Please wait and try again.', 'nicepay-payment-gateway' ) ), 429 );
            return;
        }

        $config_id  = isset( $_POST['config_id'] ) ? sanitize_text_field( wp_unslash( $_POST['config_id'] ) ) : '';
        $pay_method = isset( $_POST['pay_method'] ) ? sanitize_text_field( wp_unslash( $_POST['pay_method'] ) ) : '';
        $buyer_name = isset( $_POST['buyer_name'] ) ? wp_unslash( $_POST['buyer_name'] ) : '';
        $buyer_email = isset( $_POST['buyer_email'] ) ? wp_unslash( $_POST['buyer_email'] ) : '';
        $buyer_tel  = isset( $_POST['buyer_tel'] ) ? wp_unslash( $_POST['buyer_tel'] ) : '';

        $offer = NicePay_Offer_Resolver::resolve_standalone( $config_id, $pay_method );
        if ( is_wp_error( $offer ) ) {
            wp_send_json_error( array( 'message' => $offer->get_error_message() ), 400 );
            return;
        }

        $buyer = nicepay_validate_buyer_fields( $buyer_name, $buyer_email, $buyer_tel );
        if ( is_wp_error( $buyer ) ) {
            wp_send_json_error( array( 'message' => $buyer->get_error_message() ), 400 );
            return;
        }

        $api      = new NicePay_API();
        if ( ! $api->get_mid() || ! $api->get_merchant_key() || ! $api->get_mode() ) {
            wp_send_json_error( array( 'message' => __( 'NicePay credentials are not configured.', 'nicepay-payment-gateway' ) ), 503 );
            return;
        }

        $edi_date = $api->generate_edi_date();
        $moid     = $api->generate_moid( 'SP' );
        $sign_data = $api->create_auth_sign_data( $edi_date, $offer['amount'] );
		$binding_token = nicepay_generate_payment_binding_token();
		if ( false === $binding_token ) {
			wp_send_json_error( array( 'message' => __( 'Payment initialization failed.', 'nicepay-payment-gateway' ) ), 500 );
			return;
		}

        $tx_id = nicepay_save_transaction( array(
            'order_id'    => $moid,
            'moid'        => $moid,
            'flow'        => 'standalone',
            'source_ref'  => $offer['source_ref'],
            'config_fingerprint' => $offer['config_fingerprint'],
            'expected_method' => $offer['expected_method'],
            'allowed_methods' => $offer['expected_method'],
			'binding_token_hash' => hash( 'sha256', $binding_token ),
            'edi_date'    => $edi_date,
            'offer_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ),
            'mid'         => $api->get_mid(),
            'mode'        => $api->get_mode(),
            'currency'    => $offer['currency'],
            'amount'      => $offer['amount'],
            'status'      => 'pending',
            'approval_state' => 'pending',
            'buyer_name'  => $buyer['buyer_name'],
            'buyer_email' => $buyer['buyer_email'],
            'buyer_tel'   => $buyer['buyer_tel'],
            'goods_name'  => $offer['goods_name'],
        ) );

        if ( $tx_id === false ) {
            wp_send_json_error( array( 'message' => __( 'Payment initialization failed.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        wp_send_json_success( array(
            'edi_date'   => $edi_date,
            'moid'       => $moid,
            'sign_data'  => $sign_data,
			'req_reserved'=> $binding_token,
            'amount'     => $offer['amount'],
            'currency'   => $offer['currency'],
            'goods_name' => $offer['goods_name'],
            'goods_class'=> $offer['goods_class'],
            'pay_method' => $offer['expected_method'],
            'mid'        => $api->get_mid(),
            'return_url' => nicepay_get_standalone_return_url(),
            'charset'    => 'utf-8',
        ) );
    }

    /**
     * Mint a fresh public reliability nonce for cached standalone forms.
     */
    public function ajax_refresh_payment_nonce() {
		if ( 'yes' !== get_option( 'nicepay_standalone_enabled', 'no' ) || ! NicePay_Installer::is_current() || ! is_ssl() ) {
            wp_send_json_error( array( 'message' => __( 'Payment service is temporarily unavailable.', 'nicepay-payment-gateway' ) ), 503 );
            return;
        }

        if ( ! nicepay_check_public_rate_limit( 'standalone_nonce', 60, 60 ) ) {
            wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait and try again.', 'nicepay-payment-gateway' ) ), 429 );
            return;
        }

        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        wp_send_json_success( array( 'nonce' => wp_create_nonce( 'nicepay_init_payment' ) ) );
    }

    /**
     * AJAX handler: save/update a shortcode config
     */
    public function ajax_save_shortcode() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce(
            isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '',
            'nicepay_admin_shortcodes'
        ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $name = isset( $_POST['name'] ) ? nicepay_utf8_byte_cut( sanitize_text_field( wp_unslash( $_POST['name'] ) ), 100 ) : '';
        if ( empty( $name ) ) {
            wp_send_json_error( array( 'message' => __( 'Shortcode name is required.', 'nicepay-payment-gateway' ) ), 400 );
            return;
        }

        $edit_id    = isset( $_POST['edit_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edit_id'] ) ) : '';
        if ( '' !== $edit_id && ( strlen( $edit_id ) > 64 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $edit_id ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Payment configuration ID is invalid.', 'nicepay-payment-gateway' ) ), 400 );
            return;
        }

        $amount       = isset( $_POST['amount'] ) ? nicepay_normalize_amount( sanitize_text_field( wp_unslash( $_POST['amount'] ) ), 'KRW' ) : false;
        $goods_name   = isset( $_POST['goods_name'] ) ? nicepay_utf8_byte_cut( sanitize_text_field( wp_unslash( $_POST['goods_name'] ) ), 40 ) : '';
        $pay_method   = isset( $_POST['pay_method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['pay_method'] ) ) ) : '';
        $goods_class  = isset( $_POST['goods_class'] ) ? sanitize_text_field( wp_unslash( $_POST['goods_class'] ) ) : '';
        $display_mode = isset( $_POST['display_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['display_mode'] ) ) : 'inline';
        $language     = isset( $_POST['language'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['language'] ) ) ) : '';

        if ( false === $amount || '' === $goods_name || ! in_array( $goods_class, array( '0', '1' ), true ) ||
            ( '' !== $pay_method && ! array_key_exists( $pay_method, NicePay_API::get_certified_methods() ) ) ||
            ! in_array( $display_mode, array( 'inline', 'modal' ), true ) ||
			! in_array( $language, array( '', 'KO', 'EN', 'CN' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Payment configuration contains an invalid amount, product, method, display mode, or language.', 'nicepay-payment-gateway' ) ), 400 );
            return;
        }

        $raw_button_class = isset( $_POST['button_class'] ) ? (string) wp_unslash( $_POST['button_class'] ) : 'nicepay-pay-button';
        $button_classes   = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $raw_button_class ) ) ) );
        $button_class     = empty( $button_classes ) ? 'nicepay-pay-button' : implode( ' ', $button_classes );
        $button_color     = isset( $_POST['button_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['button_color'] ) ) : '#2563eb';
        $button_color     = $button_color ? $button_color : '#2563eb';
        $shortcodes       = nicepay_get_all_shortcodes();

        $entry = array(
            'name'         => $name,
            'display_mode' => $display_mode,
            'amount'       => $amount,
            'goods_name'   => $goods_name,
            'goods_class'  => $goods_class,
            'pay_method'   => $pay_method,
            'button_text'  => isset( $_POST['button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['button_text'] ) ) : 'Pay Now',
            'button_class' => $button_class,
            'button_color' => $button_color,
            'currency'     => 'KRW',
            'language'     => $language,
            'updated_at'   => time(),
        );

        if ( $edit_id ) {
            // Update existing
            $found = false;
            foreach ( $shortcodes as &$sc ) {
                if ( $sc['id'] === $edit_id ) {
                    $sc = array_merge( $sc, $entry );
                    // Once a merchant edits a built-in preset it becomes an
                    // ordinary saved configuration and must not be relocalized.
                    $sc['is_preset'] = false;
                    unset( $sc['preset_version'] );
                    $found = true;
                    break;
                }
            }
            unset( $sc );

            if ( ! $found ) {
                wp_send_json_error( array( 'message' => __( 'Shortcode not found.', 'nicepay-payment-gateway' ) ) );
                return;
            }
        } else {
            // Create new
            $slug = 'cfg_' . bin2hex( random_bytes( 8 ) );
            // A collision is already cryptographically unlikely; keep the
            // loop as a deterministic guard for imported/malformed options.
            $existing_ids = array_column( $shortcodes, 'id' );
            $base_slug    = $slug;
            $counter      = 2;
            while ( in_array( $slug, $existing_ids, true ) ) {
                $slug = $base_slug . '-' . $counter;
                $counter++;
            }
            $entry['id']         = $slug;
            $entry['is_preset']  = false;
            $entry['created_at'] = time();
            $shortcodes[]        = $entry;
        }

        update_option( 'nicepay_saved_shortcodes', nicepay_prepare_shortcodes_for_storage( $shortcodes ) );

        wp_send_json_success( array(
            'message' => $edit_id ? __( 'Shortcode updated!', 'nicepay-payment-gateway' ) : __( 'Shortcode saved!', 'nicepay-payment-gateway' ),
            'id'      => $edit_id ? $edit_id : $entry['id'],
        ) );
    }

    /**
     * AJAX handler: delete a shortcode config
     */
    public function ajax_delete_shortcode() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce(
            isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '',
            'nicepay_admin_shortcodes'
        ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
        if ( '' === $id || strlen( $id ) > 64 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $id ) ) {
            wp_send_json_error( array( 'message' => __( 'Payment configuration ID is invalid.', 'nicepay-payment-gateway' ) ), 400 );
            return;
        }

        $shortcodes = nicepay_get_all_shortcodes();
        $shortcodes = array_values( array_filter( $shortcodes, function ( $sc ) use ( $id ) {
            return $sc['id'] !== $id;
        } ) );

        update_option( 'nicepay_saved_shortcodes', nicepay_prepare_shortcodes_for_storage( $shortcodes ) );
        wp_send_json_success( array( 'message' => __( 'Shortcode deleted.', 'nicepay-payment-gateway' ) ) );
    }

    private function is_payment_page() {
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'nicepay_payment' ) ) {
            return true;
        }
        return false;
    }

    public function plugin_action_links( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=nicepay-settings' ) . '">'
                         . __( 'Settings', 'nicepay-payment-gateway' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}

// Activation/deactivation hooks must be registered at file scope
register_activation_hook( __FILE__, function ( $network_wide = false ) {
    $plugin = NicePay_Payment_Gateway::instance();
    $plugin->activate( (bool) $network_wide );
}, 10, 1 );

register_deactivation_hook( __FILE__, function ( $network_wide = false ) {
    $plugin = NicePay_Payment_Gateway::instance();
    $plugin->deactivate( (bool) $network_wide );
}, 10, 1 );

/**
 * Initialize the plugin
 */
function nicepay_init() {
    return NicePay_Payment_Gateway::instance();
}
add_action( 'plugins_loaded', 'nicepay_init', 0 );
