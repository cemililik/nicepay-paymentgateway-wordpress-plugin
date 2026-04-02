/**
 * NicePay Payment Gateway - Frontend JavaScript
 *
 * Handles payment form interactions and NicePay payment window callbacks.
 */
(function($) {
    'use strict';

    /**
     * NicePay Payment Handler
     */
    var NicePayHandler = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Payment method radio selection (WooCommerce receipt page)
            $(document).on('change', 'input[name="nicepay_pay_method"]', function() {
                $('#nicepay-pay-method').val($(this).val());
            });

            // Submit button click (WooCommerce receipt page)
            $(document).on('click', '#nicepay-submit-btn', function(e) {
                e.preventDefault();
                NicePayHandler.startPayment();
            });
        },

        startPayment: function() {
            var payMethod = $('#nicepay-pay-method').val();

            if (!payMethod) {
                alert(nicepayParams.i18n.selectMethod);
                return;
            }

            // Check if nicepayStart exists (from NicePay's JS)
            if (typeof nicepayStart === 'function') {
                nicepayStart();
            } else {
                console.error('[NicePay] nicepayStart function not available. Check if nicepay-pgweb.js loaded correctly.');
                alert(nicepayParams.i18n.error);
            }
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        NicePayHandler.init();
    });

})(jQuery);
