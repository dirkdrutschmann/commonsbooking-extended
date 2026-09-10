<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Support\PluginPaths;

class BookingUserFilter
{
    private const FILTER_PARAM = 'admin_filter_user';
    private const FILTER_USER_ID_PARAM = 'admin_filter_user_id';
    private const TEXT_DOMAIN = 'cb-additional-features';
    private const AJAX_ACTION = 'cbadf_booking_user_autocomplete';
    private const NONCE_ACTION = 'cbadf_booking_user_autocomplete';
    private const SCRIPT_HANDLE = 'cbadf-booking-user-filter';
    private const STYLE_HANDLE = 'cbadf-booking-user-filter';
    private const AUTOCOMPLETE_LIMIT = 20;

    public function __construct()
    {
        add_action('restrict_manage_posts', [$this, 'renderFilter']);
        add_action('pre_get_posts', [$this, 'applyFilter']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleAutocomplete']);
    }

    public function renderFilter(): void
    {
        if (!$this->isBookingListScreen()) {
            return;
        }

        $value = $this->getRequestValue(self::FILTER_PARAM);
        $placeholder = __('User / E-Mail', self::TEXT_DOMAIN);

        echo TemplateLoader::load()->render('admin/booking-user-filter.html.twig', [
            'name' => self::FILTER_PARAM,
            'id' => self::FILTER_PARAM,
            'user_id_name' => self::FILTER_USER_ID_PARAM,
            'user_id_id' => self::FILTER_USER_ID_PARAM,
            'user_id' => $this->getRequestUserId(),
            'value' => $value,
            'placeholder' => $placeholder,
            'label' => __('Buchungen nach User oder E-Mail filtern', self::TEXT_DOMAIN),
        ]);
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'edit.php' || !$this->isBookingListScreen()) {
            return;
        }

        $scriptPath = 'assets/js/booking-user-filter.js';
        $stylePath = 'assets/css/booking-user-filter.css';

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            PluginPaths::asset_url($scriptPath),
            ['jquery', 'jquery-ui-autocomplete'],
            $this->getAssetVersion($scriptPath),
            true
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'cbadfBookingUserFilter', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'inputId' => self::FILTER_PARAM,
            'userIdInputId' => self::FILTER_USER_ID_PARAM,
            'minimumLength' => 2,
            'texts' => [
                'noResults' => __('Keine passenden User gefunden.', self::TEXT_DOMAIN),
                'oneResult' => __('Ein User gefunden. Mit den Pfeiltasten navigieren.', self::TEXT_DOMAIN),
                'manyResults' => __('%d User gefunden. Mit den Pfeiltasten navigieren.', self::TEXT_DOMAIN),
            ],
        ]);

        wp_enqueue_style(
            self::STYLE_HANDLE,
            PluginPaths::asset_url($stylePath),
            [],
            $this->getAssetVersion($stylePath)
        );
    }

    public function handleAutocomplete(): void
    {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Ungültige Anfrage.', self::TEXT_DOMAIN)], 403);
        }

        if (!$this->currentUserCanAccessBookingList()) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', self::TEXT_DOMAIN)], 403);
        }

        $term = $this->getPostedValue('term');
        if (strlen($term) < 2) {
            wp_send_json_success([]);
        }

        $users = $this->findUsersForAutocomplete($term, self::AUTOCOMPLETE_LIMIT);
        $results = array_map([$this, 'formatAutocompleteResult'], $users);

        wp_send_json_success($results);
    }

    public function applyFilter(\WP_Query $query): void
    {
        if (!$this->isBookingListQuery($query)) {
            return;
        }

        $filterValue = $this->getRequestValue(self::FILTER_PARAM);
        $searchValue = $this->getRequestValue('s');
        $term = $filterValue !== '' ? $filterValue : $searchValue;

        if ($term === '') {
            return;
        }

        $selectedUserId = $filterValue !== '' ? $this->getRequestUserId() : 0;
        $userIds = $selectedUserId > 0 && get_user_by('ID', $selectedUserId)
            ? [$selectedUserId]
            : $this->findUserIds($term);

        if (empty($userIds)) {
            if ($filterValue !== '') {
                $query->set('author__in', [0]);
                $query->set('s', '');
            }
            return;
        }

        $query->set('author__in', $userIds);
        $query->set('s', '');
    }

    private function isBookingListScreen(): bool
    {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        return $screen->id === 'edit-' . $this->getBookingPostType();
    }

    private function isBookingListQuery(\WP_Query $query): bool
    {
        if (!is_admin() || !$query->is_main_query()) {
            return false;
        }

        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return false;
        }

        $postType = $query->get('post_type');
        if (is_array($postType)) {
            $postType = reset($postType);
        }

        if (!$postType && isset($_GET['post_type'])) {
            $postType = sanitize_text_field(wp_unslash($_GET['post_type']));
        }

        return $postType === $this->getBookingPostType();
    }

    private function getRequestValue(string $key): string
    {
        if (!isset($_GET[$key])) {
            return '';
        }

        return trim(sanitize_text_field(wp_unslash($_GET[$key])));
    }

    private function getPostedValue(string $key): string
    {
        if (!isset($_POST[$key])) {
            return '';
        }

        return trim(sanitize_text_field(wp_unslash($_POST[$key])));
    }

    private function getRequestUserId(): int
    {
        if (!isset($_GET[self::FILTER_USER_ID_PARAM])) {
            return 0;
        }

        return absint(wp_unslash($_GET[self::FILTER_USER_ID_PARAM]));
    }

    private function findUserIds(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $ids = [];

        if (ctype_digit($term)) {
            $user = get_user_by('ID', (int) $term);
            if ($user) {
                $ids[] = (int) $user->ID;
            }
        }

        $ids = array_merge($ids, $this->findUsersByCoreFields($term));
        $ids = array_merge($ids, $this->findUsersByMeta($term));

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids;
    }

    private function findUsersByCoreFields(string $term): array
    {
        return get_users([
            'fields' => 'ID',
            'search' => '*' . $term . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
        ]);
    }

    private function findUsersByMeta(string $term): array
    {
        $query = new \WP_User_Query([
            'fields' => 'ID',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'first_name',
                    'value' => $term,
                    'compare' => 'LIKE',
                ],
                [
                    'key' => 'last_name',
                    'value' => $term,
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        return $query->get_results();
    }

    private function findUsersForAutocomplete(string $term, int $limit): array
    {
        $usersById = [];
        $exactUserId = 0;

        if (ctype_digit($term)) {
            $user = get_user_by('ID', (int) $term);
            if ($user instanceof \WP_User) {
                $exactUserId = (int) $user->ID;
                $usersById[$user->ID] = $user;
            }
        }

        $coreUsers = get_users([
            'number' => $limit,
            'orderby' => 'user_login',
            'order' => 'ASC',
            'search' => '*' . $term . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
        ]);

        foreach ($coreUsers as $user) {
            if ($user instanceof \WP_User) {
                $usersById[$user->ID] = $user;
            }
        }

        $metaQuery = new \WP_User_Query([
            'number' => $limit,
            'orderby' => 'user_login',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'first_name',
                    'value' => $term,
                    'compare' => 'LIKE',
                ],
                [
                    'key' => 'last_name',
                    'value' => $term,
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        foreach ($metaQuery->get_results() as $user) {
            if ($user instanceof \WP_User) {
                $usersById[$user->ID] = $user;
            }
        }

        $users = array_values($usersById);

        usort($users, static function (\WP_User $left, \WP_User $right) use ($exactUserId): int {
            if ($exactUserId > 0) {
                if ($left->ID === $exactUserId) {
                    return -1;
                }
                if ($right->ID === $exactUserId) {
                    return 1;
                }
            }

            return strcasecmp($left->user_login, $right->user_login);
        });

        return array_slice($users, 0, $limit);
    }

    private function formatAutocompleteResult(\WP_User $user): array
    {
        $details = array_values(array_filter([
            $user->display_name !== $user->user_login ? $user->display_name : '',
            $user->user_email,
        ]));

        $label = $user->user_login;
        if (!empty($details)) {
            $label .= ' — ' . implode(' · ', $details);
        }

        return [
            'id' => (int) $user->ID,
            'label' => $label,
            'value' => $user->user_login,
        ];
    }

    private function currentUserCanAccessBookingList(): bool
    {
        $postType = get_post_type_object($this->getBookingPostType());
        if ($postType && isset($postType->cap->edit_posts)) {
            return current_user_can($postType->cap->edit_posts);
        }

        return current_user_can('edit_' . $this->getBookingPostType() . 's');
    }

    private function getAssetVersion(string $relativePath): string
    {
        $path = PluginPaths::asset_path($relativePath);
        $hash = is_file($path) ? hash_file('sha256', $path) : false;

        return is_string($hash) ? substr($hash, 0, 12) : '1.0.0';
    }

    private function getBookingPostType(): string
    {
        if (class_exists('\\CommonsBooking\\Wordpress\\CustomPostType\\Booking')) {
            return \CommonsBooking\Wordpress\CustomPostType\Booking::$postType;
        }

        return 'cb_booking';
    }
}
