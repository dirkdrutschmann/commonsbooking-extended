jQuery(function ($) {
    var settings = window.afcbEmailResender || {};
    var texts = settings.texts || {};

    var $forms = $('.cb-resender-form');
    if (!$forms.length) {
        return;
    }

    var $loading = $('#cb-loading');
    var $results = $('#cb-results');
    var $resultsContent = $('#cb-results-content');
    var $dateFields = $('.cb-resender-date');

    if ($.fn.datepicker && $dateFields.length) {
        $dateFields.datepicker({ dateFormat: 'yy-mm-dd' });
    }

    function buildError(label, message) {
        return '<div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"><strong>' +
            label +
            '</strong> ' +
            message +
            '</div>';
    }

    function getNonce($form) {
        return $form.find('[name="cbadf_resend_nonce"]').val() || settings.nonce || '';
    }

    $forms.on('submit', function (e) {
        e.preventDefault();

        var $form = $(this);
        var resendType = $form.data('resendType') || 'post_id';
        var $submit = $form.find('button[type="submit"]');

        var formData = {
            action: 'cbadf_resend_emails',
            cbadf_resend_nonce: getNonce($form),
            resend_type: resendType
        };

        if (resendType === 'post_id') {
            var postId = $form.find('[name="post_id"]').val();
            if (!postId) {
                alert(texts.missingId || 'Bitte geben Sie eine Buchungs-ID ein.');
                return;
            }
            formData.post_id = postId;
        }

        if (resendType === 'date_range') {
            var startDate = $form.find('[name="start_date"]').val();
            if (!startDate) {
                alert(texts.missingDate || 'Bitte geben Sie ein Startdatum ein.');
                return;
            }

            var isPreview = $form.find('[name="preview_mode"]').is(':checked');
            if (isPreview) {
                formData.resend_type = 'preview';
                formData.preview_start_date = startDate;
            } else {
                formData.start_date = startDate;
            }

            formData.booking_status = $form.find('[name="booking_status"]').val() || '';
            formData.batch_size = $form.find('[name="batch_size"]').val() || '10';
        }

        $loading.removeClass('hidden');
        $results.addClass('hidden');
        $submit.prop('disabled', true);

        $.post(settings.ajaxUrl || window.ajaxurl, formData)
            .done(function (response) {
                if (response && response.success) {
                    $resultsContent.html(response.data && response.data.html ? response.data.html : '');
                } else {
                    var label = texts.errorLabel || 'Fehler:';
                    var message = response && response.data ? response.data : '';
                    $resultsContent.html(buildError(label, message));
                }
                $results.removeClass('hidden');
            })
            .fail(function (xhr, status, error) {
                var label = texts.ajaxErrorLabel || 'AJAX Fehler:';
                $resultsContent.html(buildError(label, error));
                $results.removeClass('hidden');
            })
            .always(function () {
                $loading.addClass('hidden');
                $submit.prop('disabled', false);
            });
    });
});
