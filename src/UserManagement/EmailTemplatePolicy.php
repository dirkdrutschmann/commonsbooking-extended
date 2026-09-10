<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement;

final class EmailTemplatePolicy
{
    /** SHA-256 of the whitespace-normalized AFCB wrapper shipped before the branded mail layout. */
    private const LEGACY_WRAPPER_SHA256 = 'cd37da9001971d8eb3eeeb1e7c09c8ebb49c729575ec7230d1655673edd1e32b';

    public static function normalize_wrapper(string $template, string $default_template): string
    {
        if (trim($template) === '') {
            return $default_template;
        }

        $normalized = preg_replace('/\s+/', ' ', trim($template)) ?? '';
        if (hash_equals(self::LEGACY_WRAPPER_SHA256, hash('sha256', $normalized))) {
            return $default_template;
        }

        return $template;
    }
}
