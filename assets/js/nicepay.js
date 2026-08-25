/**
 * NicePay Payment Gateway - Frontend JavaScript
 *
 * Payment form interactions, loading states, inline notices.
 */
(function($) {
    'use strict';

    var activePaymentForm = null;
    var modalState = new Map();
    var paymentConfig = window.nicepayParams && typeof window.nicepayParams === 'object'
        ? window.nicepayParams
        : { ajaxUrl: '', initNonce: '', i18n: {} };
    var paymentI18n = paymentConfig.i18n && typeof paymentConfig.i18n === 'object'
        ? paymentConfig.i18n
        : {};

    function sameOriginHttpsUrl(value) {
        try {
            const url = new URL(String(value || ''), window.location.href);
            return url.protocol === 'https:' && url.origin === window.location.origin ? url.href : '';
        } catch {
            return '';
        }
    }

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
            var form = document.getElementById('nicepay-pay-form');

            if (!payMethod || !form) {
                this.showNotice(
                    paymentI18n.selectMethod || 'Please select a payment method.',
                    'error'
                );
                return;
            }

            activePaymentForm = form;

            // Show loading state (class-based for multi-instance support)
            $('.nicepay-pay-button').addClass('is-loading').prop('disabled', true);
            $('.nicepay-loading-overlay').addClass('is-active');

            if (typeof window.nicepayStart === 'function') {
                try {
                    window.nicepayStart();
                } catch (e) {
                    console.error('[NicePay] nicepayStart() threw:', e);
                    this.hideLoading();
                    this.showNotice(
                        paymentI18n.error || 'Payment error occurred.',
                        'error'
                    );
                }
            } else {
                this.hideLoading();
                this.showNotice(
                    paymentI18n.error || 'Payment system unavailable.',
                    'error'
                );
            }
        },

        hideLoading: function() {
            $('.nicepay-pay-button').removeClass('is-loading').prop('disabled', false);
            $('.nicepay-loading-overlay').removeClass('is-active');
            if (activePaymentForm && !activePaymentForm.classList.contains('nicepay-standalone-form')) {
                activePaymentForm = null;
            }
        },

        showNotice: function(message, type) {
            // Find the closest payment wrapper to the active form
            var wrapper = $(document.payForm).closest('.nicepay-payment-wrapper');
            if (!wrapper.length) {
                wrapper = $('.nicepay-payment-wrapper').first();
            }
            wrapper.find('.nicepay-notice').remove();

            var safeType = 'error' === type ? 'error' : 'info';
            var noticeNode = document.createElement('div');
            var icon = document.createElement('span');
            var text = document.createElement('span');
            noticeNode.className = 'nicepay-notice nicepay-notice-' + safeType;
            noticeNode.setAttribute('role', 'alert');
            icon.className = 'nicepay-notice-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '!';
            text.textContent = String(message || '');
            noticeNode.appendChild(icon);
            noticeNode.appendChild(text);
            var notice = $(noticeNode);

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

    function translated(key, fallback) {
        switch (key) {
            case 'connectionError': return paymentI18n.connectionError || fallback;
            case 'fieldTooLong': return paymentI18n.fieldTooLong || fallback;
            case 'initializationFailed': return paymentI18n.initializationFailed || fallback;
            case 'invalidEmail': return paymentI18n.invalidEmail || fallback;
            case 'invalidPhone': return paymentI18n.invalidPhone || fallback;
            case 'paymentInProgress': return paymentI18n.paymentInProgress || fallback;
            case 'requiredField': return paymentI18n.requiredField || fallback;
            case 'sessionExpired': return paymentI18n.sessionExpired || fallback;
            case 'systemUnavailable': return paymentI18n.systemUnavailable || fallback;
            case 'unexpectedError': return paymentI18n.unexpectedError || fallback;
            default: return fallback;
        }
    }

    function standaloneField(form, name) {
        return form.elements.namedItem(name);
    }

    function setStandaloneLoading(form, loading) {
        document.querySelectorAll('[data-nicepay-start]').forEach(function(button) {
            button.disabled = loading;
        });
        var button = form ? form.querySelector('[data-nicepay-start]') : null;
        if (button) {
            button.classList.toggle('is-loading', loading);
        }
    }

    function releaseStandalone(form) {
        setStandaloneLoading(form, false);
        if (form) form.removeAttribute('name');
        if (activePaymentForm === form) {
            activePaymentForm = null;
        }
    }

    function showStandaloneError(wrapper, form, message) {
        var existing = wrapper.querySelector('.nicepay-notice-error');
        if (existing) existing.remove();
        var notice = document.createElement('div');
        notice.className = 'nicepay-notice nicepay-notice-error';
        notice.setAttribute('role', 'alert');
        var icon = document.createElement('span');
        icon.className = 'nicepay-notice-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '!';
        var text = document.createElement('span');
        text.textContent = String(message || '');
        notice.appendChild(icon);
        notice.appendChild(text);
        var anchor = form.querySelector('.nicepay-submit-wrapper');
        if (anchor) anchor.before(notice);
        window.setTimeout(function() {
            if (!notice.parentNode) return;
            notice.style.opacity = '0';
            window.setTimeout(function() {
                if (notice.parentNode) notice.remove();
            }, 300);
        }, 8000);
    }

    function validateStandaloneBuyerFields(wrapper) {
        var valid = true;
        var firstInvalid = null;

        wrapper.querySelectorAll('.nicepay-field-error').forEach(function(error) { error.remove(); });
        wrapper.querySelectorAll('.nicepay-field-input').forEach(function(input) {
            input.classList.remove('is-invalid');
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
            const value = input.value.trim();
            let errorMessage = '';
            const maxBytes = parseInt(input.getAttribute('data-max-bytes') || '0', 10);
            const byteLength = typeof TextEncoder !== 'undefined'
                ? new TextEncoder().encode(value).length
                : unescape(encodeURIComponent(value)).length;

            if (!value) {
                errorMessage = translated('requiredField', 'This field is required.');
            } else if (maxBytes && byteLength > maxBytes) {
                errorMessage = translated('fieldTooLong', 'This field exceeds the payment provider limit.');
            } else if (input.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                errorMessage = translated('invalidEmail', 'Please enter a valid email address.');
            } else if (input.type === 'tel' && !/^[\d\-+() ]{7,30}$/.test(value)) {
                errorMessage = translated('invalidPhone', 'Please enter a valid phone number.');
            }

            if (errorMessage) {
                valid = false;
                input.classList.add('is-invalid');
                input.setAttribute('aria-invalid', 'true');
                const error = document.createElement('div');
                error.className = 'nicepay-field-error';
                error.setAttribute('role', 'alert');
                error.id = input.id + '-error';
                input.setAttribute('aria-describedby', error.id);
                error.textContent = errorMessage;
                input.parentNode.appendChild(error);
                if (!firstInvalid) firstInvalid = input;
            }
        });

        if (firstInvalid) firstInvalid.focus();
        return valid;
    }

    function populateStandaloneForm(form, authoritative) {
        if (!authoritative.edi_date || !authoritative.moid || !authoritative.sign_data ||
            !authoritative.amount || !authoritative.currency || !authoritative.goods_name ||
            !authoritative.pay_method || !authoritative.mid || !authoritative.return_url) return false;
        if (authoritative.pay_method === 'CELLPHONE' && authoritative.goods_class !== '0' && authoritative.goods_class !== '1') {
            return false;
        }

        var returnUrl = sameOriginHttpsUrl(authoritative.return_url);
        if (!returnUrl) {
            return false;
        }

        var values = [
            { name: 'EdiDate', value: authoritative.edi_date },
            { name: 'Moid', value: authoritative.moid },
            { name: 'SignData', value: authoritative.sign_data },
            { name: 'Amt', value: authoritative.amount },
            { name: 'CurrencyCode', value: authoritative.currency },
            { name: 'GoodsName', value: authoritative.goods_name },
            { name: 'PayMethod', value: authoritative.pay_method },
            { name: 'MID', value: authoritative.mid },
            { name: 'ReturnURL', value: returnUrl },
            { name: 'CharSet', value: authoritative.charset || 'utf-8' }
        ];
        values.forEach(function(entry) {
            const input = standaloneField(form, entry.name);
            if (input) input.value = entry.value;
        });
        form.action = returnUrl;

        var goodsClass = form.querySelector('[data-nicepay-goods-class]');
        if (authoritative.pay_method === 'CELLPHONE') {
            goodsClass.name = 'GoodsCl';
            goodsClass.value = authoritative.goods_class;
        } else {
            goodsClass.removeAttribute('name');
            goodsClass.value = '';
        }
        return true;
    }

    function requestStandaloneInitialization(form, wrapper, nonce, wasRetried) {
        var ajaxUrl = sameOriginHttpsUrl(paymentConfig.ajaxUrl);
        var methodInput = standaloneField(form, 'PayMethod');
        if (!ajaxUrl || !methodInput) {
            releaseStandalone(form);
            showStandaloneError(wrapper, form, translated('systemUnavailable', 'Payment system is currently unavailable.'));
            return;
        }

        var body = 'action=nicepay_init_payment' +
            '&nonce=' + encodeURIComponent(nonce) +
            '&config_id=' + encodeURIComponent(form.getAttribute('data-nicepay-config-id') || '') +
            '&pay_method=' + encodeURIComponent(methodInput.value) +
            '&buyer_name=' + encodeURIComponent(standaloneField(form, 'BuyerName').value) +
            '&buyer_email=' + encodeURIComponent(standaloneField(form, 'BuyerEmail').value) +
            '&buyer_tel=' + encodeURIComponent(standaloneField(form, 'BuyerTel').value);
        var request = new XMLHttpRequest();
        request.open('POST', ajaxUrl);
        request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        request.onerror = function() {
            releaseStandalone(form);
            showStandaloneError(wrapper, form, translated('connectionError', 'Connection error. Please check your internet.'));
        };
        request.onload = function() {
            if (request.status === 403 && !wasRetried) {
                const refresh = new XMLHttpRequest();
                refresh.open('POST', ajaxUrl);
                refresh.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                refresh.onerror = function() {
                    releaseStandalone(form);
                    showStandaloneError(wrapper, form, translated('connectionError', 'Connection error. Please check your internet.'));
                };
                refresh.onload = function() {
                    try {
                        const nonceResponse = JSON.parse(refresh.responseText);
                        if (refresh.status >= 200 && refresh.status < 300 && nonceResponse.success && nonceResponse.data && nonceResponse.data.nonce) {
                            form.setAttribute('data-nicepay-init-nonce', nonceResponse.data.nonce);
                            requestStandaloneInitialization(form, wrapper, nonceResponse.data.nonce, true);
                            return;
                        }
                    } catch {}
                    releaseStandalone(form);
                    showStandaloneError(wrapper, form, translated('sessionExpired', 'Payment session expired. Please refresh the page and try again.'));
                };
                refresh.send('action=nicepay_refresh_nonce&_=' + encodeURIComponent(Date.now()));
                return;
            }

            try {
                const response = JSON.parse(request.responseText);
                if (request.status >= 200 && request.status < 300 && response.success && populateStandaloneForm(form, response.data || {})) {
                    if (typeof window.nicepayStart !== 'function') {
                        throw new Error('NicePay library unavailable');
                    }
                    window.nicepayStart();
                    return;
                }

                releaseStandalone(form);
                showStandaloneError(
                    wrapper,
                    form,
                    response.data && response.data.message
                        ? response.data.message
                        : translated('initializationFailed', 'Payment initialization failed.')
                );
            } catch {
                releaseStandalone(form);
                showStandaloneError(wrapper, form, translated('unexpectedError', 'An unexpected error occurred.'));
            }
        };
        request.send(body);
    }

    function startStandalone(formId) {
        var form = document.getElementById(formId);
        var wrapper = document.getElementById(formId + '-wrapper');
        if (!form || !wrapper || !form.classList.contains('nicepay-standalone-form')) return;

        if (activePaymentForm && activePaymentForm !== form) {
            showStandaloneError(wrapper, form, translated('paymentInProgress', 'Another payment is already in progress.'));
            return;
        }
        if (!validateStandaloneBuyerFields(wrapper)) return;

        wrapper.querySelectorAll('[data-field]').forEach(function(visible) {
            const fieldName = visible.getAttribute('data-field');
            let target = null;
            if (fieldName === 'BuyerName') target = standaloneField(form, 'BuyerName');
            if (fieldName === 'BuyerEmail') target = standaloneField(form, 'BuyerEmail');
            if (fieldName === 'BuyerTel') target = standaloneField(form, 'BuyerTel');
            if (target) target.value = visible.value.trim();
        });
        const checkedMethod = wrapper.querySelector('.nicepay-methods input[type="radio"]:checked');
        if (checkedMethod) standaloneField(form, 'PayMethod').value = checkedMethod.value;

        activePaymentForm = form;
        document.querySelectorAll('.nicepay-standalone-form[name="payForm"]').forEach(function(otherForm) {
            otherForm.removeAttribute('name');
        });
        form.setAttribute('name', 'payForm');
        setStandaloneLoading(form, true);
        requestStandaloneInitialization(
            form,
            wrapper,
            form.getAttribute('data-nicepay-init-nonce') ||
                paymentConfig.initNonce,
            false
        );
    }

    function openModal(formId) {
        var modal = document.getElementById(formId + '-modal');
        if (!modal) return;
        var state = {
            trigger: document.activeElement,
            bodyOverflow: document.body.style.overflow
        };
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        var keyHandler = function(event) {
            if (event.key === 'Escape') {
                closeModal(formId);
                return;
            }
            if (event.key !== 'Tab') return;
            const focusable = modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]');
            if (!focusable.length) {
                event.preventDefault();
                modal.querySelector('.nicepay-payment-modal').focus();
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        state.keyHandler = keyHandler;
        modalState.set(formId, state);
        document.addEventListener('keydown', keyHandler);
        var firstControl = modal.querySelector('button, input, select, textarea, a[href]');
        (firstControl || modal.querySelector('.nicepay-payment-modal')).focus();
    }

    function closeModal(formId) {
        var modal = document.getElementById(formId + '-modal');
        var state = modalState.get(formId);
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = state ? state.bodyOverflow : '';
        if (state) {
            document.removeEventListener('keydown', state.keyHandler);
            if (state.trigger && document.contains(state.trigger)) state.trigger.focus();
            modalState.delete(formId);
        }
    }

    // Expose for NicePay callbacks
    window.NicePayHandler = NicePayHandler;

    $(document).ready(function() {
        NicePayHandler.init();
    });

    document.addEventListener('change', function(event) {
        if (!event.target.matches('input[type="radio"][name^="nicepay_method_"]')) return;
        var group = event.target.closest('.nicepay-methods');
        if (!group) return;
        group.querySelectorAll('.nicepay-method-option').forEach(function(option) {
            option.classList.remove('is-selected');
        });
        event.target.closest('.nicepay-method-option').classList.add('is-selected');
    });

    document.addEventListener('click', function(event) {
        var startButton = event.target.closest('[data-nicepay-start]');
        if (startButton) {
            event.preventDefault();
            startStandalone(startButton.getAttribute('data-nicepay-start'));
            return;
        }
        var openButton = event.target.closest('[data-nicepay-open-modal]');
        if (openButton) {
            openModal(openButton.getAttribute('data-nicepay-open-modal'));
            return;
        }
        var closeButton = event.target.closest('[data-nicepay-close-modal]');
        if (closeButton) {
            closeModal(closeButton.getAttribute('data-nicepay-close-modal'));
            return;
        }
        var modal = event.target.classList.contains('nicepay-payment-modal-overlay') ? event.target : null;
        if (modal) closeModal(modal.id.replace(/-modal$/, ''));
    });

    window.nicepaySubmit = function() {
        var form = activePaymentForm || document.payForm;
        if (form && typeof form.submit === 'function') form.submit();
    };

    window.nicepayClose = function() {
        var form = activePaymentForm || document.payForm;
        if (form && form.classList && form.classList.contains('nicepay-standalone-form')) {
            releaseStandalone(form);
            return;
        }
        var wrapper = form ? form.closest('.nicepay-payment-wrapper') : null;
        var checkoutUrl = wrapper ? wrapper.getAttribute('data-nicepay-checkout-url') : '';
        NicePayHandler.hideLoading();
        checkoutUrl = sameOriginHttpsUrl(checkoutUrl);
        if (checkoutUrl) window.location.assign(checkoutUrl);
    };

})(jQuery);
