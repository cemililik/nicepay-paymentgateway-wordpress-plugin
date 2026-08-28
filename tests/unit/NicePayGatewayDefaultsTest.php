<?php
/**
 * Fresh-install defaults for the WooCommerce gateway adapter.
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook_name, $callback ) {
		unset( $hook_name, $callback );
        return true;
    }
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        public $id;
        public $method_title;
        public $method_description;
        public $has_fields;
        public $supports;
        public $form_fields = array();
        public $settings = array();
        public $title;
        public $description;
        public $enabled;

        public function init_settings() {
            $this->settings = array();
        }

        public function get_option( $key, $empty_value = null ) {
            if ( array_key_exists( $key, $this->settings ) ) {
                return $this->settings[ $key ];
            }

            if ( isset( $this->form_fields[ $key ] ) && array_key_exists( 'default', $this->form_fields[ $key ] ) ) {
                return $this->form_fields[ $key ]['default'];
            }

            return $empty_value;
        }

        public function process_admin_options() {
            return true;
        }
    }
}

require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-gateway.php';

class NicePayGatewayDefaultsTest extends TestCase {

    protected function setUp(): void {
		parent::setUp();
		$this->reset_options();
    }

    protected function tearDown(): void {
		$this->reset_options();
		parent::tearDown();
	}

	private function reset_options(): void {
        global $wp_options;
        $wp_options = array();
    }

    public function test_fresh_gateway_is_disabled_until_merchant_enables_it(): void {
        $gateway = new WC_Gateway_NicePay();

        $this->assertSame( 'no', $gateway->form_fields['enabled']['default'] );
        $this->assertSame( 'no', $gateway->enabled );
        $this->assertFalse( $gateway->is_available() );
    }
}
