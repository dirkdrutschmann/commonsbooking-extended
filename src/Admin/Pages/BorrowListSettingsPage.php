<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;
use Throwable;

class BorrowListSettingsPage
{
    public const OPTION_KEY = 'commonbookings_additional_features_borrow_list';
    private const OPTION_GROUP = 'commonbookings_additional_features_borrow_list_group';

    private const SORT_MODES = [
        'location_name' => 'Nach Standortname',
        'item_name' => 'Nach Fahrradname',
        'manual' => 'Manuelle Reihenfolge',
    ];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_page(): void
    {
        add_submenu_page(
            'cbadf',
            'Ausleih-Liste',
            'Ausleih-Liste',
            'manage_options',
            'cbadf-borrow-list',
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
        $input = is_array($input) ? $input : [];
        $sortMode = isset($input['sort_mode']) ? sanitize_key((string) $input['sort_mode']) : 'location_name';

        if (array_key_exists($sortMode, self::SORT_MODES)) {
            $this->save_location_order($input['location_order'] ?? []);
        }

        if (function_exists('mlr_flush_bikes_cache')) {
            mlr_flush_bikes_cache();
        } else {
            delete_transient('mlr_bikes_data_v4');
        }

        return [
            'sort_mode' => array_key_exists($sortMode, self::SORT_MODES) ? $sortMode : 'location_name',
            'group_districts' => !empty($input['group_districts']) || !empty($input['group_frankfurt_districts']) ? '1' : '0',
            'group_frankfurt_districts' => !empty($input['group_frankfurt_districts']) ? '1' : '0',
        ];
    }

    public function render(): void
    {
        $options = wp_parse_args(get_option(self::OPTION_KEY, []), [
            'sort_mode' => 'location_name',
            'group_districts' => '1',
            'group_frankfurt_districts' => '1',
        ]);

        echo TemplateLoader::load()->render('admin/borrow-list-settings.html.twig', [
            'title' => 'CommonBookings Additional Features',
            'subtitle' => 'Ausleih-Liste',
            'action_url' => esc_url(admin_url('options.php')),
            'settings_fields' => $this->capture_settings_fields(self::OPTION_GROUP),
            'option_key' => self::OPTION_KEY,
            'sort_modes' => $this->sort_mode_options((string) $options['sort_mode']),
            'selected_sort_mode' => (string) $options['sort_mode'],
            'locations' => $this->manual_order_locations(),
            'group_districts' => !empty($options['group_districts']) || !empty($options['group_frankfurt_districts']),
        ]);
    }

    public function enqueue_assets(string $hook): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== 'cbadf-borrow-list') {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');
    }

    private function sort_mode_options(string $selected): array
    {
        $options = [];
        foreach (self::SORT_MODES as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label,
                'selected' => $value === $selected,
            ];
        }

        return $options;
    }

    private function manual_order_locations(): array
    {
        $locations = get_posts([
            'post_type' => 'cb_location',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'orderby' => [
                'menu_order' => 'ASC',
                'title' => 'ASC',
            ],
            'suppress_filters' => false,
        ]);

        return array_map(static function (\WP_Post $location): array {
            $status = get_post_status_object($location->post_status);

            return [
                'id' => (int) $location->ID,
                'title' => self::decoded_title($location->ID),
                'status' => $status ? $status->label : $location->post_status,
                'edit_url' => get_edit_post_link($location->ID, ''),
                'items' => self::location_items($location->ID),
            ];
        }, $locations);
    }

    private static function location_items(int $locationId): array
    {
        if (!class_exists('\CommonsBooking\Repository\Item')) {
            return [];
        }

        try {
            $items = \CommonsBooking\Repository\Item::getByLocation($locationId, true);
        } catch (Throwable $exception) {
            return [];
        }

        usort($items, static function ($a, $b): int {
            $order = ((int) get_post_field('menu_order', $a->ID) <=> (int) get_post_field('menu_order', $b->ID));
            return $order !== 0 ? $order : strcasecmp(self::decoded_title((int) $a->ID), self::decoded_title((int) $b->ID));
        });

        return array_map(static function ($item): array {
            return [
                'id' => (int) $item->ID,
                'title' => self::decoded_title((int) $item->ID),
                'edit_url' => get_edit_post_link((int) $item->ID, ''),
            ];
        }, $items);
    }

    private static function decoded_title(int $postId): string
    {
        return html_entity_decode(get_the_title($postId), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
    }

    private function save_location_order($locationOrder): void
    {
        if (!is_array($locationOrder)) {
            return;
        }

        $locationIds = array_values(array_unique(array_filter(array_map('absint', $locationOrder))));
        foreach ($locationIds as $index => $locationId) {
            if (get_post_type($locationId) !== 'cb_location') {
                continue;
            }

            wp_update_post([
                'ID' => $locationId,
                'menu_order' => $index,
            ]);
        }
    }

    private function capture_settings_fields(string $group): string
    {
        ob_start();
        settings_fields($group);
        return (string) ob_get_clean();
    }
}
