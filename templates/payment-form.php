<?php
/**
 * WooCommerce NicePay Payment Form Template
 *
 * @var WC_Order $order          WooCommerce order
 * @var array    $form_data      Form fields for NicePay
 * @var array    $enabled_methods Enabled payment methods
 * @var bool     $is_test_mode    Whether the configured API is in test mode
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div id="nicepay-payment-wrapper" class="nicepay-payment-wrapper" data-nicepay-checkout-url="<?php echo esc_url( wc_get_checkout_url() ); ?>">
    <?php if ( $is_test_mode ) : ?>
        <output class="nicepay-notice" aria-live="polite">
            <?php esc_html_e( 'Test mode — no real payment will be collected.', 'nicepay-payment-gateway' ); ?>
        </output>
    <?php endif; ?>

    <div class="nicepay-loading-overlay" id="nicepay-loading" role="alert" aria-live="assertive">
        <div class="nicepay-spinner"></div>
        <span class="nicepay-loading-text"><?php esc_html_e( 'Processing payment...', 'nicepay-payment-gateway' ); ?></span>
    </div>

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
            <label id="nicepay-method-group-label"><?php esc_html_e( 'Select Payment Method', 'nicepay-payment-gateway' ); ?></label>
            <div class="nicepay-methods" role="radiogroup" aria-labelledby="nicepay-method-group-label">
                <?php foreach ( $enabled_methods as $method ) : ?>
                    <label class="nicepay-method-option <?php echo ( $method === $enabled_methods[0] ) ? 'is-selected' : ''; ?>">
                        <input type="radio" name="nicepay_pay_method" value="<?php echo esc_attr( $method ); ?>"
                               <?php checked( $method, $enabled_methods[0] ); ?>>
                        <?php echo nicepay_get_method_icon( $method ); ?>
                        <span class="nicepay-method-label">
                            <?php echo esc_html( NicePay_API::get_payment_method_name( $method ) ); ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <form id="nicepay-pay-form" name="payForm" method="post"
          action="<?php echo esc_url( WC()->api_request_url( 'nicepay_return' ) ); ?>">
        <?php foreach ( $form_data as $key => $value ) : ?>
            <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
        <?php endforeach; ?>

        <?php if ( ! empty( $enabled_methods ) ) : ?>
        <input type="hidden" name="PayMethod" id="nicepay-pay-method"
               value="<?php echo esc_attr( $enabled_methods[0] ); ?>">
        <?php endif; ?>

        <div class="nicepay-submit-wrapper">
            <button type="button" id="nicepay-submit-btn" class="nicepay-pay-button"
                    aria-label="<?php esc_attr_e( 'Proceed to Payment', 'nicepay-payment-gateway' ); ?>">
                <?php esc_html_e( 'Proceed to Payment', 'nicepay-payment-gateway' ); ?>
            </button>
            <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="nicepay-cancel-link">
                <?php esc_html_e( 'Cancel', 'nicepay-payment-gateway' ); ?>
            </a>
        </div>
    </form>
</div>
