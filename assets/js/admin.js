/**
 * ATD Integration Admin JavaScript
 *
 * Handles AJAX order placement with real-time feedback
 */

jQuery(document).ready(function($) {
    'use strict';

    /**
     * Generic AJAX handler for ATD operations
     */
    function handleATDAjax(config) {
        const {
            $button,
            $controls,
            $status,
            data,
            validation,
            timeout = 30000,
            timeoutMessage = 'Request timed out. Please try again.',
            loadingHandler,
            successHandler,
            errorHandler,
            resetHandler
        } = config;

        // Validate required data
        if (validation && !validation()) {
            errorHandler('Missing required data');
            return;
        }

        // Set loading state
        loadingHandler($button, $status);

        // Make AJAX request
        $.ajax({
            url: atdAdmin.ajaxUrl,
            type: 'GET',
            data: data,
            timeout: timeout,
            success: function(response) {
                if (response.success) {
                    successHandler($button, $status, response.data);
                } else {
                    errorHandler(response.data || atdAdmin.messages.error);
                    resetHandler($button);
                }
            },
            error: function(xhr, status, error) {
                let errorMessage = atdAdmin.messages.error;

                if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage = xhr.responseJSON.data;
                } else if (status === 'timeout') {
                    errorMessage = timeoutMessage;
                } else if (status === 'abort') {
                    errorMessage = 'Request was cancelled.';
                } else if (xhr.status) {
                    errorMessage = `Error ${xhr.status}: ${error}`;
                }

                errorHandler(errorMessage);
                resetHandler($button);
            }
        });
    }

    /**
     * Handle ATD inventory scrubber button clicks
     */
    $(document).on('click', '.atd-scrubber-button[data-action="scrub-inventory"]', function(e) {
        e.preventDefault();

        const $button = $(this);
        const $controls = $button.closest('.atd-scrubber-controls');
        const $status = $controls.find('.atd-scrubber-status');

        handleATDAjax({
            $button,
            $controls,
            $status,
            data: {
                action: 'atd_inventory_scrubber',
                type: $controls.data('type'),
                p: $controls.data('page'),
                _wpnonce: atdAdmin.scrubberNonce
            },
            validation: () => $controls.data('type') && $controls.data('page'),
            timeout: 300000, // 5 minute timeout for long operations
            timeoutMessage: atdAdmin.messages.scrubberTimeout,
            loadingHandler: ($button, $status) => {
                $button
                    .prop('disabled', true)
                    .addClass('atd-loading')
                    .text('Processing...');

                $status
                    .removeClass('atd-success atd-error')
                    .addClass('atd-loading')
                    .html('<span class="spinner is-active"></span> ' + atdAdmin.messages.scrubberProcessing)
                    .show();
            },
            successHandler: ($button, $status, data) => {
                // Hide button and show success status
                $button.hide();

                let message = atdAdmin.messages.scrubberSuccess;
                let details = '';

                if (data.processed_count) {
                    details += 'Processed <strong>' + escapeHtml(String(data.processed_count)) + '</strong> products. ';
                }

                if (data.out_of_stock_count && data.out_of_stock_count > 0) {
                    details += '<strong>' + escapeHtml(String(data.out_of_stock_count)) + '</strong> marked out-of-stock.';
                } else {
                    details += 'All products are available in ATD database.';
                }

                // Show warning if there were API errors
                if (data.api_error_count && data.api_error_count > 0) {
                    details += '<br><span style="color: #d63638;">⚠️ <strong>' + escapeHtml(String(data.api_error_count)) + '</strong> API errors occurred. Check error logs for details.</span>';
                }

                $status
                    .removeClass('atd-loading atd-error')
                    .addClass('atd-success')
                    .html(
                        '<span class="dashicons dashicons-yes-alt"></span> ' + message +
                        '<div class="atd-scrubber-details">' + details + '</div>'
                    )
                    .show();
            },
            errorHandler: (message) => {
                $status
                    .removeClass('atd-loading atd-success')
                    .addClass('atd-error')
                    .html('<span class="dashicons dashicons-warning"></span> ' + escapeHtml(message))
                    .show();
            },
            resetHandler: ($button) => {
                const originalText = $button.data('original-text') || 'Scrub Inventory';

                $button
                    .prop('disabled', false)
                    .removeClass('atd-loading')
                    .text(originalText)
                    .show();
            }
        });
    });

    /**
     * Handle ATD order placement button clicks
     */
    $(document).on('click', '.atd-order-button[data-action="place-order"]', function(e) {
        e.preventDefault();

        const $button = $(this);
        const $controls = $button.closest('.atd-order-controls');
        const $status = $controls.find('.atd-order-status');

        handleATDAjax({
            $button,
            $controls,
            $status,
            data: {
                action: 'atd_order',
                order_id: $controls.data('order-id'),
                product_id: $controls.data('product-id'),
                order_item_id: $controls.data('order-item-id'),
                _ajax_nonce: atdAdmin.nonce
            },
            validation: () => $controls.data('order-id') && $controls.data('product-id') && $controls.data('order-item-id'),
            timeout: 30000, // 30 second timeout
            timeoutMessage: 'Request timed out. Please try again.',
            loadingHandler: ($button, $status) => {
                $button
                    .prop('disabled', true)
                    .addClass('atd-loading')
                    .text('Placing Order...');

                $status
                    .removeClass('atd-success atd-error')
                    .addClass('atd-loading')
                    .html('<span class="spinner is-active"></span> ' + atdAdmin.messages.placing)
                    .show();
            },
            successHandler: ($button, $status, data) => {
                // Hide button and show success status
                $button.hide();

                $status
                    .removeClass('atd-loading atd-error')
                    .addClass('atd-success')
                    .html(
                        '<span class="dashicons dashicons-yes-alt"></span> ' +
                        atdAdmin.messages.success +
                        (data.atd_order_id ? ' (ID: ' + escapeHtml(data.atd_order_id) + ')' : '')
                    )
                    .show();

                // If there's a redirect URL, show a link
                if (data.redirect_url) {
                    setTimeout(function() {
                        $status.append(' <a href="' + escapeHtml(data.redirect_url) + '">Refresh page</a>');
                    }, 1000);
                }
            },
            errorHandler: (message) => {
                $status
                    .removeClass('atd-loading atd-success')
                    .addClass('atd-error')
                    .html('<span class="dashicons dashicons-warning"></span> ' + escapeHtml(message))
                    .show();
            },
            resetHandler: ($button) => {
                $button
                    .prop('disabled', false)
                    .removeClass('atd-loading')
                    .text('Order via ATD');
            }
        });
    });

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

});