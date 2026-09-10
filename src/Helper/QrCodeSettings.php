<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

class QrCodeSettings
{
    public const OPTION_KEY = 'commonbookings_additional_features_option_qrcode';
    public const OPTION_ENABLED = 'qrcode_enabled';

    public static function getOptions(): array
    {
        $options = get_option(self::OPTION_KEY, []);

        return is_array($options) ? $options : [];
    }

    public static function getOption(string $key, $default = null)
    {
        $options = self::getOptions();

        return $options[$key] ?? $default;
    }

    public static function isEnabled(): bool
    {
        return !empty(self::getOption(self::OPTION_ENABLED));
    }
}
