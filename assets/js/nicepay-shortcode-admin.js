/**
 * NicePay shortcode generator.
 *
 * This file intentionally owns only the shortcode-generator page. Dynamic
 * values are supplied by the localized `nicepayShortcodeAdmin` object so the
 * generator itself remains cacheable and versioned with the plugin.
 */
(function($) {
    'use strict';

    var adminConfig = window.nicepayAdmin && typeof window.nicepayAdmin === 'object'
        ? window.nicepayAdmin
        : { ajaxUrl: '', shortcodeNonce: '' };

    function getConfig() {
        return typeof window.nicepayShortcodeAdmin !== 'undefined' ? window.nicepayShortcodeAdmin : null;
    }

    function text(config, key, fallback) {
        if (!config.i18n || typeof config.i18n !== 'object') {
            return fallback;
        }

        switch (key) {
            case 'referenceShortcode': return config.i18n.referenceShortcode || fallback;
            case 'payNow': return config.i18n.payNow || fallback;
            case 'productName': return config.i18n.productName || fallback;
            case 'amountRequired': return config.i18n.amountRequired || fallback;
            case 'productNameRequired': return config.i18n.productNameRequired || fallback;
            case 'copy': return config.i18n.copy || fallback;
            case 'copied': return config.i18n.copied || fallback;
            case 'copyFailed': return config.i18n.copyFailed || fallback;
            case 'saving': return config.i18n.saving || fallback;
            case 'saveShortcode': return config.i18n.saveShortcode || fallback;
            case 'updateShortcode': return config.i18n.updateShortcode || fallback;
            case 'saved': return config.i18n.saved || fallback;
            case 'error': return config.i18n.error || fallback;
            case 'requestFailed': return config.i18n.requestFailed || fallback;
            default: return fallback;
        }
    }

    function restoreSaveButton(button, config, editId) {
        button.prop('disabled', false).text(editId ? text(config, 'updateShortcode', 'Update Shortcode') : text(config, 'saveShortcode', 'Save Shortcode'));
    }

    function showToast(message, type) {
        if (typeof window.NicePayToast !== 'undefined') {
            window.NicePayToast.show(message, type);
        }
    }

    function sameOriginUrl(value) {
        try {
            const url = new URL(String(value || ''), window.location.href);
            return url.origin === window.location.origin ? url.href : '';
        } catch {
            return '';
        }
    }

    $(function() {
        var config = getConfig();
        var builder = $('.nicepay-sc-builder');
        if (!config || !builder.length) {
            return;
        }

        var defaultColor = '#2563eb';
        var fields = {
            name: '#sc-name',
            displayMode: 'input[name="sc-display-mode"]:checked',
            amount: '#sc-amount',
            goodsName: '#sc-goods-name',
            goodsClass: '#sc-goods-class',
            payMethod: 'input[name="sc-pay-method"]:checked',
            buyerName: '#sc-buyer-name',
            buyerEmail: '#sc-buyer-email',
            buyerTel: '#sc-buyer-tel',
            buttonText: '#sc-button-text',
            buttonClass: '#sc-button-class',
            buttonColor: '#sc-button-color',
            currency: '#sc-currency',
            language: '#sc-language'
        };
        var editId = String(builder.data('edit-id') || config.editId || '');

        function selectedValue(selector) {
            return $(selector).val() || '';
        }

        function inputValue(selector) {
            return String($(selector).val() || '').trim();
        }

        function contrastColor(hexColor) {
            var match = /^#([0-9a-f]{6})$/i.exec(String(hexColor || ''));
            if (!match) {
                return '#ffffff';
            }
            var components = match[1].match(/.{2}/g).map(function(component) {
                var value = parseInt(component, 16) / 255;
                return value <= 0.04045 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
            });
            var luminance = (0.2126 * components[0]) + (0.7152 * components[1]) + (0.0722 * components[2]);
            var whiteContrast = 1.05 / (luminance + 0.05);
            var darkContrast = (luminance + 0.05) / 0.0599;
            return darkContrast > whiteContrast ? '#111827' : '#ffffff';
        }

        function buildShortcode() {
            return editId ? '[nicepay_payment id="' + editId + '"]' : text(config, 'referenceShortcode', 'Save the configuration to generate its reference shortcode.');
        }

        function renderMethodOptions() {
            var container = $('#sc-pv-method-options').empty();
            (config.enabledMethods || []).forEach(function(method) {
                var optionNode = document.createElement('div');
                var option = $(optionNode);
                option.addClass('nicepay-sc-pv-method-option');
                optionNode.textContent = String(method && method.label ? method.label : '');
                container.append(option);
            });
        }

        function updatePreview() {
            var amount = inputValue(fields.amount);
            var currency = selectedValue(fields.currency) || 'KRW';
            var goodsName = inputValue(fields.goodsName);
            var payMethod = selectedValue(fields.payMethod);
            var buttonText = inputValue(fields.buttonText) || text(config, 'payNow', 'Pay Now');
            var buttonColor = selectedValue(fields.buttonColor) || defaultColor;
            var numericAmount;

            $('#sc-output-code').text(buildShortcode());
            $('#sc-pv-product').text(goodsName || text(config, 'productName', 'Product Name'));

            if (amount) {
                numericAmount = currency === 'KRW' ? parseInt(amount, 10) : parseFloat(amount);
                $('#sc-pv-amount').text(Number.isFinite(numericAmount)
                    ? (currency === 'KRW' ? numericAmount.toLocaleString() : numericAmount.toFixed(2)) + ' ' + currency
                    : '0 ' + currency);
            } else {
                $('#sc-pv-amount').text('0 ' + currency);
            }

            if (!payMethod) {
                renderMethodOptions();
                $('#sc-pv-methods').show();
            } else {
                $('#sc-pv-methods').hide();
            }

            $('#sc-pv-fields').toggle(!(inputValue(fields.buyerName) && inputValue(fields.buyerEmail) && inputValue(fields.buyerTel)));
            $('#sc-preview-btn').text(buttonText).css({
                '--nicepay-button-background': buttonColor,
                '--nicepay-button-text': contrastColor(buttonColor)
            });

            var messages = [];
            if (!amount) {
                messages.push(text(config, 'amountRequired', 'Amount is required'));
            }
            if (!goodsName) {
                messages.push(text(config, 'productNameRequired', 'Product name is required'));
            }

            $('#sc-validation').toggle(messages.length > 0).find('#sc-validation-msg').text(messages.join(', '));
            $('#sc-copy-btn').prop('disabled', messages.length > 0 || !editId);
        }

        function prepopulate(data) {
            if (!data || typeof data !== 'object') {
                return;
            }

            var values = [
                { value: data.name, selector: fields.name },
                { value: data.amount, selector: fields.amount },
                { value: data.goods_name, selector: fields.goodsName },
                { value: data.goods_class, selector: fields.goodsClass },
                { value: data.buyer_name, selector: fields.buyerName },
                { value: data.buyer_email, selector: fields.buyerEmail },
                { value: data.buyer_tel, selector: fields.buyerTel },
                { value: data.button_text, selector: fields.buttonText },
                { value: data.button_class, selector: fields.buttonClass },
                { value: data.currency, selector: fields.currency },
                { value: data.language, selector: fields.language }
            ];

            values.forEach(function(entry) {
                if (entry.value !== undefined && entry.value !== null && entry.value !== '') {
                    $(entry.selector).val(entry.value);
                }
            });
            if (data.button_color) {
                $(fields.buttonColor).val(data.button_color).trigger('input');
            }
            if (data.display_mode) {
                $('input[name="sc-display-mode"]').each(function() {
                    if (this.value === data.display_mode) {
                        $(this).prop('checked', true).trigger('change');
                    }
                });
            }
            if (data.pay_method) {
                $('input[name="sc-pay-method"]').each(function() {
                    if (this.value === data.pay_method) {
                        $(this).prop('checked', true).trigger('change');
                    }
                });
            }
        }

        builder.on('input change', 'input, select', updatePreview);

        $('.nicepay-sc-color-swatch').on('click', function() {
            var color = $(this).data('color');
            $(fields.buttonColor).val(color).trigger('input');
            $('.nicepay-sc-color-swatch').removeClass('is-active');
            $(this).addClass('is-active');
        });

        $(fields.buttonColor).on('input', function() {
            var value = String($(this).val() || '').toLowerCase();
            $('.nicepay-sc-color-swatch').each(function() {
                $(this).toggleClass('is-active', $(this).data('color') === value);
            });
        });

        $('input[name="sc-pay-method"]').on('change', function() {
            $('.nicepay-sc-method-chip').removeClass('is-active');
            $(this).closest('.nicepay-sc-method-chip').addClass('is-active');
            updatePreview();
        });

        $('input[name="sc-display-mode"]').on('change', function() {
            $('.nicepay-sc-display-mode').removeClass('is-active');
            $(this).closest('.nicepay-sc-display-mode').addClass('is-active');
            updatePreview();
        });

        $('#sc-copy-btn').on('click', function() {
            var button = $(this);
            var code = $('#sc-output-code').text();
            if (!navigator.clipboard || !navigator.clipboard.writeText) {
                showToast(text(config, 'copyFailed', 'Copy failed'), 'error');
                return;
            }
            navigator.clipboard.writeText(code).then(function() {
                button.find('span').text(text(config, 'copied', 'Copied!'));
                button.addClass('is-copied');
                setTimeout(function() {
                    button.find('span').text(text(config, 'copy', 'Copy'));
                    button.removeClass('is-copied');
                }, 2000);
            }).catch(function() {
                showToast(text(config, 'copyFailed', 'Copy failed'), 'error');
            });
        });

        $('#sc-save-btn').on('click', function() {
            var name = inputValue(fields.name);
            var button = $(this);
            if (!name) {
                $(fields.name).addClass('is-invalid').attr('aria-invalid', 'true').focus();
                return;
            }
            $(fields.name).removeClass('is-invalid').removeAttr('aria-invalid');

            button.prop('disabled', true).text(text(config, 'saving', 'Saving...'));
            $.post(adminConfig.ajaxUrl, {
                action: 'nicepay_save_shortcode',
                nonce: adminConfig.shortcodeNonce,
                edit_id: editId,
                name: name,
                display_mode: selectedValue(fields.displayMode) || 'inline',
                amount: inputValue(fields.amount),
                goods_name: inputValue(fields.goodsName),
                goods_class: selectedValue(fields.goodsClass),
                pay_method: selectedValue(fields.payMethod),
                buyer_name: inputValue(fields.buyerName),
                buyer_email: inputValue(fields.buyerEmail),
                buyer_tel: inputValue(fields.buyerTel),
                button_text: inputValue(fields.buttonText),
                button_class: inputValue(fields.buttonClass),
                button_color: selectedValue(fields.buttonColor),
                currency: selectedValue(fields.currency),
                language: selectedValue(fields.language)
            }, function(response) {
                if (response && response.success) {
                    showToast(response.data && response.data.message ? response.data.message : text(config, 'saved', 'Saved.'), 'success');
                    setTimeout(function() {
                        const redirectUrl = sameOriginUrl(config.redirectUrl);
                        if (redirectUrl) {
                            window.location.assign(redirectUrl);
                        }
                    }, 800);
                    return;
                }
                restoreSaveButton(button, config, editId);
                showToast(response && response.data && response.data.message ? response.data.message : text(config, 'error', 'Error'), 'error');
            }).fail(function() {
                restoreSaveButton(button, config, editId);
                showToast(text(config, 'requestFailed', 'Request failed. Please try again.'), 'error');
            });
        });

        prepopulate(config.editData);
        updatePreview();
    });
})(jQuery);
