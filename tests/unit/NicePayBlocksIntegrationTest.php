<?php
/**
 * Contract tests for the WooCommerce Checkout Blocks adapter.
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
    require_once NICEPAY_PLUGIN_DIR . 'tests/fixtures/blocks-abstract-payment-method-type.php';
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook_name, $callback ) {
		unset( $hook_name, $callback );
		return true;
	}
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
    function wp_set_script_translations( $handle, $domain, $path = '' ) {
		unset( $handle, $domain, $path );
		return true;
	}
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
			public function get_return_url( $order = null ) {
				unset( $order );
				return 'https://example.com/order-received/';
			}
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
		$result = null;
		foreach ( array( 'wp_nicepay_reconciliation_audit', 'wp_nicepay_refund_attempts', 'wp_nicepay_transactions' ) as $table ) {
			if ( false !== strpos( $query, $table ) ) {
				$result = $table;
				break;
			}
		}
		return $result;
    }
}

class NicePayBlocksIntegrationTest extends TestCase {
	/** @var callable */
	private $allow_test_checkout;

	protected function setUp(): void {
        global $wp_options, $nicepay_registered_scripts, $nicepay_wc_test_environment, $wpdb;
        $wp_options = array();
        $nicepay_registered_scripts = array();
        $wpdb = new NicePayBlocksSchemaWpdbFake();

		update_option( NicePay_Installer::VERSION_OPTION, NicePay_Installer::schema_version() );
		update_option( NicePay_Installer::VERIFIED_VERSION_OPTION, NicePay_Installer::schema_version() );
		update_option( 'nicepay_mode', 'test' );
        update_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
        update_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );
		update_option( 'nicepay_enabled_methods', array( 'CARD' ) );
		$this->allow_test_checkout = static function() { return true; };
		add_filter( 'nicepay_allow_test_mode_checkout', $this->allow_test_checkout );

		$api     = new NicePay_API();
		$gateway = new WC_Gateway_NicePay( $api );
        $gateway->enabled     = 'yes';
        $gateway->title       = '<strong>NicePay</strong>';
        $gateway->description = '<em>Secure redirect</em>';
        $gateway->supports    = array( 'products', 'refunds' );
			$nicepay_wc_test_environment = new NicePayBlocksWooFake( $gateway );
	}

	protected function tearDown(): void {
		remove_filter( 'nicepay_allow_test_mode_checkout', $this->allow_test_checkout );
		parent::tearDown();
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
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
            $script['dependencies']
        );

        $source = file_get_contents( NICEPAY_PLUGIN_DIR . 'assets/js/nicepay-blocks.js' );
        $this->assertStringContainsString( 'wcBlocksRegistry.registerPaymentMethod', $source );
        $this->assertStringContainsString( "name: 'nicepay'", $source );
        $this->assertStringContainsString( 'canMakePayment', $source );
    }
}
