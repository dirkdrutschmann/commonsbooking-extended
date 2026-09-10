(function ($) {
    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var context = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, delay);
        };
    }

    function formatAddressLabel(item) {
        var streetLine = [item.street, item.house_number].filter(Boolean).join(' ');
        var cityLine = [item.zip, item.city].filter(Boolean).join(' ');

        return [streetLine, cityLine].filter(Boolean).join(', ');
    }

    function fillAddress($form, item, $searchInput) {
        $form.find('input[name="street"]').val(item.street || '');
        $form.find('input[name="house_number"]').val(item.house_number || '');
        $form.find('input[name="zip"]').val(item.zip || '');
        $form.find('input[name="city"]').val(item.city || '');
        $form.find('input[name="state"]').val(item.state || '');
        $form.find('input[name="country"]').val(item.country || '');
        $form.find('.afcb-address-lat').val(item.lat || '');
        $form.find('.afcb-address-lon').val(item.lon || '');
        $form.find('.afcb-address-raw').val(JSON.stringify(item.raw || {}));

        if ($searchInput && $searchInput.length) {
            $searchInput.val(formatAddressLabel(item) || item.display_name || '');
        }

        ['street', 'house_number', 'zip', 'city'].some(function (fieldName) {
            var $field = $form.find('input[name="' + fieldName + '"]');
            if ($field.length && !$field.val()) {
                $field.trigger('focus');
                return true;
            }

            return false;
        });
    }

    function clearAddress($form) {
        $form.find('input[name="street"]').val('');
        $form.find('input[name="house_number"]').val('');
        $form.find('input[name="zip"]').val('');
        $form.find('input[name="city"]').val('');
        $form.find('input[name="state"]').val('');
        $form.find('input[name="country"]').val('');
        $form.find('.afcb-address-lat').val('');
        $form.find('.afcb-address-lon').val('');
        $form.find('.afcb-address-raw').val('');
    }

    function clearAddressLookupMeta($form) {
        $form.find('.afcb-address-lat').val('');
        $form.find('.afcb-address-lon').val('');
        $form.find('.afcb-address-raw').val('');
    }

    function renderResults($results, items, $form, $input) {
        $results.empty();
        if (!items.length) {
            $results.removeClass('active');
            return;
        }

        items.forEach(function (item) {
            var $btn = $('<button type="button"></button>');
            var primary = [item.street, item.house_number].filter(Boolean).join(' ');
            var cityLine = [item.zip, item.city].filter(Boolean).join(' ');
            var secondary = [cityLine, item.country].filter(Boolean).join(', ');

            if (!primary) {
                primary = item.display_name || '';
            }

            $btn.append($('<span class="afcb-address-result-title"></span>').text(primary));
            if (secondary) {
                $btn.append($('<span class="afcb-address-result-meta"></span>').text(secondary));
            }
            $btn.on('click', function () {
                fillAddress($form, item, $input);
                $results.removeClass('active');
            });
            $results.append($btn);
        });

        $results.addClass('active');
    }

    function initAddressSearch() {
        if (typeof afcbUserManagement === 'undefined' || !afcbUserManagement.addressEnabled) {
            return;
        }

        $('.afcb-address-search').each(function () {
            var $input = $(this);
            var $form = $input.closest('form');
            var $results = $input.closest('.afcb-address').find('.afcb-address-results');

            var doSearch = debounce(function () {
                var query = $input.val().trim();
                if (query.length < 3) {
                    $results.removeClass('active');
                    return;
                }

                $.get(afcbUserManagement.ajaxUrl, {
                    action: 'afcb_address_search',
                    nonce: afcbUserManagement.addressNonce,
                    query: query
                }).done(function (response) {
                    if (!response || !response.success) {
                        $results.removeClass('active');
                        return;
                    }
                    renderResults($results, response.data || [], $form, $input);
                });
            }, 350);

            $input.on('input', function () {
                clearAddress($form);
                doSearch();
            });

            $form.find('.afcb-address-detail').on('input', function () {
                clearAddressLookupMeta($form);
            });
        });

        $(document).on('click', function (event) {
            var $target = $(event.target);
            if ($target.closest('.afcb-address').length === 0) {
                $('.afcb-address-results').removeClass('active');
            }
        });
    }

    function setAvailabilityMessage($input, message) {
        var $message = $input.next('.afcb-availability-message');

        if (!$message.length) {
            $message = $('<div class="afcb-availability-message" aria-live="polite"></div>');
            $input.after($message);
        }

        if (!message) {
            $message.text('').removeClass('is-error');
            return;
        }

        $message.text(message).addClass('is-error');
    }

    function initRegistrationAvailability() {
        if (typeof afcbUserManagement === 'undefined' || !afcbUserManagement.registrationLookupNonce) {
            return;
        }

        $('[data-afcb-check-field]').each(function () {
            var input = this;
            var $input = $(input);
            var field = $input.data('afcb-check-field');
            var pendingRequest = null;

            function lookupAvailability() {
                var value = ($input.val() || '').trim();
                input.setCustomValidity('');
                setAvailabilityMessage($input, '');

                if (!value || !input.checkValidity()) {
                    return;
                }

                if (pendingRequest) {
                    pendingRequest.abort();
                }

                pendingRequest = $.get(afcbUserManagement.ajaxUrl, {
                    action: 'afcb_registration_lookup',
                    nonce: afcbUserManagement.registrationLookupNonce,
                    field: field,
                    value: value
                }).done(function (response) {
                    var message = '';

                    if (($input.val() || '').trim() !== value) {
                        return;
                    }

                    if (!response || !response.success || !response.data) {
                        return;
                    }

                    if (response.data.available === false) {
                        message = response.data.message || 'Dieser Wert ist bereits vergeben.';
                        input.setCustomValidity(message);
                        setAvailabilityMessage($input, message);
                    }
                }).always(function () {
                    pendingRequest = null;
                });
            }

            var debouncedLookup = debounce(lookupAvailability, 350);
            $input.on('input', debouncedLookup);
            $input.on('blur', lookupAvailability);

            if (($input.val() || '').trim()) {
                lookupAvailability();
            }
        });
    }

    function initRecaptchaV3() {
        if (typeof afcbUserManagement === 'undefined') {
            return;
        }
        if (!afcbUserManagement.recaptchaSiteKey || afcbUserManagement.recaptchaVersion !== 'v3') {
            return;
        }
        if (typeof grecaptcha === 'undefined') {
            return;
        }

        $('.afcb-form').each(function () {
            var $form = $(this);
            if ($form.data('afcb-recaptcha-bound')) {
                return;
            }
            $form.data('afcb-recaptcha-bound', true);

            var $tokenInput = $form.find('input[name=\"g-recaptcha-response\"]');
            if (!$tokenInput.length) {
                $tokenInput = $('<input type=\"hidden\" name=\"g-recaptcha-response\">');
                $form.append($tokenInput);
            }

            $form.on('submit', function (event) {
                if ($form.data('afcb-recaptcha-ready')) {
                    return true;
                }
                event.preventDefault();

                grecaptcha.ready(function () {
                    grecaptcha.execute(afcbUserManagement.recaptchaSiteKey, { action: 'submit' })
                        .then(function (token) {
                            $tokenInput.val(token);
                            $form.data('afcb-recaptcha-ready', true);
                            $form[0].submit();
                        })
                        .catch(function () {
                            $form.data('afcb-recaptcha-ready', true);
                            $form[0].submit();
                        });
                });
            });
        });
    }

    function initPasswordStrength() {
        var $password = $('#afcb-password');
        var $confirm = $('#afcb-password-confirm');
        var $meter = $('#afcb-password-meter');
        var $text = $('#afcb-password-text');

        if (!$password.length || !$confirm.length) {
            return;
        }

        function scorePassword(value) {
            var score = 0;
            if (value.length >= 8) score += 1;
            if (/[A-Z]/.test(value)) score += 1;
            if (/[a-z]/.test(value)) score += 1;
            if (/[0-9]/.test(value)) score += 1;
            if (/[^A-Za-z0-9]/.test(value)) score += 1;
            return score;
        }

        function isStrong(value) {
            return value.length >= 8 &&
                /[A-Z]/.test(value) &&
                /[a-z]/.test(value) &&
                /[0-9]/.test(value) &&
                /[^A-Za-z0-9]/.test(value);
        }

        function updateStrength() {
            var value = $password.val() || '';
            var score = scorePassword(value);
            var percent = Math.min(score, 5) * 20;

            if ($meter.length) {
                $meter.css('width', percent + '%');
                $meter.removeClass('bg-red-400 bg-amber-400 bg-emerald-500');
                if (score <= 2) {
                    $meter.addClass('bg-red-400');
                } else if (score === 3 || score === 4) {
                    $meter.addClass('bg-amber-400');
                } else {
                    $meter.addClass('bg-emerald-500');
                }
            }

            if ($text.length) {
                if (!value) {
                    $text.text('');
                } else if (!isStrong(value)) {
                    $text.text('Passwort ist zu schwach.');
                } else {
                    $text.text('Passwort ist stark.');
                }
            }

            if (value && !isStrong(value)) {
                $password[0].setCustomValidity('Passwort ist zu schwach.');
            } else {
                $password[0].setCustomValidity('');
            }
        }

        function updateConfirm() {
            var value = $password.val() || '';
            var confirmValue = $confirm.val() || '';
            if (confirmValue && value !== confirmValue) {
                $confirm[0].setCustomValidity('Passwörter stimmen nicht überein.');
            } else {
                $confirm[0].setCustomValidity('');
            }
        }

        $password.on('input', function () {
            updateStrength();
            updateConfirm();
        });
        $confirm.on('input', updateConfirm);
    }

    function getGermanMobileFallbackNumber(rawValue) {
        var value = (rawValue || '').replace(/[^\d+]/g, '');
        var national = '';

        if (!value) {
            return '';
        }

        if (value.indexOf('00') === 0) {
            value = '+' + value.substring(2);
        }

        if (value.charAt(0) === '+') {
            if (value.indexOf('+49') !== 0) {
                return '';
            }
            national = value.substring(3);
        } else if (value.indexOf('49') === 0) {
            national = value.substring(2);
        } else if (value.charAt(0) === '0') {
            national = value.substring(1);
        } else {
            return '';
        }

        if (!/^(15|16|17)\d+$/.test(national)) {
            return '';
        }

        if (national.length < 9 || national.length > 13) {
            return '';
        }

        return '+49' + national;
    }

    function normalizePhoneInput($input) {
        var instance = $input.data('afcbPhoneInput');
        var rawValue = ($input.val() || '').trim();
        var normalized = '';

        if (!rawValue) {
            return;
        }

        if (instance) {
            normalized = instance.getNumber();
        }

        if (!normalized || normalized.charAt(0) !== '+') {
            normalized = getGermanMobileFallbackNumber(rawValue);
        }

        if (normalized && normalized.charAt(0) === '+') {
            $input.val(normalized);
        }
    }

    function normalizePhoneInputs($scope) {
        $scope.find('.afcb-phone-input').each(function () {
            normalizePhoneInput($(this));
        });
    }

    function getPhoneNumberForChannelDetection($input) {
        var instance = $input.data('afcbPhoneInput');
        var value = ($input.val() || '').trim();
        var number = '';

        if (instance) {
            number = instance.getNumber();
        }

        if (!number || number.charAt(0) !== '+') {
            number = getGermanMobileFallbackNumber(value);
        }

        if (!number) {
            number = value.replace(/[^\d+]/g, '');
            if (number.indexOf('00') === 0) {
                number = '+' + number.substring(2);
            }
        }

        return number;
    }

    function isGermanMobileNumberValue(value) {
        return !!getGermanMobileFallbackNumber(value);
    }

    function isInternationalPhoneNumberValue(value) {
        var number = (value || '').replace(/[^\d+]/g, '');
        if (number.indexOf('00') === 0) {
            number = '+' + number.substring(2);
        }

        return /^\+[1-9]\d{6,14}$/.test(number) && number.indexOf('+49') !== 0;
    }

    function initPhoneInputs(attempt) {
        attempt = attempt || 0;

        if (typeof window.intlTelInput !== 'function') {
            if ($('.afcb-phone-input').length && attempt < 20) {
                window.setTimeout(function () {
                    initPhoneInputs(attempt + 1);
                }, 100);
            }
            return;
        }

        $('.afcb-phone-input').each(function () {
            var input = this;
            var $input = $(input);
            if ($input.data('afcbPhoneInput')) {
                return;
            }

            var instance = window.intlTelInput(input, {
                initialCountry: 'de',
                countryOrder: ['de', 'at', 'ch', 'nl', 'be', 'fr', 'pl', 'dk'],
                formatAsYouType: true,
                strictMode: true,
                separateDialCode: false
            });

            $input.data('afcbPhoneInput', instance);
            $input.trigger('afcbPhoneInputReady');
        });

        $('.afcb-form, #afcb-send-code-form, #afcb-manual-phone-review-form').off('submit.afcbPhoneInput').on('submit.afcbPhoneInput', function () {
            normalizePhoneInputs($(this));
        });
    }

    function initPhoneVerifyModal() {
        var $container = $('.afcb-user-form[data-show-phone-modal="1"]');
        var $modal = $('#afcb-phone-verify-modal');
        if (!$modal.length || !$container.length) {
            return;
        }
        var ajaxUrl = (typeof afcbUserManagement !== 'undefined' && afcbUserManagement.ajaxUrl) ? afcbUserManagement.ajaxUrl : '';
        if (!ajaxUrl) {
            $modal.show();
            return;
        }
        $modal.show();

        var $messageEl = $modal.find('#afcb-phone-modal-message');
        var $phoneInput = $modal.find('#afcb-modal-phone');
        var $smsOption = $modal.find('.afcb-phone-channel-sms');
        var $smsRadio = $smsOption.find('input[type="radio"]');
        var $signalRadio = $modal.find('input[name="phone_channel"][value="signal"]');
        var $manualSection = $modal.find('.afcb-manual-phone-review-section');

        function showModalMessage(type, text) {
            var messageClasses = {
                error: 'bg-red-50 text-red-800 border border-red-200',
                warning: 'bg-amber-50 text-amber-800 border border-amber-200',
                success: 'bg-green-50 text-green-800 border border-green-200'
            };
            $messageEl.removeClass('hidden bg-red-50 text-red-800 bg-amber-50 text-amber-800 bg-green-50 text-green-800 border border-red-200 border-amber-200 border-green-200')
                .addClass(messageClasses[type] || messageClasses.success)
                .text(text)
                .removeClass('hidden');
        }

        function getAjaxFailureMessage(xhr) {
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                return xhr.responseJSON.data.message;
            }
            if (!xhr || xhr.status === 0) {
                return 'Die Verbindung ist fehlgeschlagen. Bitte prüfe deine Internetverbindung und versuche es erneut.';
            }
            return 'Der Server konnte die Anfrage nicht verarbeiten. Bitte versuche es später erneut.';
        }

        function updatePhoneChannelOptions() {
            var number = getPhoneNumberForChannelDetection($phoneInput);
            var isGerman = isGermanMobileNumberValue(number);
            var isInternational = isInternationalPhoneNumberValue(number);

            if (isInternational) {
                $smsOption.addClass('hidden');
                $smsRadio.prop('disabled', true).prop('checked', false);
                $signalRadio.prop('checked', true);
                $manualSection.removeClass('hidden');
                return;
            }

            $smsOption.removeClass('hidden');
            $smsRadio.prop('disabled', false);
            $manualSection.addClass('hidden');

            if (isGerman && !$signalRadio.prop('checked')) {
                $smsRadio.prop('checked', true);
            }
        }

        function closeModal() {
            $modal.hide();
            $container.attr('data-show-phone-modal', '0');
        }

        function reloadWithoutNoticeParams() {
            var url = new URL(window.location.href);
            url.searchParams.delete('afcb_notice');
            url.searchParams.delete('afcb_notice_type');
            window.location.href = url.toString();
        }

        function clearPageNotices() {
            $('.afcb-user-form .afcb-notice-error, .afcb-user-form .afcb-notice-warning, .afcb-user-form .afcb-notice-success, .afcb-user-form .afcb-notice-info').remove();
        }

        $modal.find('.afcb-modal-close').on('click', closeModal);
        $modal.on('click', function (e) {
            if (e.target === $modal[0]) {
                closeModal();
            }
        });
        $(document).on('keydown.afcbPhoneModal', function (e) {
            if (e.key === 'Escape') {
                closeModal();
                $(document).off('keydown.afcbPhoneModal');
            }
        });

        updatePhoneChannelOptions();
        $phoneInput.on('input change countrychange afcbPhoneInputReady', updatePhoneChannelOptions);

        $modal.find('#afcb-send-code-form').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            $messageEl.addClass('hidden');
            $btn.prop('disabled', true);
            normalizePhoneInputs($form);
            updatePhoneChannelOptions();
            $.post(ajaxUrl, $form.serialize())
                .done(function (r) {
                    if (r && r.success && r.data && r.data.message) {
                        showModalMessage(r.data.delivery_status === 'uncertain' ? 'warning' : 'success', r.data.message);
                    } else {
                        showModalMessage('error', (r && r.data && r.data.message) ? r.data.message : 'Es ist ein Fehler aufgetreten. Bitte versuche es erneut.');
                    }
                })
                .fail(function (xhr) {
                    showModalMessage('error', getAjaxFailureMessage(xhr));
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });

        $modal.find('#afcb-verify-code-form').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            $messageEl.addClass('hidden');
            $btn.prop('disabled', true);
            $.post(ajaxUrl, $form.serialize())
                .done(function (r) {
                    if (r && r.success && r.data && r.data.message) {
                        showModalMessage('success', r.data.message);
                        clearPageNotices();
                        setTimeout(function () {
                            reloadWithoutNoticeParams();
                        }, 1200);
                    } else {
                        showModalMessage('error', (r && r.data && r.data.message) ? r.data.message : 'Es ist ein Fehler aufgetreten. Bitte versuche es erneut.');
                        $btn.prop('disabled', false);
                    }
                })
                .fail(function (xhr) {
                    showModalMessage('error', getAjaxFailureMessage(xhr));
                    $btn.prop('disabled', false);
                });
        });

        $modal.find('#afcb-manual-phone-review-form').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            $messageEl.addClass('hidden');
            $btn.prop('disabled', true);
            normalizePhoneInputs($form);
            $.post(ajaxUrl, $form.serialize())
                .done(function (r) {
                    if (r && r.success && r.data && r.data.message) {
                        showModalMessage('success', r.data.message);
                        clearPageNotices();
                        setTimeout(function () {
                            reloadWithoutNoticeParams();
                        }, 1200);
                    } else {
                        showModalMessage('error', (r && r.data && r.data.message) ? r.data.message : 'Es ist ein Fehler aufgetreten. Bitte versuche es erneut.');
                        $btn.prop('disabled', false);
                    }
                })
                .fail(function () {
                    showModalMessage('error', 'Die Verbindung ist fehlgeschlagen. Bitte prüfe deine Internetverbindung und versuche es erneut.');
                    $btn.prop('disabled', false);
                });
        });
    }

    $(document).ready(function () {
        initAddressSearch();
        initRegistrationAvailability();
        initPhoneInputs();
        initRecaptchaV3();
        initPasswordStrength();
        initPhoneVerifyModal();
    });
})(jQuery);
