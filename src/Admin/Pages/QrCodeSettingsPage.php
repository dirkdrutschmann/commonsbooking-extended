<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;

class QrCodeSettingsPage
{
    private const OPTION_KEY = 'commonbookings_additional_features_option_qrcode';
    private const OPTION_GROUP = 'commonbookings_additional_features_qrcode_group';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_page(): void
    {
        add_submenu_page(
            'cbadf',
            'QR-Code',
            'QR-Code',
            'manage_options',
            'cbadf-qrcode',
            [$this, 'render']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_KEY,
            [$this, 'sanitize']
        );
    }

    public function sanitize($input): array
    {
        return [
            'qrcode_enabled' => !empty($input['qrcode_enabled']) ? 1 : 0,
        ];
    }

    public function render(): void
    {
        $options = get_option(self::OPTION_KEY, []);
        $context = [
            'title' => 'CommonBookings Additional Features',
            'action_url' => esc_url(admin_url('options.php')),
            'settings_fields' => $this->capture_settings_fields(self::OPTION_GROUP),
            'enabled' => !empty($options['qrcode_enabled']),
        ];

        echo TemplateLoader::load()->render('admin/qrcode-settings.html.twig', $context);
    }

    private function capture_settings_fields(string $group): string
    {
        ob_start();
        settings_fields($group);
        return (string) ob_get_clean();
    }
}
