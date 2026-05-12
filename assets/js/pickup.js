jQuery(function ($) {
    var $submitBtn = $('#klp_pickup_submit');
    var $codeInput = $('#klp_pickup_code');
    var $message = $('.klp-pickup-message');

    if (!$submitBtn.length) return;

    $submitBtn.on('click', function () {
        var code = $codeInput.val().trim();
        if (!code) {
            showMessage('Voer uw ophaalcode in.', 'error');
            return;
        }

        $submitBtn.prop('disabled', true).text('Bezig met aanmelden...');
        $message.hide();

        $.post(klp_pickup.ajax_url, {
            action: 'klp_request_pickup',
            code: code,
            nonce: klp_pickup.nonce
        }, function (response) {
            if (response.success) {
                showMessage(response.data.message, 'success');
                $codeInput.val('');
            } else {
                showMessage(response.data.message, 'error');
            }
        }).fail(function () {
            showMessage('Er is een fout opgetreden. Probeer het opnieuw.', 'error');
        }).always(function () {
            $submitBtn.prop('disabled', false).text('Container aanmelden voor ophalen');
        });
    });

    function showMessage(text, type) {
        $message.removeClass('success error').addClass(type).text(text).show();
    }
});
