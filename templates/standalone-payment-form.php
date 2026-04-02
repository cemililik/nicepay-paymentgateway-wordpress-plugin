<?php
/**
 * Standalone NicePay Payment Form Template
 *
 * Used by the [nicepay_payment] shortcode for non-WooCommerce payments.
 *
 * Available variables:
 * @var array $atts Shortcode attributes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$api = new NicePay_API();
$edi_date = $api->generate_edi_date();
$moid     = $api->generate_moid( 'SP' );
$currency = sanitize_text_field( $atts['currency'] );
$language = NicePay_API::get_nicepay_lang( $atts['language'] );
$charset  = get_option( 'nicepay_charset', 'utf-8' );

// Normalize amount: strip non-numeric chars except decimal point, validate
$raw_amount = preg_replace( '/[^0-9.]/', '', sanitize_text_field( $atts['amount'] ) );
$amount     = nicepay_get_amount( $raw_amount, $currency );

if ( empty( $amount ) || (float) $amount <= 0 ) {
    echo '<p>' . esc_html__( 'Invalid payment amount.', 'nicepay-payment-gateway' ) . '</p>';
    return;
}

$sign_data      = $api->create_auth_sign_data( $edi_date, $amount );
$return_url     = home_url( '/nicepay-return/' );
$enabled_methods = get_option( 'nicepay_enabled_methods', array( 'CARD' ) );

$pay_method = sanitize_text_field( $atts['pay_method'] );
if ( $pay_method && ! in_array( $pay_method, $enabled_methods, true ) ) {
    $pay_method = '';
}

$show_method_selector = empty( $pay_method ) && count( $enabled_methods ) > 1;
$default_method = $pay_method ? $pay_method : $enabled_methods[0];

$form_id = 'nicepay-standalone-' . wp_rand( 1000, 9999 );

// Save initial transaction record (must succeed before rendering form)
$tx_id = nicepay_save_transaction( array(
    'order_id'    => $moid,
    'moid'        => $moid,
    'amount'      => $amount,
    'status'      => 'pending',
    'buyer_name'  => sanitize_text_field( $atts['buyer_name'] ),
    'buyer_email' => sanitize_email( $atts['buyer_email'] ),
    'buyer_tel'   => sanitize_text_field( $atts['buyer_tel'] ),
    'goods_name'  => mb_strcut( sanitize_text_field( $atts['goods_name'] ), 0, 40, 'UTF-8' ),
) );

if ( $tx_id === false ) {
    echo '<p>' . esc_html__( 'Payment initialization failed. Please try again.', 'nicepay-payment-gateway' ) . '</p>';
    return;
}
?>

<div class="nicepay-standalone-wrapper" id="<?php echo esc_attr( $form_id . '-wrapper' ); ?>">
    <?php if ( $show_method_selector ) : ?>
        <div class="nicepay-method-selector">
            <label><?php esc_html_e( 'Payment Method', 'nicepay-payment-gateway' ); ?></label>
            <div class="nicepay-methods">
                <?php foreach ( $enabled_methods as $method ) : ?>
                    <label class="nicepay-method-option">
                        <input type="radio" name="nicepay_method_<?php echo esc_attr( $form_id ); ?>"
                               value="<?php echo esc_attr( $method ); ?>"
                               <?php checked( $method, $enabled_methods[0] ); ?>>
                        <span class="nicepay-method-label">
                            <?php echo esc_html( NicePay_API::get_payment_method_name( $method ) ); ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <form id="<?php echo esc_attr( $form_id ); ?>" name="payForm" method="post"
          action="<?php echo esc_url( $return_url ); ?>"
          accept-charset="<?php echo esc_attr( $charset ); ?>">
        <input type="hidden" name="GoodsName" value="<?php echo esc_attr( $atts['goods_name'] ); ?>">
        <input type="hidden" name="Amt" value="<?php echo esc_attr( $amount ); ?>">
        <input type="hidden" name="MID" value="<?php echo esc_attr( $api->get_mid() ); ?>">
        <input type="hidden" name="EdiDate" value="<?php echo esc_attr( $edi_date ); ?>">
        <input type="hidden" name="Moid" value="<?php echo esc_attr( $moid ); ?>">
        <input type="hidden" name="SignData" value="<?php echo esc_attr( $sign_data ); ?>">
        <input type="hidden" name="PayMethod" id="<?php echo esc_attr( $form_id . '-method' ); ?>" value="<?php echo esc_attr( $default_method ); ?>">
        <input type="hidden" name="ReturnURL" value="<?php echo esc_url( $return_url ); ?>">
        <input type="hidden" name="BuyerName" value="<?php echo esc_attr( $atts['buyer_name'] ); ?>">
        <input type="hidden" name="BuyerTel" value="<?php echo esc_attr( $atts['buyer_tel'] ); ?>">
        <input type="hidden" name="BuyerEmail" value="<?php echo esc_attr( $atts['buyer_email'] ); ?>">
        <input type="hidden" name="NpLang" value="<?php echo esc_attr( $language ); ?>">
        <input type="hidden" name="CurrencyCode" value="<?php echo esc_attr( $currency ); ?>">
        <input type="hidden" name="CharSet" value="<?php echo esc_attr( $charset ); ?>">

        <?php if ( in_array( 'VBANK', $enabled_methods, true ) ) : ?>
            <input type="hidden" name="VbankExpDate" value="<?php echo esc_attr( $api->get_vbank_exp_date() ); ?>">
        <?php endif; ?>

        <?php if ( in_array( 'CELLPHONE', $enabled_methods, true ) ) : ?>
            <input type="hidden" name="GoodsCl" value="1">
        <?php endif; ?>

        <button type="button" class="<?php echo esc_attr( $atts['button_class'] ); ?>"
                onclick="nicepayStartStandalone('<?php echo esc_js( $form_id ); ?>')">
            <?php echo esc_html( $atts['button_text'] ); ?>
        </button>
    </form>
</div>

<script>
function nicepayStartStandalone(formId) {
    var form = document.getElementById(formId);
    if (!form) return;

    // Update payment method from radio if exists
    var radios = document.querySelectorAll('input[name="nicepay_method_' + formId + '"]');
    var methodInput = document.getElementById(formId + '-method');
    radios.forEach(function(radio) {
        if (radio.checked) {
            methodInput.value = radio.value;
        }
    });

    // Set the active form for nicepay callbacks
    document.payForm = form;
    nicepayStart();
}

window.nicepaySubmit = window.nicepaySubmit || function() {
    document.payForm.submit();
};

window.nicepayClose = window.nicepayClose || function() {
    // Do nothing on close for standalone
};
</script>
