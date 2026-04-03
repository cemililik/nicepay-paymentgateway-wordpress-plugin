/**
 * NicePay Payment Gateway - Frontend JavaScript
 *
 * Payment form interactions, loading states, inline notices.
 */
(function($) {
    'use strict';

    var NicePayHandler = {
        init: function() {
            this.bindEvents();
            this.updateMethodSelection();
        },

        bindEvents: function() {
            $(document).on('change', 'input[name="nicepay_pay_method"]', function() {
                $('#nicepay-pay-method').val($(this).val());
                NicePayHandler.updateMethodSelection();
            });

            $(document).on('click', '#nicepay-submit-btn', function(e) {
                e.preventDefault();
                NicePayHandler.startPayment();
            });
        },

        updateMethodSelection: function() {
            $('.nicepay-method-option').removeClass('is-selected');
            $('.nicepay-method-option input:checked').closest('.nicepay-method-option').addClass('is-selected');
        },

        startPayment: function() {
            var payMethod = $('#nicepay-pay-method').val();

            if (!payMethod) {
                this.showNotice(
                    typeof nicepayParams !== 'undefined' ? nicepayParams.i18n.selectMethod : 'Please select a payment method.',
                    'error'
                );
                return;
            }

            // Show loading state
            var btn = $('#nicepay-submit-btn');
            btn.addClass('is-loading').prop('disabled', true);
            $('#nicepay-loading').addClass('is-active');

            if (typeof nicepayStart === 'function') {
                nicepayStart();
            } else {
                this.hideLoading();
                this.showNotice(
                    typeof nicepayParams !== 'undefined' ? nicepayParams.i18n.error : 'Payment system unavailable.',
                    'error'
                );
            }
        },

        hideLoading: function() {
            $('#nicepay-submit-btn').removeClass('is-loading').prop('disabled', false);
            $('#nicepay-loading').removeClass('is-active');
        },

        showNotice: function(message, type) {
            var wrapper = $('#nicepay-payment-wrapper, .nicepay-standalone-wrapper').first();
            wrapper.find('.nicepay-notice').remove();

            var icon = '<span class="nicepay-notice-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg></span>';
            var notice = $('<div class="nicepay-notice nicepay-notice-' + type + '" role="alert">' + icon + '<span>' + $('<span>').text(message).html() + '</span></div>');

            wrapper.find('.nicepay-submit-wrapper').before(notice);

            setTimeout(function() {
                notice.fadeOut(300, function() { notice.remove(); });
            }, 6000);
        }
    };

    // Expose hideLoading for NicePay callbacks
    window.NicePayHandler = NicePayHandler;

    $(document).ready(function() {
        NicePayHandler.init();
    });

})(jQuery);
