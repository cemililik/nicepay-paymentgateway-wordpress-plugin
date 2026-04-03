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

    /* ==========================================
       Modal Dialog
       ========================================== */
    var NicePayModal = {
        overlay: null,

        open: function(options) {
            var self = this;
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

            var html = '<div class="nicepay-modal-overlay is-active" id="nicepay-modal">' +
                '<div class="nicepay-modal" role="dialog" aria-modal="true" aria-labelledby="nicepay-modal-title">' +
                    '<div class="nicepay-modal-header">' +
                        '<h3 class="nicepay-modal-title" id="nicepay-modal-title">' + $('<span>').text(opts.title).html() + '</h3>' +
                    '</div>' +
                    '<div class="nicepay-modal-body">' +
                        (opts.message ? '<p>' + $('<span>').text(opts.message).html() + '</p>' : '') +
                        (opts.inputLabel ? '<label style="display:block;font-size:13px;font-weight:500;margin-bottom:6px;color:#374151;">' + $('<span>').text(opts.inputLabel).html() + '</label>' : '') +
                        '<input type="text" id="nicepay-modal-input" placeholder="' + $('<span>').text(opts.inputPlaceholder).html() + '" autocomplete="off">' +
                    '</div>' +
                    '<div class="nicepay-modal-footer">' +
                        '<button type="button" class="nicepay-modal-btn nicepay-modal-btn-secondary" id="nicepay-modal-cancel">' + $('<span>').text(opts.cancelText).html() + '</button>' +
                        '<button type="button" class="nicepay-modal-btn ' + opts.confirmClass + '" id="nicepay-modal-confirm">' + $('<span>').text(opts.confirmText).html() + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

            $('body').append(html);
            this.overlay = $('#nicepay-modal');

            // Focus input
            setTimeout(function() {
                $('#nicepay-modal-input').focus();
            }, 100);

            // Close handlers
            $('#nicepay-modal-cancel').on('click', function() { self.close(); });
            this.overlay.on('click', function(e) {
                if ($(e.target).is('.nicepay-modal-overlay')) self.close();
            });

            // Escape key
            $(document).on('keydown.nicepayModal', function(e) {
                if (e.key === 'Escape') self.close();
            });

            // Confirm
            $('#nicepay-modal-confirm').on('click', function() {
                var value = $('#nicepay-modal-input').val();
                opts.onConfirm(value, self);
            });

            // Enter key in input
            $('#nicepay-modal-input').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#nicepay-modal-confirm').click();
                }
            });
        },

        setLoading: function(loading) {
            $('#nicepay-modal-confirm').prop('disabled', loading);
            $('#nicepay-modal-cancel').prop('disabled', loading);
        },

        close: function() {
            $(document).off('keydown.nicepayModal');
            if (this.overlay) {
                this.overlay.remove();
                this.overlay = null;
            }
            $('#nicepay-modal').remove();
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
                    $('#nicepay-modal-input').css('border-color', '#dc2626').focus();
                    return;
                }

                modal.setLoading(true);

                $.post(nicepayAdmin.ajaxUrl, {
                    action: 'nicepay_cancel_transaction',
                    tid: tid,
                    id: id,
                    reason: reason.trim(),
                    nonce: nonce
                }, function(response) {
                    modal.close();
                    if (response.success) {
                        NicePayToast.show(response.data.message, 'success');
                        // Update row status badge in place
                        btn.closest('tr').find('.nicepay-status')
                            .removeClass('nicepay-status-paid nicepay-status-waiting')
                            .addClass('nicepay-status-cancelled')
                            .text(nicepayAdmin.i18n.statusCancelled || 'CANCELLED');
                        btn.remove();
                    } else {
                        NicePayToast.show(response.data.message || nicepayAdmin.i18n.cancelFailed, 'error');
                    }
                }).fail(function() {
                    modal.close();
                    NicePayToast.show(nicepayAdmin.i18n.requestFailed || 'Request failed.', 'error');
                });
            }
        });
    });

    /* ==========================================
       Copy to Clipboard
       ========================================== */
    $(document).on('click', '.nicepay-copy-btn', function() {
        var btn = $(this);
        var text = btn.data('copy');

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                btn.addClass('is-copied');
                NicePayToast.show(nicepayAdmin.i18n.copied || 'Copied!', 'success', 2000);
                setTimeout(function() { btn.removeClass('is-copied'); }, 2000);
            });
        } else {
            // Fallback
            var temp = $('<input>').val(text).appendTo('body').select();
            document.execCommand('copy');
            temp.remove();
            btn.addClass('is-copied');
            setTimeout(function() { btn.removeClass('is-copied'); }, 2000);
        }
    });

})(jQuery);
