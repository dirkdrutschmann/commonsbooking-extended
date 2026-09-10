<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement;

final class FrontendAssetRequirements
{
    private const DEFINITIONS = [
        [
            'page_option' => 'registration_page_id',
            'shortcodes' => ['afcb_register', 'cbaf_register'],
            'address_lookup' => true,
            'recaptcha' => true,
            'phone_input' => true,
        ],
        [
            'page_option' => 'login_page_id',
            'shortcodes' => ['afcb_login', 'cbaf_login'],
            'address_lookup' => false,
            'recaptcha' => true,
            'phone_input' => false,
        ],
        [
            'page_option' => 'profile_page_id',
            'shortcodes' => ['afcb_profile', 'cbaf_profile'],
            'address_lookup' => true,
            'recaptcha' => false,
            'phone_input' => true,
        ],
        [
            'page_option' => 'forgot_password_page_id',
            'shortcodes' => ['afcb_forgot_password', 'cbaf_forgot_password'],
            'address_lookup' => false,
            'recaptcha' => true,
            'phone_input' => false,
        ],
        [
            'page_option' => 'forgot_username_page_id',
            'shortcodes' => ['afcb_forgot_username', 'cbaf_forgot_username'],
            'address_lookup' => false,
            'recaptcha' => true,
            'phone_input' => true,
        ],
    ];

    public static function resolve(int $page_id, string $content, array $options): ?array
    {
        $requirements = [
            'address_lookup' => false,
            'recaptcha' => false,
            'phone_input' => false,
        ];
        $matched = false;

        foreach (self::DEFINITIONS as $definition) {
            $configured_page_id = (int) ($options[$definition['page_option']] ?? 0);
            $matches_page = $page_id > 0
                && $configured_page_id > 0
                && $page_id === $configured_page_id;
            $matches_shortcode = self::contains_shortcode($content, $definition['shortcodes']);

            if (!$matches_page && !$matches_shortcode) {
                continue;
            }

            $matched = true;
            foreach (array_keys($requirements) as $requirement) {
                $requirements[$requirement] = $requirements[$requirement]
                    || (bool) $definition[$requirement];
            }
        }

        return $matched ? $requirements : null;
    }

    private static function contains_shortcode(string $content, array $shortcodes): bool
    {
        if ($content === '' || !function_exists('has_shortcode')) {
            return false;
        }

        foreach ($shortcodes as $shortcode) {
            if (has_shortcode($content, $shortcode)) {
                return true;
            }
        }

        return false;
    }
}
