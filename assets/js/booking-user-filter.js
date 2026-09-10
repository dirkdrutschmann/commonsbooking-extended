(function ($) {
    'use strict';

    const config = window.cbadfBookingUserFilter;

    if (!config) {
        return;
    }

    const $input = $('#' + config.inputId);
    const $userIdInput = $('#' + config.userIdInputId);

    if (!$input.length || !$userIdInput.length || typeof $input.autocomplete !== 'function') {
        return;
    }

    $input.on('input', () => {
        $userIdInput.val('');
    });

    $input.autocomplete({
        delay: 250,
        minLength: config.minimumLength || 2,
        source(request, respond) {
            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: config.action,
                    nonce: config.nonce,
                    term: request.term,
                },
            })
                .done((response) => {
                    const results = response && response.success && Array.isArray(response.data)
                        ? response.data
                        : [];

                    respond(results);
                })
                .fail(() => {
                    respond([]);
                });
        },
        focus(event, item) {
            $input.attr('aria-activedescendant', 'cbadf-booking-user-' + item.item.id);
            event.preventDefault();
        },
        select(event, item) {
            $input.val(item.item.value);
            $userIdInput.val(item.item.id);
            return false;
        },
        open() {
            $input.attr('aria-expanded', 'true');
        },
        close() {
            $input.attr('aria-expanded', 'false');
        },
        messages: {
            noResults: config.texts.noResults,
            results(count) {
                if (count === 1) {
                    return config.texts.oneResult;
                }

                return config.texts.manyResults.replace('%d', count);
            },
        },
    });

    const autocomplete = $input.autocomplete('instance');

    if (autocomplete) {
        const $widget = $input.autocomplete('widget');

        $input
            .attr({
                role: 'combobox',
                'aria-autocomplete': 'list',
                'aria-expanded': 'false',
                'aria-controls': $widget.attr('id'),
            })
            .on('keydown', () => {
                $input.removeAttr('aria-activedescendant');
            });

        $widget
            .addClass('cbadf-booking-user-autocomplete')
            .attr('role', 'listbox')
            .removeAttr('tabindex')
            .on('menufocus', (event, item) => {
                item.item.attr('aria-selected', 'true');
            })
            .on('menublur', () => {
                $widget.find('[aria-selected="true"]').removeAttr('aria-selected');
            });

        autocomplete._renderItem = function (list, item) {
            return $('<li>', {
                id: 'cbadf-booking-user-' + item.id,
                role: 'option',
            })
                .append($('<div>').text(item.label))
                .appendTo(list);
        };
    }
})(jQuery);
