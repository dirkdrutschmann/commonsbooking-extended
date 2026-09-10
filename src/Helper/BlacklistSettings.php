<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

class BlacklistSettings
{
    public const OPTION_KEY = 'commonbookings_additional_features_option_blacklist';
    private const DEFAULT_NOTICE_TEXT = 'Buchung nicht möglich: Du hast das Limit für {{reason}}-Buchungen ({{limit}} in {{interval}} Tagen) erreicht.';

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

    public static function getNoticeText(): string
    {
        $text = self::getOption('blacklist_notice_text');

        if ($text && is_string($text)) {
            return $text;
        }

        return self::getDefaultNoticeText();
    }

    public static function getDefaultNoticeText(): string
    {
        return __(self::DEFAULT_NOTICE_TEXT, 'cb-additional-features');
    }
}
