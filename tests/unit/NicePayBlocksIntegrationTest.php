<?php
/**
 * Contract tests for the WooCommerce Checkout Blocks adapter.
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
    eval( 'namespace Automattic\\WooCommerce\\Blocks\\Payments\\Integrations; abstract class AbstractPaymentMethodType { protected $name = ""; abstract public function initialize(); abstract public function is_active(); abstract public function get_payment_method_script_handles(); abstract public function get_payment_method_data(); }' );
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook_name, $callback ) { return true; }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'https://example.com/wp-admin/' . ltrim( $path, '/' ); }
}

global $nicepay_registered_scripts, $nicepay_wc_test_environment;
$nicepay_registered_scripts = array();

if ( ! function_exists( 'wp_register_script' ) ) {
    function wp_register_script( $handle, $src, $dependencies, $version, $in_footer ) {
        global $nicepay_registered_scripts;
        $nicepay_registered_scripts[ $handle ] = compact( 'src', 'dependencies', 'version', 'in_footer' );
        return true;
    }
}

if ( ! function_exists( 'wp_set_script_translations' ) ) {
    function wp_set_script_translations( $handle, $domain, $path = '' ) { return true; }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
}

if ( ! function_exists( 'WC' ) ) {
    function WC() {
        global $nicepay_wc_test_environment;
        return $nicepay_wc_test_environment;
    }
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        public $id;
        public $method_title;
        public $method_description;
        public $has_fields;
        public $supports = array();
        public $form_fields = array();
        public $settings = array();
        public $title;
        public $description;
        public $enabled;
        public function init_settings() { $this->settings = array(); }
        public function get_option( $key, $default = null ) {
            return isset( $this->form_fields[ $key ]['default'] ) ? $this->form_fields[ $key ]['default'] : $default;
        }
        public function process_admin_options() { return true; }
    }
}

require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-installer.php';
require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-gateway.php';
require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-blocks-integration.php';

class NicePayBlocksPaymentGatewaysFake {
    private $gateways;
    public function __construct( $gateway ) { $this->gateways = array( 'nicepay' => $gateway ); }
    public function payment_gateways() { return $this->gateways; }
}

class NicePayBlocksWooFake {
    private $payment_gateways;
    public function __construct( $gateway ) { $this->payment_gateways = new NicePayBlocksPaymentGatewaysFake( $gateway ); }
    public function payment_gateways() { return $this->payment_gateways; }
}

class NicePayBlocksSchemaWpdbFake {
    public $prefix = 'wp_';

    public function prepare( $query, $value ) {
        return str_replace( '%s', "'" . addslashes( $value ) . "'", $query );
    }

    public function get_var( $query ) {
        if ( false !== strpos( $query, 'wp_nicepay_refund_attempts' ) ) {
            return 'wp_nicepay_refund_attempts';
        }
        if ( false !== strpos( $query, 'wp_nicepay_transactions' ) ) {
            return 'wp_nicepay_transactions';
        }
        return null;
    }
}

class NicePayBlocksIntegrationTest extends TestCase {

    protected function setUp(): void {
        global $wp_options, $nicepay_registered_scripts, $nicepay_wc_test_environment, $wpdb;
        $wp_options = array();
        $nicepay_registered_scripts = array();
        $wpdb = new NicePayBlocksSchemaWpdbFake();

        update_option( NicePay_Installer::VERSION_OPTION, NicePay_Installer::schema_version() );
        update_option( 'nicepay_mode', 'test' );
        update_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
        update_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );
        update_option( 'nicepay_enabled_methods', array( 'CARD' ) );

        $gateway = ( new ReflectionClass( WC_Gateway_NicePay::class ) )->newInstanceWithoutConstructor();
        $gateway->enabled     = 'yes';
        $gateway->title       = '<strong>NicePay</strong>';
        $gateway->description = '<em>Secure redirect</em>';
        $gateway->supports    = array( 'products', 'refunds' );
        $api = new NicePay_API();
        $api_property = new ReflectionProperty( WC_Gateway_NicePay::class, 'api' );
        $api_property->setAccessible( true );
        $api_property->setValue( $gateway, $api );

        $nicepay_wc_test_environment = new NicePayBlocksWooFake( $gateway );
    }

    public function test_blocks_adapter_exposes_only_an_available_product_gateway(): void {
        $integration = new NicePay_Blocks_Integration();
        $integration->initialize();

        $this->assertTrue( $integration->is_active() );
        $data = $integration->get_payment_method_data();
        $this->assertSame( 'NicePay', $data['title'] );
        $this->assertSame( 'Secure redirect', $data['description'] );
        $this->assertSame( array( 'products' ), $data['supports'] );
        $this->assertTrue( $data['is_available'] );
        $this->assertTrue( $data['is_test_mode'] );
    }

    public function test_blocks_script_uses_the_official_registry_and_declared_dependencies(): void {
        global $nicepay_registered_scripts;
        $integration = new NicePay_Blocks_Integration();
        $handles     = $integration->get_payment_method_script_handles();

        $this->assertSame( array( 'nicepay-checkout-blocks' ), $handles );
        $script = $nicepay_registered_scripts['nicepay-checkout-blocks'];
        $this->assertSame(
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
            $script['dependencies']
        );

        $source = file_get_contents( NICEPAY_PLUGIN_DIR . 'assets/js/nicepay-blocks.js' );
        $this->assertStringContainsString( 'wcBlocksRegistry.registerPaymentMethod', $source );
        $this->assertStringContainsString( "name: 'nicepay'", $source );
        $this->assertStringContainsString( 'canMakePayment', $source );
    }
}
