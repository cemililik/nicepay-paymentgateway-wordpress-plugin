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
        require_once NICEPAY_PLUGIN_DIR . 'includes/nicepay-icons.php';

        if ( is_admin() ) {
            require_once NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-admin.php';
            require_once NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-transactions.php';
        }
    }

    private function init_hooks() {
        // Activation/deactivation hooks registered at file scope (see bottom of file)

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'plugins_loaded', array( $this, 'init_woocommerce_gateway' ), 11 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Payment return handler
        add_action( 'init', array( $this, 'register_endpoints' ) );
        add_action( 'template_redirect', array( $this, 'handle_payment_return' ) );

        // Shortcodes
        add_shortcode( 'nicepay_payment', array( $this, 'render_payment_shortcode' ) );

        // AJAX handlers
        add_action( 'wp_ajax_nicepay_init_payment', array( $this, 'ajax_init_payment' ) );
        add_action( 'wp_ajax_nopriv_nicepay_init_payment', array( $this, 'ajax_init_payment' ) );
        add_action( 'wp_ajax_nicepay_save_shortcode', array( $this, 'ajax_save_shortcode' ) );
        add_action( 'wp_ajax_nicepay_delete_shortcode', array( $this, 'ajax_delete_shortcode' ) );

        // Admin hooks
        if ( is_admin() ) {
            add_filter( 'plugin_action_links_' . NICEPAY_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
        }
    }

    public function activate() {
        $this->create_tables();
        $this->set_default_options();
        // Register endpoints before flushing so the rewrite rules are persisted
        $this->register_endpoints();
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
        add_option( 'nicepay_saved_shortcodes', nicepay_get_default_presets() );
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
        $load = $this->is_payment_page();

        if ( ! $load && function_exists( 'is_checkout' ) ) {
            $load = is_checkout();
        }

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
        $this->enqueue_payment_assets();

        $raw_atts = (array) $atts;

        // Default values
        $defaults = array(
            'id'           => '',
            'display_mode' => 'inline',
            'amount'       => '',
            'goods_name'   => '',
            'buyer_name'   => '',
            'buyer_email'  => '',
            'buyer_tel'    => '',
            'pay_method'   => '',
            'button_text'  => __( 'Pay Now', 'nicepay-payment-gateway' ),
            'button_class' => 'nicepay-pay-button',
            'button_color' => '',
            'currency'     => get_option( 'nicepay_currency', 'KRW' ),
            'language'     => get_option( 'nicepay_language', 'KO' ),
        );

        // If ID specified, load saved config as base defaults
        if ( ! empty( $raw_atts['id'] ) ) {
            $saved = nicepay_get_saved_shortcode( sanitize_text_field( $raw_atts['id'] ) );
            if ( $saved ) {
                foreach ( $defaults as $key => $default_val ) {
                    if ( isset( $saved[ $key ] ) && $saved[ $key ] !== '' ) {
                        $defaults[ $key ] = $saved[ $key ];
                    }
                }
            }
        }

        $atts = shortcode_atts( $defaults, $raw_atts, 'nicepay_payment' );

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
            'returnUrl'  => home_url( '/nicepay-return/' ),
            'nonce'      => wp_create_nonce( 'nicepay_payment' ),
            'i18n'       => array(
                'processing'   => __( 'Processing payment...', 'nicepay-payment-gateway' ),
                'error'        => __( 'Payment error occurred. Please try again.', 'nicepay-payment-gateway' ),
                'selectMethod' => __( 'Please select a payment method.', 'nicepay-payment-gateway' ),
            ),
        ) );
    }

    /**
     * AJAX handler: initialize standalone payment
     * Creates transaction record and returns EdiDate, Moid, SignData
     */
    public function ajax_init_payment() {
        if ( ! wp_verify_nonce(
            isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '',
            'nicepay_init_payment'
        ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $amount     = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
        $goods_name = isset( $_POST['goods_name'] ) ? sanitize_text_field( wp_unslash( $_POST['goods_name'] ) ) : '';
        $buyer_name = isset( $_POST['buyer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['buyer_name'] ) ) : '';
        $buyer_email = isset( $_POST['buyer_email'] ) ? sanitize_email( wp_unslash( $_POST['buyer_email'] ) ) : '';
        $buyer_tel  = isset( $_POST['buyer_tel'] ) ? sanitize_text_field( wp_unslash( $_POST['buyer_tel'] ) ) : '';

        if ( empty( $amount ) || (float) $amount <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid payment amount.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $api      = new NicePay_API();
        $edi_date = $api->generate_edi_date();
        $moid     = $api->generate_moid( 'SP' );
        $sign_data = $api->create_auth_sign_data( $edi_date, $amount );

        $tx_id = nicepay_save_transaction( array(
            'order_id'    => $moid,
            'moid'        => $moid,
            'amount'      => $amount,
            'status'      => 'pending',
            'buyer_name'  => $buyer_name,
            'buyer_email' => $buyer_email,
            'buyer_tel'   => $buyer_tel,
            'goods_name'  => mb_strcut( $goods_name, 0, 40, 'UTF-8' ),
        ) );

        if ( $tx_id === false ) {
            wp_send_json_error( array( 'message' => __( 'Payment initialization failed.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        wp_send_json_success( array(
            'edi_date'  => $edi_date,
            'moid'      => $moid,
            'sign_data' => $sign_data,
        ) );
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

        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        if ( empty( $name ) ) {
            wp_send_json_error( array( 'message' => __( 'Shortcode name is required.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $edit_id    = isset( $_POST['edit_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edit_id'] ) ) : '';
        $shortcodes = get_option( 'nicepay_saved_shortcodes', array() );

        $entry = array(
            'name'         => $name,
            'display_mode' => isset( $_POST['display_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['display_mode'] ) ) : 'inline',
            'amount'       => isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '',
            'goods_name'   => isset( $_POST['goods_name'] ) ? sanitize_text_field( wp_unslash( $_POST['goods_name'] ) ) : '',
            'pay_method'   => isset( $_POST['pay_method'] ) ? sanitize_text_field( wp_unslash( $_POST['pay_method'] ) ) : '',
            'buyer_name'   => isset( $_POST['buyer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['buyer_name'] ) ) : '',
            'buyer_email'  => isset( $_POST['buyer_email'] ) ? sanitize_email( wp_unslash( $_POST['buyer_email'] ) ) : '',
            'buyer_tel'    => isset( $_POST['buyer_tel'] ) ? sanitize_text_field( wp_unslash( $_POST['buyer_tel'] ) ) : '',
            'button_text'  => isset( $_POST['button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['button_text'] ) ) : 'Pay Now',
            'button_class' => isset( $_POST['button_class'] ) ? sanitize_text_field( wp_unslash( $_POST['button_class'] ) ) : 'nicepay-pay-button',
            'button_color' => isset( $_POST['button_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['button_color'] ) ) : '#2563eb',
            'currency'     => isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'KRW',
            'language'     => isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '',
            'updated_at'   => time(),
        );

        if ( $edit_id ) {
            // Update existing
            $found = false;
            foreach ( $shortcodes as &$sc ) {
                if ( $sc['id'] === $edit_id ) {
                    $sc = array_merge( $sc, $entry );
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
            $slug = sanitize_title( $name );
            // Ensure unique slug
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

        update_option( 'nicepay_saved_shortcodes', $shortcodes );

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

        $id         = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
        $shortcodes = get_option( 'nicepay_saved_shortcodes', array() );
        $shortcodes = array_values( array_filter( $shortcodes, function ( $sc ) use ( $id ) {
            return $sc['id'] !== $id;
        } ) );

        update_option( 'nicepay_saved_shortcodes', $shortcodes );
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
register_activation_hook( __FILE__, function () {
    $plugin = NicePay_Payment_Gateway::instance();
    $plugin->activate();
} );

register_deactivation_hook( __FILE__, function () {
    $plugin = NicePay_Payment_Gateway::instance();
    $plugin->deactivate();
} );

/**
 * Initialize the plugin
 */
function nicepay_init() {
    return NicePay_Payment_Gateway::instance();
}
add_action( 'plugins_loaded', 'nicepay_init', 0 );
