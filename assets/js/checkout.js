jQuery(function ($) {
    if (typeof klp_params === 'undefined') return;

    var $dateField = $('#klp_delivery_date');
    var $timeSlot = $('#klp_time_slot');
    var $wrapper = $dateField.closest('.klp-checkout-fields');

    if ($wrapper.find('.klp-availability-msg').length === 0) {
        $wrapper.find('.klp-date-field').after(
            '<div class="klp-availability-msg"></div>' +
            '<div class="klp-legend">' +
                '<span class="klp-legend-item klp-legend-available">&#9632; Beschikbaar</span>' +
                '<span class="klp-legend-item klp-legend-limited">&#9632; Beperkt</span>' +
                '<span class="klp-legend-item klp-legend-full">&#9632; Vol/gesloten</span>' +
                '<span class="klp-legend-item klp-legend-past">&#9632; Zondag/voorbij</span>' +
            '</div>'
        );
    }

    var $availabilityMsg = $wrapper.find('.klp-availability-msg');
    var closedDates = klp_params.closed_dates || [];
    var fullDates = klp_params.full_dates || [];

    var maxPerDay = parseInt(klp_params.max_per_day) || 200;
    var dateCache = {};

    function formatDmy(date) {
        var d = String(date.getDate()).padStart(2, '0');
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var y = date.getFullYear();
        return d + '-' + m + '-' + y;
    }

    function isBlocked(date) {
        var formatted = formatDmy(date);
        if (closedDates.indexOf(formatted) !== -1) return true;
        if (date.getDay() === 0) return true;
        return false;
    }

    function getFullStatus(formatted) {
        if (fullDates.indexOf(formatted) !== -1) return 'full';
        return null;
    }

    var minParts = klp_params.min_date.split('-');
    var minDate = new Date(parseInt(minParts[2]), parseInt(minParts[1]) - 1, parseInt(minParts[0]));

    $dateField.datepicker({
        dateFormat: 'dd-mm-yy',
        minDate: minDate,
        maxDate: '+60',
        firstDay: 1,
        dayNamesMin: ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'],
        dayNames: ['Zondag', 'Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag'],
        monthNames: ['Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni', 'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December'],
        beforeShowDay: function (date) {
            var formatted = formatDmy(date);

            if (isBlocked(date)) {
                return [false, 'klp-blocked klp-status-closed', 'Niet beschikbaar'];
            }

            var status = getFullStatus(formatted);
            if (status === 'full') {
                return [false, 'klp-blocked klp-status-full', 'Volgeboekt'];
            }

            var today = new Date();
            today.setHours(0, 0, 0, 0);
            if (date <= today || date.getDay() === 0) {
                return [false, 'klp-blocked klp-status-past', 'Niet beschikbaar'];
            }

            return [true, 'klp-available klp-status-open', 'Beschikbaar'];
        },
        onSelect: function (dateText) {
            $timeSlot.val('').prop('disabled', false);
            $availabilityMsg.html('<div class="klp-loading">Controleren beschikbaarheid...</div>').show();

            $.post(klp_params.ajax_url, {
                action: 'klp_check_availability',
                date: dateText,
                nonce: klp_params.nonce
            }, function (response) {
                if (response.success) {
                    var avail = response.data.available;
                    var max = response.data.max;
                    if (avail <= 0) {
                        $availabilityMsg.html(
                            '<div class="klp-msg-full">' +
                                '<strong>&#10060; Deze datum is volgeboekt</strong><br>' +
                                '<span>Kies een andere datum in de kalender</span>' +
                            '</div>'
                        ).show();
                        $timeSlot.prop('disabled', true);
                    } else {
                        $availabilityMsg.html(
                            '<div class="klp-msg-available">' +
                                '<strong>&#9989; Bezorgmoment beschikbaar</strong>' +
                            '</div>'
                        ).show();
                    }
                }
            }).fail(function () {
                $availabilityMsg.html('<div class="klp-msg-error">Kon beschikbaarheid niet controleren</div>').show();
            });
        }
    });

    $dateField.on('change', function () {
        $timeSlot.prop('disabled', !$(this).val());
    });
});
