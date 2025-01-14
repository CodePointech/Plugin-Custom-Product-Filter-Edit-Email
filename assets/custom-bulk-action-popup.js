jQuery(document).ready(function ($) {
    const bulkActionData = window.bulkActionData || {};

    // Listen for the bulk action dropdown change
    $('#bulk-action-selector-top, #bulk-action-selector-bottom').on('change', function () {
        if ($(this).val() === 'out_of_stock_email_sent') {
            // Prevent the default bulk action
            $(this).val('-1');
            $('.button.action').prop('disabled', true);

            // Create and display a popup with default text
            const popup = `
                <div id="bulk-email-popup" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border: 1px solid #ddd; padding: 20px; z-index: 9999; width: 400px;">
                    <h3>Send Out of Stock Email</h3>
                    <textarea id="bulk-email-content" style="width: 100%; height: 100px;">You selected product stock is not available</textarea>
                    <div style="margin-top: 10px; text-align: right;">
                        <button id="send-bulk-email" class="button-primary">Send</button>
                        <button id="close-bulk-email-popup" class="button-secondary">Cancel</button>
                    </div>
                </div>
                <div id="popup-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9998;"></div>
            `;
            $('body').append(popup);

            // Handle popup buttons
            $('#send-bulk-email').on('click', function () {
                const emailContent = $('#bulk-email-content').val();
                const orderIds = $('.check-column input:checked')
                    .map(function () {
                        return $(this).val();
                    })
                    .get();

                if (!emailContent) {
                    alert('Please enter email content.');
                    return;
                }

                if (orderIds.length === 0) {
                    alert('No orders selected.');
                    return;
                }

                // Send AJAX request
                $.ajax({
                    url: bulkActionData.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'send_bulk_emails',
                        nonce: bulkActionData.nonce,
                        order_ids: orderIds,
                        email_content: emailContent,
                    },
                    success: function (response) {
                        if (response.success) {
                            alert(response.data.message);
                        } else {
                            alert(response.data.message || 'An error occurred.');
                        }
                    },
                    error: function () {
                        alert('An error occurred while sending emails.');
                    },
                    complete: function () {
                        $('#bulk-email-popup, #popup-overlay').remove();
                        $('.button.action').prop('disabled', false);
                    },
                });
            });

            $('#close-bulk-email-popup').on('click', function () {
                $('#bulk-email-popup, #popup-overlay').remove();
                $('.button.action').prop('disabled', false);
            });
        }
    });
});
