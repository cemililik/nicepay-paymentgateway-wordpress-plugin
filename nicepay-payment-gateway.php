<?php
/**
 * Plugin Name: NicePay Payment Gateway
 * Plugin URI: https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin
 * Description: NicePay Payment Gateway integration for WooCommerce. Supports credit card, bank transfer, virtual account, and mobile payments.
 * Version: 2.0.0
 * Author: cemililik
 * Author URI: https://github.com/cemililik
 * Text Domain: nicepay-payment-gateway
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License: MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NICEPAY_VERSION', '2.0.0' );
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
 * Main NicePay Plugin Class
 */
final class NicePay_Payment_Gateway {

    private static $instance = null;

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
        require_once NICEPAY_PLUGIN_DIR . 'includes/nicepay-functions.php';
        require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-api.php';

        if ( is_admin() ) {
            require_once NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-admin.php';
            require_once NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-transactions.php';
        }
    }

    private function init_hooks() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'plugins_loaded', array( $this, 'init_woocommerce_gateway' ), 11 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Payment return handler
        add_action( 'init', array( $this, 'register_endpoints' ) );
        add_action( 'template_redirect', array( $this, 'handle_payment_return' ) );

        // Shortcodes
        add_shortcode( 'nicepay_payment', array( $this, 'render_payment_shortcode' ) );

        // Admin hooks
        if ( is_admin() ) {
            add_filter( 'plugin_action_links_' . NICEPAY_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
        }
    }

    public function activate() {
        $this->create_tables();
        $this->set_default_options();
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'nicepay_transactions';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tid varchar(50) NOT NULL DEFAULT '',
            order_id varchar(100) NOT NULL DEFAULT '',
            wc_order_id bigint(20) UNSIGNED DEFAULT NULL,
            moid varchar(64) NOT NULL DEFAULT '',
            amount decimal(12,2) NOT NULL DEFAULT 0,
            payment_method varchar(20) NOT NULL DEFAULT '',
            pay_method_name varchar(50) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            result_code varchar(10) NOT NULL DEFAULT '',
            result_msg text NOT NULL,
            auth_token varchar(50) NOT NULL DEFAULT '',
            buyer_name varchar(100) NOT NULL DEFAULT '',
            buyer_email varchar(100) NOT NULL DEFAULT '',
            buyer_tel varchar(30) NOT NULL DEFAULT '',
            goods_name varchar(100) NOT NULL DEFAULT '',
            card_code varchar(5) NOT NULL DEFAULT '',
            card_name varchar(50) NOT NULL DEFAULT '',
            card_no varchar(30) NOT NULL DEFAULT '',
            card_quota varchar(5) NOT NULL DEFAULT '',
            bank_code varchar(5) NOT NULL DEFAULT '',
            bank_name varchar(50) NOT NULL DEFAULT '',
            vbank_num varchar(30) NOT NULL DEFAULT '',
            vbank_exp_date varchar(20) NOT NULL DEFAULT '',
            payment_data longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_tid (tid),
            KEY idx_order_id (order_id),
            KEY idx_wc_order_id (wc_order_id),
            KEY idx_moid (moid),
            KEY idx_status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private function set_default_options() {
        add_option( 'nicepay_mode', 'test' );
        add_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
        add_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );
        add_option( 'nicepay_live_mid', '' );
        add_option( 'nicepay_live_merchant_key', '' );
        add_option( 'nicepay_enabled_methods', array( 'CARD', 'BANK', 'VBANK', 'CELLPHONE' ) );
        add_option( 'nicepay_language', 'KO' );
        add_option( 'nicepay_currency', 'KRW' );
        add_option( 'nicepay_vbank_expiry_days', 3 );
        add_option( 'nicepay_charset', 'utf-8' );
        add_option( 'nicepay_db_version', NICEPAY_VERSION );
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

    public function enqueue_scripts() {
        $load_scripts = is_checkout() || $this->is_payment_page();

        // Also load on checkout pay page (receipt page)
        if ( ! $load_scripts && function_exists( 'is_checkout_pay_page' ) ) {
            $load_scripts = is_checkout_pay_page();
        }

        if ( ! $load_scripts ) {
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
            'returnUrl'  => home_url( '/nicepay-return/' ),
            'nonce'      => wp_create_nonce( 'nicepay_payment' ),
            'i18n'       => array(
                'processing' => __( 'Processing payment...', 'nicepay-payment-gateway' ),
                'error'      => __( 'Payment error occurred. Please try again.', 'nicepay-payment-gateway' ),
                'selectMethod' => __( 'Please select a payment method.', 'nicepay-payment-gateway' ),
            ),
        ) );
    }

    public function register_endpoints() {
        add_rewrite_rule(
            '^nicepay-return/?$',
            'index.php?nicepay_return=1',
            'top'
        );
        add_filter( 'query_vars', function ( $vars ) {
            $vars[] = 'nicepay_return';
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

    public function render_payment_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'amount'       => '',
            'goods_name'   => '',
            'buyer_name'   => '',
            'buyer_email'  => '',
            'buyer_tel'    => '',
            'pay_method'   => '',
            'button_text'  => __( 'Pay Now', 'nicepay-payment-gateway' ),
            'button_class' => 'nicepay-pay-button',
            'currency'     => get_option( 'nicepay_currency', 'KRW' ),
            'language'     => get_option( 'nicepay_language', 'KO' ),
        ), $atts, 'nicepay_payment' );

        ob_start();
        include NICEPAY_PLUGIN_DIR . 'templates/standalone-payment-form.php';
        return ob_get_clean();
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

/**
 * Initialize the plugin
 */
function nicepay_init() {
    return NicePay_Payment_Gateway::instance();
}
add_action( 'plugins_loaded', 'nicepay_init', 0 );
