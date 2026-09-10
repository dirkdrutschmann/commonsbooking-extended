<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;

class UserManagementAdmin
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_afcb_review_user', [$this, 'handle_review_user']);
        add_action('wp_ajax_afcb_review_compare_data', [$this, 'handle_review_compare_data']);
        add_action('admin_post_afcb_create_user_management_page', [$this, 'handle_create_page']);
        add_action('admin_post_afcb_replace_user_management_content', [$this, 'handle_replace_content']);
        add_action('admin_post_afcb_manual_verify_user', [$this, 'handle_manual_verify_user']);
        add_action('admin_post_afcb_admin_resend_email_verification', [$this, 'handle_admin_resend_email_verification']);
        add_action('admin_post_afcb_save_user_data', [$this, 'handle_save_user_data']);
        add_action('admin_post_afcb_admin_delete_user', [$this, 'handle_admin_delete_user']);
        add_action('wp_ajax_afcb_user_bookings', [$this, 'handle_ajax_user_bookings']);
        add_filter('user_row_actions', [$this, 'add_user_row_action_detail'], 10, 2);
        add_filter('manage_users_columns', [$this, 'add_users_columns']);
        add_filter('manage_users_sortable_columns', [$this, 'sortable_users_columns']);
        add_filter('manage_users_custom_column', [$this, 'render_users_column'], 10, 3);
        add_action('restrict_manage_users', [$this, 'render_users_filters']);
        add_action('pre_get_users', [$this, 'apply_users_filters']);
        add_action('pre_user_query', [$this, 'apply_users_orderby']);
        add_action('admin_head-users.php', [$this, 'users_list_inline_styles']);
    }

    /**
     * Link „Nutzerdaten & Verifizierung“ in der WordPress-Benutzerliste (Benutzer → Alle Benutzer).
     *
     * @param array<string, string> $actions
     * @param \WP_User $user
     * @return array<string, string>
     */
    public function add_user_row_action_detail(array $actions, \WP_User $user): array
    {
        if (!current_user_can('manage_options')) {
            return $actions;
        }
        $url = add_query_arg(
            ['page' => 'cbadf-user-management', 'afcb_user_id' => $user->ID],
            admin_url('admin.php')
        );
        $actions['afcb_user_detail'] = '<a href="' . esc_url($url) . '">Nutzerdaten &amp; Verifizierung</a>';
        return $actions;
    }

    /**
     * Zusätzliche Spalten in der WordPress-Benutzerliste (Benutzer → Alle Benutzer).
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function add_users_columns(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            if ($key === 'posts') {
                $new['afcb_bookings'] = 'Buchungen';
                $new['afcb_last_booking'] = 'Letzte Buchung';
                continue;
            }
            $new[$key] = $label;
            if ($key === 'email') {
                $new['afcb_registered'] = 'Registriert';
                $new['afcb_last_login'] = 'Letzter Login';
                $new['afcb_verified'] = 'Verifizierung';
            }
        }
        if (!isset($new['afcb_registered'])) {
            $new['afcb_registered'] = 'Registriert';
        }
        if (!isset($new['afcb_last_login'])) {
            $new['afcb_last_login'] = 'Letzter Login';
            $new['afcb_verified'] = 'Verifizierung';
        }
        if (!isset($new['afcb_bookings'])) {
            $new['afcb_bookings'] = 'Buchungen';
            $new['afcb_last_booking'] = 'Letzte Buchung';
        }
        return $new;
    }

    /**
     * Inhalt der benutzerdefinierten Spalten in der Benutzerliste.
     *
     * @param string $output
     * @param string $column_name
     * @param int $user_id
     * @return string
     */
    public function render_users_column($output, $column_name, $user_id)
    {
        if ($column_name === 'afcb_registered') {
            $user = get_userdata($user_id);
            if (!$user || empty($user->user_registered)) {
                return '<span class="afcb-muted">—</span>';
            }

            $timestamp = strtotime((string) $user->user_registered);
            if (!$timestamp) {
                return '<span class="afcb-muted">—</span>';
            }

            return esc_html(wp_date('d.m.Y H:i', $timestamp));
        }

        if ($column_name === 'afcb_last_login') {
            $ts = (int) get_user_meta($user_id, UserManagement::META_LAST_LOGIN, true);
            if ($ts > 0) {
                return esc_html(wp_date('d.m.Y H:i', $ts));
            }
            return '<span class="afcb-muted">—</span>';
        }
        if ($column_name === 'afcb_verified') {
            $email_ok = (bool) get_user_meta($user_id, UserManagement::META_EMAIL_VERIFIED_AT, true);
            $phone_ok = UserManagement::is_phone_verified($user_id);
            $parts = [];
            $parts[] = $this->verified_icon('email', $email_ok, __('E-Mail verifiziert', 'cb-additional-features'), __('E-Mail nicht verifiziert', 'cb-additional-features'));
            $parts[] = $this->verified_icon('phone', $phone_ok, __('Telefon verifiziert', 'cb-additional-features'), __('Telefon nicht verifiziert', 'cb-additional-features'));
            return implode(' ', $parts);
        }
        if ($column_name === 'afcb_bookings') {
            $count = $this->get_booking_count_for_user($user_id);
            $url = add_query_arg(['page' => 'cbadf-user-management', 'afcb_user_id' => $user_id], admin_url('admin.php'));
            if ($count > 0) {
                return '<a href="' . esc_url($url) . '#afcb-user-bookings">' . (int) $count . '</a>';
            }
            return (string) $count;
        }
        if ($column_name === 'afcb_last_booking') {
            $ts = $this->get_last_booking_date_for_user($user_id);
            if ($ts > 0) {
                return esc_html(wp_date('d.m.Y H:i', $ts));
            }
            return '<span class="afcb-muted">—</span>';
        }
        return $output;
    }

    /**
     * Einzelnes Verifizierungs-Icon (grün = verifiziert, rot = nicht verifiziert).
     */
    private function verified_icon(string $type, bool $verified, string $title_yes, string $title_no): string
    {
        $title = $verified ? $title_yes : $title_no;
        $color = $verified ? '#059669' : '#dc2626';
        if ($type === 'email') {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;color:' . esc_attr($color) . '" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>';
        } else {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;color:' . esc_attr($color) . '" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
        }
        return '<span title="' . esc_attr($title) . '" class="afcb-verified-icon">' . $svg . '</span>';
    }

    /**
     * Sortierbare Spalten in der Benutzerliste.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function sortable_users_columns(array $columns): array
    {
        $columns['afcb_bookings'] = 'afcb_bookings';
        $columns['afcb_registered'] = 'afcb_registered';
        $columns['afcb_last_login'] = 'afcb_last_login';
        $columns['afcb_last_booking'] = 'afcb_last_booking';
        return $columns;
    }

    /**
     * Filter-Dropdowns oberhalb der Benutzerliste ausgeben.
     */
    public function render_users_filters(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'users') {
            return;
        }
        $last_login = isset($_GET['afcb_filter_last_login']) ? sanitize_key($_GET['afcb_filter_last_login']) : '';
        $email_verified = isset($_GET['afcb_filter_email_verified']) ? sanitize_key($_GET['afcb_filter_email_verified']) : '';
        $phone_verified = isset($_GET['afcb_filter_phone_verified']) ? sanitize_key($_GET['afcb_filter_phone_verified']) : '';
        $options_last = [
            '' => 'Letzter Login',
            'never' => 'Nie eingeloggt',
            'last_7' => 'Letzte 7 Tage',
            'last_30' => 'Letzte 30 Tage',
            'last_90' => 'Letzte 90 Tage',
        ];
        echo '<select name="afcb_filter_last_login" id="afcb-filter-last-login" style="float:none;margin-left:8px">';
        foreach ($options_last as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($last_login, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<select name="afcb_filter_email_verified" id="afcb-filter-email-verified" style="float:none;margin-left:8px">';
        echo '<option value=""' . selected($email_verified, '', false) . '>E-Mail</option>';
        echo '<option value="yes"' . selected($email_verified, 'yes', false) . '>E-Mail: Ja</option>';
        echo '<option value="no"' . selected($email_verified, 'no', false) . '>E-Mail: Nein</option>';
        echo '</select>';
        echo '<select name="afcb_filter_phone_verified" id="afcb-filter-phone-verified" style="float:none;margin-left:8px">';
        echo '<option value=""' . selected($phone_verified, '', false) . '>Telefon</option>';
        echo '<option value="yes"' . selected($phone_verified, 'yes', false) . '>Telefon: Ja</option>';
        echo '<option value="no"' . selected($phone_verified, 'no', false) . '>Telefon: Nein</option>';
        echo '</select>';
        echo '<script>(function(){var s1=document.getElementById("afcb-filter-last-login"),s2=document.getElementById("afcb-filter-email-verified"),s3=document.getElementById("afcb-filter-phone-verified");if(!s1)return;function applyFilter(){var params=new URLSearchParams(window.location.search);var keys=["afcb_filter_last_login","afcb_filter_email_verified","afcb_filter_phone_verified"];params.set(keys[0],s1.value||"");params.set(keys[1],s2?s2.value:"");params.set(keys[2],s3?s3.value:"");keys.forEach(function(k){if(!params.get(k))params.delete(k);});var q=params.toString();window.location=(q?window.location.pathname+"?"+q:window.location.pathname);}s1.addEventListener("change",applyFilter);if(s2)s2.addEventListener("change",applyFilter);if(s3)s3.addEventListener("change",applyFilter);})();</script>';
    }

    /**
     * Benutzerliste nach afcb-Filtern einschränken.
     *
     * @param \WP_User_Query $query
     */
    public function apply_users_filters(\WP_User_Query $query): void
    {
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : '';
        if ($orderby === 'afcb_registered') {
            $query->set('orderby', 'registered');
            $order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';
            $query->set('order', $order);
        }

        if ($orderby === 'afcb_last_login') {
            $query->set('orderby', 'meta_value_num');
            $query->set('meta_key', UserManagement::META_LAST_LOGIN);
            $order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';
            $query->set('order', $order);
        }

        $last = isset($_GET['afcb_filter_last_login']) ? sanitize_key($_GET['afcb_filter_last_login']) : '';
        $email = isset($_GET['afcb_filter_email_verified']) ? sanitize_key($_GET['afcb_filter_email_verified']) : '';
        $phone = isset($_GET['afcb_filter_phone_verified']) ? sanitize_key($_GET['afcb_filter_phone_verified']) : '';
        $meta_query = $query->get('meta_query') ?: [];
        if (!is_array($meta_query)) {
            $meta_query = [];
        }
        if ($last === 'never') {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => UserManagement::META_LAST_LOGIN,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => UserManagement::META_LAST_LOGIN,
                    'value' => '0',
                    'compare' => '=',
                ],
            ];
        } elseif (in_array($last, ['last_7', 'last_30', 'last_90'], true)) {
            $days = (int) str_replace('last_', '', $last);
            $meta_query[] = [
                'key' => UserManagement::META_LAST_LOGIN,
                'value' => (string) (time() - $days * 86400),
                'compare' => '>=',
                'type' => 'NUMERIC',
            ];
        }
        if ($email === 'yes') {
            $meta_query[] = [
                'key' => UserManagement::META_EMAIL_VERIFIED_AT,
                'compare' => 'EXISTS',
            ];
        } elseif ($email === 'no') {
            $meta_query[] = [
                'key' => UserManagement::META_EMAIL_VERIFIED_AT,
                'compare' => 'NOT EXISTS',
            ];
        }
        if ($phone === 'yes') {
            $meta_query[] = [
                'key' => UserManagement::META_PHONE_VERIFIED_AT,
                'compare' => 'EXISTS',
            ];
        } elseif ($phone === 'no') {
            $meta_query[] = [
                'key' => UserManagement::META_PHONE_VERIFIED_AT,
                'compare' => 'NOT EXISTS',
            ];
        }
        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Benutzerliste nach Buchungen oder letzter Buchung sortieren (per JOIN auf Buchungs-Posts).
     *
     * @param \WP_User_Query $wp_user_query
     */
    public function apply_users_orderby(\WP_User_Query $wp_user_query): void
    {
        global $pagenow;
        if (!is_admin() || $pagenow !== 'users.php') {
            return;
        }
        $orderby = $wp_user_query->get('orderby');
        $order = strtoupper((string) $wp_user_query->get('order'));
        if ($order !== 'ASC' && $order !== 'DESC') {
            $order = 'DESC';
        }
        if ($orderby !== 'afcb_bookings' && $orderby !== 'afcb_last_booking') {
            return;
        }

        global $wpdb;
        $post_type = $this->get_booking_post_type();
        $post_type_esc = esc_sql($post_type);

        if ($orderby === 'afcb_bookings') {
            $wp_user_query->query_from .= " LEFT JOIN (SELECT post_author AS uid, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type = '{$post_type_esc}' GROUP BY post_author) AS afcb_b ON afcb_b.uid = {$wpdb->users}.ID ";
            $wp_user_query->query_orderby = ' ORDER BY COALESCE(afcb_b.cnt, 0) ' . $order . ' ';
        } else {
            $wp_user_query->query_from .= " LEFT JOIN (SELECT post_author AS uid, MAX(post_date) AS last_d FROM {$wpdb->posts} WHERE post_type = '{$post_type_esc}' GROUP BY post_author) AS afcb_lb ON afcb_lb.uid = {$wpdb->users}.ID ";
            $wp_user_query->query_orderby = ' ORDER BY afcb_lb.last_d ' . $order . ' ';
        }
    }

    /**
     * Minimales Styling für die Benutzerliste (Spalte „Letzter Login“, Verifizierungs-Icons).
     */
    public function users_list_inline_styles(): void
    {
        echo "<style>.afcb-muted{color:#a0aec0;}.afcb-verified-icon{display:inline-block;margin-right:2px;}</style>\n";
    }

    public function add_pages(): void
    {
        $pending_review_count = UserManagement::count_pending_reviews();
        $page_title = $pending_review_count > 0
            ? sprintf('User Management (%d offene Prüfungen)', $pending_review_count)
            : 'User Management';

        add_submenu_page(
            'cbadf',
            $page_title,
            'User Management' . $this->format_pending_review_menu_count($pending_review_count),
            'manage_options',
            'cbadf-user-management',
            [$this, 'render_page']
        );
    }

    private function format_pending_review_menu_count(int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return sprintf(
            ' <span class="update-plugins count-%1$d"><span class="plugin-count">%2$s</span></span>',
            $count,
            esc_html(number_format_i18n($count))
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'afcb_user_management_option_group',
            UserManagement::OPTION_KEY,
            [$this, 'sanitize_options']
        );
    }

    public function sanitize_options($input): array
    {
        $options = UserManagement::get_options();

        $options['registration_page_id'] = array_key_exists('registration_page_id', $input)
            ? (int) $input['registration_page_id']
            : (int) ($options['registration_page_id'] ?? 0);
        $options['login_page_id'] = array_key_exists('login_page_id', $input)
            ? (int) $input['login_page_id']
            : (int) ($options['login_page_id'] ?? 0);
        $options['profile_page_id'] = array_key_exists('profile_page_id', $input)
            ? (int) $input['profile_page_id']
            : (int) ($options['profile_page_id'] ?? 0);
        $options['forgot_password_page_id'] = array_key_exists('forgot_password_page_id', $input)
            ? (int) $input['forgot_password_page_id']
            : (int) ($options['forgot_password_page_id'] ?? 0);
        $options['forgot_username_page_id'] = array_key_exists('forgot_username_page_id', $input)
            ? (int) $input['forgot_username_page_id']
            : (int) ($options['forgot_username_page_id'] ?? 0);
        $options['privacy_page_id'] = array_key_exists('privacy_page_id', $input)
            ? (int) $input['privacy_page_id']
            : (int) ($options['privacy_page_id'] ?? 0);
        $options['terms_page_id'] = array_key_exists('terms_page_id', $input)
            ? (int) $input['terms_page_id']
            : (int) ($options['terms_page_id'] ?? 0);
        $options['privacy_url'] = array_key_exists('privacy_url', $input)
            ? esc_url_raw($input['privacy_url'])
            : (string) ($options['privacy_url'] ?? '');
        $options['terms_url'] = array_key_exists('terms_url', $input)
            ? esc_url_raw($input['terms_url'])
            : (string) ($options['terms_url'] ?? '');

        $required_fields = array_key_exists('required_fields', $input) && is_array($input['required_fields'])
            ? array_map('sanitize_key', $input['required_fields'])
            : (array) ($options['required_fields'] ?? []);

        $options['recaptcha_site_key'] = array_key_exists('recaptcha_site_key', $input)
            ? sanitize_text_field($input['recaptcha_site_key'])
            : (string) ($options['recaptcha_site_key'] ?? '');
        $options['recaptcha_secret_key'] = array_key_exists('recaptcha_secret_key', $input)
            ? sanitize_text_field($input['recaptcha_secret_key'])
            : (string) ($options['recaptcha_secret_key'] ?? '');
        $options['recaptcha_version'] = array_key_exists('recaptcha_version', $input) && in_array($input['recaptcha_version'], ['v2', 'v3'], true)
            ? $input['recaptcha_version']
            : (string) ($options['recaptcha_version'] ?? 'v2');
        $options['recaptcha_threshold'] = array_key_exists('recaptcha_threshold', $input)
            ? (float) $input['recaptcha_threshold']
            : (float) ($options['recaptcha_threshold'] ?? 0.5);
        $options['nominatim_email'] = array_key_exists('nominatim_email', $input)
            ? sanitize_email($input['nominatim_email'])
            : (string) ($options['nominatim_email'] ?? '');
        $options['fallback_login_slug'] = array_key_exists('fallback_login_slug', $input)
            ? sanitize_title($input['fallback_login_slug'])
            : (string) ($options['fallback_login_slug'] ?? 'fallback-login');
        if (array_key_exists('review_notification_emails', $input)) {
            $review_emails = UserManagement::parse_review_notification_emails((string) $input['review_notification_emails']);
            $options['review_notification_emails'] = implode("\n", $review_emails);
        } else {
            $options['review_notification_emails'] = (string) ($options['review_notification_emails'] ?? '');
        }

        $options['sms_api_url'] = array_key_exists('sms_api_url', $input)
            ? UserManagement::normalize_messages_api_url(esc_url_raw(trim((string) $input['sms_api_url'])))
            : (string) ($options['sms_api_url'] ?? '');
        if (array_key_exists('sms_api_key', $input)) {
            $key = sanitize_text_field($input['sms_api_key']);
            if ($key !== '') {
                $options['sms_api_key'] = $key;
            }
        }
        $options['sms_verification_message'] = array_key_exists('sms_verification_message', $input)
            ? sanitize_textarea_field($input['sms_verification_message'])
            : (string) ($options['sms_verification_message'] ?? '');

        $email_definitions = UserManagement::get_email_template_definitions();
        $options['email_html_template'] = array_key_exists('email_html_template', $input)
            ? $this->sanitize_email_html($input['email_html_template'])
            : (string) ($options['email_html_template'] ?? '');
        $options['email_header_image_cid'] = array_key_exists('email_header_image_cid', $input)
            ? sanitize_text_field($input['email_header_image_cid'])
            : (string) ($options['email_header_image_cid'] ?? '');
        $options['email_header_image_alt'] = array_key_exists('email_header_image_alt', $input)
            ? sanitize_text_field($input['email_header_image_alt'])
            : (string) ($options['email_header_image_alt'] ?? '');
        $options['email_footer_text'] = array_key_exists('email_footer_text', $input)
            ? $this->sanitize_email_html($input['email_footer_text'])
            : (string) ($options['email_footer_text'] ?? '');

        foreach ($email_definitions as $definition) {
            $subject_key = $definition['subject_key'];
            $body_key = $definition['body_key'];

            if (array_key_exists($subject_key, $input)) {
                $options[$subject_key] = sanitize_text_field($input[$subject_key]);
            }
            if (array_key_exists($body_key, $input)) {
                $options[$body_key] = $this->sanitize_email_html($input[$body_key]);
            }
        }

        $custom_fields = $options['custom_fields'] ?? [];
        if (array_key_exists('custom_fields', $input) && is_array($input['custom_fields'])) {
            $custom_fields_raw = $input['custom_fields'];
            $custom_fields = [];
            foreach ($custom_fields_raw as $field) {
                $key = sanitize_key($field['key'] ?? '');
                $label = sanitize_text_field($field['label'] ?? '');
                $type = sanitize_text_field($field['type'] ?? 'text');
                $options_raw = sanitize_text_field($field['options'] ?? '');
                $required = !empty($field['required']);

                if ($key === '' || $label === '') {
                    continue;
                }

                if (in_array($key, ['user_login', 'user_pass', 'user_email'], true)) {
                    continue;
                }

                $custom_fields[] = [
                    'key' => $key,
                    'label' => $label,
                    'type' => in_array($type, ['text', 'textarea', 'number', 'date', 'select'], true) ? $type : 'text',
                    'options' => $options_raw,
                    'required' => $required,
                ];
            }
        }

        foreach ($custom_fields as $field) {
            if (!empty($field['required'])) {
                $required_fields[] = $field['key'];
            }
        }

        $allowed_required = array_keys(UserManagement::get_default_field_definitions());
        foreach ($custom_fields as $field) {
            if (!empty($field['key'])) {
                $allowed_required[] = $field['key'];
            }
        }

        $options['custom_fields'] = $custom_fields;
        $options['required_fields'] = UserManagement::normalize_required_fields(
            array_values(array_unique(array_intersect(array_filter($required_fields), $allowed_required)))
        );

        return $options;
    }

    private function sanitize_email_html($value): string
    {
        $value = is_string($value) ? $value : '';
        $allowed = wp_kses_allowed_html('post');
        $allowed['style'] = ['type' => true];
        $allowed['table'] = ['role' => true, 'width' => true, 'cellspacing' => true, 'cellpadding' => true, 'style' => true, 'border' => true, 'align' => true, 'bgcolor' => true];
        $allowed['tr'] = ['style' => true, 'bgcolor' => true];
        $allowed['td'] = ['style' => true, 'align' => true, 'valign' => true, 'width' => true, 'bgcolor' => true];
        $allowed['th'] = ['style' => true, 'align' => true, 'valign' => true, 'width' => true];
        $allowed['tbody'] = ['style' => true];
        $allowed['thead'] = ['style' => true];
        $allowed['tfoot'] = ['style' => true];
        $allowed['span']['style'] = true;
        $allowed['div']['style'] = true;
        $allowed['p']['style'] = true;
        $allowed['a']['style'] = true;
        $allowed['a']['target'] = true;
        $allowed['a']['rel'] = true;
        $allowed['h1']['style'] = true;
        $allowed['h2']['style'] = true;
        $allowed['h3']['style'] = true;
        $allowed['ul']['style'] = true;
        $allowed['li']['style'] = true;

        return wp_kses($value, $allowed);
    }

    public function enqueue_assets(string $hook): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'cbadf-user-management') {
            return;
        }

        wp_enqueue_editor();

        $base_url = plugin_dir_url(dirname(__DIR__, 2) . '/commonsbooking-extended.php');

        wp_enqueue_script(
            'afcb-user-management-admin',
            $base_url . 'assets/js/afcb-user-management-admin.js',
            [],
            '0.3',
            true
        );
        wp_localize_script('afcb-user-management-admin', 'afcbUserManagementAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'compareNonce' => wp_create_nonce('afcb_review_compare'),
        ]);
    }

    public function render_page(): void
    {
        $user_id = isset($_GET['afcb_user_id']) ? (int) $_GET['afcb_user_id'] : 0;
        if ($user_id && current_user_can('manage_options')) {
            $user = get_userdata($user_id);
            if ($user) {
                $this->render_user_detail_page($user_id, $user);
                return;
            }
        }

        $notice = isset($_GET['afcb_notice']) ? sanitize_text_field(wp_unslash($_GET['afcb_notice'])) : '';
        $notice_type = isset($_GET['afcb_notice_type']) ? sanitize_text_field(wp_unslash($_GET['afcb_notice_type'])) : '';

        $options = UserManagement::get_options();
        $required_fields = isset($options['required_fields']) && is_array($options['required_fields']) ? $options['required_fields'] : [];
        $custom_fields = UserManagement::get_custom_fields();
        $default_fields = UserManagement::get_default_field_definitions();

        $pending_users = get_users([
            'meta_key' => UserManagement::META_ACCOUNT_STATUS,
            'meta_value' => 'pending',
            'number' => 50,
        ]);
        $pending_review_count = UserManagement::count_pending_reviews();
        $context = [
            'notice' => $notice,
            'notice_type' => $notice_type,
            'settings_fields' => $this->capture_settings_fields('afcb_user_management_option_group'),
            'options' => $options,
            'admin_post_url' => esc_url(admin_url('admin-post.php')),
            'option_key' => UserManagement::OPTION_KEY,
            'basic_fields' => $this->build_basic_fields($options),
            'page_select_fields' => $this->build_page_select_fields($options),
            'page_rows' => $this->build_page_management_rows($options),
            'required_fields' => $this->build_required_fields($default_fields, $required_fields),
            'custom_fields' => $this->build_custom_fields($custom_fields),
            'custom_fields_index' => count($custom_fields),
            'custom_field_types' => $this->get_custom_field_types(),
            'email_html_template' => $options['email_html_template'] ?? '',
            'email_header_image_cid' => $options['email_header_image_cid'] ?? '',
            'email_header_image_alt' => $options['email_header_image_alt'] ?? '',
            'email_footer_editor' => $this->render_footer_editor((string) ($options['email_footer_text'] ?? '')),
            'email_templates' => $this->build_email_templates($options),
            'sms_fields' => $this->build_sms_fields($options),
            'review_notification_emails' => (string) ($options['review_notification_emails'] ?? ''),
            'pending_review_count' => $pending_review_count,
            'pending_users' => $this->build_pending_users($pending_users),
            'wp_users_url' => admin_url('users.php'),
        ];

        echo TemplateLoader::load()->render('admin/user-management.html.twig', $context);
    }

    private function build_page_select_fields(array $options): array
    {
        $pages = $this->get_page_options();
        $fields = [];

        foreach (UserManagement::get_page_definitions() as $key => $definition) {
            $fields[] = [
                'key' => $key,
                'label' => $definition['title'],
                'name' => UserManagement::OPTION_KEY . '[' . $key . ']',
                'options' => $this->mark_selected($pages, $options[$key] ?? 0),
            ];
        }

        return $fields;
    }

    private function build_basic_fields(array $options): array
    {
        $pages = $this->get_page_options();

        return [
            $this->page_or_url_field(
                'privacy',
                'Datenschutz',
                (int) ($options['privacy_page_id'] ?? 0),
                (string) ($options['privacy_url'] ?? ''),
                $pages
            ),
            $this->page_or_url_field(
                'terms',
                'Nutzungsbedingungen',
                (int) ($options['terms_page_id'] ?? 0),
                (string) ($options['terms_url'] ?? ''),
                $pages
            ),
            $this->text_field('recaptcha_site_key', 'reCAPTCHA Site-Key', $options['recaptcha_site_key'] ?? ''),
            $this->text_field('recaptcha_secret_key', 'reCAPTCHA Secret-Key', $options['recaptcha_secret_key'] ?? '', 'password'),
            $this->select_field('recaptcha_version', 'reCAPTCHA Version', $options['recaptcha_version'] ?? 'v2', [
                ['value' => 'v2', 'label' => 'v2 Checkbox'],
                ['value' => 'v3', 'label' => 'v3 (Score)'],
            ]),
            $this->text_field('recaptcha_threshold', 'reCAPTCHA v3 Schwelle', (string) ($options['recaptcha_threshold'] ?? 0.5)),
            $this->text_field(
                'nominatim_email',
                'OpenStreetMap Kontakt E-Mail',
                $options['nominatim_email'] ?? '',
                'email',
                'Optionale E-Mail für die Adresssuche. Wird an Nominatim/OpenStreetMap übermittelt, damit Betreiber dich bei technischen Problemen oder zu vielen Anfragen erreichen können.'
            ),
            $this->text_field('fallback_login_slug', 'Fallback Login Slug', $options['fallback_login_slug'] ?? ''),
        ];
    }

    private function build_page_management_rows(array $options): array
    {
        $rows = [];
        foreach (UserManagement::get_page_definitions() as $key => $definition) {
            $page_id = isset($options[$key]) ? (int) $options[$key] : 0;
            $page_title = $page_id ? get_the_title($page_id) : '— nicht gesetzt —';
            $edit_link = $page_id ? get_edit_post_link($page_id) : '';
            $rows[] = [
                'key' => $key,
                'title' => $definition['title'],
                'page_id' => $page_id,
                'page_title' => $page_title,
                'edit_link' => $edit_link,
                'create_nonce' => wp_create_nonce('afcb_create_page_' . $key),
                'replace_nonce' => wp_create_nonce('afcb_replace_content_' . $key),
            ];
        }

        return $rows;
    }

    private function build_required_fields(array $default_fields, array $required_fields): array
    {
        $fields = [];
        foreach ($default_fields as $key => $label) {
            $fields[] = [
                'key' => $key,
                'label' => $label,
                'checked' => in_array($key, $required_fields, true),
                'name' => UserManagement::OPTION_KEY . '[required_fields][]',
            ];
        }

        return $fields;
    }

    private function build_custom_fields(array $custom_fields): array
    {
        $fields = [];
        foreach ($custom_fields as $index => $field) {
            $fields[] = [
                'index' => $index,
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'options_raw' => $field['options_raw'] ?? '',
                'required' => !empty($field['required']),
            ];
        }

        return $fields;
    }

    private function build_email_templates(array $options): array
    {
        $templates = [];
        foreach (UserManagement::get_email_template_definitions() as $definition) {
            $templates[] = [
                'label' => $definition['label'],
                'subject_key' => $definition['subject_key'],
                'body_key' => $definition['body_key'],
                'subject_value' => $options[$definition['subject_key']] ?? '',
                'body_value' => $options[$definition['body_key']] ?? '',
            ];
        }

        return $templates;
    }

    private function render_footer_editor(string $value): string
    {
        ob_start();
        wp_editor($value, 'afcb_email_footer_text', [
            'textarea_name' => UserManagement::OPTION_KEY . '[email_footer_text]',
            'textarea_rows' => 6,
            'media_buttons' => false,
            'teeny' => true,
            'quicktags' => true,
        ]);
        return (string) ob_get_clean();
    }

    private function build_sms_fields(array $options): array
    {
        $default_message = 'Hallo {{vorname}} {{nachname}}, der Code zum Verifizieren deiner Telefonnummer für {{page_title}} ist: {{code}}. Er ist 1 Stunde gültig.';
        return [
            $this->text_field('sms_api_url', 'Nachrichten API-URL', $options['sms_api_url'] ?? '', 'url'),
            $this->text_field('sms_api_key', 'Nachrichten API-Key', $options['sms_api_key'] ?? '', 'password'),
            [
                'key' => 'sms_verification_message',
                'label' => 'Verifizierungstext für SMS/Signal',
                'name' => UserManagement::OPTION_KEY . '[sms_verification_message]',
                'type' => 'textarea',
                'value' => $options['sms_verification_message'] ?? $default_message,
            ],
        ];
    }

    private function text_field(string $key, string $label, string $value, string $type = 'text', string $help = ''): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'name' => UserManagement::OPTION_KEY . '[' . $key . ']',
            'type' => $type,
            'value' => $value,
            'help' => $help,
        ];
    }

    private function page_or_url_field(string $key, string $label, int $page_id, string $url, array $pages): array
    {
        return [
            'key' => $key . '_page_id',
            'label' => $label,
            'type' => 'page_or_url',
            'page_name' => UserManagement::OPTION_KEY . '[' . $key . '_page_id]',
            'page_id' => $key . '_page_id',
            'page_options' => $this->mark_selected($pages, $page_id),
            'url_name' => UserManagement::OPTION_KEY . '[' . $key . '_url]',
            'url_id' => $key . '_url',
            'url_value' => $url,
            'help' => 'Interne WordPress-Seite auswählen oder alternativ eine externe URL eintragen.',
        ];
    }

    private function select_field(string $key, string $label, string $value, array $choices): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'name' => UserManagement::OPTION_KEY . '[' . $key . ']',
            'type' => 'select',
            'options' => $this->mark_selected($choices, $value),
        ];
    }

    private function build_pending_users(array $pending_users): array
    {
        $rows = [];
        foreach ($pending_users as $user) {
            $match_ids = get_user_meta($user->ID, UserManagement::META_ACCOUNT_REVIEW_MATCH_IDS, true);
            $match_ids = is_array($match_ids) ? array_map('intval', $match_ids) : [];
            if (empty($match_ids)) {
                $match_ids = array_map(
                    static fn(\WP_User $match): int => (int) $match->ID,
                    UserManagement::find_possible_duplicate_users((int) $user->ID)
                );
            }
            $reason = (string) get_user_meta($user->ID, UserManagement::META_ACCOUNT_REVIEW_REASON, true);
            $rows[] = [
                'id' => $user->ID,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'reason' => UserManagement::get_review_reason_label($reason),
                'detail_url' => esc_url_raw(add_query_arg(
                    ['page' => 'cbadf-user-management', 'afcb_user_id' => $user->ID],
                    admin_url('admin.php')
                )),
                'nonce' => wp_create_nonce('afcb_review_user_' . $user->ID),
                'has_compare' => !empty($match_ids),
            ];
        }

        return $rows;
    }

    public function handle_review_compare_data(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unzureichende Berechtigung.'], 403);
        }

        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        if (!$user_id) {
            wp_send_json_error(['message' => 'user_id fehlt.'], 400);
        }

        check_ajax_referer('afcb_review_compare', 'nonce');

        $status = (string) get_user_meta($user_id, UserManagement::META_ACCOUNT_STATUS, true);
        if ($status !== 'pending') {
            wp_send_json_error(['message' => 'User steht nicht zur Prüfung.'], 400);
        }

        $user_data = UserManagement::get_user_display_data($user_id);
        $match_ids = get_user_meta($user_id, UserManagement::META_ACCOUNT_REVIEW_MATCH_IDS, true);
        $match_ids = is_array($match_ids) ? array_filter(array_map('intval', $match_ids)) : [];
        if (empty($match_ids)) {
            $match_ids = array_map(
                static fn(\WP_User $match): int => (int) $match->ID,
                UserManagement::find_possible_duplicate_users($user_id)
            );
        }
        $matches = [];

        foreach ($match_ids as $match_id) {
            if ($match_id === $user_id) {
                continue;
            }
            $match_user = get_userdata($match_id);
            if (!$match_user) {
                continue;
            }
            $matches[] = [
                'id' => $match_user->ID,
                'user_login' => $match_user->user_login,
                'user_email' => $match_user->user_email,
                'name' => trim($match_user->first_name . ' ' . $match_user->last_name),
                'fields' => UserManagement::get_user_display_data($match_id),
            ];
        }

        $ud = get_userdata($user_id);
        wp_send_json_success([
            'user' => [
                'id' => $user_id,
                'user_login' => $ud ? $ud->user_login : '',
                'user_email' => $ud ? $ud->user_email : '',
                'name' => $ud ? trim($ud->first_name . ' ' . $ud->last_name) : '',
                'fields' => $user_data,
            ],
            'matches' => $matches,
        ]);
    }

    private function get_page_options(): array
    {
        $options = [
            [
                'value' => 0,
                'label' => '— Nicht gesetzt —',
            ],
        ];

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

    private function mark_selected(array $options, $value): array
    {
        foreach ($options as &$option) {
            $option['selected'] = ((string) $option['value'] === (string) $value);
        }
        unset($option);

        return $options;
    }

    private function get_custom_field_types(): array
    {
        return [
            ['value' => 'text', 'label' => 'Text'],
            ['value' => 'textarea', 'label' => 'Textarea'],
            ['value' => 'number', 'label' => 'Zahl'],
            ['value' => 'date', 'label' => 'Datum'],
            ['value' => 'select', 'label' => 'Select'],
        ];
    }

    private function capture_settings_fields(string $group): string
    {
        ob_start();
        settings_fields($group);
        return (string) ob_get_clean();
    }

    public function handle_review_user(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unzureichende Berechtigung.');
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $action = sanitize_text_field(wp_unslash($_POST['review_action'] ?? ''));

        if (!$user_id) {
            wp_safe_redirect(admin_url('admin.php?page=cbadf-user-management'));
            exit;
        }

        check_admin_referer('afcb_review_user_' . $user_id, 'afcb_review_user_nonce');

        if ($action === 'approve') {
            $review_reason = (string) get_user_meta($user_id, UserManagement::META_ACCOUNT_REVIEW_REASON, true);
            $phone = (string) get_user_meta($user_id, UserManagement::META_PHONE, true);
            update_user_meta($user_id, UserManagement::META_ACCOUNT_STATUS, 'active');
            update_user_meta($user_id, UserManagement::META_ACCOUNT_REVIEW_AT, time());
            if ($phone !== '' && ($review_reason === 'foreign_phone_manual_verification' || UserManagement::requires_manual_phone_verification($phone))) {
                update_user_meta($user_id, UserManagement::META_PHONE_VERIFIED_AT, time());
            }
            if ($review_reason === 'foreign_phone_manual_verification') {
                delete_user_meta($user_id, UserManagement::META_PHONE_MANUAL_REVIEW_NOTE);
            }
            $user = get_userdata($user_id);
            if ($user) {
                $sent = UserManagement::send_templated_mail(
                    $user->user_email,
                    'approved',
                    [
                        'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                        'site_url' => home_url(),
                        'year' => date('Y'),
                        'user_login' => esc_html($user->user_login),
                        'user_email' => esc_html($user->user_email),
                    ]
                );
                if (!$sent) {
                    $this->redirect_notice('error', 'Account wurde freigegeben, aber die Freigabe-E-Mail konnte nicht versendet werden.');
                }
            }
            $this->redirect_notice('success', 'Account wurde freigegeben.');
        }

        if ($action === 'decline') {
            $user = get_userdata($user_id);
            if ($user) {
                $sent = UserManagement::send_templated_mail(
                    $user->user_email,
                    'declined',
                    [
                        'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                        'site_url' => home_url(),
                        'year' => date('Y'),
                        'user_login' => esc_html($user->user_login),
                        'user_email' => esc_html($user->user_email),
                    ]
                );
                if (!$sent) {
                    $this->redirect_notice('error', 'Ablehnungs-E-Mail konnte nicht versendet werden. Der Nutzer wurde nicht gelöscht.');
                }
            }
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $reassign = get_current_user_id() ?: null;
            if (wp_delete_user($user_id, $reassign)) {
                $this->redirect_notice('success', 'Account wurde abgelehnt und der Nutzer gelöscht.');
            } else {
                update_user_meta($user_id, UserManagement::META_ACCOUNT_STATUS, 'declined');
                update_user_meta($user_id, UserManagement::META_ACCOUNT_REVIEW_AT, time());
                $this->redirect_notice('error', 'Account wurde abgelehnt. Der Nutzer konnte nicht gelöscht werden (z. B. weil ihm Inhalte zugeordnet sind).');
            }
        }

        $this->redirect_notice('error', 'Aktion nicht erkannt.');
    }

    public function handle_admin_delete_user(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unzureichende Berechtigung.');
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if (!$user_id) {
            wp_safe_redirect(admin_url('admin.php?page=cbadf-user-management'));
            exit;
        }

        check_admin_referer('afcb_admin_delete_user_' . $user_id, 'afcb_admin_delete_user_nonce');

        $user = get_userdata($user_id);
        if (!$user) {
            $this->redirect_notice('error', 'Nutzer wurde nicht gefunden.');
        }

        if (!$this->can_delete_user_from_detail($user_id)) {
            $this->redirect_notice_user_detail($user_id, 'error', 'Dieser Nutzer kann auf der Detailseite nicht gelöscht werden.');
        }

        if (empty($_POST['afcb_confirm_delete_user'])) {
            $this->redirect_notice_user_detail($user_id, 'error', 'Bitte bestätige die Löschung ausdrücklich.');
        }

        $message = isset($_POST['afcb_delete_user_message'])
            ? wp_kses_post(wp_unslash($_POST['afcb_delete_user_message']))
            : '';
        if (trim(wp_strip_all_tags($message)) === '') {
            $this->redirect_notice_user_detail($user_id, 'error', 'Bitte gib eine Mitteilung für den Nutzer ein.');
        }

        if (!is_email($user->user_email)) {
            $this->redirect_notice_user_detail($user_id, 'error', 'Der Nutzer hat keine gültige E-Mail-Adresse. Der Account wurde nicht gelöscht.');
        }

        $subject = sprintf(
            'Dein Account bei %s wurde gelöscht',
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );
        $sent = UserManagement::send_mail(
            $user->user_email,
            $subject,
            wpautop($message),
            'admin_delete_user'
        );

        if (!$sent) {
            $this->redirect_notice_user_detail($user_id, 'error', 'Die Lösch-Mitteilung konnte nicht versendet werden. Der Account wurde nicht gelöscht.');
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        // Reassign to author 0 so booking posts remain available instead of being deleted with the user.
        $deleted = wp_delete_user($user_id, 0);
        if (!$deleted) {
            $this->redirect_notice_user_detail($user_id, 'error', 'Die Lösch-Mitteilung wurde gesendet, aber der Nutzer konnte nicht gelöscht werden.');
        }

        $this->redirect_notice('success', 'Lösch-Mitteilung wurde versendet und der Nutzer wurde gelöscht.');
    }

    public function handle_create_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unzureichende Berechtigung.');
        }

        $key = sanitize_text_field(wp_unslash($_POST['page_key'] ?? ''));
        $definitions = UserManagement::get_page_definitions();

        if (empty($key) || !isset($definitions[$key])) {
            $this->redirect_notice('error', 'Seite nicht erkannt.', 'pages');
        }

        if (!isset($_POST['afcb_create_page_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_create_page_nonce'])), 'afcb_create_page_' . $key)) {
            $this->redirect_notice('error', 'Ungültige Anfrage.', 'pages');
        }

        $definition = $definitions[$key];

        $page_id = wp_insert_post([
            'post_title' => $definition['title'],
            'post_name' => $definition['slug'],
            'post_content' => $definition['shortcode'],
            'post_status' => 'publish',
            'post_type' => 'page',
        ]);

        if (is_wp_error($page_id) || !$page_id) {
            $this->redirect_notice('error', 'Seite konnte nicht erstellt werden.', 'pages');
        }

        $options = UserManagement::get_options();
        $options[$key] = (int) $page_id;
        $created_pages = isset($options['created_pages']) && is_array($options['created_pages']) ? $options['created_pages'] : [];
        $created_pages[$key] = (int) $page_id;
        $options['created_pages'] = $created_pages;
        update_option(UserManagement::OPTION_KEY, $options);

        update_post_meta($page_id, '_afcb_user_management_page', $key);
        update_post_meta($page_id, '_afcb_user_management_created', 1);

        $this->redirect_notice('success', 'Seite wurde erstellt und zugewiesen.', 'pages');
    }

    public function handle_replace_content(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unzureichende Berechtigung.');
        }

        $key = sanitize_text_field(wp_unslash($_POST['page_key'] ?? ''));
        $definitions = UserManagement::get_page_definitions();

        if (empty($key) || !isset($definitions[$key])) {
            $this->redirect_notice('error', 'Seite nicht erkannt.', 'pages');
        }

        if (!isset($_POST['afcb_replace_content_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_replace_content_nonce'])), 'afcb_replace_content_' . $key)) {
            $this->redirect_notice('error', 'Ungültige Anfrage.', 'pages');
        }

        $options = UserManagement::get_options();
        $page_id = isset($options[$key]) ? (int) $options[$key] : 0;
        if (!$page_id) {
            $this->redirect_notice('error', 'Keine Seite zugewiesen.', 'pages');
        }

        $definition = $definitions[$key];
        wp_update_post([
            'ID' => $page_id,
            'post_content' => $definition['shortcode'],
        ]);

        update_post_meta($page_id, '_afcb_user_management_page', $key);

        $this->redirect_notice('success', 'Seiteninhalt wurde ersetzt.', 'pages');
    }

    private function redirect_notice(string $type, string $message, string $tab = ''): void
    {
        $allowed_tabs = ['settings', 'pages', 'fields', 'emails', 'reviews', 'sms'];
        if ($tab === '') {
            $tab = isset($_POST['afcb_tab']) ? sanitize_key(wp_unslash($_POST['afcb_tab'])) : '';
        }

        $query_args = [
            'afcb_notice' => $message,
            'afcb_notice_type' => $type,
        ];
        if ($tab !== '' && in_array($tab, $allowed_tabs, true)) {
            $query_args['afcb_tab'] = $tab;
        }

        $url = add_query_arg($query_args, admin_url('admin.php?page=cbadf-user-management'));
        wp_safe_redirect($url);
        exit;
    }

    private function redirect_notice_user_detail(int $user_id, string $type, string $message): void
    {
        $url = add_query_arg([
            'page' => 'cbadf-user-management',
            'afcb_user_id' => $user_id,
            'afcb_notice' => $message,
            'afcb_notice_type' => $type,
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private function render_user_detail_page(int $user_id, \WP_User $user): void
    {
        $notice = isset($_GET['afcb_notice']) ? sanitize_text_field(wp_unslash($_GET['afcb_notice'])) : '';
        $notice_type = isset($_GET['afcb_notice_type']) ? sanitize_text_field(wp_unslash($_GET['afcb_notice_type'])) : '';

        $account_status = (string) get_user_meta($user_id, UserManagement::META_ACCOUNT_STATUS, true);
        $email_verified = (bool) get_user_meta($user_id, UserManagement::META_EMAIL_VERIFIED_AT, true);
        $phone_verified = UserManagement::is_phone_verified($user_id);
        $fully_verified = $email_verified && $phone_verified;
        $possible_duplicate_users = $this->build_possible_duplicate_rows($user_id);

        $context = [
            'user_id' => $user_id,
            'user' => $user,
            'user_login' => $user->user_login,
            'user_email' => $user->user_email,
            'user_name' => trim($user->first_name . ' ' . $user->last_name),
            'user_phone' => (string) get_user_meta($user_id, UserManagement::META_PHONE, true),
            'editable_fields' => UserManagement::get_user_editable_fields($user_id),
            'account_status' => $account_status ?: '—',
            'account_review_reason' => (string) get_user_meta($user_id, UserManagement::META_ACCOUNT_REVIEW_REASON, true),
            'manual_phone_review_note' => (string) get_user_meta($user_id, UserManagement::META_PHONE_MANUAL_REVIEW_NOTE, true),
            'registration_meta_rows' => $this->build_registration_meta_rows($user_id, $user, count($possible_duplicate_users)),
            'possible_duplicate_users' => $possible_duplicate_users,
            'email_verified' => $email_verified,
            'phone_verified' => $phone_verified,
            'fully_verified' => $fully_verified,
            'admin_post_url' => esc_url(admin_url('admin-post.php')),
            'back_url' => esc_url(admin_url('admin.php?page=cbadf-user-management')),
            'manual_verify_nonce' => wp_create_nonce('afcb_manual_verify_user_' . $user_id),
            'resend_email_nonce' => wp_create_nonce('afcb_admin_resend_email_verification_' . $user_id),
            'save_user_data_nonce' => wp_create_nonce('afcb_save_user_data_' . $user_id),
            'delete_user_nonce' => wp_create_nonce('afcb_admin_delete_user_' . $user_id),
            'can_delete_user' => $this->can_delete_user_from_detail($user_id),
            'account_deletion_editor' => $this->render_account_deletion_editor($user),
            'bookings_ajax_url' => esc_url(admin_url('admin-ajax.php')),
            'bookings_nonce' => wp_create_nonce('afcb_user_bookings_' . $user_id),
            'bookings_per_page' => 10,
            'notice' => $notice,
            'notice_type' => $notice_type,
        ];

        echo TemplateLoader::load()->render('admin/user-detail.html.twig', $context);
    }

    private function build_registration_meta_rows(int $user_id, \WP_User $user, ?int $possible_duplicate_count = null): array
    {
        $rows = [];

        $registered_at = $user->user_registered
            ? $this->format_meta_timestamp($user->user_registered . ' UTC')
            : '';
        $this->add_registration_meta_row($rows, 'WordPress-Registrierung', 'wp_users.user_registered', $registered_at, $registered_at !== '', 'registriert', 'fehlt');

        $timestamp_meta = [
            UserManagement::META_TERMS_ACCEPTED_AT => ['Nutzungsbedingungen', 'akzeptiert', 'offen'],
            UserManagement::META_PRIVACY_ACCEPTED_AT => ['Datenschutz', 'akzeptiert', 'offen'],
            UserManagement::META_EMAIL_VERIFIED_AT => ['E-Mail-Adresse', 'verifiziert', 'offen'],
            UserManagement::META_EMAIL_LAST_SENT => ['Verifizierungs-Mail', 'gesendet', 'nicht gesendet'],
            UserManagement::META_EMAIL_TOKEN_EXPIRES => ['E-Mail-Verifizierungslink gültig bis', 'aktiv', 'nicht aktiv'],
            UserManagement::META_EMAIL_REMINDER_SENT_AT => ['E-Mail-Erinnerung', 'gesendet', 'nicht gesendet'],
            UserManagement::META_PHONE_VERIFIED_AT => ['Telefonnummer', 'verifiziert', 'offen'],
            UserManagement::META_PHONE_CODE_LAST_SENT => ['Telefon-Code', 'gesendet', 'nicht gesendet'],
            UserManagement::META_PHONE_CODE_EXPIRES => ['Telefon-Code gültig bis', 'aktiv', 'nicht aktiv'],
            UserManagement::META_ACCOUNT_REVIEW_AT => ['Prüfstatus', 'gesetzt', 'nicht gesetzt'],
            UserManagement::META_LAST_LOGIN => ['Letzter Login', 'angemeldet', 'nie angemeldet'],
        ];

        foreach ($timestamp_meta as $key => [$label, $present_label, $missing_label]) {
            $raw = get_user_meta($user_id, $key, true);
            $value = $this->format_meta_timestamp($raw);
            $this->add_registration_meta_row($rows, $label, $key, $value, $value !== '', $present_label, $missing_label);
        }

        $status = (string) get_user_meta($user_id, UserManagement::META_ACCOUNT_STATUS, true);
        $this->add_registration_meta_row(
            $rows,
            'Account-Status',
            UserManagement::META_ACCOUNT_STATUS,
            $this->format_account_status_label($status),
            $status !== '',
            'gesetzt',
            'nicht gesetzt'
        );

        $review_reason = (string) get_user_meta($user_id, UserManagement::META_ACCOUNT_REVIEW_REASON, true);
        $this->add_registration_meta_row(
            $rows,
            'Prüfgrund',
            UserManagement::META_ACCOUNT_REVIEW_REASON,
            $review_reason !== '' ? UserManagement::get_review_reason_label($review_reason) : '',
            $review_reason !== '',
            'gesetzt',
            'kein Prüfgrund'
        );

        if ($possible_duplicate_count === null) {
            $match_ids = get_user_meta($user_id, UserManagement::META_ACCOUNT_REVIEW_MATCH_IDS, true);
            $possible_duplicate_count = is_array($match_ids) ? count(array_filter($match_ids)) : 0;
        }
        $this->add_registration_meta_row(
            $rows,
            'Mögliche Dubletten',
            UserManagement::META_ACCOUNT_REVIEW_MATCH_IDS,
            $possible_duplicate_count > 0 ? sprintf('%d mögliche Übereinstimmung(en)', $possible_duplicate_count) : 'Keine ähnlichen Nutzer gefunden',
            $possible_duplicate_count > 0,
            'prüfen',
            'keine'
        );

        $token_hash = (string) get_user_meta($user_id, UserManagement::META_EMAIL_TOKEN_HASH, true);
        $this->add_registration_meta_row(
            $rows,
            'E-Mail-Verifizierungslink',
            UserManagement::META_EMAIL_TOKEN_HASH,
            $token_hash !== '' ? 'Ein Link zur E-Mail-Bestätigung ist aktiv.' : 'Kein aktiver Bestätigungslink.',
            $token_hash !== '',
            'aktiv',
            'inaktiv'
        );

        $phone_code_hash = (string) get_user_meta($user_id, UserManagement::META_PHONE_CODE_HASH, true);
        $this->add_registration_meta_row(
            $rows,
            'Telefon-Verifizierungscode',
            UserManagement::META_PHONE_CODE_HASH,
            $phone_code_hash !== '' ? 'Ein Telefon-Code ist aktiv.' : 'Kein aktiver Telefon-Code.',
            $phone_code_hash !== '',
            'aktiv',
            'inaktiv'
        );

        return $rows;
    }

    private function add_registration_meta_row(
        array &$rows,
        string $label,
        string $key,
        string $value,
        bool $present,
        string $present_label = 'gespeichert',
        string $missing_label = 'offen'
    ): void
    {
        $rows[] = [
            'label' => $label,
            'key' => $key,
            'value' => $value !== '' ? $value : ($present ? 'vorhanden' : 'nicht vorhanden'),
            'present' => $present,
            'status_label' => $present ? $present_label : $missing_label,
        ];
    }

    private function format_account_status_label(string $status): string
    {
        return match ($status) {
            'active' => 'Aktiv / freigegeben',
            'pending' => 'Wartet auf Prüfung',
            'declined' => 'Abgelehnt',
            '' => '',
            default => $status,
        };
    }

    private function build_possible_duplicate_rows(int $user_id): array
    {
        $rows = [];
        foreach (UserManagement::find_possible_duplicate_users($user_id) as $duplicate_user) {
            $duplicate_id = (int) $duplicate_user->ID;
            $status = (string) get_user_meta($duplicate_id, UserManagement::META_ACCOUNT_STATUS, true);
            $rows[] = [
                'id' => $duplicate_id,
                'user_login' => $duplicate_user->user_login,
                'user_email' => $duplicate_user->user_email,
                'name' => trim($duplicate_user->first_name . ' ' . $duplicate_user->last_name) ?: '—',
                'registered' => $this->format_meta_timestamp($duplicate_user->user_registered . ' UTC'),
                'status' => $this->format_account_status_label($status ?: 'active'),
                'roles' => $this->format_user_roles($duplicate_user),
                'match_reason' => $this->describe_duplicate_match($user_id, $duplicate_id),
                'detail_url' => esc_url_raw(add_query_arg(
                    ['page' => 'cbadf-user-management', 'afcb_user_id' => $duplicate_id],
                    admin_url('admin.php')
                )),
            ];
        }

        return $rows;
    }

    private function describe_duplicate_match(int $user_id, int $duplicate_id): string
    {
        $reasons = [];
        $user = get_userdata($user_id);
        $duplicate = get_userdata($duplicate_id);

        if ($user && $duplicate) {
            $same_first_name = $this->normalize_compare_value((string) $user->first_name) === $this->normalize_compare_value((string) $duplicate->first_name);
            $same_last_name = $this->normalize_compare_value((string) $user->last_name) === $this->normalize_compare_value((string) $duplicate->last_name);
            if ($same_first_name && $same_last_name && trim($user->first_name . $user->last_name) !== '') {
                $reasons[] = 'gleicher Vor- und Nachname';
            }
        }

        $same_address = true;
        foreach ([UserManagement::META_STREET, UserManagement::META_HOUSE_NUMBER, UserManagement::META_ZIP, UserManagement::META_CITY] as $meta_key) {
            $value = $this->normalize_compare_value((string) get_user_meta($user_id, $meta_key, true));
            $duplicate_value = $this->normalize_compare_value((string) get_user_meta($duplicate_id, $meta_key, true));
            if ($value === '' || $duplicate_value === '' || $value !== $duplicate_value) {
                $same_address = false;
                break;
            }
        }

        if ($same_address) {
            $reasons[] = 'gleiche Adresse';
        }

        return $reasons ? implode(', ', $reasons) : 'ähnliche Nutzerdaten';
    }

    private function normalize_compare_value(string $value): string
    {
        $value = remove_accents($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/\s+/', ' ', trim($value));

        return $value ?? '';
    }

    private function format_user_roles(\WP_User $user): string
    {
        if (empty($user->roles)) {
            return '—';
        }

        global $wp_roles;

        $role_names = [];
        foreach ($user->roles as $role) {
            $role_name = isset($wp_roles->roles[$role]['name']) ? $wp_roles->roles[$role]['name'] : $role;
            $role_names[] = translate_user_role($role_name);
        }

        return implode(', ', $role_names);
    }

    private function format_meta_timestamp($value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }

        $timestamp = is_numeric($value)
            ? (int) $value
            : strtotime((string) $value);

        if ($timestamp <= 0) {
            return '';
        }

        return wp_date('d.m.Y H:i', $timestamp);
    }

    private function render_account_deletion_editor(\WP_User $user): string
    {
        ob_start();
        wp_editor($this->get_default_account_deletion_message($user), 'afcb_delete_user_message_editor', [
            'textarea_name' => 'afcb_delete_user_message',
            'textarea_rows' => 8,
            'media_buttons' => false,
            'teeny' => true,
            'quicktags' => true,
        ]);
        return (string) ob_get_clean();
    }

    private function get_default_account_deletion_message(\WP_User $user): string
    {
        $display_name = trim($user->first_name . ' ' . $user->last_name);
        if ($display_name === '') {
            $display_name = $user->user_login;
        }

        return sprintf(
            '<p>Hallo %s,</p><p>wir haben deinen Account bei %s geprüft und ihn gelöscht, weil die Registrierung nicht eindeutig verifiziert werden konnte.</p><p>Falls du der Meinung bist, dass dies ein Fehler war, melde dich bitte bei uns. Wir prüfen das gerne noch einmal.</p><p>Viele Grüße<br>%s</p>',
            esc_html($display_name),
            esc_html(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)),
            esc_html(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES))
        );
    }

    private function can_delete_user_from_detail(int $user_id): bool
    {
        if (!current_user_can('manage_options')) {
            return false;
        }

        if ($user_id === get_current_user_id()) {
            return false;
        }

        return !user_can($user_id, 'manage_options');
    }

    /**
     * Buchungen eines Nutzers (CommonsBooking) paginiert für AJAX.
     *
     * @return array{items: array, total: int, pages: int, current_page: int}
     */
    public function get_bookings_for_user_paginated(int $user_id, int $page = 1, int $per_page = 10): array
    {
        $post_type = $this->get_booking_post_type();
        $query = new \WP_Query([
            'post_type' => $post_type,
            'author' => $user_id,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_status' => 'any',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $list = $this->map_posts_to_booking_rows($query->posts ?? []);
        $total = (int) ($query->found_posts ?? 0);
        $pages = (int) ($query->max_num_pages ?? 1);

        return [
            'items' => $list,
            'total' => $total,
            'pages' => $pages,
            'current_page' => $page,
        ];
    }

    /**
     * @param array<int, \WP_Post> $posts
     * @return array<int, array{item_name: string, location_name: string, start_date: string, end_date: string, status: string, edit_link: string}>
     */
    private function map_posts_to_booking_rows(array $posts): array
    {
        $list = [];
        $useModel = class_exists('\\CommonsBooking\\Model\\Booking');

        foreach ($posts as $post) {
            $item_name = '';
            $location_name = '';
            $start_date = '';
            $end_date = '';
            $status = (string) $post->post_status;

            if ($useModel) {
                try {
                    $model = new \CommonsBooking\Model\Booking($post->ID);
                    $item = $model->getItem();
                    $location = $model->getLocation();
                    $item_name = $item ? $item->post_title : '—';
                    $location_name = $location ? $location->post_title : '—';
                    $start_date = $model->getStartDate() ? date_i18n('d.m.Y H:i', $model->getStartDate()) : '—';
                    $end_date = $model->getEndDate() ? date_i18n('d.m.Y H:i', $model->getEndDate()) : '—';
                } catch (\Throwable $e) {
                    $item_name = $post->post_title ?: '—';
                }
            } else {
                $item_name = $post->post_title ?: '—';
                $start_date = get_post_meta($post->ID, 'repetition-start', true);
                $end_date = get_post_meta($post->ID, 'repetition-end', true);
                if ($start_date) {
                    $start_date = date_i18n('d.m.Y H:i', (int) $start_date);
                } else {
                    $start_date = '—';
                }
                if ($end_date) {
                    $end_date = date_i18n('d.m.Y H:i', (int) $end_date);
                } else {
                    $end_date = '—';
                }
            }

            $list[] = [
                'item_name' => $item_name,
                'location_name' => $location_name,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'status' => $status,
                'edit_link' => get_edit_post_link($post->ID, 'raw'),
            ];
        }

        return $list;
    }

    /**
     * Anzahl der Buchungen (CommonsBooking) eines Nutzers für die Benutzerliste.
     */
    private function get_booking_count_for_user(int $user_id): int
    {
        $post_type = $this->get_booking_post_type();
        $query = new \WP_Query([
            'post_type' => $post_type,
            'author' => $user_id,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_status' => 'any',
            'no_found_rows' => false,
        ]);

        return (int) ($query->found_posts ?? 0);
    }

    /**
     * Datum der letzten Buchung (CommonsBooking) eines Nutzers für die Benutzerliste.
     * Rückgabe: Unix-Timestamp oder 0.
     */
    private function get_last_booking_date_for_user(int $user_id): int
    {
        $post_type = $this->get_booking_post_type();
        $query = new \WP_Query([
            'post_type' => $post_type,
            'author' => $user_id,
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => 'any',
            'fields' => 'ids',
        ]);
        $posts = $query->posts ?? [];
        if (empty($posts)) {
            return 0;
        }
        $post = get_post((int) $posts[0]);
        if (!$post || !$post->post_date) {
            return 0;
        }
        return (int) strtotime($post->post_date);
    }

    /**
     * CommonsBooking-Buchungs-Post-Typ.
     */
    private function get_booking_post_type(): string
    {
        if (class_exists('\\CommonsBooking\\Wordpress\\CustomPostType\\Booking')) {
            return \CommonsBooking\Wordpress\CustomPostType\Booking::$postType;
        }
        return 'cb_booking';
    }

    /**
     * Buchungen eines Nutzers (CommonsBooking) für die Admin-Detailansicht.
     *
     * @return array<int, array{item_name: string, location_name: string, start_date: string, end_date: string, status: string, edit_link: string}>
     */
    private function get_bookings_for_user(int $user_id): array
    {
        $post_type = $this->get_booking_post_type();
        $query = new \WP_Query([
            'post_type' => $post_type,
            'author' => $user_id,
            'posts_per_page' => 100,
            'post_status' => 'any',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        return $this->map_posts_to_booking_rows($query->posts ?? []);
    }

    public function handle_ajax_user_bookings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unzureichende Berechtigung.']);
        }

        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        if (!$user_id) {
            wp_send_json_error(['message' => 'Ungültige Nutzer-ID.']);
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'] ?? '')), 'afcb_user_bookings_' . $user_id)) {
            wp_send_json_error(['message' => 'Ungültiger Sicherheits-Code.']);
        }

        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page = isset($_GET['per_page']) ? max(1, min(50, (int) $_GET['per_page'])) : 10;

        $result = $this->get_bookings_for_user_paginated($user_id, $page, $per_page);
        wp_send_json_success($result);
    }

    public function handle_save_user_data(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unzureichende Berechtigung.');
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if (!$user_id || !get_userdata($user_id)) {
            wp_safe_redirect(admin_url('admin.php?page=cbadf-user-management'));
            exit;
        }

        check_admin_referer('afcb_save_user_data_' . $user_id, 'afcb_save_user_data_nonce');

        $editable = UserManagement::get_user_editable_fields($user_id);
        $wp_keys = ['first_name', 'last_name', 'user_email'];
        $user_update = [];
        foreach ($editable as $field) {
            $key = $field['key'] ?? '';
            if ($key === '') {
                continue;
            }
            $value = isset($_POST['afcb_field'][$key]) ? wp_unslash($_POST['afcb_field'][$key]) : '';
            $value = is_string($value) ? trim($value) : '';

            if (in_array($key, $wp_keys, true)) {
                if ($key === 'user_email') {
                    $value = sanitize_email($value);
                } else {
                    $value = sanitize_text_field($value);
                }
                $user_update[$key] = $value;
            } else {
                update_user_meta($user_id, $key, $value);
            }
        }

        if (!empty($user_update)) {
            $user_update['ID'] = $user_id;
            wp_update_user($user_update);
        }

        UserManagement::sync_commonsbooking_legacy_user_meta($user_id);

        $this->redirect_notice_user_detail($user_id, 'success', 'Nutzerdaten wurden gespeichert.');
    }

    public function handle_admin_resend_email_verification(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unzureichende Berechtigung.');
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) {
            wp_safe_redirect(admin_url('admin.php?page=cbadf-user-management'));
            exit;
        }

        check_admin_referer('afcb_admin_resend_email_verification_' . $user_id, 'afcb_admin_resend_email_nonce');

        if (!is_email($user->user_email)) {
            $this->redirect_notice_user_detail($user_id, 'error', 'Für diesen Nutzer ist keine gültige E-Mail-Adresse hinterlegt.');
        }

        if (get_user_meta($user_id, UserManagement::META_EMAIL_VERIFIED_AT, true)) {
            $this->redirect_notice_user_detail($user_id, 'info', 'Die E-Mail-Adresse ist bereits verifiziert.');
        }

        if (!UserManagement::send_email_verification($user_id)) {
            $this->redirect_notice_user_detail($user_id, 'error', 'Verifizierungs-Mail konnte nicht versendet werden.');
        }

        $this->redirect_notice_user_detail($user_id, 'success', 'Verifizierungs-Mail wurde erneut gesendet.');
    }

    public function handle_manual_verify_user(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unzureichende Berechtigung.');
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if (!$user_id) {
            wp_safe_redirect(admin_url('admin.php?page=cbadf-user-management'));
            exit;
        }

        check_admin_referer('afcb_manual_verify_user_' . $user_id, 'afcb_manual_verify_nonce');

        $now = time();
        if (!get_user_meta($user_id, UserManagement::META_PHONE_VERIFIED_AT, true)) {
            update_user_meta($user_id, UserManagement::META_PHONE_VERIFIED_AT, $now);
        }
        if (!get_user_meta($user_id, UserManagement::META_EMAIL_VERIFIED_AT, true)) {
            update_user_meta($user_id, UserManagement::META_EMAIL_VERIFIED_AT, $now);
        }
        update_user_meta($user_id, UserManagement::META_ACCOUNT_STATUS, 'active');
        update_user_meta($user_id, UserManagement::META_ACCOUNT_REVIEW_AT, $now);
        delete_user_meta($user_id, UserManagement::META_PHONE_MANUAL_REVIEW_NOTE);

        $this->redirect_notice_user_detail($user_id, 'success', 'Nutzer wurde manuell verifiziert (E-Mail und Telefon gelten als bestätigt).');
    }
}
