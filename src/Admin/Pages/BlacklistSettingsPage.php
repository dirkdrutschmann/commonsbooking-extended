<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\BlacklistLog;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\BlacklistSettings;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\BookingGroups;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;

class BlacklistSettingsPage
{
    private const OPTION_KEY = 'commonbookings_additional_features_option_blacklist';
    private const OPTION_GROUP = 'commonbookings_additional_features_blacklist_group';
    private const LOGS_PER_PAGE = 10;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_page(): void
    {
        add_submenu_page(
            'cbadf',
            'Beschränkung',
            'Beschränkung',
            'manage_options',
            'cbadf-blacklist',
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
        if (!is_array($input)) {
            return [];
        }

        $sanitary_values = [];
        foreach ([
            'blacklist_item_active',
            'blacklist_item_followup_gap_active',
            'blacklist_item_include_admins',
            'blacklist_location_active',
            'blacklist_location_include_admins',
            'blacklist_global_active',
            'blacklist_global_include_admins',
        ] as $key) {
            if (!empty($input[$key])) {
                $sanitary_values[$key] = 'on';
            }
        }

        foreach ([
            'blacklist_item_interval',
            'blacklist_item',
            'blacklist_location_interval',
            'blacklist_location',
            'blacklist_global_interval',
            'blacklist_global',
        ] as $key) {
            if (isset($input[$key])) {
                $sanitary_values[$key] = max(0, (int) $input[$key]);
            }
        }

        if (isset($input['blacklist_notice_text'])) {
            $sanitary_values['blacklist_notice_text'] = wp_kses_post($input['blacklist_notice_text']);
        }

        $rawGroups = $input[BookingGroups::OPTION_KEY] ?? [];
        $preparedGroups = $this->prepare_booking_groups($rawGroups);
        $normalizedGroups = BookingGroups::normalize(
            $preparedGroups,
            fn(int $itemId): bool => $this->is_valid_item_id($itemId)
        );
        $sanitary_values[BookingGroups::OPTION_KEY] = $normalizedGroups;

        if ($this->booking_groups_changed_during_normalization($preparedGroups, $normalizedGroups)) {
            add_settings_error(
                self::OPTION_KEY,
                'afcb-booking-groups-normalized',
                __('Einige Verbundangaben wurden nicht gespeichert. Jeder Verbund benötigt mindestens zwei gültige Artikel und jeder Artikel darf nur einem Verbund angehören.', 'cb-additional-features'),
                'warning'
            );
        }

        return $sanitary_values;
    }

    public function render(): void
    {
        $options = get_option(self::OPTION_KEY, []);
        $bookingGroups = BookingGroups::normalize(
            $options[BookingGroups::OPTION_KEY] ?? [],
            fn(int $itemId): bool => $this->is_valid_item_id($itemId)
        );
        $sections = $this->build_sections(
            $options,
            $bookingGroups,
            $this->get_selectable_items()
        );
        $current_log_page = $this->get_current_log_page();
        $log_entries = $this->build_log_entries($current_log_page);
        $stats = $this->build_stats();

        $context = [
            'title' => 'CommonBookings Additional Features',
            'action_url' => esc_url(admin_url('options.php')),
            'settings_fields' => $this->capture_settings_fields(self::OPTION_GROUP),
            'sections' => $sections,
            'logs' => $log_entries,
            'logs_pagination' => $this->build_logs_pagination($current_log_page),
            'stats' => $stats,
        ];

        echo TemplateLoader::load()->render('admin/blacklist-settings.html.twig', $context);
    }

    private function build_sections(array $options, array $bookingGroups, array $items): array
    {
        $displayGroups = $bookingGroups ?: [
            [
                'name' => '',
                'item_ids' => [],
            ],
        ];

        return [
            [
                'title' => 'Buchungsbeschränkung Artikel',
                'description' => 'Hier kann festgelegt werden, wie oft ein Artikel von einer Person im festgelegten Interval gebucht werden kann. Alle weiteren Buchungen werden automatisch abgelehnt.',
                'fields' => [
                    $this->checkbox_field('blacklist_item_active', 'Buchungsbeschränkung aktivieren', !empty($options['blacklist_item_active'])),
                    $this->number_field('blacklist_item', 'Max. Buchungen pro Artikel', $options['blacklist_item'] ?? ''),
                    $this->number_field('blacklist_item_interval', 'Interval (Tage)', $options['blacklist_item_interval'] ?? ''),
                    $this->checkbox_field(
                        'blacklist_item_followup_gap_active',
                        'Folgebuchung desselben Artikels erst nach 2 buchbaren Tagen erlauben',
                        !empty($options['blacklist_item_followup_gap_active']),
                        'Wenn aktiviert, muss zwischen dem Ende der letzten Buchung und der neuen Buchung desselben Artikels ein Abstand von mindestens 2 buchbaren Tagen liegen.'
                    ),
                    $this->checkbox_field('blacklist_item_include_admins', 'Admins einbeziehen', !empty($options['blacklist_item_include_admins'])),
                ],
            ],
            [
                'title' => 'Buchungsverbünde',
                'description' => 'Zusammengehörige Artikel mit identischem Standort und Buchungszeitraum werden für Standort- und Global-Limits als eine Buchung gezählt. Artikel-Limits und Verfügbarkeit bleiben getrennt.',
                'fields' => [
                    [
                        'type' => 'booking_groups',
                        'key' => BookingGroups::OPTION_KEY,
                        'groups' => $displayGroups,
                        'items' => $items,
                        'name_prefix' => self::OPTION_KEY . '[' . BookingGroups::OPTION_KEY . ']',
                        'next_index' => count($displayGroups),
                    ],
                ],
            ],
            [
                'title' => 'Buchungsbeschränkung Standort',
                'description' => 'Hier kann festgelegt werden, wie oft an einer Station von einer Person im festgelegten Interval gebucht werden kann.',
                'fields' => [
                    $this->checkbox_field('blacklist_location_active', 'Buchungsbeschränkung aktivieren', !empty($options['blacklist_location_active'])),
                    $this->number_field('blacklist_location', 'Max. Buchungen pro Standort', $options['blacklist_location'] ?? ''),
                    $this->number_field('blacklist_location_interval', 'Interval (Tage)', $options['blacklist_location_interval'] ?? ''),
                    $this->checkbox_field('blacklist_location_include_admins', 'Admins einbeziehen', !empty($options['blacklist_location_include_admins'])),
                ],
            ],
            [
                'title' => 'Buchungsbeschränkung Global',
                'description' => 'Hier kann festgelegt werden, wie oft generell von einer Person im festgelegten Interval gebucht werden kann.',
                'fields' => [
                    $this->checkbox_field('blacklist_global_active', 'Buchungsbeschränkung aktivieren', !empty($options['blacklist_global_active'])),
                    $this->number_field('blacklist_global', 'Max. Buchungen gesamt', $options['blacklist_global'] ?? ''),
                    $this->number_field('blacklist_global_interval', 'Interval (Tage)', $options['blacklist_global_interval'] ?? ''),
                    $this->checkbox_field('blacklist_global_include_admins', 'Admins einbeziehen', !empty($options['blacklist_global_include_admins'])),
                ],
            ],
            [
                'title' => 'Hinweis im Frontend',
                'description' => 'Dieser Text wird Nutzer:innen angezeigt, wenn eine Buchung aufgrund der Buchungsbeschränkungen nicht möglich ist.',
                'fields' => [
                    $this->textarea_field('blacklist_notice_text', 'Hinweistext', $options['blacklist_notice_text'] ?? BlacklistSettings::getDefaultNoticeText(), 'Verfügbare Platzhalter: {{reason}}, {{limit}}, {{interval}}.'),
                ],
            ],
        ];
    }

    /**
     * @param mixed $rawGroups
     * @return array<int, array{name: string, item_ids: array<int, int>}>
     */
    private function prepare_booking_groups($rawGroups): array
    {
        if (!is_array($rawGroups)) {
            return [];
        }

        $groups = [];
        foreach ($rawGroups as $rawGroup) {
            if (!is_array($rawGroup)) {
                continue;
            }

            $rawItemIds = $rawGroup['item_ids'] ?? [];
            if (!is_array($rawItemIds)) {
                $rawItemIds = [$rawItemIds];
            }

            $name = sanitize_text_field(wp_unslash((string) ($rawGroup['name'] ?? '')));
            $itemIds = array_values(array_filter(array_map('absint', $rawItemIds)));
            if ($name === '' && !$itemIds) {
                continue;
            }

            $groups[] = [
                'name' => $name,
                'item_ids' => $itemIds,
            ];
        }

        return $groups;
    }

    private function booking_groups_changed_during_normalization(array $preparedGroups, array $normalizedGroups): bool
    {
        $preparedMemberships = 0;
        foreach ($preparedGroups as $group) {
            $preparedMemberships += count(array_unique($group['item_ids'] ?? []));
        }

        $normalizedMemberships = 0;
        foreach ($normalizedGroups as $group) {
            $normalizedMemberships += count($group['item_ids']);
        }

        return count($preparedGroups) !== count($normalizedGroups)
            || $preparedMemberships !== $normalizedMemberships;
    }

    private function is_valid_item_id(int $itemId): bool
    {
        $post = get_post($itemId);

        return $post instanceof \WP_Post && $post->post_type === 'cb_item';
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private function get_selectable_items(): array
    {
        $posts = get_posts([
            'post_type' => 'cb_item',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        return array_map(
            static function (\WP_Post $post): array {
                $title = $post->post_title ?: __('Artikel', 'cb-additional-features');

                return [
                    'id' => (int) $post->ID,
                    'title' => sprintf('%s (#%d)', $title, $post->ID),
                ];
            },
            $posts
        );
    }

    private function build_log_entries(int $page): array
    {
        $total_entries = count(BlacklistLog::getEntries());
        $total_pages = max(1, (int) ceil($total_entries / self::LOGS_PER_PAGE));
        $page = min(max(1, $page), $total_pages);
        $offset = ($page - 1) * self::LOGS_PER_PAGE;
        $entries = array_slice(
            BlacklistLog::getRecentEntries(0),
            $offset,
            self::LOGS_PER_PAGE
        );
        if (!$entries) {
            return [];
        }

        $reason_map = $this->get_reason_labels();
        $rows = [];
        foreach ($entries as $entry) {
            $user = isset($entry['user_id']) ? get_userdata($entry['user_id']) : null;
            $user_name = $user
                ? trim($user->first_name . ' ' . $user->last_name) . ' (' . $user->user_login . ')'
                : sprintf(__('Nutzer #%d', 'cb-additional-features'), intval($entry['user_id'] ?? 0));
            $user_email = $user ? $user->user_email : '–';
            $item_title = isset($entry['item_id']) && $entry['item_id'] ? get_the_title($entry['item_id']) : '–';
            $location_title = isset($entry['location_id']) && $entry['location_id'] ? get_the_title($entry['location_id']) : '–';
            $start = isset($entry['start']) ? intval($entry['start']) : 0;
            $end = isset($entry['end']) ? intval($entry['end']) : 0;
            $period = $start && $end ? date_i18n('d.m.', $start) . ' - ' . date_i18n('d.m.Y', $end) : '–';
            $attempt = isset($entry['timestamp']) && $entry['timestamp'] ? date_i18n('d.m.Y H:i', $entry['timestamp']) : '–';
            $reason_key = $entry['rule'] ?? '';
            $reason_label = $reason_map[$reason_key] ?? __('Unbekannt', 'cb-additional-features');

            $rows[] = [
                'user' => $user_name,
                'email' => $user_email,
                'item' => $item_title ?: '–',
                'location' => $location_title ?: '–',
                'period' => $period,
                'attempt' => $attempt,
                'reason' => $reason_label,
            ];
        }

        return $rows;
    }

    private function get_current_log_page(): int
    {
        $page = isset($_GET['history_page']) ? intval(wp_unslash($_GET['history_page'])) : 1;

        return max(1, $page);
    }

    private function build_logs_pagination(int $current_page): array
    {
        $total_entries = count(BlacklistLog::getEntries());
        $total_pages = max(1, (int) ceil($total_entries / self::LOGS_PER_PAGE));
        $current_page = min(max(1, $current_page), $total_pages);

        $pages = [];
        for ($page = 1; $page <= $total_pages; $page++) {
            if (
                $page !== 1 &&
                $page !== $total_pages &&
                abs($page - $current_page) > 2
            ) {
                continue;
            }

            $pages[] = [
                'number' => $page,
                'url' => esc_url($this->get_log_page_url($page)),
                'current' => $page === $current_page,
            ];
        }

        return [
            'current_page' => $current_page,
            'total_pages' => $total_pages,
            'total_entries' => $total_entries,
            'per_page' => self::LOGS_PER_PAGE,
            'from' => $total_entries === 0 ? 0 : (($current_page - 1) * self::LOGS_PER_PAGE) + 1,
            'to' => min($total_entries, $current_page * self::LOGS_PER_PAGE),
            'previous_url' => $current_page > 1 ? esc_url($this->get_log_page_url($current_page - 1)) : '',
            'next_url' => $current_page < $total_pages ? esc_url($this->get_log_page_url($current_page + 1)) : '',
            'pages' => $pages,
        ];
    }

    private function get_log_page_url(int $page): string
    {
        return add_query_arg(
            [
                'page' => 'cbadf-blacklist',
                'history_page' => max(1, $page),
            ],
            admin_url('admin.php')
        );
    }

    private function build_stats(): array
    {
        $stats = BlacklistLog::getStats();
        if (!$stats) {
            return [];
        }

        uasort($stats, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        $rows = [];
        foreach ($stats as $user_id => $data) {
            $user = $user_id ? get_userdata($user_id) : null;
            $user_name = $user
                ? trim($user->first_name . ' ' . $user->last_name) . ' (' . $user->user_login . ')'
                : sprintf(__('Nutzer #%d', 'cb-additional-features'), intval($user_id));
            $last_attempt = $data['last_attempt'] ? date_i18n('d.m.Y H:i', $data['last_attempt']) : '–';

            $rows[] = [
                'user' => $user_name,
                'total' => $data['total'],
                'item' => $data['rules']['item'] ?? 0,
                'location' => $data['rules']['location'] ?? 0,
                'global' => $data['rules']['global'] ?? 0,
                'last_attempt' => $last_attempt,
            ];
        }

        return $rows;
    }

    private function get_reason_labels(): array
    {
        return [
            'item' => __('Artikel', 'cb-additional-features'),
            'following_item_gap' => __('Folgebuchung Artikel', 'cb-additional-features'),
            'location' => __('Standort', 'cb-additional-features'),
            'global' => __('Buchung', 'cb-additional-features'),
        ];
    }

    private function checkbox_field(string $key, string $label, bool $checked, string $help = ''): array
    {
        return [
            'type' => 'checkbox',
            'key' => $key,
            'label' => $label,
            'checked' => $checked,
            'help' => $help,
            'name' => self::OPTION_KEY . '[' . $key . ']',
        ];
    }

    private function number_field(string $key, string $label, $value): array
    {
        return [
            'type' => 'number',
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'name' => self::OPTION_KEY . '[' . $key . ']',
        ];
    }

    private function text_field(string $key, string $label, $value): array
    {
        return [
            'type' => 'text',
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'name' => self::OPTION_KEY . '[' . $key . ']',
        ];
    }

    private function textarea_field(string $key, string $label, $value, string $help = ''): array
    {
        return [
            'type' => 'textarea',
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'help' => $help,
            'name' => self::OPTION_KEY . '[' . $key . ']',
        ];
    }

    private function capture_settings_fields(string $group): string
    {
        ob_start();
        settings_fields($group);
        return (string) ob_get_clean();
    }
}
