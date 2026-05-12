jQuery(function ($) {
    if (typeof klp_order === 'undefined') return;

    $('#klp_edit_date').datepicker({
        dateFormat: 'dd-mm-yy',
        firstDay: 1,
        dayNamesMin: ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'],
        dayNames: ['Zondag', 'Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag'],
        monthNames: ['Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni', 'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December'],
    });

    $('#klp_save_delivery').on('click', function () {
        var $btn      = $(this);
        var $feedback = $('#klp_delivery_feedback');
        var date      = $('#klp_edit_date').val();
        var time_slot = $('#klp_edit_time_slot').val();
        var order_id  = $('#klp_edit_date').data('order-id');

        $feedback.hide();

        if (!date) {
            $feedback.text('Vul een datum in.').css('color', '#dc3232').show();
            return;
        }

        $btn.prop('disabled', true).text('Opslaan...');

        $.post(klp_order.ajax_url, {
            action:    'klp_update_delivery',
            nonce:     klp_order.nonce,
            order_id:  order_id,
            date:      date,
            time_slot: time_slot,
        }, function (response) {
            if (response.success) {
                $feedback.text(response.data.message).css('color', '#46b450').show();
            } else {
                $feedback.text(response.data).css('color', '#dc3232').show();
            }
        }).fail(function () {
            $feedback.text('Fout bij opslaan.').css('color', '#dc3232').show();
        }).always(function () {
            $btn.prop('disabled', false).text('Opslaan');
        });
    });
});
