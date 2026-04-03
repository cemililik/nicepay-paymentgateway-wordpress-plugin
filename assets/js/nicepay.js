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

            // Show loading state (class-based for multi-instance support)
            $('.nicepay-pay-button').addClass('is-loading').prop('disabled', true);
            $('.nicepay-loading-overlay').addClass('is-active');

            if (typeof nicepayStart === 'function') {
                try {
                    nicepayStart();
                } catch (e) {
                    console.error('[NicePay] nicepayStart() threw:', e);
                    this.hideLoading();
                    this.showNotice(
                        typeof nicepayParams !== 'undefined' ? nicepayParams.i18n.error : 'Payment error occurred.',
                        'error'
                    );
                }
            } else {
                this.hideLoading();
                this.showNotice(
                    typeof nicepayParams !== 'undefined' ? nicepayParams.i18n.error : 'Payment system unavailable.',
                    'error'
                );
            }
        },

        hideLoading: function() {
            $('.nicepay-pay-button').removeClass('is-loading').prop('disabled', false);
            $('.nicepay-loading-overlay').removeClass('is-active');
        },

        showNotice: function(message, type) {
            // Find the closest payment wrapper to the active form
            var wrapper = $(document.payForm).closest('.nicepay-payment-wrapper');
            if (!wrapper.length) {
                wrapper = $('.nicepay-payment-wrapper').first();
            }
            wrapper.find('.nicepay-notice').remove();

            var icon = '<span class="nicepay-notice-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg></span>';
            var notice = $('<div class="nicepay-notice nicepay-notice-' + type + '" role="alert">' + icon + '<span>' + $('<span>').text(message).html() + '</span></div>');

            // Insert before submit button or at end of wrapper
            var anchor = wrapper.find('.nicepay-submit-wrapper, form > button, form > .nicepay-submit-wrapper').first();
            if (anchor.length) {
                anchor.before(notice);
            } else {
                wrapper.append(notice);
            }

            setTimeout(function() {
                notice.fadeOut(300, function() { notice.remove(); });
            }, 6000);
        }
    };

    // Expose for NicePay callbacks
    window.NicePayHandler = NicePayHandler;

    $(document).ready(function() {
        NicePayHandler.init();
    });

})(jQuery);
