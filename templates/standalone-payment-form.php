<?php
/**
 * Standalone NicePay Payment Form Template
 *
 * Used by the [nicepay_payment] shortcode for non-WooCommerce payments.
 * Buyer information is always collected interactively so reusable public
 * forms never embed a previous buyer's PII.
 *
 * @var array $atts Shortcode attributes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$config_id    = sanitize_text_field( $atts['id'] );
$currency     = strtoupper( sanitize_text_field( $atts['currency'] ) );
$language     = NicePay_API::get_nicepay_lang( $atts['language'] );
$display_mode = isset( $atts['display_mode'] ) ? sanitize_text_field( $atts['display_mode'] ) : 'inline';
$display_mode = in_array( $display_mode, array( 'inline', 'modal' ), true ) ? $display_mode : 'inline';
$is_modal     = ( $display_mode === 'modal' );
$button_color = isset( $atts['button_color'] ) ? sanitize_hex_color( $atts['button_color'] ) : '';
$button_classes = isset( $atts['button_class'] )
    ? preg_split( '/\s+/', trim( (string) $atts['button_class'] ) )
    : array();
$button_classes = array_filter( array_map( 'sanitize_html_class', (array) $button_classes ) );
array_unshift( $button_classes, 'nicepay-pay-button' );
$button_class = implode( ' ', array_unique( $button_classes ) );
$button_style = '';
if ( $button_color ) {
    $button_style = sprintf(
        '--nicepay-button-background:%1$s;--nicepay-button-text:%2$s;',
        $button_color,
        nicepay_get_contrast_color( $button_color )
    );
}

// Display the saved amount. The AJAX initializer resolves and signs it again
// from this configuration ID immediately before opening NicePay.
$amount = nicepay_get_amount( $atts['amount'], $currency );

if ( empty( $amount ) || (float) $amount <= 0 ) {
    echo '<div class="nicepay-notice nicepay-notice-error" role="alert"><span>' . esc_html__( 'Invalid payment amount.', 'nicepay-payment-gateway' ) . '</span></div>';
    return;
}

$enabled_methods = nicepay_get_enabled_methods();
if ( empty( $enabled_methods ) ) {
    echo '<div class="nicepay-notice nicepay-notice-error" role="alert"><span>' . esc_html__( 'No certified payment method is currently available.', 'nicepay-payment-gateway' ) . '</span></div>';
    return;
}

$pay_method      = sanitize_text_field( $atts['pay_method'] );
if ( $pay_method && ! in_array( $pay_method, $enabled_methods, true ) ) {
    echo '<div class="nicepay-notice nicepay-notice-error" role="alert"><span>' . esc_html__( 'The saved payment method is not currently available.', 'nicepay-payment-gateway' ) . '</span></div>';
    return;
}

$show_method_selector = empty( $pay_method ) && count( $enabled_methods ) > 1;
$default_method       = $pay_method ? $pay_method : $enabled_methods[0];

static $nicepay_form_sequence = 0;
$nicepay_form_sequence++;
$form_id = 'nicepay-standalone-' . $nicepay_form_sequence;

// Determine which buyer fields need user input
$preset_name  = sanitize_text_field( $atts['buyer_name'] );
$preset_email = sanitize_email( $atts['buyer_email'] );
$preset_tel   = sanitize_text_field( $atts['buyer_tel'] );
$goods_name   = nicepay_utf8_byte_cut( sanitize_text_field( $atts['goods_name'] ), 40 );
$show_buyer_fields = empty( $preset_name ) || empty( $preset_email ) || empty( $preset_tel );
?>

<?php if ( $is_modal ) : ?>
<!-- Modal Trigger Button -->
<div class="nicepay-standalone-wrapper" id="<?php echo esc_attr( $form_id . '-trigger' ); ?>">
    <button type="button" class="<?php echo esc_attr( $button_class ); ?>"
            <?php if ( $button_style ) : ?>style="<?php echo esc_attr( $button_style ); ?>"<?php endif; ?>
            data-nicepay-open-modal="<?php echo esc_attr( $form_id ); ?>">
        <?php echo esc_html( $atts['button_text'] ); ?>
    </button>
</div>

<!-- Modal Overlay -->
<div class="nicepay-payment-modal-overlay" id="<?php echo esc_attr( $form_id . '-modal' ); ?>" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $form_id . '-title' ); ?>">
    <div class="nicepay-payment-modal" tabindex="-1">
        <button type="button" class="nicepay-payment-modal-close" data-nicepay-close-modal="<?php echo esc_attr( $form_id ); ?>" aria-label="<?php esc_attr_e( 'Close', 'nicepay-payment-gateway' ); ?>">&times;</button>
        <div class="nicepay-payment-modal-body">
<?php endif; ?>

<div class="<?php echo $is_modal ? 'nicepay-payment-wrapper' : 'nicepay-standalone-wrapper nicepay-payment-wrapper'; ?>" id="<?php echo esc_attr( $form_id . '-wrapper' ); ?>">
    <?php if ( 'test' === get_option( 'nicepay_mode', 'test' ) ) : ?>
		<output class="nicepay-notice nicepay-notice-warning" aria-live="polite">
            <span><?php esc_html_e( 'Test mode — no real payment will be collected.', 'nicepay-payment-gateway' ); ?></span>
        </output>
    <?php endif; ?>

    <!-- Payment Summary -->
    <div class="nicepay-payment-info">
        <h3 id="<?php echo esc_attr( $form_id . '-title' ); ?>"><?php echo esc_html( $goods_name ); ?></h3>
        <p class="nicepay-order-summary">
            <strong><?php echo esc_html( nicepay_format_amount( $amount, $currency ) ); ?></strong>
        </p>
    </div>

    <?php if ( $show_method_selector ) : ?>
        <div class="nicepay-method-selector">
            <label id="<?php echo esc_attr( $form_id . '-method-label' ); ?>"><?php esc_html_e( 'Payment Method', 'nicepay-payment-gateway' ); ?></label>
            <div class="nicepay-methods" role="radiogroup" aria-labelledby="<?php echo esc_attr( $form_id . '-method-label' ); ?>">
                <?php foreach ( $enabled_methods as $method ) : ?>
                    <label class="nicepay-method-option <?php echo ( $method === $enabled_methods[0] ) ? 'is-selected' : ''; ?>">
                        <input type="radio" name="nicepay_method_<?php echo esc_attr( $form_id ); ?>"
							   form="<?php echo esc_attr( $form_id ); ?>"
                               value="<?php echo esc_attr( $method ); ?>"
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

    <?php if ( $show_buyer_fields ) : ?>
        <div class="nicepay-buyer-fields">
            <?php if ( empty( $preset_name ) ) : ?>
                <div class="nicepay-field">
                    <label for="<?php echo esc_attr( $form_id . '-name' ); ?>"><?php esc_html_e( 'Name', 'nicepay-payment-gateway' ); ?> <span class="nicepay-field-required">*</span></label>
                    <input type="text" id="<?php echo esc_attr( $form_id . '-name' ); ?>"
						   form="<?php echo esc_attr( $form_id ); ?>"
                           class="nicepay-field-input" data-field="BuyerName"
                           data-max-bytes="30" maxlength="30" autocomplete="name"
                           placeholder="<?php esc_attr_e( 'Enter your name', 'nicepay-payment-gateway' ); ?>" required>
                </div>
            <?php endif; ?>
            <?php if ( empty( $preset_email ) ) : ?>
                <div class="nicepay-field">
                    <label for="<?php echo esc_attr( $form_id . '-email' ); ?>"><?php esc_html_e( 'Email', 'nicepay-payment-gateway' ); ?> <span class="nicepay-field-required">*</span></label>
                    <input type="email" id="<?php echo esc_attr( $form_id . '-email' ); ?>"
						   form="<?php echo esc_attr( $form_id ); ?>"
                           class="nicepay-field-input" data-field="BuyerEmail"
                           data-max-bytes="60" maxlength="60" autocomplete="email" inputmode="email"
                           placeholder="<?php esc_attr_e( 'Enter your email', 'nicepay-payment-gateway' ); ?>" required>
                </div>
            <?php endif; ?>
            <?php if ( empty( $preset_tel ) ) : ?>
                <div class="nicepay-field">
                    <label for="<?php echo esc_attr( $form_id . '-tel' ); ?>"><?php esc_html_e( 'Phone', 'nicepay-payment-gateway' ); ?> <span class="nicepay-field-required">*</span></label>
                    <input type="tel" id="<?php echo esc_attr( $form_id . '-tel' ); ?>"
						   form="<?php echo esc_attr( $form_id ); ?>"
                           class="nicepay-field-input" data-field="BuyerTel"
                           data-max-bytes="20" maxlength="20" autocomplete="tel" inputmode="tel"
                           placeholder="<?php esc_attr_e( 'Enter your phone number', 'nicepay-payment-gateway' ); ?>" required>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- NicePay Hidden Form (populated on submit) -->
    <form id="<?php echo esc_attr( $form_id ); ?>" class="nicepay-standalone-form"
          data-nicepay-config-id="<?php echo esc_attr( $config_id ); ?>"
          data-nicepay-init-nonce="<?php echo esc_attr( wp_create_nonce( 'nicepay_init_payment' ) ); ?>"
          method="post"
          action="<?php echo esc_url( nicepay_get_standalone_return_url() ); ?>">
        <input type="hidden" name="GoodsName" id="<?php echo esc_attr( $form_id . '-goodsname' ); ?>" value="">
        <input type="hidden" name="Amt" id="<?php echo esc_attr( $form_id . '-amount' ); ?>" value="">
        <input type="hidden" name="MID" id="<?php echo esc_attr( $form_id . '-mid' ); ?>" value="">
        <input type="hidden" name="EdiDate" id="<?php echo esc_attr( $form_id . '-edidate' ); ?>" value="">
        <input type="hidden" name="Moid" id="<?php echo esc_attr( $form_id . '-moid' ); ?>" value="">
        <input type="hidden" name="SignData" id="<?php echo esc_attr( $form_id . '-signdata' ); ?>" value="">
		<input type="hidden" name="ReqReserved" id="<?php echo esc_attr( $form_id . '-reserved' ); ?>" value="">
        <input type="hidden" name="PayMethod" id="<?php echo esc_attr( $form_id . '-method' ); ?>" value="<?php echo esc_attr( $default_method ); ?>">
        <input type="hidden" name="ReturnURL" id="<?php echo esc_attr( $form_id . '-returnurl' ); ?>" value="">
        <input type="hidden" name="BuyerName" id="<?php echo esc_attr( $form_id . '-buyername' ); ?>" value="<?php echo esc_attr( $preset_name ); ?>">
        <input type="hidden" name="BuyerTel" id="<?php echo esc_attr( $form_id . '-buyertel' ); ?>" value="<?php echo esc_attr( $preset_tel ); ?>">
        <input type="hidden" name="BuyerEmail" id="<?php echo esc_attr( $form_id . '-buyeremail' ); ?>" value="<?php echo esc_attr( $preset_email ); ?>">
        <input type="hidden" name="NpLang" value="<?php echo esc_attr( $language ); ?>">
        <input type="hidden" name="CurrencyCode" id="<?php echo esc_attr( $form_id . '-currency' ); ?>" value="">
        <input type="hidden" name="CharSet" id="<?php echo esc_attr( $form_id . '-charset' ); ?>" value="utf-8">

        <input type="hidden" data-nicepay-goods-class value="">

        <div class="nicepay-submit-wrapper">
			<button type="submit" class="<?php echo esc_attr( $button_class ); ?>"
                    data-nicepay-start="<?php echo esc_attr( $form_id ); ?>"
                    <?php if ( $button_style ) : ?>
                        style="<?php echo esc_attr( $button_style ); ?>"
                    <?php endif; ?>>
                <?php echo esc_html( $atts['button_text'] ); ?>
            </button>
        </div>
    </form>
</div>

<?php if ( $is_modal ) : ?>
        </div><!-- .nicepay-payment-modal-body -->
    </div><!-- .nicepay-payment-modal -->
</div><!-- .nicepay-payment-modal-overlay -->
<?php endif; ?>
