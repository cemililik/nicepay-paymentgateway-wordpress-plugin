<?php
/**
 * Standalone NicePay Payment Form Template
 *
 * Used by the [nicepay_payment] shortcode for non-WooCommerce payments.
 * If buyer info is provided in shortcode attributes, fields are pre-filled and hidden.
 * Otherwise, an interactive form is shown for the buyer to fill in.
 *
 * @var array $atts Shortcode attributes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$api          = new NicePay_API();
$currency     = sanitize_text_field( $atts['currency'] );
$language     = NicePay_API::get_nicepay_lang( $atts['language'] );
$charset      = get_option( 'nicepay_charset', 'utf-8' );
$display_mode = isset( $atts['display_mode'] ) ? sanitize_text_field( $atts['display_mode'] ) : 'inline';
$is_modal     = ( $display_mode === 'modal' );

// Normalize amount
$raw_amount = preg_replace( '/[^0-9.]/', '', sanitize_text_field( $atts['amount'] ) );
$amount     = nicepay_get_amount( $raw_amount, $currency );

if ( empty( $amount ) || (float) $amount <= 0 ) {
    echo '<div class="nicepay-notice nicepay-notice-error" role="alert"><span>' . esc_html__( 'Invalid payment amount.', 'nicepay-payment-gateway' ) . '</span></div>';
    return;
}

$enabled_methods = get_option( 'nicepay_enabled_methods', array( 'CARD' ) );
$pay_method      = sanitize_text_field( $atts['pay_method'] );
if ( $pay_method && ! in_array( $pay_method, $enabled_methods, true ) ) {
    $pay_method = '';
}

$show_method_selector = empty( $pay_method ) && count( $enabled_methods ) > 1;
$default_method       = $pay_method ? $pay_method : $enabled_methods[0];

$form_id = 'nicepay-standalone-' . wp_rand( 1000, 9999 );

// Determine which buyer fields need user input
$preset_name  = sanitize_text_field( $atts['buyer_name'] );
$preset_email = sanitize_email( $atts['buyer_email'] );
$preset_tel   = sanitize_text_field( $atts['buyer_tel'] );
$goods_name   = mb_strcut( sanitize_text_field( $atts['goods_name'] ), 0, 40, 'UTF-8' );
$show_buyer_fields = empty( $preset_name ) || empty( $preset_email ) || empty( $preset_tel );
?>

<?php if ( $is_modal ) : ?>
<!-- Modal Trigger Button -->
<div class="nicepay-standalone-wrapper" id="<?php echo esc_attr( $form_id . '-trigger' ); ?>">
    <button type="button" class="<?php echo esc_attr( $atts['button_class'] ); ?>"
            <?php if ( ! empty( $atts['button_color'] ) ) : ?>style="background:<?php echo esc_attr( $atts['button_color'] ); ?>"<?php endif; ?>
            onclick="nicepayOpenPaymentModal('<?php echo esc_js( $form_id ); ?>')">
        <?php echo esc_html( $atts['button_text'] ); ?>
    </button>
</div>

<!-- Modal Overlay -->
<div class="nicepay-payment-modal-overlay" id="<?php echo esc_attr( $form_id . '-modal' ); ?>" style="display:none;" role="dialog" aria-modal="true">
    <div class="nicepay-payment-modal">
        <button type="button" class="nicepay-payment-modal-close" onclick="nicepayClosePaymentModal('<?php echo esc_js( $form_id ); ?>')" aria-label="<?php esc_attr_e( 'Close', 'nicepay-payment-gateway' ); ?>">&times;</button>
        <div class="nicepay-payment-modal-body">
<?php endif; ?>

<div class="<?php echo $is_modal ? 'nicepay-payment-wrapper' : 'nicepay-standalone-wrapper nicepay-payment-wrapper'; ?>" id="<?php echo esc_attr( $form_id . '-wrapper' ); ?>">
    <!-- Payment Summary -->
    <div class="nicepay-payment-info">
        <h3><?php echo esc_html( $goods_name ); ?></h3>
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
                           class="nicepay-field-input" data-field="BuyerName"
                           placeholder="<?php esc_attr_e( 'Enter your name', 'nicepay-payment-gateway' ); ?>" required>
                </div>
            <?php endif; ?>
            <?php if ( empty( $preset_email ) ) : ?>
                <div class="nicepay-field">
                    <label for="<?php echo esc_attr( $form_id . '-email' ); ?>"><?php esc_html_e( 'Email', 'nicepay-payment-gateway' ); ?> <span class="nicepay-field-required">*</span></label>
                    <input type="email" id="<?php echo esc_attr( $form_id . '-email' ); ?>"
                           class="nicepay-field-input" data-field="BuyerEmail"
                           placeholder="<?php esc_attr_e( 'Enter your email', 'nicepay-payment-gateway' ); ?>" required>
                </div>
            <?php endif; ?>
            <?php if ( empty( $preset_tel ) ) : ?>
                <div class="nicepay-field">
                    <label for="<?php echo esc_attr( $form_id . '-tel' ); ?>"><?php esc_html_e( 'Phone', 'nicepay-payment-gateway' ); ?> <span class="nicepay-field-required">*</span></label>
                    <input type="tel" id="<?php echo esc_attr( $form_id . '-tel' ); ?>"
                           class="nicepay-field-input" data-field="BuyerTel"
                           placeholder="<?php esc_attr_e( 'Enter your phone number', 'nicepay-payment-gateway' ); ?>" required>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- NicePay Hidden Form (populated on submit) -->
    <form id="<?php echo esc_attr( $form_id ); ?>" name="payForm" method="post"
          action="<?php echo esc_url( home_url( '/nicepay-return/' ) ); ?>"
          accept-charset="<?php echo esc_attr( $charset ); ?>">
        <input type="hidden" name="GoodsName" value="<?php echo esc_attr( $goods_name ); ?>">
        <input type="hidden" name="Amt" value="<?php echo esc_attr( $amount ); ?>">
        <input type="hidden" name="MID" value="<?php echo esc_attr( $api->get_mid() ); ?>">
        <input type="hidden" name="EdiDate" id="<?php echo esc_attr( $form_id . '-edidate' ); ?>" value="">
        <input type="hidden" name="Moid" id="<?php echo esc_attr( $form_id . '-moid' ); ?>" value="">
        <input type="hidden" name="SignData" id="<?php echo esc_attr( $form_id . '-signdata' ); ?>" value="">
        <input type="hidden" name="PayMethod" id="<?php echo esc_attr( $form_id . '-method' ); ?>" value="<?php echo esc_attr( $default_method ); ?>">
        <input type="hidden" name="ReturnURL" value="<?php echo esc_url( home_url( '/nicepay-return/' ) ); ?>">
        <input type="hidden" name="BuyerName" id="<?php echo esc_attr( $form_id . '-buyername' ); ?>" value="<?php echo esc_attr( $preset_name ); ?>">
        <input type="hidden" name="BuyerTel" id="<?php echo esc_attr( $form_id . '-buyertel' ); ?>" value="<?php echo esc_attr( $preset_tel ); ?>">
        <input type="hidden" name="BuyerEmail" id="<?php echo esc_attr( $form_id . '-buyeremail' ); ?>" value="<?php echo esc_attr( $preset_email ); ?>">
        <input type="hidden" name="NpLang" value="<?php echo esc_attr( $language ); ?>">
        <input type="hidden" name="CurrencyCode" value="<?php echo esc_attr( $currency ); ?>">
        <input type="hidden" name="CharSet" value="<?php echo esc_attr( $charset ); ?>">

        <?php if ( in_array( 'VBANK', $enabled_methods, true ) ) : ?>
            <input type="hidden" name="VbankExpDate" value="<?php echo esc_attr( $api->get_vbank_exp_date() ); ?>">
        <?php endif; ?>

        <?php if ( in_array( 'CELLPHONE', $enabled_methods, true ) ) : ?>
            <input type="hidden" name="GoodsCl" value="1">
        <?php endif; ?>

        <?php if ( in_array( 'GIFT_CULT', $enabled_methods, true ) ) : ?>
            <input type="hidden" name="MallUserID" id="<?php echo esc_attr( $form_id . '-malluserid' ); ?>" value="">
        <?php endif; ?>

        <div class="nicepay-submit-wrapper">
            <button type="button" class="<?php echo esc_attr( $atts['button_class'] ); ?>"
                    <?php if ( ! empty( $atts['button_color'] ) ) : ?>
                        style="background:<?php echo esc_attr( $atts['button_color'] ); ?>"
                    <?php endif; ?>
                    onclick="nicepayStartStandalone('<?php echo esc_js( $form_id ); ?>')">
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

<script>
// Payment method radio selection for standalone forms
(function() {
    document.addEventListener('change', function(e) {
        if (e.target.type === 'radio' && e.target.name && e.target.name.indexOf('nicepay_method_') === 0) {
            // Update is-selected class on all options in this group
            var group = e.target.closest('.nicepay-methods');
            if (group) {
                group.querySelectorAll('.nicepay-method-option').forEach(function(opt) {
                    opt.classList.remove('is-selected');
                });
                e.target.closest('.nicepay-method-option').classList.add('is-selected');
            }
        }
    });
})();

function nicepayStartStandalone(formId) {
    var wrapper = document.getElementById(formId + '-wrapper');
    var form = document.getElementById(formId);
    if (!form || !wrapper) return;

    // Clear previous errors
    wrapper.querySelectorAll('.nicepay-field-error').forEach(function(el) { el.remove(); });
    wrapper.querySelectorAll('.nicepay-field-input').forEach(function(el) { el.classList.remove('is-invalid'); });
    var existingNotice = wrapper.querySelector('.nicepay-notice');
    if (existingNotice) existingNotice.remove();

    // Validate visible buyer fields
    var valid = true;
    var firstInvalid = null;
    wrapper.querySelectorAll('.nicepay-field-input').forEach(function(input) {
        var value = input.value.trim();
        var fieldName = input.getAttribute('data-field');
        var errorMsg = '';

        if (!value) {
            errorMsg = '<?php echo esc_js( __( 'This field is required.', 'nicepay-payment-gateway' ) ); ?>';
        } else if (input.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            errorMsg = '<?php echo esc_js( __( 'Please enter a valid email address.', 'nicepay-payment-gateway' ) ); ?>';
        } else if (input.type === 'tel' && !/^[\d\-+() ]{7,20}$/.test(value)) {
            errorMsg = '<?php echo esc_js( __( 'Please enter a valid phone number.', 'nicepay-payment-gateway' ) ); ?>';
        }

        if (errorMsg) {
            valid = false;
            input.classList.add('is-invalid');
            var err = document.createElement('div');
            err.className = 'nicepay-field-error';
            err.setAttribute('role', 'alert');
            err.textContent = errorMsg;
            input.parentNode.appendChild(err);
            if (!firstInvalid) firstInvalid = input;
        }
    });

    if (!valid) {
        if (firstInvalid) firstInvalid.focus();
        return;
    }

    // Sync buyer field values to hidden inputs
    var nameInput = wrapper.querySelector('[data-field="BuyerName"]');
    var emailInput = wrapper.querySelector('[data-field="BuyerEmail"]');
    var telInput = wrapper.querySelector('[data-field="BuyerTel"]');
    if (nameInput) document.getElementById(formId + '-buyername').value = nameInput.value.trim();
    if (emailInput) document.getElementById(formId + '-buyeremail').value = emailInput.value.trim();
    if (telInput) document.getElementById(formId + '-buyertel').value = telInput.value.trim();

    // Set MallUserID for GIFT_CULT (Culture Cash) — uses buyer email
    var mallUserIdInput = document.getElementById(formId + '-malluserid');
    if (mallUserIdInput) {
        var email = document.getElementById(formId + '-buyeremail').value;
        mallUserIdInput.value = email || '';
    }

    // Update payment method from radio
    var radios = wrapper.querySelectorAll('input[name="nicepay_method_' + formId + '"]');
    var methodInput = document.getElementById(formId + '-method');
    radios.forEach(function(radio) {
        if (radio.checked) methodInput.value = radio.value;
    });

    // Initialize payment via AJAX (creates transaction + returns SignData)
    var btn = form.querySelector('button');
    btn.classList.add('is-loading');
    btn.disabled = true;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.success) {
                    // Populate form with server-generated values
                    document.getElementById(formId + '-edidate').value = resp.data.edi_date;
                    document.getElementById(formId + '-moid').value = resp.data.moid;
                    document.getElementById(formId + '-signdata').value = resp.data.sign_data;

                    // Set active form and launch NicePay
                    document.payForm = form;
                    if (typeof nicepayStart === 'function') {
                        nicepayStart();
                    } else {
                        btn.classList.remove('is-loading');
                        btn.disabled = false;
                        showStandaloneError(wrapper, form, '<?php echo esc_js( __( 'Payment system is currently unavailable. Please try again later.', 'nicepay-payment-gateway' ) ); ?>');
                    }
                } else {
                    btn.classList.remove('is-loading');
                    btn.disabled = false;
                    showStandaloneError(wrapper, form, resp.data ? resp.data.message : '<?php echo esc_js( __( 'Payment initialization failed.', 'nicepay-payment-gateway' ) ); ?>');
                }
            } catch(e) {
                btn.classList.remove('is-loading');
                btn.disabled = false;
                showStandaloneError(wrapper, form, '<?php echo esc_js( __( 'An unexpected error occurred.', 'nicepay-payment-gateway' ) ); ?>');
            }
        } else {
            btn.classList.remove('is-loading');
            btn.disabled = false;
            showStandaloneError(wrapper, form, '<?php echo esc_js( __( 'Request failed. Please try again.', 'nicepay-payment-gateway' ) ); ?>');
        }
    };
    xhr.onerror = function() {
        btn.classList.remove('is-loading');
        btn.disabled = false;
        showStandaloneError(wrapper, form, '<?php echo esc_js( __( 'Connection error. Please check your internet.', 'nicepay-payment-gateway' ) ); ?>');
    };
    xhr.send('action=nicepay_init_payment&nonce=<?php echo esc_js( wp_create_nonce( 'nicepay_init_payment' ) ); ?>&amount=<?php echo esc_js( $amount ); ?>&goods_name=' + encodeURIComponent('<?php echo esc_js( $goods_name ); ?>') + '&buyer_name=' + encodeURIComponent(document.getElementById(formId + '-buyername').value) + '&buyer_email=' + encodeURIComponent(document.getElementById(formId + '-buyeremail').value) + '&buyer_tel=' + encodeURIComponent(document.getElementById(formId + '-buyertel').value));
}

function showStandaloneError(wrapper, form, message) {
    var existing = wrapper.querySelector('.nicepay-notice');
    if (existing) existing.remove();
    var notice = document.createElement('div');
    notice.className = 'nicepay-notice nicepay-notice-error';
    notice.setAttribute('role', 'alert');
    notice.innerHTML = '<span class="nicepay-notice-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg></span><span>' + message + '</span>';
    form.querySelector('.nicepay-submit-wrapper').before(notice);
    setTimeout(function() { if (notice.parentNode) notice.style.opacity = '0'; setTimeout(function() { if (notice.parentNode) notice.remove(); }, 300); }, 8000);
}

window.nicepaySubmit = window.nicepaySubmit || function() {
    document.payForm.submit();
};

window.nicepayClose = window.nicepayClose || function() {
    if (window.NicePayHandler) window.NicePayHandler.hideLoading();
    document.querySelectorAll('.nicepay-pay-button.is-loading').forEach(function(btn) {
        btn.classList.remove('is-loading');
        btn.disabled = false;
    });
};

// Modal mode functions
var _nicepayModalEscHandlers = {};

function nicepayOpenPaymentModal(formId) {
    var modal = document.getElementById(formId + '-modal');
    if (!modal) return;

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    // Close on backdrop click
    modal.onclick = function(e) {
        if (e.target === modal) nicepayClosePaymentModal(formId);
    };

    // Close on Escape — store handler for cleanup
    var escHandler = function(e) {
        if (e.key === 'Escape') nicepayClosePaymentModal(formId);
    };
    _nicepayModalEscHandlers[formId] = escHandler;
    document.addEventListener('keydown', escHandler);
}

function nicepayClosePaymentModal(formId) {
    var modal = document.getElementById(formId + '-modal');
    if (modal) {
        modal.style.display = 'none';
        modal.onclick = null;
        document.body.style.overflow = '';
    }
    // Remove stored Escape handler
    if (_nicepayModalEscHandlers[formId]) {
        document.removeEventListener('keydown', _nicepayModalEscHandlers[formId]);
        delete _nicepayModalEscHandlers[formId];
    }
}
</script>
