jQuery(document).ready(function($) {
    $('#generate-gls-label').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const statusDiv = $('#gls-label-status');
        const orderId = button.data('order-id');

        button.prop('disabled', true);
        statusDiv.html('<p>Címke generálása folyamatban...</p>');

        $.ajax({
            url: glsLabelsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'generate_gls_label',
                order_id: orderId,
                nonce: glsLabelsAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusDiv.html(`
                        <p style="color: green;">${response.data.message}</p>
                        <a href="${response.data.pdf_url}" target="_blank" class="button">
                            Címke letöltése
                        </a>
                    `);
                } else {
                    statusDiv.html(`<p style="color: red;">${response.data}</p>`);
                }
            },
            error: function() {
                statusDiv.html('<p style="color: red;">Hiba történt a címke generálása közben!</p>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
}); 