/**
 * NicePay Payment Gateway - Admin JavaScript
 *
 * Modal dialogs, toast notifications, cancel flow, copy-to-clipboard.
 */
(function($) {
    'use strict';

    /* ==========================================
       Toast Notifications
       ========================================== */
    var NicePayToast = {
        container: null,

        init: function() {
            if (!this.container) {
                this.container = $('<div class="nicepay-toast-container" id="nicepay-toast-root"></div>');
                $('body').append(this.container);
            }
        },

        show: function(message, type, duration) {
            this.init();
            type = type || 'info';
            duration = duration || 4000;

            var icons = {
                success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>',
                error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>',
                info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>'
            };

            var toast = $('<div class="nicepay-toast nicepay-toast-' + type + '" role="status" aria-live="polite"></div>');
            toast.html((icons[type] || '') + '<span>' + $('<span>').text(message).html() + '</span>');

            this.container.append(toast);

            setTimeout(function() {
                toast.addClass('is-leaving');
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
                confirmText: nicepayAdmin.i18n.confirm || 'Confirm',
                cancelText: nicepayAdmin.i18n.cancel || 'Cancel',
                confirmClass: 'nicepay-modal-btn-danger',
                onConfirm: function() {}
            }, options);

            this.close();
            self.lastFocus = document.activeElement;

            var html = '<div class="nicepay-modal-overlay is-active" id="nicepay-modal">' +
                '<div class="nicepay-modal" role="dialog" aria-modal="true" aria-labelledby="nicepay-modal-title" aria-busy="false">' +
                    '<div class="nicepay-modal-header">' +
                        '<h3 class="nicepay-modal-title" id="nicepay-modal-title">' + $('<span>').text(opts.title).html() + '</h3>' +
                    '</div>' +
                    '<div class="nicepay-modal-body">' +
                        (opts.message ? '<p>' + $('<span>').text(opts.message).html() + '</p>' : '') +
                        (opts.inputLabel ? '<label for="nicepay-modal-input" style="display:block;font-size:13px;font-weight:500;margin-bottom:6px;color:#374151;">' + $('<span>').text(opts.inputLabel).html() + '</label>' : '') +
                        (opts.inputLabel ? '<input type="text" id="nicepay-modal-input" placeholder="' + $('<span>').text(opts.inputPlaceholder).html() + '" autocomplete="off" aria-describedby="nicepay-modal-error">' : '') +
                        '<p class="nicepay-modal-error" id="nicepay-modal-error" role="alert" aria-live="assertive" hidden></p>' +
                    '</div>' +
                    '<div class="nicepay-modal-footer">' +
                        '<button type="button" class="nicepay-modal-btn nicepay-modal-btn-secondary" id="nicepay-modal-cancel">' + $('<span>').text(opts.cancelText).html() + '</button>' +
                        '<button type="button" class="nicepay-modal-btn ' + opts.confirmClass + '" id="nicepay-modal-confirm">' + $('<span>').text(opts.confirmText).html() + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

            $('body').append(html);
            this.overlay = $('#nicepay-modal');

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
                    var focusable = self.overlay.find('button:not(:disabled), input:not(:disabled), a[href]').filter(function() {
                        return $(this).attr('aria-hidden') !== 'true';
                    });
                    if (!focusable.length) return;
                    var first = focusable.first()[0];
                    var last = focusable.last()[0];
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
            error.text(message || nicepayAdmin.i18n.cancelFailed || 'Cancellation failed.').prop('hidden', false);
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
            title: nicepayAdmin.i18n.cancelTitle || 'Cancel Transaction',
            message: nicepayAdmin.i18n.cancelMessage || 'This action cannot be undone.',
            inputLabel: nicepayAdmin.i18n.cancelReasonLabel || 'Cancellation reason',
            inputPlaceholder: nicepayAdmin.i18n.cancelReasonPlaceholder || 'Enter reason...',
            confirmText: nicepayAdmin.i18n.cancelConfirm || 'Cancel Transaction',
            onConfirm: function(reason, modal) {
                if (!reason || !reason.trim()) {
                    modal.showError(nicepayAdmin.i18n.cancelReasonRequired || 'A cancellation reason is required.');
                    return;
                }

                modal.clearError();
                modal.setLoading(true);

                $.post(nicepayAdmin.ajaxUrl, {
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
                            .text(nicepayAdmin.i18n.statusRefunded || 'REFUNDED');
                        btn.remove();
                    } else {
                        var message = response && response.data && response.data.message ? response.data.message : nicepayAdmin.i18n.cancelFailed;
                        modal.setLoading(false);
                        modal.showError(message);
                        NicePayToast.show(message, 'error');
                    }
                }).fail(function() {
                    var message = nicepayAdmin.i18n.requestFailed || 'Request failed.';
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
                NicePayToast.show(nicepayAdmin.i18n.copied || 'Copied!', 'success', 2000);
            }).catch(function() {
                NicePayToast.show('Copy failed', 'error', 2000);
            });
        } else {
            var temp = $('<textarea>').val(text).appendTo('body').select();
            var ok = document.execCommand('copy');
            temp.remove();
            NicePayToast.show(ok ? (nicepayAdmin.i18n.copied || 'Copied!') : 'Copy failed', ok ? 'success' : 'error', 2000);
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
            title: nicepayAdmin.i18n.deleteConfirmTitle || 'Delete Shortcode',
            message: nicepayAdmin.i18n.deleteConfirmMsg || 'Are you sure?',
            inputPlaceholder: '',
            confirmText: nicepayAdmin.i18n.delete || 'Delete',
            confirmClass: 'nicepay-modal-btn-danger',
            onConfirm: function(val, modal) {
                modal.setLoading(true);
                $.post(nicepayAdmin.ajaxUrl, {
                    action: 'nicepay_delete_shortcode',
                    id: id,
                    nonce: nonce
                }, function(resp) {
                    modal.close();
                    if (resp.success) {
                        NicePayToast.show(nicepayAdmin.i18n.shortcodeDeleted || 'Deleted.', 'success');
                        $('#sc-card-' + id).fadeOut(300, function() { $(this).remove(); });
                    } else {
                        NicePayToast.show(resp.data.message || 'Error', 'error');
                    }
                }).fail(function() {
                    modal.close();
                    NicePayToast.show(nicepayAdmin.i18n.requestFailed || 'Failed.', 'error');
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
                NicePayToast.show(nicepayAdmin.i18n.copied || 'Copied!', 'success', 2000);
                setTimeout(function() { btn.removeClass('is-copied'); }, 2000);
            }).catch(function() {
                NicePayToast.show('Copy failed', 'error', 2000);
            });
        } else {
            var temp = $('<input>').val(text).appendTo('body').select();
            var ok = document.execCommand('copy');
            temp.remove();
            if (ok) {
                btn.addClass('is-copied');
                NicePayToast.show(nicepayAdmin.i18n.copied || 'Copied!', 'success', 2000);
                setTimeout(function() { btn.removeClass('is-copied'); }, 2000);
            } else {
                NicePayToast.show('Copy failed', 'error', 2000);
            }
        }
    });

})(jQuery);
