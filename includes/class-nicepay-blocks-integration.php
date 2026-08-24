<?php
/**
 * WooCommerce Cart and Checkout Blocks adapter.
 *
 * The Store API continues to call WC_Gateway_NicePay::process_payment(); this
 * class only registers the block-facing label, content, assets, and availability.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class NicePay_Blocks_Integration extends AbstractPaymentMethodType {

    /** @var string */
    protected $name = 'nicepay';

    /** @var WC_Gateway_NicePay|null */
    private $gateway;

    public function initialize() {
        $this->gateway = null;
        if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if ( isset( $gateways['nicepay'] ) && $gateways['nicepay'] instanceof WC_Gateway_NicePay ) {
                $this->gateway = $gateways['nicepay'];
            }
        }
    }

    public function is_active() {
        return $this->gateway instanceof WC_Gateway_NicePay && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        $handle = 'nicepay-checkout-blocks';
        wp_register_script(
            $handle,
            NICEPAY_PLUGIN_URL . 'assets/js/nicepay-blocks.js',
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
            NICEPAY_VERSION,
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( $handle, 'nicepay-payment-gateway', NICEPAY_PLUGIN_DIR . 'languages' );
        }

        return array( $handle );
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data() {
        $title = $this->gateway instanceof WC_Gateway_NicePay
            ? $this->gateway->title
            : __( 'NicePay Payment', 'nicepay-payment-gateway' );
        $description = $this->gateway instanceof WC_Gateway_NicePay
            ? $this->gateway->description
            : __( 'Pay securely via NicePay.', 'nicepay-payment-gateway' );

        return array(
            'title'          => wp_strip_all_tags( (string) $title ),
            'description'    => wp_strip_all_tags( (string) $description ),
            'supports'       => array( 'products' ),
            'is_available'   => $this->is_active(),
            'is_test_mode'   => 'test' === get_option( 'nicepay_mode', 'test' ),
            'test_mode_label'=> __( 'Test mode — no real payment will be collected.', 'nicepay-payment-gateway' ),
            'place_order_label' => __( 'Continue to NicePay', 'nicepay-payment-gateway' ),
        );
    }
}
