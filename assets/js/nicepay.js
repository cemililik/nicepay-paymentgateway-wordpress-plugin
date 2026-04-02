/**
 * NicePay Payment Gateway - Frontend JavaScript
 *
 * Handles payment form interactions and NicePay payment window callbacks.
 */
(function($) {
    'use strict';

    var NicePayHandler = {
        init: function() {
            this.bindEvents();
            this.updateMethodSelection();
        },

        bindEvents: function() {
            // Payment method radio selection
            $(document).on('change', 'input[name="nicepay_pay_method"]', function() {
                $('#nicepay-pay-method').val($(this).val());
                NicePayHandler.updateMethodSelection();
            });

            // Submit button click
            $(document).on('click', '#nicepay-submit-btn', function(e) {
                e.preventDefault();
                NicePayHandler.startPayment();
            });
        },

        /**
         * Update .is-selected class on method options (CSS :has() fallback)
         */
        updateMethodSelection: function() {
            $('.nicepay-method-option').removeClass('is-selected');
            $('.nicepay-method-option input:checked').closest('.nicepay-method-option').addClass('is-selected');
        },

        startPayment: function() {
            var payMethod = $('#nicepay-pay-method').val();

            if (!payMethod) {
                if (typeof nicepayParams !== 'undefined') {
                    alert(nicepayParams.i18n.selectMethod);
                }
                return;
            }

            if (typeof nicepayStart === 'function') {
                nicepayStart();
            } else {
                console.error('[NicePay] nicepayStart not available. Check if nicepay-pgweb.js loaded.');
                if (typeof nicepayParams !== 'undefined') {
                    alert(nicepayParams.i18n.error);
                }
            }
        }
    };

    $(document).ready(function() {
        NicePayHandler.init();
    });

})(jQuery);
