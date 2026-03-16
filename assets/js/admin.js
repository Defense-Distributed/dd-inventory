/**
 * DD Inventory - Admin Scripts
 */

(function($) {
    'use strict';

    var DDI_Admin = {

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Connect with token
            $(document).on('click', '#ddi-connect-btn', this.connect);

            // Allow Enter key in token input
            $(document).on('keydown', '#ddi-connection-token', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    DDI_Admin.connect.call($('#ddi-connect-btn')[0], e);
                }
            });

            // Disconnect
            $(document).on('click', '#ddi-disconnect-btn', this.disconnect);

            // Test connection
            $(document).on('click', '.ddi-test-connection', this.testConnection);

            // Register webhooks
            $(document).on('click', '.ddi-register-webhooks', this.registerWebhooks);

            // Copy to clipboard
            $(document).on('click', '.ddi-copy-btn', this.copyToClipboard);
        },

        /**
         * Connect using token
         */
        connect: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $tokenInput = $('#ddi-connection-token');
            var $status = $('#ddi-connect-status');
            var token = $tokenInput.val().trim();

            if (!token) {
                $status.html('<span class="error">Please enter a connection token.</span>').show();
                return;
            }

            if (token.indexOf('ddi_') !== 0) {
                $status.html('<span class="error">Invalid token. Token should start with "ddi_".</span>').show();
                return;
            }

            var originalText = $btn.text();
            $btn.text(ddi_admin.strings.connecting).prop('disabled', true);
            $tokenInput.prop('disabled', true);
            $status.html('<span class="connecting">Connecting to inventory system...</span>').show();

            $.ajax({
                url: ddi_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'ddi_connect',
                    nonce: ddi_admin.nonce,
                    token: token
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<span class="success">Connected! Reloading...</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        $status.html('<span class="error">' + response.data.message + '</span>');
                        $btn.text(originalText).prop('disabled', false);
                        $tokenInput.prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    $status.html('<span class="error">Request failed: ' + error + '</span>');
                    $btn.text(originalText).prop('disabled', false);
                    $tokenInput.prop('disabled', false);
                }
            });
        },

        /**
         * Disconnect from inventory system
         */
        disconnect: function(e) {
            e.preventDefault();

            if (!confirm(ddi_admin.strings.confirm_disconnect)) {
                return;
            }

            var $btn = $(this);
            var originalText = $btn.text();
            $btn.text(ddi_admin.strings.disconnecting).prop('disabled', true);

            $.ajax({
                url: ddi_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'ddi_disconnect',
                    nonce: ddi_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        DDI_Admin.showToast('Disconnected', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        DDI_Admin.showToast(ddi_admin.strings.error + response.data.message, 'error');
                        $btn.text(originalText).prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    DDI_Admin.showToast(ddi_admin.strings.error + error, 'error');
                    $btn.text(originalText).prop('disabled', false);
                }
            });
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

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    DDI_Admin.showCopyFeedback($btn);
                });
            } else {
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
                DDI_Admin.showCopyFeedback($btn);
            }
        },

        showCopyFeedback: function($btn) {
            var originalText = $btn.text();
            $btn.text('Copied!').addClass('button-primary');

            setTimeout(function() {
                $btn.text(originalText).removeClass('button-primary');
            }, 1500);
        },

        showToast: function(message, type) {
            type = type || 'info';
            $('.ddi-toast').remove();

            var $toast = $('<div class="ddi-toast ' + type + '">' + message + '</div>');
            $('body').append($toast);

            setTimeout(function() {
                $toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 4000);
        }
    };

    $(document).ready(function() {
        DDI_Admin.init();
    });

})(jQuery);
