/**
 * NicePay Payment Gateway - Admin JavaScript
 *
 * Modal dialogs, toast notifications, cancel flow, copy-to-clipboard.
 */
(function($) {
    'use strict';

    var adminConfig = window.nicepayAdmin && typeof window.nicepayAdmin === 'object'
        ? window.nicepayAdmin
        : { ajaxUrl: '', i18n: {} };
    var adminI18n = adminConfig.i18n && typeof adminConfig.i18n === 'object'
        ? adminConfig.i18n
        : {};

    function element(tagName, className, text) {
        var node = document.createElement(tagName);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    /* ==========================================
       Toast Notifications
       ========================================== */
    var NicePayToast = {
        container: null,

        init: function() {
            if (!this.container) {
                this.container = element('div', 'nicepay-toast-container');
                this.container.id = 'nicepay-toast-root';
                document.body.appendChild(this.container);
            }
        },

        show: function(message, type, duration) {
            this.init();
            type = type || 'info';
            duration = duration || 4000;

            var safeType = 'info';
            var iconText = 'i';
            if ('success' === type) {
                safeType = 'success';
                iconText = '✓';
            } else if ('error' === type) {
                safeType = 'error';
                iconText = '!';
            }
            var toast = element('div', 'nicepay-toast nicepay-toast-' + safeType);
            var icon = element('span', 'nicepay-toast-icon', iconText);
            var text = element('span', '', message);
            icon.setAttribute('aria-hidden', 'true');
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.appendChild(icon);
            toast.appendChild(text);
            this.container.appendChild(toast);

            setTimeout(function() {
                toast.classList.add('is-leaving');
                setTimeout(function() { toast.remove(); }, 300);
            }, duration);
        }
    };

    // Expose for inline scripts
    window.NicePayToast = NicePayToast;

    /* ==========================================
       Modal Dialog
       ========================================== */
    var NicePayModal = {
        overlay: null,
        isLoading: false,
        lastFocus: null,

        open: function(options) {
            var self = this;
            self.isLoading = false;

            var opts = $.extend({
                title: '',
                message: '',
                inputLabel: '',
                inputPlaceholder: '',
                confirmText: adminI18n.confirm || 'Confirm',
                cancelText: adminI18n.cancel || 'Cancel',
                confirmClass: 'nicepay-modal-btn-danger',
                onConfirm: function() {}
            }, options);

            this.close();
            self.lastFocus = document.activeElement;

            var overlay = element('div', 'nicepay-modal-overlay is-active');
            var dialog = element('div', 'nicepay-modal');
            var header = element('div', 'nicepay-modal-header');
            var title = element('h3', 'nicepay-modal-title', opts.title);
            var body = element('div', 'nicepay-modal-body');
            var footer = element('div', 'nicepay-modal-footer');
            var cancelButton = element('button', 'nicepay-modal-btn nicepay-modal-btn-secondary', opts.cancelText);
            var confirmClass = 'nicepay-modal-btn-danger' === opts.confirmClass ? ' nicepay-modal-btn-danger' : '';
            var confirmButton = element('button', 'nicepay-modal-btn' + confirmClass, opts.confirmText);
            var error = element('p', 'nicepay-modal-error');

            overlay.id = 'nicepay-modal';
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-labelledby', 'nicepay-modal-title');
            dialog.setAttribute('aria-busy', 'false');
            title.id = 'nicepay-modal-title';
            header.appendChild(title);

            if (opts.message) {
                body.appendChild(element('p', '', opts.message));
            }
            if (opts.inputLabel) {
                var label = element('label', 'nicepay-modal-input-label', opts.inputLabel);
                var input = element('input');
                label.setAttribute('for', 'nicepay-modal-input');
                input.type = 'text';
                input.id = 'nicepay-modal-input';
                input.placeholder = String(opts.inputPlaceholder || '');
                input.autocomplete = 'off';
                input.setAttribute('aria-describedby', 'nicepay-modal-error');
                body.appendChild(label);
                body.appendChild(input);
            }

            error.id = 'nicepay-modal-error';
            error.setAttribute('role', 'alert');
            error.setAttribute('aria-live', 'assertive');
            error.hidden = true;
            body.appendChild(error);

            cancelButton.type = 'button';
            cancelButton.id = 'nicepay-modal-cancel';
            confirmButton.type = 'button';
            confirmButton.id = 'nicepay-modal-confirm';
            footer.appendChild(cancelButton);
            footer.appendChild(confirmButton);
            dialog.appendChild(header);
            dialog.appendChild(body);
            dialog.appendChild(footer);
            overlay.appendChild(dialog);
            document.body.appendChild(overlay);
            this.overlay = $(overlay);

            var firstControl = self.overlay.find('#nicepay-modal-input').first();
            if (!firstControl.length) {
                firstControl = self.overlay.find('#nicepay-modal-cancel').first();
            }
            firstControl.focus();

            // Close handlers — guarded by loading state
            $('#nicepay-modal-cancel').on('click', function() {
                if (!self.isLoading) self.close();
            });
            this.overlay.on('click', function(e) {
                if (!self.isLoading && $(e.target).is('.nicepay-modal-overlay')) self.close();
            });
            $(document).on('keydown.nicepayModal', function(e) {
                if (e.key === 'Escape' && !self.isLoading) {
                    e.preventDefault();
                    self.close();
                }
                if (e.key === 'Tab' && self.overlay) {
                    const focusable = self.overlay.find('button:not(:disabled), input:not(:disabled), a[href]').filter(function() {
                        return $(this).attr('aria-hidden') !== 'true';
                    });
                    if (!focusable.length) return;
                    const first = focusable.first()[0];
                    const last = focusable.last()[0];
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            });

            // Confirm
            $('#nicepay-modal-confirm').on('click', function() {
                if (self.isLoading) return;
                var value = $('#nicepay-modal-input').length ? $('#nicepay-modal-input').val() : '';
                opts.onConfirm(value, self);
            });

            // Enter key in input
            $('#nicepay-modal-input').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (!self.isLoading) $('#nicepay-modal-confirm').click();
                }
            });
        },

        setLoading: function(loading) {
            this.isLoading = loading;
            $('#nicepay-modal-confirm').prop('disabled', loading);
            $('#nicepay-modal-cancel').prop('disabled', loading);
            $('#nicepay-modal-input').prop('readonly', loading);
            $('#nicepay-modal .nicepay-modal').attr('aria-busy', loading ? 'true' : 'false');
        },

        showError: function(message) {
            var error = $('#nicepay-modal-error');
            error.text(message || adminI18n.cancelFailed || 'Cancellation failed.').prop('hidden', false);
            $('#nicepay-modal-input').attr('aria-invalid', 'true').css('border-color', '#dc2626').focus();
        },

        clearError: function() {
            $('#nicepay-modal-error').text('').prop('hidden', true);
            $('#nicepay-modal-input').removeAttr('aria-invalid').css('border-color', '');
        },

        close: function() {
            this.isLoading = false;
            $(document).off('keydown.nicepayModal');
            if (this.overlay) {
                this.overlay.remove();
                this.overlay = null;
            }
            $('#nicepay-modal').remove();
            if (this.lastFocus && document.contains(this.lastFocus)) {
                this.lastFocus.focus();
            }
            this.lastFocus = null;
        }
    };

    /* ==========================================
       Cancel Transaction Handler
       ========================================== */
    $(document).on('click', '.nicepay-cancel-btn', function() {
        var btn = $(this);
        var tid = btn.data('tid');
        var id = btn.data('id');
        var nonce = btn.data('nonce');

        NicePayModal.open({
            title: adminI18n.cancelTitle || 'Cancel Transaction',
            message: adminI18n.cancelMessage || 'This action cannot be undone.',
            inputLabel: adminI18n.cancelReasonLabel || 'Cancellation reason',
            inputPlaceholder: adminI18n.cancelReasonPlaceholder || 'Enter reason...',
            confirmText: adminI18n.cancelConfirm || 'Cancel Transaction',
            onConfirm: function(reason, modal) {
                if (!reason || !reason.trim()) {
                    modal.showError(adminI18n.cancelReasonRequired || 'A cancellation reason is required.');
                    return;
                }

                modal.clearError();
                modal.setLoading(true);

                $.post(adminConfig.ajaxUrl, {
                    action: 'nicepay_cancel_transaction',
                    tid: tid,
                    id: id,
                    reason: reason.trim(),
                    nonce: nonce
                }, function(response) {
                    if (response && response.success) {
                        modal.close();
                        NicePayToast.show(response.data.message, 'success');
                        btn.closest('tr').find('.nicepay-status')
                            .removeClass('nicepay-status-paid nicepay-status-partially_refunded')
                            .addClass('nicepay-status-refunded')
                            .text(adminI18n.statusRefunded || 'REFUNDED');
                        btn.remove();
                    } else {
                        const message = response && response.data && response.data.message ? response.data.message : adminI18n.cancelFailed;
                        modal.setLoading(false);
                        modal.showError(message);
                        NicePayToast.show(message, 'error');
                    }
                }).fail(function() {
                    const message = adminI18n.requestFailed || 'Request failed.';
                    modal.setLoading(false);
                    modal.showError(message);
                    NicePayToast.show(message, 'error');
                });
            }
        });
    });

    /* ==========================================
       Shortcode Card: Copy
       ========================================== */
    $(document).on('click', '.nicepay-sc-card-copy', function() {
        var btn = $(this);
        var text = btn.data('shortcode');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                NicePayToast.show(adminI18n.copied || 'Copied!', 'success', 2000);
            }).catch(function() {
                NicePayToast.show('Copy failed', 'error', 2000);
            });
        } else {
            var temp = $(element('textarea')).val(text).appendTo('body').select();
            var ok = document.execCommand('copy');
            temp.remove();
            NicePayToast.show(ok ? (adminI18n.copied || 'Copied!') : 'Copy failed', ok ? 'success' : 'error', 2000);
        }
    });

    /* ==========================================
       Shortcode Card: Delete
       ========================================== */
    $(document).on('click', '.nicepay-sc-card-delete', function() {
        var btn = $(this);
        var id = btn.data('id');
        var nonce = btn.data('nonce');

        NicePayModal.open({
            title: adminI18n.deleteConfirmTitle || 'Delete Shortcode',
            message: adminI18n.deleteConfirmMsg || 'Are you sure?',
            inputPlaceholder: '',
            confirmText: adminI18n.delete || 'Delete',
            confirmClass: 'nicepay-modal-btn-danger',
            onConfirm: function(val, modal) {
                modal.setLoading(true);
                $.post(adminConfig.ajaxUrl, {
                    action: 'nicepay_delete_shortcode',
                    id: id,
                    nonce: nonce
                }, function(resp) {
                    modal.close();
                    if (resp.success) {
                        NicePayToast.show(adminI18n.shortcodeDeleted || 'Deleted.', 'success');
                        $(document.getElementById('sc-card-' + String(id))).fadeOut(300, function() { $(this).remove(); });
                    } else {
                        NicePayToast.show(resp.data.message || 'Error', 'error');
                    }
                }).fail(function() {
                    modal.close();
                    NicePayToast.show(adminI18n.requestFailed || 'Failed.', 'error');
                });
            }
        });
    });

    /* ==========================================
       Copy to Clipboard (generic)
       ========================================== */
    $(document).on('click', '.nicepay-copy-btn', function() {
        var btn = $(this);
        var text = btn.data('copy');

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                btn.addClass('is-copied');
                NicePayToast.show(adminI18n.copied || 'Copied!', 'success', 2000);
                setTimeout(function() { btn.removeClass('is-copied'); }, 2000);
            }).catch(function() {
                NicePayToast.show('Copy failed', 'error', 2000);
            });
        } else {
            var temp = $(element('input')).val(text).appendTo('body').select();
            var ok = document.execCommand('copy');
            temp.remove();
            if (ok) {
                btn.addClass('is-copied');
                NicePayToast.show(adminI18n.copied || 'Copied!', 'success', 2000);
                setTimeout(function() { btn.removeClass('is-copied'); }, 2000);
            } else {
                NicePayToast.show('Copy failed', 'error', 2000);
            }
        }
    });

})(jQuery);
