/**
 * DD Inventory - Admin Scripts
 */

(function($) {
    'use strict';

    var DDI_Admin = {

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Test connection
            $(document).on('click', '.ddi-test-connection', this.testConnection);

            // Register webhooks
            $(document).on('click', '.ddi-register-webhooks', this.registerWebhooks);

            // Copy to clipboard
            $(document).on('click', '.ddi-copy-btn', this.copyToClipboard);
        },

        /**
         * Test connection to webhook endpoint
         */
        testConnection: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var originalText = $btn.text();

            $btn.text(ddi_admin.strings.testing).prop('disabled', true);

            $.ajax({
                url: ddi_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'ddi_test_connection',
                    nonce: ddi_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        DDI_Admin.showToast(ddi_admin.strings.success, 'success');
                    } else {
                        DDI_Admin.showToast(ddi_admin.strings.error + response.data.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    DDI_Admin.showToast(ddi_admin.strings.error + error, 'error');
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Register webhooks
         */
        registerWebhooks: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var originalText = $btn.text();

            $btn.text(ddi_admin.strings.registering).prop('disabled', true);

            $.ajax({
                url: ddi_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'ddi_register_webhooks',
                    nonce: ddi_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        DDI_Admin.showToast(response.data.message, 'success');
                        // Refresh the page to update webhook count
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        DDI_Admin.showToast(ddi_admin.strings.error + response.data.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    DDI_Admin.showToast(ddi_admin.strings.error + error, 'error');
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Copy text to clipboard
         */
        copyToClipboard: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var targetId = $btn.data('target');
            var $target = $('#' + targetId);
            var text = $target.text();

            // Use modern clipboard API if available
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    DDI_Admin.showCopyFeedback($btn);
                });
            } else {
                // Fallback for older browsers
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
                DDI_Admin.showCopyFeedback($btn);
            }
        },

        /**
         * Show copy feedback on button
         */
        showCopyFeedback: function($btn) {
            var originalText = $btn.text();
            $btn.text('Copied!').addClass('button-primary');

            setTimeout(function() {
                $btn.text(originalText).removeClass('button-primary');
            }, 1500);
        },

        /**
         * Show toast notification
         */
        showToast: function(message, type) {
            type = type || 'info';

            // Remove existing toasts
            $('.ddi-toast').remove();

            var $toast = $('<div class="ddi-toast ' + type + '">' + message + '</div>');
            $('body').append($toast);

            // Auto-remove after 4 seconds
            setTimeout(function() {
                $toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 4000);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        DDI_Admin.init();
    });

})(jQuery);
