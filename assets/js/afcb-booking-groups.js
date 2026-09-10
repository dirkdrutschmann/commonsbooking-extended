(function () {
    'use strict';

    function initializeBookingGroups(root) {
        var list = root.querySelector('[data-afcb-booking-groups-list]');
        var template = root.querySelector('[data-afcb-booking-group-template]');
        var addButton = root.querySelector('[data-afcb-add-booking-group]');

        if (!list || !template || !addButton) {
            return;
        }

        var nextIndex = parseInt(root.getAttribute('data-next-index'), 10);
        if (Number.isNaN(nextIndex) || nextIndex < 0) {
            nextIndex = list.querySelectorAll('[data-afcb-booking-group-row]').length;
        }

        root.addEventListener('click', function (event) {
            var removeButton = event.target.closest('[data-afcb-remove-booking-group]');
            if (!removeButton || !root.contains(removeButton)) {
                return;
            }

            var row = removeButton.closest('[data-afcb-booking-group-row]');
            if (row) {
                row.remove();
            }
        });

        addButton.addEventListener('click', function () {
            var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
            nextIndex += 1;
            root.setAttribute('data-next-index', String(nextIndex));
            list.insertAdjacentHTML('beforeend', html);

            var rows = list.querySelectorAll('[data-afcb-booking-group-row]');
            var newRow = rows[rows.length - 1];
            var nameInput = newRow ? newRow.querySelector('input[type="text"]') : null;
            if (nameInput) {
                nameInput.focus();
            }
        });
    }

    function initialize() {
        document.querySelectorAll('[data-afcb-booking-groups]').forEach(initializeBookingGroups);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}());
