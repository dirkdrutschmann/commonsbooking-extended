(function () {
    function repairEscapedQueryFragment() {
        var hash = window.location.hash || '';
        var fragment = hash.charAt(0) === '#' ? hash.slice(1) : hash;

        if (fragment.indexOf('038;') !== 0 && fragment.indexOf('38;') !== 0) {
            return false;
        }

        fragment = fragment.replace(/^0?38;/, '');

        try {
            var params = new URLSearchParams(fragment);
            var userId = params.get('afcb_user_id');

            if (!userId || !/^\d+$/.test(userId)) {
                return false;
            }

            var url = new URL(window.location.href);
            url.hash = '';
            url.searchParams.set('afcb_user_id', userId);

            window.location.replace(url.toString());
            return true;
        } catch (e) {
            return false;
        }
    }

    if (repairEscapedQueryFragment()) {
        return;
    }

    function initTabs() {
        var buttons = document.querySelectorAll('.afcb-tab-button');
        var panels = document.querySelectorAll('.afcb-tab-panel');
        if (!buttons.length || !panels.length) {
            return;
        }
        var allowedTabs = Array.prototype.map.call(buttons, function (button) {
            return button.getAttribute('data-afcb-tab');
        }).filter(Boolean);

        function syncFormTabState(tab) {
            var forms = document.querySelectorAll('form');
            forms.forEach(function (form) {
                var action = form.getAttribute('action') || '';
                if (action.indexOf('options.php') === -1 && action.indexOf('admin-post.php') === -1) {
                    return;
                }

                var hidden = form.querySelector('input[name="afcb_tab"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'afcb_tab';
                    form.appendChild(hidden);
                }
                hidden.value = tab;

                var referer = form.querySelector('input[name="_wp_http_referer"]');
                if (referer) {
                    try {
                        var refererUrl = new URL(window.location.href);
                        refererUrl.searchParams.set('afcb_tab', tab);
                        referer.value = refererUrl.pathname + refererUrl.search;
                    } catch (e) {
                        // Keep current referer when URL parsing fails.
                    }
                }
            });
        }

        function activate(tab) {
            if (allowedTabs.indexOf(tab) === -1) {
                tab = allowedTabs[0];
            }
            buttons.forEach(function (btn) {
                if (btn.getAttribute('data-afcb-tab') === tab) {
                    btn.classList.add('bg-slate-800', 'text-white');
                    btn.classList.remove('bg-white', 'text-slate-700');
                } else {
                    btn.classList.remove('bg-slate-800', 'text-white');
                    btn.classList.add('bg-white', 'text-slate-700');
                }
            });
            panels.forEach(function (panel) {
                if (panel.getAttribute('data-afcb-tab-panel') === tab) {
                    panel.classList.remove('hidden');
                } else {
                    panel.classList.add('hidden');
                }
            });

            syncFormTabState(tab);

            try {
                var url = new URL(window.location.href);
                url.searchParams.set('afcb_tab', tab);
                window.history.replaceState({}, '', url.toString());
            } catch (e) {
                // Ignore URL API issues in older browsers.
            }
        }

        buttons.forEach(function (btn, index) {
            btn.addEventListener('click', function () {
                activate(btn.getAttribute('data-afcb-tab'));
            });
            if (index === 0) {
                var urlTab = null;
                try {
                    var currentUrl = new URL(window.location.href);
                    urlTab = currentUrl.searchParams.get('afcb_tab');
                } catch (e) {
                    urlTab = null;
                }
                activate(urlTab || btn.getAttribute('data-afcb-tab'));
            }
        });

        document.querySelectorAll('form[action*="options.php"], form[action*="admin-post.php"]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var activeButton = document.querySelector('.afcb-tab-button.bg-slate-800');
                var activeTab = activeButton ? activeButton.getAttribute('data-afcb-tab') : allowedTabs[0];
                syncFormTabState(activeTab || allowedTabs[0]);
            });
        });
    }

    function initCustomFields() {
        var container = document.getElementById('afcb-custom-fields');
        var addButton = document.getElementById('afcb-add-field');
        var template = document.getElementById('afcb-custom-field-template');
        if (!container || !addButton || !template) {
            return;
        }

        var index = parseInt(container.getAttribute('data-index') || '0', 10);

        addButton.addEventListener('click', function () {
            var html = template.innerHTML.replace(/__INDEX__/g, String(index));
            index += 1;
            container.insertAdjacentHTML('beforeend', html);
        });

        container.addEventListener('click', function (event) {
            var target = event.target;
            if (!target.classList.contains('afcb-remove-field')) {
                return;
            }
            var wrapper = target.closest('.afcb-custom-field');
            if (wrapper) {
                wrapper.remove();
            }
        });
    }

    function initReviewCompare() {
        var modal = document.getElementById('afcb-review-compare-modal');
        var content = document.getElementById('afcb-review-compare-content');
        if (!modal || !content) {
            return;
        }

        function closeModal() {
            modal.classList.add('hidden');
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function renderTable(data) {
            var user = data.user;
            var matches = data.matches || [];
            var userFields = user.fields || [];
            var columns = [{ title: 'Prüfling', name: user.name || user.user_login, fields: userFields }];
            matches.forEach(function (m) {
                columns.push({
                    title: m.name || m.user_login,
                    name: m.user_login,
                    fields: m.fields || []
                });
            });

            var numCols = 1 + columns.length;
            var thead = '<thead class="text-xs uppercase text-slate-500 border-b"><tr><th class="py-2 pr-4 text-left">Feld</th>';
            columns.forEach(function (col) {
                thead += '<th class="py-2 px-2 text-left">' + escapeHtml(col.title) + '</th>';
            });
            thead += '</tr></thead>';

            var tbody = '<tbody class="divide-y text-slate-700">';
            for (var i = 0; i < userFields.length; i++) {
                var label = userFields[i].label;
                tbody += '<tr><td class="py-2 pr-4 font-medium text-slate-600">' + escapeHtml(label) + '</td>';
                columns.forEach(function (col) {
                    var val = (col.fields[i] && col.fields[i].value) ? col.fields[i].value : '';
                    tbody += '<td class="py-2 px-2">' + escapeHtml(val) + '</td>';
                });
                tbody += '</tr>';
            }
            tbody += '</tbody>';

            return '<table class="min-w-full text-left text-sm">' + thead + tbody + '</table>';
        }

        document.querySelectorAll('.afcb-review-compare-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var userId = btn.getAttribute('data-user-id');
                if (!userId) {
                    return;
                }
                var cfg = typeof afcbUserManagementAdmin !== 'undefined' ? afcbUserManagementAdmin : {};
                var ajaxUrl = cfg.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
                var nonce = cfg.compareNonce || '';

                modal.classList.remove('hidden');
                content.innerHTML = '<p class="text-sm text-slate-500">Lade …</p>';

                var xhr = new XMLHttpRequest();
                xhr.open('GET', ajaxUrl + '?action=afcb_review_compare_data&user_id=' + encodeURIComponent(userId) + '&nonce=' + encodeURIComponent(nonce));
                xhr.onload = function () {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data) {
                            content.innerHTML = renderTable(res.data);
                        } else {
                            content.innerHTML = '<p class="text-sm text-rose-600">' + escapeHtml(res.data && res.data.message ? res.data.message : 'Daten konnten nicht geladen werden.') + '</p>';
                        }
                    } catch (e) {
                        content.innerHTML = '<p class="text-sm text-rose-600">Antwort konnte nicht gelesen werden.</p>';
                    }
                };
                xhr.onerror = function () {
                    content.innerHTML = '<p class="text-sm text-rose-600">Anfrage fehlgeschlagen.</p>';
                };
                xhr.send();
            });
        });

        function closeBtn(el) {
            if (!el) return;
            el.addEventListener('click', closeModal);
        }
        closeBtn(modal.querySelector('[data-afcb-compare-close]'));
        modal.querySelectorAll('.afcb-review-compare-close').forEach(closeBtn);
    }

    function initUserDetailBookings() {
        var container = document.getElementById('afcb-user-bookings');
        if (!container) {
            return;
        }
        var userId = container.getAttribute('data-user-id');
        var perPage = parseInt(container.getAttribute('data-per-page') || '10', 10);
        var ajaxUrl = container.getAttribute('data-ajax-url');
        var nonce = container.getAttribute('data-nonce');
        if (!userId || !ajaxUrl || !nonce) {
            container.innerHTML = '<p class="text-sm text-amber-600">Konfiguration fehlt.</p>';
            return;
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function buildUrl(paged) {
            var params = new URLSearchParams();
            params.set('action', 'afcb_user_bookings');
            params.set('user_id', userId);
            params.set('nonce', nonce);
            params.set('paged', String(paged));
            params.set('per_page', String(perPage));
            return ajaxUrl + (ajaxUrl.indexOf('?') !== -1 ? '&' : '?') + params.toString();
        }

        function render(result) {
            var items = result.items || [];
            var total = result.total || 0;
            var pages = result.pages || 1;
            var currentPage = result.current_page || 1;

            if (items.length === 0) {
                container.innerHTML = '<p class="text-sm text-slate-600">Keine Buchungen.</p>';
                return;
            }

            var table = '<div class="overflow-x-auto"><table class="min-w-full text-left text-sm">';
            table += '<thead class="text-xs uppercase text-slate-500 border-b"><tr>';
            table += '<th class="py-2">Objekt</th><th class="py-2">Standort</th><th class="py-2">Von</th><th class="py-2">Bis</th><th class="py-2">Status</th><th class="py-2">Aktion</th></tr></thead>';
            table += '<tbody class="divide-y text-slate-700">';
            items.forEach(function (b) {
                table += '<tr>';
                table += '<td class="py-3">' + escapeHtml(b.item_name) + '</td>';
                table += '<td class="py-3">' + escapeHtml(b.location_name) + '</td>';
                table += '<td class="py-3">' + escapeHtml(b.start_date) + '</td>';
                table += '<td class="py-3">' + escapeHtml(b.end_date) + '</td>';
                table += '<td class="py-3">' + escapeHtml(b.status) + '</td>';
                table += '<td class="py-3">';
                if (b.edit_link) {
                    table += '<a href="' + escapeHtml(b.edit_link) + '" class="text-teal-600 hover:text-teal-700 text-xs font-semibold">Bearbeiten</a>';
                }
                table += '</td></tr>';
            });
            table += '</tbody></table></div>';

            var pagination = '';
            if (pages > 1) {
                pagination = '<div class="mt-4 flex flex-wrap items-center justify-between gap-2">';
                pagination += '<p class="text-sm text-slate-600">Seite ' + currentPage + ' von ' + pages + ' (' + total + ' Buchungen)</p>';
                pagination += '<nav class="flex gap-1">';
                if (currentPage > 1) {
                    pagination += '<button type="button" class="afcb-bookings-page px-3 py-1.5 rounded border border-slate-300 text-sm font-medium text-slate-700 hover:bg-slate-50" data-page="' + (currentPage - 1) + '">&larr;</button>';
                }
                var start = Math.max(1, currentPage - 2);
                var end = Math.min(pages, currentPage + 2);
                for (var p = start; p <= end; p++) {
                    var active = p === currentPage ? ' bg-teal-600 text-white border-teal-600' : ' border-slate-300 text-slate-700 hover:bg-slate-50';
                    pagination += '<button type="button" class="afcb-bookings-page px-3 py-1.5 rounded border text-sm font-medium' + active + '" data-page="' + p + '">' + p + '</button>';
                }
                if (currentPage < pages) {
                    pagination += '<button type="button" class="afcb-bookings-page px-3 py-1.5 rounded border border-slate-300 text-sm font-medium text-slate-700 hover:bg-slate-50" data-page="' + (currentPage + 1) + '">&rarr;</button>';
                }
                pagination += '</nav></div>';
            }

            container.innerHTML = table + pagination;

            container.querySelectorAll('.afcb-bookings-page').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var p = parseInt(btn.getAttribute('data-page'), 10);
                    if (p >= 1) {
                        loadPage(p);
                    }
                });
            });
        }

        function loadPage(paged) {
            container.innerHTML = '<p class="text-sm text-slate-500">Lade Buchungen …</p>';
            var xhr = new XMLHttpRequest();
            xhr.open('GET', buildUrl(paged));
            xhr.onload = function () {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data) {
                        render(res.data);
                    } else {
                        container.innerHTML = '<p class="text-sm text-rose-600">' + escapeHtml(res.data && res.data.message ? res.data.message : 'Buchungen konnten nicht geladen werden.') + '</p>';
                    }
                } catch (e) {
                    container.innerHTML = '<p class="text-sm text-rose-600">Antwort konnte nicht gelesen werden.</p>';
                }
            };
            xhr.onerror = function () {
                container.innerHTML = '<p class="text-sm text-rose-600">Anfrage fehlgeschlagen.</p>';
            };
            xhr.send();
        }

        loadPage(1);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initCustomFields();
        initReviewCompare();
        initUserDetailBookings();
    });
})();
