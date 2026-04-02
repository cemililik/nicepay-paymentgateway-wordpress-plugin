<?php
/**
 * WooCommerce NicePay Payment Form Template
 *
 * Displayed on the order receipt page after checkout.
 * Generates the NicePay payment form and triggers the payment window.
 *
 * Available variables:
 * @var WC_Order $order      WooCommerce order
 * @var array    $form_data  Form fields for NicePay
 * @var array    $enabled_methods Enabled payment methods
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div id="nicepay-payment-wrapper" class="nicepay-payment-wrapper">
    <div class="nicepay-payment-info">
        <h3><?php esc_html_e( 'Complete Your Payment', 'nicepay-payment-gateway' ); ?></h3>
        <p class="nicepay-order-summary">
            <?php
            /* translators: %s: formatted order total */
            printf( esc_html__( 'Order Total: %s', 'nicepay-payment-gateway' ), '<strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong>' );
            ?>
        </p>
    </div>

    <?php if ( count( $enabled_methods ) > 1 ) : ?>
        <div class="nicepay-method-selector">
            <label><?php esc_html_e( 'Select Payment Method', 'nicepay-payment-gateway' ); ?></label>
            <div class="nicepay-methods">
                <?php foreach ( $enabled_methods as $method ) : ?>
                    <label class="nicepay-method-option">
                        <input type="radio" name="nicepay_pay_method" value="<?php echo esc_attr( $method ); ?>"
                               <?php checked( $method, $enabled_methods[0] ); ?>>
                        <span class="nicepay-method-label">
                            <?php echo esc_html( NicePay_API::get_payment_method_name( $method ) ); ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <form id="nicepay-pay-form" name="payForm" method="post"
          action="<?php echo esc_url( WC()->api_request_url( 'nicepay_return' ) ); ?>"
          accept-charset="<?php echo esc_attr( $form_data['CharSet'] ); ?>">
        <?php foreach ( $form_data as $key => $value ) : ?>
            <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
        <?php endforeach; ?>

        <?php if ( ! empty( $enabled_methods ) ) : ?>
        <input type="hidden" name="PayMethod" id="nicepay-pay-method"
               value="<?php echo esc_attr( $enabled_methods[0] ); ?>">
        <?php endif; ?>

        <?php if ( in_array( 'CELLPHONE', $enabled_methods, true ) ) : ?>
            <input type="hidden" name="GoodsCl" value="1">
        <?php endif; ?>

        <div class="nicepay-submit-wrapper">
            <button type="button" id="nicepay-submit-btn" class="button alt nicepay-pay-button">
                <?php esc_html_e( 'Proceed to Payment', 'nicepay-payment-gateway' ); ?>
            </button>
            <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="nicepay-cancel-link">
                <?php esc_html_e( 'Cancel', 'nicepay-payment-gateway' ); ?>
            </a>
        </div>
    </form>
</div>

<script>
(function() {
    'use strict';

    // NicePay callback functions (required by nicepay-pgweb.js)
    // DOM event handling is in assets/js/nicepay.js to avoid duplicate bindings
    window.nicepaySubmit = function() {
        document.payForm.submit();
    };

    window.nicepayClose = function() {
        window.location.href = '<?php echo esc_js( wc_get_checkout_url() ); ?>';
    };
})();
</script>
