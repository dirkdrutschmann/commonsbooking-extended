jQuery(function ($) {
    var settings = window.afcbEmailResenderActions || {};
    var texts = settings.texts || {};

    function buildNotice(type, message) {
        var dismissLabel = texts.dismissLabel || 'Diese Benachrichtigung ausblenden';
        return (
            '<div class="notice notice-' + type + ' is-dismissible"><p>' +
            message +
            '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">' +
            dismissLabel +
            '</span></button></div>'
        );
    }

    $(document).on('click', '.cb-resend-email-link', function (e) {
        e.preventDefault();

        var $link = $(this);
        var bookingId = $link.data('booking-id');
        var originalText = $link.text();

        if (!confirm(texts.confirm || 'Moechten Sie die Bestaetigungs-E-Mail fuer diese Buchung wirklich erneut versenden?')) {
            return;
        }

        $link.text(texts.sending || 'Wird versendet...').addClass('cb-sending');

        $.post(settings.ajaxUrl || window.ajaxurl, {
            action: 'cbadf_resend_single_email',
            booking_id: bookingId,
            nonce: settings.nonce || ''
        })
            .done(function (response) {
                if (response && response.success) {
                    $('h1.wp-heading-inline').after(buildNotice('success', response.data));
                    $link.text(texts.sent || 'Versendet').removeClass('cb-sending').addClass('cb-sent');

                    setTimeout(function () {
                        $link.text(originalText).removeClass('cb-sent');
                    }, 3000);
                } else {
                    var label = texts.errorLabel || 'Fehler:';
                    var message = response && response.data ? response.data : '';
                    $('h1.wp-heading-inline').after(buildNotice('error', '<strong>' + label + '</strong> ' + message));
                    $link.text(originalText).removeClass('cb-sending');
                }
            })
            .fail(function (xhr, status, error) {
                var label = texts.ajaxErrorLabel || 'AJAX-Fehler:';
                $('h1.wp-heading-inline').after(buildNotice('error', '<strong>' + label + '</strong> ' + error));
                $link.text(originalText).removeClass('cb-sending');
            });
    });

    $(document).on('click', '.notice-dismiss', function () {
        $(this).closest('.notice').fadeOut();
    });
});
