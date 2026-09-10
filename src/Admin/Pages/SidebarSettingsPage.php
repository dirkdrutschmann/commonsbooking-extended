<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;

class SidebarSettingsPage
{
    private const OPTION_KEY = 'commonbookings_additional_features_option_name';
    private const OPTION_GROUP = 'commonbookings_additional_features_option_group';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_page(): void
    {
        add_submenu_page(
            'cbadf',
            'Sidebar',
            'Sidebar',
            'manage_options',
            'cbadf',
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
        $sanitary_values = [];
        if (isset($input['werbung_0'])) {
            $sanitary_values['werbung_0'] = $input['werbung_0'];
        }
        if (isset($input['buchung_0'])) {
            $sanitary_values['buchung_0'] = $input['buchung_0'];
        }
        if (isset($input['buchung_historie_1'])) {
            $sanitary_values['buchung_historie_1'] = $input['buchung_historie_1'];
        }
        if (isset($input['profil_2'])) {
            $sanitary_values['profil_2'] = $input['profil_2'];
        }
        if (isset($input['konto_3'])) {
            $sanitary_values['konto_3'] = $input['konto_3'];
        }
        return $sanitary_values;
    }

    public function render(): void
    {
        $options = get_option(self::OPTION_KEY, []);
        $fields = [
            [
                'key' => 'werbung_0',
                'label' => 'Werbung',
                'type' => 'textarea',
                'value' => isset($options['werbung_0']) ? (string) $options['werbung_0'] : '',
                'help' => '',
            ],
            [
                'key' => 'buchung_0',
                'label' => 'Meine Buchungen',
                'type' => 'select',
                'value' => isset($options['buchung_0']) ? (int) $options['buchung_0'] : 0,
                'options' => $this->get_page_options(),
                'help' => 'Die Seite auf der der Shortcode "[afcb_bookings]" aufgerufen wird. Dies gibt eine Übersicht über die getätigten Buchungen.',
            ],
            [
                'key' => 'buchung_historie_1',
                'label' => 'Buchungshistorie',
                'type' => 'select',
                'value' => isset($options['buchung_historie_1']) ? (int) $options['buchung_historie_1'] : 0,
                'options' => $this->get_page_options(),
                'help' => 'Die Seite auf der der Shortcode "[afcb_historie_table]" aufgerufen wird. Dies gibt eine Übersicht über vergangene Buchungen.',
            ],
            [
                'key' => 'profil_2',
                'label' => 'Mein Profil',
                'type' => 'select',
                'value' => isset($options['profil_2']) ? (int) $options['profil_2'] : -1,
                'options' => $this->get_page_options([
                    [
                        'value' => -1,
                        'label' => 'Standard (WP-Profil)',
                    ],
                ]),
                'help' => 'Falls ein Plugin die Profilbearbeitung übernimmt, kann hier die Seite eingestellt werden auf der die Profildaten geändert werden können.',
            ],
            [
                'key' => 'konto_3',
                'label' => 'Mein Konto',
                'type' => 'select',
                'value' => isset($options['konto_3']) ? (int) $options['konto_3'] : -1,
                'options' => $this->get_page_options([
                    [
                        'value' => -1,
                        'label' => 'Ausblenden',
                    ],
                ]),
                'help' => 'Falls ein Plugin die Profilbearbeitung übernimmt, kann hier die Seite eingestellt werden auf der die Profildaten geändert werden können.',
            ],
        ];

        $context = [
            'title' => 'CommonBookings Additional Features',
            'subtitle' => 'Sidebar',
            'action_url' => esc_url(admin_url('options.php')),
            'settings_fields' => $this->capture_settings_fields(self::OPTION_GROUP),
            'fields' => $this->prepare_fields($fields),
        ];

        echo TemplateLoader::load()->render('admin/sidebar-settings.html.twig', $context);
    }

    private function get_page_options(array $prefix = []): array
    {
        $options = [];
        foreach ($prefix as $option) {
            $options[] = [
                'value' => $option['value'],
                'label' => $option['label'],
            ];
        }

        $pages = get_pages([
            'sort_order' => 'asc',
            'sort_column' => 'post_title',
            'hierarchical' => 1,
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        foreach ($pages as $page) {
            $options[] = [
                'value' => (int) $page->ID,
                'label' => get_the_title($page->ID),
            ];
        }

        return $options;
    }

    private function prepare_fields(array $fields): array
    {
        foreach ($fields as &$field) {
            $field['name'] = self::OPTION_KEY . '[' . $field['key'] . ']';
            if ($field['type'] === 'select') {
                $field['options'] = $this->mark_selected($field['options'] ?? [], $field['value'] ?? null);
            }
        }
        unset($field);

        return $fields;
    }

    private function mark_selected(array $options, $value): array
    {
        foreach ($options as &$option) {
            $option['selected'] = ((string) $option['value'] === (string) $value);
        }
        unset($option);
        return $options;
    }

    private function capture_settings_fields(string $group): string
    {
        ob_start();
        settings_fields($group);
        return (string) ob_get_clean();
    }
}
