<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Support\EnvironmentSafety;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Support\PluginPaths;

class UserManagement
{
    public const OPTION_KEY = 'afcb_user_management_option_name';
    private static bool $is_sending_templated_mail = false;
    private static array $last_message_api_error = [];

    public const META_PHONE = 'afcb_phone';
    public const META_PHONE_VERIFIED_AT = 'afcb_phone_verified_at';
    public const META_PHONE_CODE_HASH = 'afcb_phone_verification_code_hash';
    public const META_PHONE_CODE_EXPIRES = 'afcb_phone_verification_expires';
    public const META_PHONE_CODE_LAST_SENT = 'afcb_phone_verification_last_sent';
    public const META_PHONE_CODE_SEND_LOCK = 'afcb_phone_verification_send_lock';
    public const META_PHONE_MANUAL_REVIEW_NOTE = 'afcb_phone_manual_review_note';

    public const META_EMAIL_VERIFIED_AT = 'afcb_email_verified_at';
    public const META_EMAIL_TOKEN_HASH = 'afcb_email_verification_token_hash';
    public const META_EMAIL_TOKEN_EXPIRES = 'afcb_email_verification_expires';
    public const META_EMAIL_LAST_SENT = 'afcb_email_verification_last_sent';
    public const META_EMAIL_REMINDER_SENT_AT = 'afcb_email_verification_reminder_sent_at';
    public const META_DELETE_PROFILE_TOKEN_HASH = 'afcb_delete_profile_token_hash';
    public const META_DELETE_PROFILE_TOKEN_EXPIRES = 'afcb_delete_profile_token_expires';
    public const META_DELETE_PROFILE_LAST_SENT = 'afcb_delete_profile_last_sent';

    public const META_ACCOUNT_STATUS = 'afcb_account_status';
    public const META_ACCOUNT_REVIEW_REASON = 'afcb_account_review_reason';
    public const META_ACCOUNT_REVIEW_AT = 'afcb_account_review_at';
    public const META_ACCOUNT_REVIEW_MATCH_IDS = 'afcb_account_review_match_ids';

    public const META_TERMS_ACCEPTED_AT = 'afcb_terms_accepted_at';
    public const META_PRIVACY_ACCEPTED_AT = 'afcb_privacy_accepted_at';
    public const META_LEGACY_MIGRATION_PROVENANCE = 'afcb_legacy_migration_provenance';

    public const META_STREET = 'afcb_street';
    public const META_HOUSE_NUMBER = 'afcb_house_number';
    public const META_ZIP = 'afcb_zip';
    public const META_CITY = 'afcb_city';
    public const META_STATE = 'afcb_state';
    public const META_COUNTRY = 'afcb_country';
    public const META_ADDRESS_LAT = 'afcb_address_lat';
    public const META_ADDRESS_LON = 'afcb_address_lon';
    public const META_ADDRESS_RAW = 'afcb_address_raw';
    public const META_LAST_LOGIN = 'afcb_last_login';

    private const MAIL_LOG_PREFIX = '[AFCB MAIL] ';
    private const MESSAGE_LOG_PREFIX = '[AFCB Messages] ';
    private const MESSAGES_API_URL = 'https://sms.ddvelop.de/v1/messages/send';
    private const LEGACY_SMS_API_URL = 'https://sms.ddvelop.de/v1/sms/send';
    private const PREVIOUS_MESSAGES_API_URL = 'https://messages.api.ddvelop.de/v1/messages/send';
    private const DEFAULT_EMAIL_HEADER_IMAGE_PATH = 'assets/img/email/main-lastenrad-logo-main-green.png';

    private static $pending_inline_email_images = [];
    private static array $pending_new_user_notifications = [];
    private static array $pending_password_change_notifications = [];
    private static array $pending_commonsbooking_legacy_user_meta_sync = [];
    private static int $commonsbooking_legacy_sync_suspension_depth = 0;
    private bool $frontend_assets_enqueued = false;

    private const CORE_ADDRESS_FIELDS = [
        self::META_STREET,
        self::META_HOUSE_NUMBER,
        self::META_ZIP,
        self::META_CITY,
    ];

    private const PHONE_CODE_EXPIRY_SECONDS = 3600;
    private const PHONE_CODE_RESEND_COOLDOWN = 60;
    private const PHONE_CODE_SEND_LOCK_SECONDS = 30;
    private const MESSAGE_DELIVERY_STATUS_FAILED = 'failed';
    private const MESSAGE_DELIVERY_STATUS_UNCERTAIN = 'uncertain';
    private const MESSAGE_DELIVERY_UNCERTAIN_NOTICE = 'Der Versand konnte nicht bestätigt werden. Falls du einen Code erhältst, kannst du ihn trotzdem verwenden. Bitte warte eine Minute, bevor du erneut sendest.';
    private const EMAIL_RESEND_COOLDOWN = 60;
    private const DELETE_PROFILE_TOKEN_EXPIRY_SECONDS = 7200;
    private const DELETE_PROFILE_RESEND_COOLDOWN = 300;
    private const FORGOT_USERNAME_PHONE_RATE_LIMIT_SECONDS = 900;
    private const FORGOT_USERNAME_IP_RATE_LIMIT_SECONDS = 900;
    private const FORGOT_USERNAME_IP_RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const CONTACT_CONFLICT_NOTIFICATION_COOLDOWN_SECONDS = 3600;
    private const FORGOT_USERNAME_RATE_LIMIT_MESSAGE = 'Bitte warte 15 Minuten, bevor du erneut eine SMS anforderst.';
    private const PASSWORD_RESET_REQUEST_NOTICE = 'Wenn es zu deiner Eingabe ein Konto gibt, wurde eine E-Mail zum Zurücksetzen des Passworts gesendet.';
    private const REGISTER_FORM_TRANSIENT_PREFIX = 'afcb_register_form_';
    private const CREATED_PAGES_KEY = 'created_pages';

    /** Option-Key: Bei Deinstallation Benutzermetadaten bereinigen (Wert '1' = ja). Standard beim Abfragen: nein. */
    public const UNINSTALL_CLEANUP_OPTION = 'afcb_uninstall_cleanup_meta';

    public function __construct()
    {
        add_shortcode('afcb_register', [$this, 'render_register']);
        add_shortcode('afcb_login', [$this, 'render_login']);
        add_shortcode('afcb_profile', [$this, 'render_profile']);
        add_shortcode('afcb_forgot_password', [$this, 'render_forgot_password']);
        add_shortcode('afcb_forgot_username', [$this, 'render_forgot_username']);
        add_shortcode('cbaf_register', [$this, 'render_register']);
        add_shortcode('cbaf_login', [$this, 'render_login']);
        add_shortcode('cbaf_profile', [$this, 'render_profile']);
        add_shortcode('cbaf_forgot_password', [$this, 'render_forgot_password']);
        add_shortcode('cbaf_forgot_username', [$this, 'render_forgot_username']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_current_page_assets'], 20);

        add_action('admin_post_nopriv_afcb_register', [$this, 'handle_register']);
        add_action('admin_post_afcb_register', [$this, 'handle_register']);

        add_action('admin_post_nopriv_afcb_login', [$this, 'handle_login']);
        add_action('admin_post_afcb_login', [$this, 'handle_login']);

        add_action('admin_post_afcb_profile_update', [$this, 'handle_profile_update']);
        add_action('admin_post_afcb_delete_profile', [$this, 'handle_delete_profile_request']);
        add_action('admin_post_afcb_request_profile_deletion', [$this, 'handle_delete_profile_request']);
        add_action('admin_post_afcb_resend_email_verification', [$this, 'handle_resend_email_verification']);
        add_action('admin_post_afcb_send_phone_code', [$this, 'handle_send_phone_code']);
        add_action('admin_post_afcb_verify_phone_code', [$this, 'handle_verify_phone_code']);
        add_action('admin_post_afcb_request_manual_phone_verification', [$this, 'handle_manual_phone_verification_request']);

        add_action('admin_post_nopriv_afcb_forgot_password', [$this, 'handle_forgot_password']);
        add_action('admin_post_nopriv_afcb_reset_password', [$this, 'handle_reset_password']);
        add_action('admin_post_nopriv_afcb_forgot_username', [$this, 'handle_forgot_username']);

        add_action('init', [$this, 'handle_email_verification']);
        add_action('init', [$this, 'handle_delete_profile_confirmation']);
        add_action('init', [$this, 'maybe_handle_fallback_login'], 0);
        add_action('template_redirect', [$this, 'enforce_profile_completion']);
        add_action('wp_ajax_afcb_address_search', [$this, 'handle_address_search']);
        add_action('wp_ajax_nopriv_afcb_address_search', [$this, 'handle_address_search']);
        add_action('wp_ajax_afcb_registration_lookup', [$this, 'handle_registration_lookup']);
        add_action('wp_ajax_nopriv_afcb_registration_lookup', [$this, 'handle_registration_lookup']);
        add_action('wp_ajax_afcb_send_phone_code', [$this, 'handle_ajax_send_phone_code']);
        add_action('wp_ajax_afcb_verify_phone_code', [$this, 'handle_ajax_verify_phone_code']);
        add_action('wp_ajax_afcb_request_manual_phone_verification', [$this, 'handle_ajax_manual_phone_verification_request']);
        add_action('login_init', [$this, 'redirect_wp_login']);
        add_action('login_form', [$this, 'render_fallback_marker']);
        add_action('wp_login', [$this, 'track_login'], 10, 2);
        add_action('afcb_cron_email_verification_reminder', [$this, 'run_email_verification_cron']);
        add_action('init', [$this, 'maybe_schedule_email_verification_cron'], 20);
        add_action('wp_mail_failed', [$this, 'handle_wp_mail_failed'], 10, 1);
        add_action('wp_mail_succeeded', [$this, 'handle_wp_mail_succeeded'], 10, 1);
        add_action('post_smtp_on_failed', [$this, 'handle_post_smtp_failed'], 10, 5);
        add_action('post_smtp_on_success', [$this, 'handle_post_smtp_success'], 10, 4);
        add_action('phpmailer_init', [$this, 'attach_inline_email_images'], 10, 1);
        add_action('user_register', [$this, 'queue_new_user_notification'], 100, 2);
        add_action('wp_set_password', [$this, 'queue_password_change_notification'], 20, 3);
        add_action('added_user_meta', [$this, 'queue_commonsbooking_legacy_user_meta_sync'], 10, 4);
        add_action('updated_user_meta', [$this, 'queue_commonsbooking_legacy_user_meta_sync'], 10, 4);
        add_action('deleted_user_meta', [$this, 'queue_commonsbooking_legacy_user_meta_sync'], 10, 4);
        add_action('profile_update', [$this, 'queue_commonsbooking_legacy_profile_sync'], 20, 2);
        add_action('shutdown', [$this, 'sync_pending_commonsbooking_legacy_user_meta']);
        add_action('shutdown', [$this, 'send_pending_admin_event_notifications']);

        add_filter('login_url', [$this, 'filter_login_url'], 10, 3);
        add_filter('register_url', [$this, 'filter_register_url']);
        add_filter('lostpassword_url', [$this, 'filter_lostpassword_url'], 10, 2);
        add_filter('wp_mail', [$this, 'wrap_external_mail'], 50);
        add_filter('afcb_send_sms', [$this, 'send_sms_via_api'], 10, 4);
        add_filter('afcb_send_signal', [$this, 'send_signal_via_api'], 10, 4);
        add_filter('commonsbooking_tag_user_address', [$this, 'filter_commonsbooking_user_address'], 10, 3);
        add_filter('commonsbooking_tag_user_phone', [$this, 'filter_commonsbooking_user_phone'], 10, 3);
    }

    public static function get_options(): array
    {
        $email_defaults = self::get_email_template_defaults();
        $defaults = [
            'registration_page_id' => 0,
            'login_page_id' => 0,
            'profile_page_id' => 0,
            'forgot_password_page_id' => 0,
            'forgot_username_page_id' => 0,
            'privacy_page_id' => 0,
            'terms_page_id' => 0,
            'privacy_url' => '',
            'terms_url' => '',
            'required_fields' => [
                'first_name',
                'last_name',
                self::META_PHONE,
                self::META_STREET,
                self::META_HOUSE_NUMBER,
                self::META_ZIP,
                self::META_CITY,
            ],
            'recaptcha_site_key' => '',
            'recaptcha_secret_key' => '',
            'recaptcha_version' => 'v2',
            'recaptcha_threshold' => 0.5,
            'nominatim_email' => '',
            'fallback_login_slug' => 'fallback-login',
            'custom_fields' => [],
            self::CREATED_PAGES_KEY => [],
            'review_notification_emails' => '',
            'sms_api_url' => self::MESSAGES_API_URL,
            'sms_api_key' => '',
            'sms_verification_message' => 'Hallo {{vorname}} {{nachname}}, der Code zum Verifizieren deiner Telefonnummer für {{page_title}} ist: {{code}}. Er ist 1 Stunde gültig.',
        ];
        $defaults = array_merge($defaults, $email_defaults);

        $options = get_option(self::OPTION_KEY, []);
        if (!is_array($options)) {
            $options = [];
        }

        $options = array_merge($defaults, $options);
        $options['email_html_template'] = EmailTemplatePolicy::normalize_wrapper(
            (string) ($options['email_html_template'] ?? ''),
            (string) $email_defaults['email_html_template']
        );

        if (is_string($options['required_fields'])) {
            $fields = array_filter(array_map('trim', explode(',', $options['required_fields'])));
            $options['required_fields'] = $fields ? array_values(array_unique($fields)) : $defaults['required_fields'];
        }

        if (!is_array($options['required_fields'])) {
            $options['required_fields'] = $defaults['required_fields'];
        }
        $options['required_fields'] = self::normalize_required_fields($options['required_fields']);

        $options['sms_api_url'] = self::normalize_messages_api_url((string) ($options['sms_api_url'] ?? ''));

        if (
            isset($options['email_review_body'])
            && is_string($options['email_review_body'])
            && strpos($options['email_review_body'], '{{review_reason}}') === false
            && strpos($options['email_review_body'], 'Mehrfachanmeldung') !== false
        ) {
            $options['email_review_body'] = $email_defaults['email_review_body'];
        }

        $options['email_header_image_cid'] = EmailHeaderImage::normalize_reference(
            self::get_site_key(),
            (string) ($options['email_header_image_cid'] ?? '')
        );
        if (trim((string) ($options['email_footer_text'] ?? '')) === '') {
            $options['email_footer_text'] = self::get_default_email_footer_text();
        }
        if (self::should_replace_default_email_footer((string) ($options['email_footer_text'] ?? ''))) {
            $options['email_footer_text'] = self::get_default_email_footer_text();
        }
        $options = self::replace_legacy_default_email_bodies($options, $email_defaults);

        return $options;
    }

    public static function normalize_messages_api_url(string $api_url): string
    {
        $api_url = trim($api_url);
        if ($api_url === '') {
            return '';
        }

        if (in_array(rtrim(strtolower($api_url), '/'), [
            rtrim(strtolower(self::LEGACY_SMS_API_URL), '/'),
            rtrim(strtolower(self::PREVIOUS_MESSAGES_API_URL), '/'),
        ], true)) {
            return self::MESSAGES_API_URL;
        }

        return $api_url;
    }

    /**
     * Letzter bereinigter API-Fehler innerhalb des aktuellen Requests.
     *
     * @return array<string, mixed>
     */
    public static function get_last_message_api_error(): array
    {
        return self::$last_message_api_error;
    }

    public static function was_last_message_delivery_uncertain(): bool
    {
        return (self::$last_message_api_error['delivery_status'] ?? '') === self::MESSAGE_DELIVERY_STATUS_UNCERTAIN;
    }

    /**
     * Prüft, ob Nachrichtenversand konfiguriert ist (API-URL und API-Key gesetzt).
     * Ohne Konfiguration wird keine Telefonverifizierung durchgeführt; Telefonnummern gelten dann als automatisch verifiziert.
     */
    public static function is_sms_configured(): bool
    {
        $options = self::get_options();
        $api_url = isset($options['sms_api_url']) ? trim((string) $options['sms_api_url']) : '';
        $api_key = self::get_sms_api_key($options);
        return $api_url !== '' && $api_key !== '';
    }

    private static function get_sms_api_key(?array $options = null): string
    {
        if (defined('CBAF_DEV_SMS_API_KEY')) {
            return trim((string) CBAF_DEV_SMS_API_KEY);
        }

        $options = $options ?? self::get_options();
        return isset($options['sms_api_key']) ? trim((string) $options['sms_api_key']) : '';
    }

    /**
     * Prüft, ob die Telefonnummer des Nutzers als verifiziert gilt.
     * Wenn keine SMS-Zugangsdaten konfiguriert sind, gilt eine gesetzte Nummer als automatisch verifiziert.
     */
    public static function is_phone_verified(int $user_id): bool
    {
        if (!self::is_sms_configured()) {
            $phone = (string) get_user_meta($user_id, self::META_PHONE, true);
            return trim($phone) !== '';
        }
        return (bool) get_user_meta($user_id, self::META_PHONE_VERIFIED_AT, true);
    }

    public function filter_commonsbooking_user_address($value, $wp_object = null, $args = null)
    {
        $user_id = self::resolve_commonsbooking_user_id($wp_object);
        if ($user_id <= 0) {
            return $value;
        }

        $address = self::format_user_management_address($user_id);

        return $address !== '' ? $address : $value;
    }

    public function filter_commonsbooking_user_phone($value, $wp_object = null, $args = null)
    {
        $user_id = self::resolve_commonsbooking_user_id($wp_object);
        if ($user_id <= 0) {
            return $value;
        }

        $phone = trim((string) get_user_meta($user_id, self::META_PHONE, true));

        return $phone !== '' ? sanitize_text_field($phone) : $value;
    }

    private static function resolve_commonsbooking_user_id($wp_object): int
    {
        if ($wp_object instanceof \WP_User) {
            return (int) $wp_object->ID;
        }

        if ($wp_object instanceof \WP_Post && (int) $wp_object->post_author > 0) {
            return (int) $wp_object->post_author;
        }

        return is_user_logged_in() ? (int) get_current_user_id() : 0;
    }

    private static function format_user_management_address(int $user_id): string
    {
        $street = trim((string) get_user_meta($user_id, self::META_STREET, true));
        $house_number = trim((string) get_user_meta($user_id, self::META_HOUSE_NUMBER, true));
        $zip = trim((string) get_user_meta($user_id, self::META_ZIP, true));
        $city = trim((string) get_user_meta($user_id, self::META_CITY, true));

        $street_line = trim(trim($street . ' ' . $house_number));
        $city_line = trim(trim($zip . ' ' . $city));
        $parts = array_filter([$street_line, $city_line], static fn(string $part): bool => $part !== '');

        return sanitize_text_field(implode(', ', $parts));
    }

    public function queue_commonsbooking_legacy_user_meta_sync($meta_id, $user_id, $meta_key, $meta_value = null): void
    {
        if (self::$commonsbooking_legacy_sync_suspension_depth > 0) {
            return;
        }

        if (!in_array((string) $meta_key, self::get_commonsbooking_legacy_sync_source_keys(), true)) {
            return;
        }

        $user_id = (int) $user_id;
        $force_contact_cleanup = current_filter() === 'deleted_user_meta';
        self::$pending_commonsbooking_legacy_user_meta_sync[$user_id] = !empty(self::$pending_commonsbooking_legacy_user_meta_sync[$user_id]) || $force_contact_cleanup;
    }

    public function queue_commonsbooking_legacy_profile_sync(int $user_id, \WP_User $old_user_data): void
    {
        if (self::$commonsbooking_legacy_sync_suspension_depth > 0) {
            return;
        }

        self::$pending_commonsbooking_legacy_user_meta_sync[$user_id] = !empty(self::$pending_commonsbooking_legacy_user_meta_sync[$user_id]);
    }

    public static function suspend_commonsbooking_legacy_sync(): void
    {
        self::$commonsbooking_legacy_sync_suspension_depth++;
    }

    public static function resume_commonsbooking_legacy_sync(): void
    {
        self::$commonsbooking_legacy_sync_suspension_depth = max(
            0,
            self::$commonsbooking_legacy_sync_suspension_depth - 1
        );
    }

    public function sync_pending_commonsbooking_legacy_user_meta(): void
    {
        if (empty(self::$pending_commonsbooking_legacy_user_meta_sync)) {
            return;
        }

        $queued_user_ids = self::$pending_commonsbooking_legacy_user_meta_sync;
        self::$pending_commonsbooking_legacy_user_meta_sync = [];

        foreach ($queued_user_ids as $user_id => $force_contact_cleanup) {
            self::sync_commonsbooking_legacy_user_meta((int) $user_id, (bool) $force_contact_cleanup);
        }
    }

    private static function get_commonsbooking_legacy_sync_source_keys(): array
    {
        return [
            self::META_PHONE,
            self::META_STREET,
            self::META_HOUSE_NUMBER,
            self::META_ZIP,
            self::META_CITY,
            self::META_TERMS_ACCEPTED_AT,
        ];
    }

    private static function has_user_management_contact_meta(int $user_id): bool
    {
        foreach ([self::META_PHONE, self::META_STREET, self::META_HOUSE_NUMBER, self::META_ZIP, self::META_CITY] as $meta_key) {
            if (metadata_exists('user', $user_id, $meta_key)) {
                return true;
            }
        }

        return false;
    }

    public static function count_pending_reviews(): int
    {
        $query = new \WP_User_Query([
            'fields' => 'ID',
            'number' => 1,
            'count_total' => true,
            'meta_query' => [
                [
                    'key' => self::META_ACCOUNT_STATUS,
                    'value' => 'pending',
                    'compare' => '=',
                ],
            ],
        ]);

        return (int) $query->get_total();
    }

    public static function get_user_meta_keys(): array
    {
        return [
            self::META_PHONE,
            self::META_PHONE_VERIFIED_AT,
            self::META_PHONE_CODE_HASH,
            self::META_PHONE_CODE_EXPIRES,
            self::META_PHONE_CODE_LAST_SENT,
            self::META_PHONE_CODE_SEND_LOCK,
            self::META_PHONE_MANUAL_REVIEW_NOTE,
            self::META_EMAIL_VERIFIED_AT,
            self::META_EMAIL_TOKEN_HASH,
            self::META_EMAIL_TOKEN_EXPIRES,
            self::META_EMAIL_LAST_SENT,
            self::META_EMAIL_REMINDER_SENT_AT,
            self::META_DELETE_PROFILE_TOKEN_HASH,
            self::META_DELETE_PROFILE_TOKEN_EXPIRES,
            self::META_DELETE_PROFILE_LAST_SENT,
            self::META_ACCOUNT_STATUS,
            self::META_ACCOUNT_REVIEW_REASON,
            self::META_ACCOUNT_REVIEW_AT,
            self::META_ACCOUNT_REVIEW_MATCH_IDS,
            self::META_TERMS_ACCEPTED_AT,
            self::META_PRIVACY_ACCEPTED_AT,
            self::META_LEGACY_MIGRATION_PROVENANCE,
            self::META_STREET,
            self::META_HOUSE_NUMBER,
            self::META_ZIP,
            self::META_CITY,
            self::META_STATE,
            self::META_COUNTRY,
            self::META_ADDRESS_LAT,
            self::META_ADDRESS_LON,
            self::META_ADDRESS_RAW,
            self::META_LAST_LOGIN,
        ];
    }

    public static function get_page_definitions(): array
    {
        return [
            'registration_page_id' => [
                'title' => 'Registrierung',
                'slug' => 'register',
                'shortcode' => '[afcb_register]',
            ],
            'login_page_id' => [
                'title' => 'Login',
                'slug' => 'login',
                'shortcode' => '[afcb_login]',
            ],
            'profile_page_id' => [
                'title' => 'Profil',
                'slug' => 'profil',
                'shortcode' => '[afcb_profile]',
            ],
            'forgot_password_page_id' => [
                'title' => 'Passwort vergessen',
                'slug' => 'passwort-vergessen',
                'shortcode' => '[afcb_forgot_password]',
            ],
            'forgot_username_page_id' => [
                'title' => 'Benutzername vergessen',
                'slug' => 'benutzername-vergessen',
                'shortcode' => '[afcb_forgot_username]',
            ],
        ];
    }

    public static function activate(): void
    {
        if (!function_exists('wp_insert_post')) {
            return;
        }

        $options = self::get_options();
        $created_pages = isset($options[self::CREATED_PAGES_KEY]) && is_array($options[self::CREATED_PAGES_KEY])
            ? $options[self::CREATED_PAGES_KEY]
            : [];

        foreach (self::get_page_definitions() as $key => $definition) {
            $existing_id = isset($options[$key]) ? (int) $options[$key] : 0;
            if ($existing_id && get_post_status($existing_id)) {
                continue;
            }

            $page = get_page_by_path($definition['slug']);
            if ($page && isset($page->ID)) {
                $options[$key] = (int) $page->ID;
                continue;
            }

            $page_id = wp_insert_post([
                'post_title' => $definition['title'],
                'post_name' => $definition['slug'],
                'post_content' => $definition['shortcode'],
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);

            if (!is_wp_error($page_id) && $page_id) {
                $options[$key] = (int) $page_id;
                $created_pages[$key] = (int) $page_id;
                update_post_meta($page_id, '_afcb_user_management_page', $key);
                update_post_meta($page_id, '_afcb_user_management_created', 1);
            }
        }

        $options[self::CREATED_PAGES_KEY] = $created_pages;
        update_option(self::OPTION_KEY, $options);

        if (!wp_next_scheduled('afcb_cron_email_verification_reminder')) {
            wp_schedule_event(time(), 'hourly', 'afcb_cron_email_verification_reminder');
        }
    }

    /**
     * Entfernt alle vom User-Management gesetzten Benutzermetadaten.
     * Wird bei Deinstallation verwendet, wenn der Nutzer das zuvor bestätigt hat.
     */
    public static function purge_user_meta(): int
    {
        $meta_keys = self::get_user_meta_keys();
        $custom_fields = self::get_custom_fields();
        foreach ($custom_fields as $field) {
            if (!empty($field['key'])) {
                $meta_keys[] = $field['key'];
            }
        }
        $meta_keys = array_values(array_unique($meta_keys));
        $users = get_users(['fields' => 'ID']);
        $deleted = 0;
        foreach ($users as $user_id) {
            $user_id = (int) $user_id;
            foreach ($meta_keys as $key) {
                if (delete_user_meta($user_id, $key)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    public static function uninstall(): void
    {
        if (!function_exists('wp_delete_post')) {
            return;
        }

        $do_cleanup = get_option(self::UNINSTALL_CLEANUP_OPTION) === '1';
        if ($do_cleanup) {
            self::purge_user_meta();
        }
        delete_option(self::UNINSTALL_CLEANUP_OPTION);

        $options = get_option(self::OPTION_KEY, []);
        $created_pages = isset($options[self::CREATED_PAGES_KEY]) && is_array($options[self::CREATED_PAGES_KEY])
            ? $options[self::CREATED_PAGES_KEY]
            : [];

        foreach ($created_pages as $page_id) {
            $page_id = (int) $page_id;
            if (!$page_id) {
                continue;
            }
            $created = get_post_meta($page_id, '_afcb_user_management_created', true);
            if (!$created) {
                continue;
            }
            wp_delete_post($page_id, true);
        }

        delete_option(self::OPTION_KEY);
    }

    public static function normalize_phone(string $phone): string
    {
        $normalized = preg_replace('/[^0-9+]/', '', $phone);
        if (strpos($normalized, '00') === 0) {
            $normalized = '+' . substr($normalized, 2);
        }

        $normalized_e164 = null;
        if (self::is_german_mobile_number($normalized, $normalized_e164) && $normalized_e164 !== null) {
            return $normalized_e164;
        }

        return $normalized;
    }

    /**
     * Prüft, ob die Nummer eine gültige deutsche Handynummer ist.
     * Erlaubt: +49 1XX XXXXXXXX (1XX = 15x, 16x, 17x) oder 01XX XXXXXXX.
     */
    public static function is_german_mobile(string $phone): bool
    {
        $unused = null;
        return self::is_german_mobile_number($phone, $unused);
    }

    /**
     * Prüft, ob ein String eine deutsche Handynummer ist.
     * Optional: gibt normalisierte E.164-Nummer zurück (+49...).
     *
     * Akzeptiert z. B.: 0176 12345678, +49 (176) 123-456-78,
     * 0049 176 12345678, 49176 12345678, 0151-23456789.
     */
    public static function is_german_mobile_number(string $input, ?string &$normalizedE164 = null): bool
    {
        $normalizedE164 = null;
        $s = trim($input);
        if ($s === '') {
            return false;
        }
        $s = preg_replace('/[^\d+]/', '', $s);
        if (strpos($s, '00') === 0) {
            $s = '+' . substr($s, 2);
        }
        if (!preg_match('/^\+?\d+$/', $s)) {
            return false;
        }
        if (strpos($s, '+') === 0) {
            if (strpos($s, '+49') !== 0) {
                return false;
            }
            $national = substr($s, 3);
        } elseif (strpos($s, '49') === 0) {
            $national = substr($s, 2);
        } else {
            if (strpos($s, '0') !== 0) {
                return false;
            }
            $national = substr($s, 1);
        }
        if (!preg_match('/^(15|16|17)\d+$/', $national)) {
            return false;
        }
        $len = strlen($national);
        if ($len < 9 || $len > 13) {
            return false;
        }
        $normalizedE164 = '+49' . $national;
        return true;
    }

    private static function is_international_phone_number(string $phone): bool
    {
        $normalized = self::normalize_phone($phone);
        if (strpos($normalized, '+49') === 0) {
            return false;
        }

        return (bool) preg_match('/^\+[1-9]\d{6,14}$/', $normalized);
    }

    public static function requires_manual_phone_verification(string $phone): bool
    {
        return self::is_international_phone_number($phone);
    }

    private static function normalize_account_phone(string $phone_input, ?string &$error, bool &$requires_manual_review): string
    {
        $error = null;
        $requires_manual_review = false;

        if ($phone_input === '') {
            return '';
        }

        $normalized_german_mobile = null;
        if (self::is_german_mobile_number($phone_input, $normalized_german_mobile) && $normalized_german_mobile !== null) {
            return $normalized_german_mobile;
        }

        $phone = self::normalize_phone($phone_input);
        if (self::is_international_phone_number($phone)) {
            $requires_manual_review = true;
            return $phone;
        }

        $error = 'Bitte gib eine gültige deutsche Handynummer oder eine internationale Telefonnummer mit Landesvorwahl ein.';
        return '';
    }

    public static function normalize_verification_phone(string $phone_input, ?string &$error, bool &$requires_manual_review): string
    {
        return self::normalize_account_phone($phone_input, $error, $requires_manual_review);
    }

    /**
     * Prüft, ob der Nutzer jemals eine Buchung (CommonsBooking) hatte.
     * Solche Accounts werden bei nicht verifizierter E-Mail nach 24h nicht gelöscht.
     */
    public static function user_has_ever_booked(int $user_id): bool
    {
        $post_type = self::get_booking_post_type();
        $query = new \WP_Query([
            'post_type' => $post_type,
            'author' => $user_id,
            'posts_per_page' => 1,
            'post_status' => 'any',
            'fields' => 'ids',
        ]);
        return $query->found_posts > 0;
    }

    public function maybe_schedule_email_verification_cron(): void
    {
        if (wp_doing_cron() || wp_doing_ajax()) {
            return;
        }
        if (!wp_next_scheduled('afcb_cron_email_verification_reminder')) {
            wp_schedule_event(time(), 'hourly', 'afcb_cron_email_verification_reminder');
        }
    }

    public function run_email_verification_cron(): void
    {
        $now = time();
        $reminder_interval = DAY_IN_SECONDS;
        $delete_interval = DAY_IN_SECONDS;

        $users = get_users([
            'fields' => 'ID',
            'meta_query' => [
                [
                    'key' => self::META_EMAIL_VERIFIED_AT,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        foreach ($users as $user) {
            $user_id = (int) $user->ID;
            $user_data = get_userdata($user_id);
            if (!$user_data) {
                continue;
            }
            $registered = (int) strtotime($user_data->user_registered);
            $reminder_sent = (int) get_user_meta($user_id, self::META_EMAIL_REMINDER_SENT_AT, true);

            if ($reminder_sent === 0) {
                if (($now - $registered) < $reminder_interval) {
                    continue;
                }
                self::send_email_verification($user_id);
                update_user_meta($user_id, self::META_EMAIL_REMINDER_SENT_AT, $now);
                continue;
            }

            if (($now - $reminder_sent) < $delete_interval) {
                continue;
            }

            if (self::user_has_ever_booked($user_id)) {
                continue;
            }

            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user_id);
        }
    }

    public function render_register(): string
    {
        $this->enqueue_assets(true, true, true);
        $options = self::get_options();

        return TemplateLoader::load()->render('user-register.html.twig', [
            'action_url' => esc_url(admin_url('admin-post.php')),
            'nonce_field' => wp_nonce_field('afcb_register', 'afcb_register_nonce', true, false),
            'notice' => $this->get_notice_message(),
            'notice_type' => $this->get_notice_type(),
            'privacy_url' => esc_url($this->get_legal_url('privacy_page_id', 'privacy_url')),
            'terms_url' => esc_url($this->get_legal_url('terms_page_id', 'terms_url')),
            'recaptcha_site_key' => esc_html($options['recaptcha_site_key']),
            'recaptcha_version' => $options['recaptcha_version'],
            'required_fields' => $options['required_fields'],
            'custom_fields' => self::get_custom_fields(),
            'form_data' => $this->get_register_form_data(),
            'login_url' => $this->get_page_url('login_page_id'),
        ]);
    }

    public function render_login(): string
    {
        $this->enqueue_assets(false, true);
        $options = self::get_options();
        $login_url = $this->get_page_url('login_page_id') ?: home_url('/');
        $profile_url = $this->get_page_url('profile_page_id') ?: home_url('/');

        return TemplateLoader::load()->render('user-login.html.twig', [
            'action_url' => esc_url(admin_url('admin-post.php')),
            'nonce_field' => wp_nonce_field('afcb_login', 'afcb_login_nonce', true, false),
            'notice' => $this->get_login_notice_message(),
            'notice_type' => $this->get_login_notice_type(),
            'recaptcha_site_key' => esc_html($options['recaptcha_site_key']),
            'recaptcha_version' => $options['recaptcha_version'],
            'forgot_password_url' => $this->get_page_url('forgot_password_page_id'),
            'forgot_username_url' => $this->get_page_url('forgot_username_page_id'),
            'register_url' => $this->get_page_url('registration_page_id'),
            'redirect_to' => isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '',
            'is_logged_in' => is_user_logged_in(),
            'current_user_display' => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            'profile_url' => $profile_url,
            'logout_url' => wp_logout_url(add_query_arg('loggedout', 'true', $login_url)),
        ]);
    }

    public function render_profile(): string
    {
        if (!is_user_logged_in()) {
            return '';
        }

        $this->enqueue_assets(true, false, true);
        $user = wp_get_current_user();
        $options = self::get_options();

        $phone = (string) get_user_meta($user->ID, self::META_PHONE, true);
        $account_review_reason = (string) get_user_meta($user->ID, self::META_ACCOUNT_REVIEW_REASON, true);

        $data = [
            'user_login' => $user->user_login,
            'user_email' => $user->user_email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $phone,
            'phone_requires_manual_review' => $phone !== '' && self::requires_manual_phone_verification($phone),
            'street' => get_user_meta($user->ID, self::META_STREET, true),
            'house_number' => get_user_meta($user->ID, self::META_HOUSE_NUMBER, true),
            'zip' => get_user_meta($user->ID, self::META_ZIP, true),
            'city' => get_user_meta($user->ID, self::META_CITY, true),
            'state' => get_user_meta($user->ID, self::META_STATE, true),
            'country' => get_user_meta($user->ID, self::META_COUNTRY, true),
            'address_lat' => get_user_meta($user->ID, self::META_ADDRESS_LAT, true),
            'address_lon' => get_user_meta($user->ID, self::META_ADDRESS_LON, true),
            'address_raw' => get_user_meta($user->ID, self::META_ADDRESS_RAW, true),
            'phone_verified' => self::is_phone_verified($user->ID),
            'email_verified' => (bool) get_user_meta($user->ID, self::META_EMAIL_VERIFIED_AT, true),
            'account_status' => $this->get_account_status($user->ID),
            'account_review_reason' => $account_review_reason,
            'account_review_label' => $account_review_reason !== '' ? self::get_review_reason_label($account_review_reason) : '',
            'manual_phone_review_note' => (string) get_user_meta($user->ID, self::META_PHONE_MANUAL_REVIEW_NOTE, true),
            'required_missing' => $this->get_missing_required_fields($user->ID),
        ];

        return TemplateLoader::load()->render('user-profile.html.twig', [
            'action_url' => esc_url(admin_url('admin-post.php')),
            'nonce_profile' => wp_nonce_field('afcb_profile_update', 'afcb_profile_nonce', true, false),
            'nonce_delete_profile' => wp_nonce_field('afcb_delete_profile', 'afcb_delete_profile_nonce', true, false),
            'nonce_resend_email' => wp_nonce_field('afcb_resend_email', 'afcb_resend_email_nonce', true, false),
            'nonce_send_phone' => wp_nonce_field('afcb_send_phone', 'afcb_send_phone_nonce', true, false),
            'nonce_verify_phone' => wp_nonce_field('afcb_verify_phone', 'afcb_verify_phone_nonce', true, false),
            'nonce_manual_phone_review' => wp_nonce_field('afcb_manual_phone_review', 'afcb_manual_phone_review_nonce', true, false),
            'notice' => $this->get_notice_message(),
            'notice_type' => $this->get_notice_type(),
            'data' => $data,
            'sms_configured' => self::is_sms_configured(),
            'privacy_url' => esc_url($this->get_legal_url('privacy_page_id', 'privacy_url')),
            'terms_url' => esc_url($this->get_legal_url('terms_page_id', 'terms_url')),
            'required_fields' => $options['required_fields'],
            'custom_fields' => self::get_custom_fields_with_values($user->ID),
        ]);
    }

    public function render_forgot_password(): string
    {
        $this->enqueue_assets(false, true);
        $options = self::get_options();
        $reset_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $reset_login = isset($_GET['login']) ? sanitize_user(wp_unslash($_GET['login'] ?? ''), true) : '';
        $is_reset = isset($_GET['afcb_action']) && sanitize_key(wp_unslash($_GET['afcb_action'])) === 'reset_password';

        return TemplateLoader::load()->render('user-forgot-password.html.twig', [
            'action_url' => esc_url(admin_url('admin-post.php')),
            'nonce_field' => wp_nonce_field('afcb_forgot_password', 'afcb_forgot_password_nonce', true, false),
            'reset_nonce_field' => wp_nonce_field('afcb_reset_password', 'afcb_reset_password_nonce', true, false),
            'notice' => $this->get_notice_message(),
            'notice_type' => $this->get_notice_type(),
            'recaptcha_site_key' => esc_html($options['recaptcha_site_key']),
            'recaptcha_version' => $options['recaptcha_version'],
            'is_reset' => $is_reset && $reset_key !== '' && $reset_login !== '',
            'reset_key' => $reset_key,
            'reset_login' => $reset_login,
            'login_url' => $this->get_page_url('login_page_id'),
        ]);
    }

    public function render_forgot_username(): string
    {
        $this->enqueue_assets(false, true, true);
        $options = self::get_options();

        return TemplateLoader::load()->render('user-forgot-username.html.twig', [
            'action_url' => esc_url(admin_url('admin-post.php')),
            'nonce_field' => wp_nonce_field('afcb_forgot_username', 'afcb_forgot_username_nonce', true, false),
            'notice' => $this->get_notice_message(),
            'notice_type' => $this->get_notice_type(),
            'recaptcha_site_key' => esc_html($options['recaptcha_site_key']),
            'recaptcha_version' => $options['recaptcha_version'],
            'login_url' => $this->get_page_url('login_page_id'),
        ]);
    }

    public function enqueue_current_page_assets(): void
    {
        if (is_admin()) {
            return;
        }

        $page_id = (int) get_queried_object_id();
        $content = $page_id > 0
            ? (string) get_post_field('post_content', $page_id, 'raw')
            : '';
        $options = get_option(self::OPTION_KEY, []);
        $requirements = FrontendAssetRequirements::resolve(
            $page_id,
            $content,
            is_array($options) ? $options : []
        );

        if ($requirements === null) {
            return;
        }

        $this->enqueue_assets(
            $requirements['address_lookup'],
            $requirements['recaptcha'],
            $requirements['phone_input']
        );
    }

    private function enqueue_assets(bool $needs_address_lookup, bool $needs_recaptcha, bool $needs_phone_input = false): void
    {
        if ($this->frontend_assets_enqueued) {
            return;
        }
        $this->frontend_assets_enqueued = true;

        $base_path = dirname(__DIR__, 2) . '/';
        $base_url = plugin_dir_url($base_path . 'commonsbooking-extended.php');
        $css_path = $base_path . 'assets/css/user-management.css';
        $css_version = file_exists($css_path) ? (string) filemtime($css_path) : '0.1';
        $js_path = $base_path . 'assets/js/afcb-user-management.js';
        $js_version = file_exists($js_path) ? (string) filemtime($js_path) : '0.1';
        $phone_input_version = '29.1.0';
        $style_deps = [];
        $script_deps = ['jquery'];

        if ($needs_phone_input) {
            wp_enqueue_style('afcb-intl-tel-input', 'https://cdn.jsdelivr.net/npm/intl-tel-input@29.1.0/dist/css/intlTelInput.css', [], $phone_input_version);
            wp_enqueue_script('afcb-intl-tel-input', 'https://cdn.jsdelivr.net/npm/intl-tel-input@29.1.0/dist/js/intlTelInputWithUtils.min.js', [], $phone_input_version, true);

            $style_deps[] = 'afcb-intl-tel-input';
            $script_deps[] = 'afcb-intl-tel-input';
        }

        wp_register_style('afcb-user-management', $base_url . 'assets/css/user-management.css', $style_deps, $css_version);
        wp_enqueue_style('afcb-user-management');

        if (!wp_style_is('mlr-modern-theme', 'enqueued')) {
            wp_enqueue_script('afcb-tailwind', 'https://cdn.tailwindcss.com', [], null, false);
            wp_add_inline_script(
                'afcb-tailwind',
                'window.tailwind = window.tailwind || {}; window.tailwind.config = { corePlugins: { preflight: false } };',
                'before'
            );
        }

        wp_register_script('afcb-user-management', $base_url . 'assets/js/afcb-user-management.js', $script_deps, $js_version, true);

        $options = self::get_options();

        wp_localize_script('afcb-user-management', 'afcbUserManagement', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'addressNonce' => wp_create_nonce('afcb_address_search'),
            'registrationLookupNonce' => wp_create_nonce('afcb_registration_lookup'),
            'addressEnabled' => $needs_address_lookup,
            'recaptchaSiteKey' => $options['recaptcha_site_key'],
            'recaptchaVersion' => $options['recaptcha_version'],
        ]);

        wp_enqueue_script('afcb-user-management');

        if ($needs_recaptcha && !empty($options['recaptcha_site_key'])) {
            $script_url = 'https://www.google.com/recaptcha/api.js';
            if (($options['recaptcha_version'] ?? 'v2') === 'v3') {
                $script_url = add_query_arg('render', $options['recaptcha_site_key'], $script_url);
            }
            wp_enqueue_script('afcb-google-recaptcha', $script_url, [], null, true);
        }
    }

    public function redirect_wp_login(): void
    {
        if (defined('AFCB_FALLBACK_LOGIN') && AFCB_FALLBACK_LOGIN) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] ?? '' === 'POST') {
            $fallback_marker = isset($_POST['afcb_fallback']) ? sanitize_text_field(wp_unslash($_POST['afcb_fallback'])) : '';
            if ($fallback_marker === '1') {
                return;
            }
        }

        if (isset($_GET['interim-login']) || isset($_GET['checkemail'])) {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : 'login';
        $skip_actions = ['logout', 'postpass', 'confirm_admin_email'];

        if (in_array($action, $skip_actions, true)) {
            return;
        }

        $target = '';
        if ($action === 'register') {
            $target = $this->get_page_url('registration_page_id');
        } elseif ($action === 'lostpassword' || $action === 'retrievepassword') {
            $target = $this->get_page_url('forgot_password_page_id');
        } elseif ($action === 'resetpass' || $action === 'rp') {
            $target = $this->get_page_url('forgot_password_page_id');
            if ($target) {
                $target = add_query_arg([
                    'afcb_action' => 'reset_password',
                    'key' => isset($_REQUEST['key']) ? sanitize_text_field(wp_unslash($_REQUEST['key'])) : '',
                    'login' => isset($_REQUEST['login']) ? sanitize_user(wp_unslash($_REQUEST['login']), true) : '',
                ], $target);
            }
        } else {
            $target = $this->get_page_url('login_page_id');
        }

        if (!$target) {
            return;
        }

        $redirect = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
        if (!empty($redirect)) {
            $target = add_query_arg('redirect_to', $redirect, $target);
        }

        wp_safe_redirect($target);
        exit;
    }

    public function render_fallback_marker(): void
    {
        if (!defined('AFCB_FALLBACK_LOGIN') || !AFCB_FALLBACK_LOGIN) {
            return;
        }

        echo TemplateLoader::load()->render('partials/fallback-marker.html.twig');
    }

    public function maybe_handle_fallback_login(): void
    {
        if (!function_exists('wp_parse_url')) {
            return;
        }

        $options = self::get_options();
        $slug = trim((string) ($options['fallback_login_slug'] ?? ''), '/');
        if ($slug === '') {
            return;
        }

        $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path = trim((string) $path, '/');

        if ($path !== $slug) {
            return;
        }

        if (!defined('AFCB_FALLBACK_LOGIN')) {
            define('AFCB_FALLBACK_LOGIN', true);
        }

        require_once ABSPATH . 'wp-login.php';
        exit;
    }

    public function track_login(string $user_login, \WP_User $user): void
    {
        $timestamp = current_time('timestamp');
        update_user_meta($user->ID, self::META_LAST_LOGIN, $timestamp);
        update_user_meta($user->ID, 'when_last_login', $timestamp);
    }

    public function filter_login_url(string $login_url, string $redirect, bool $force_reauth): string
    {
        if (defined('AFCB_FALLBACK_LOGIN') && AFCB_FALLBACK_LOGIN) {
            return $login_url;
        }

        $target = $this->get_page_url('login_page_id');
        if (!$target) {
            return $login_url;
        }

        if (!empty($redirect)) {
            $target = add_query_arg('redirect_to', $redirect, $target);
        }

        if ($force_reauth) {
            $target = add_query_arg('reauth', '1', $target);
        }

        return $target;
    }

    public function filter_register_url(string $url): string
    {
        if (defined('AFCB_FALLBACK_LOGIN') && AFCB_FALLBACK_LOGIN) {
            return $url;
        }

        $target = $this->get_page_url('registration_page_id');
        return $target ?: $url;
    }

    public function filter_lostpassword_url(string $url, string $redirect): string
    {
        if (defined('AFCB_FALLBACK_LOGIN') && AFCB_FALLBACK_LOGIN) {
            return $url;
        }

        $target = $this->get_page_url('forgot_password_page_id');
        if (!$target) {
            return $url;
        }

        if (!empty($redirect)) {
            $target = add_query_arg('redirect_to', $redirect, $target);
        }

        return $target;
    }

    public function handle_register(): void
    {
        if (!isset($_POST['afcb_register_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_register_nonce'])), 'afcb_register')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Ungültige Anfrage.');
        }

        if (is_user_logged_in()) {
            $this->redirect_with_notice($this->get_page_url('profile_page_id'), 'info', 'Du bist bereits angemeldet.');
        }

        if (!$this->verify_recaptcha()) {
            $this->redirect_register_error('reCAPTCHA Prüfung fehlgeschlagen.');
        }

        $user_login = sanitize_user(wp_unslash($_POST['user_login'] ?? ''), true);
        $email = sanitize_email(wp_unslash($_POST['user_email'] ?? ''));
        $password = (string) wp_unslash($_POST['user_pass'] ?? '');
        $password_confirm = (string) wp_unslash($_POST['user_pass_confirm'] ?? '');
        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $phone_input = trim((string) wp_unslash($_POST['phone'] ?? ''));
        $phone_error = null;
        $phone_requires_manual_review = false;
        $phone = self::normalize_account_phone($phone_input, $phone_error, $phone_requires_manual_review);

        $street = sanitize_text_field(wp_unslash($_POST['street'] ?? ''));
        $house_number = sanitize_text_field(wp_unslash($_POST['house_number'] ?? ''));
        $zip = sanitize_text_field(wp_unslash($_POST['zip'] ?? ''));
        $city = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $state = sanitize_text_field(wp_unslash($_POST['state'] ?? ''));
        $country = sanitize_text_field(wp_unslash($_POST['country'] ?? ''));
        $address_lat = sanitize_text_field(wp_unslash($_POST['address_lat'] ?? ''));
        $address_lon = sanitize_text_field(wp_unslash($_POST['address_lon'] ?? ''));
        $address_raw = wp_unslash($_POST['address_raw'] ?? '');

        $terms_checked = !empty($_POST['terms_accept']);
        $privacy_checked = !empty($_POST['privacy_accept']);

        if (empty($user_login) || empty($email) || empty($password)) {
            $this->redirect_register_error('Bitte fülle alle Pflichtfelder aus.');
        }

        if ($password !== $password_confirm) {
            $this->redirect_register_error('Passwörter stimmen nicht überein.');
        }

        $password_strength = $this->validate_password_strength($password);
        if (!$password_strength['valid']) {
            $this->redirect_register_error($password_strength['message']);
        }

        if (!is_email($email)) {
            $this->redirect_register_error('Bitte gib eine gültige E-Mail-Adresse an.');
        }

        if (!$terms_checked || !$privacy_checked) {
            $this->redirect_register_error('Bitte Datenschutz und Nutzungsbedingungen akzeptieren.');
        }

        if (username_exists($user_login)) {
            $this->redirect_register_error('Benutzername existiert bereits.');
        }

        $existing_email_user_id = (int) email_exists($email);
        if ($existing_email_user_id) {
            $this->send_contact_conflict_admin_notification(0, $existing_email_user_id, 'email', $email, 'Registrierung', [
                'user_login' => $user_login,
                'user_email' => $email,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
            ]);
            $this->redirect_register_error('E-Mail-Adresse ist bereits registriert.');
        }

        if ($phone_error !== null) {
            $this->redirect_register_error($phone_error);
        }

        if (!empty($phone)) {
            $existing_phone_user_id = (int) $this->find_user_by_phone($phone);
            if ($existing_phone_user_id) {
                $this->send_contact_conflict_admin_notification(0, $existing_phone_user_id, 'phone', $phone, 'Registrierung', [
                    'user_login' => $user_login,
                    'user_email' => $email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'phone' => $phone,
                ]);
                $this->redirect_register_error('Telefonnummer ist bereits registriert.');
            }
        }

        $custom_fields = self::get_custom_fields();
        $custom_values = $this->sanitize_custom_fields($custom_fields, $_POST['custom_fields'] ?? []);

        $input_map = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'user_email' => $email,
            self::META_PHONE => $phone,
            self::META_STREET => $street,
            self::META_HOUSE_NUMBER => $house_number,
            self::META_ZIP => $zip,
            self::META_CITY => $city,
            self::META_STATE => $state,
            self::META_COUNTRY => $country,
        ];

        foreach ($custom_values as $key => $value) {
            $input_map[$key] = $value;
        }

        $missing = $this->get_missing_required_from_input($input_map);
        if (!empty($missing)) {
            $this->redirect_register_error($this->get_required_fields_error_message($missing));
        }

        $user_id = wp_insert_user([
            'user_login' => $user_login,
            'user_email' => $email,
            'user_pass' => $password,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'role' => 'subscriber',
        ]);

        if (is_wp_error($user_id)) {
            $this->redirect_register_error($user_id->get_error_message());
        }

        if (!empty($phone)) {
            update_user_meta($user_id, self::META_PHONE, $phone);
            if (!self::is_sms_configured()) {
                update_user_meta($user_id, self::META_PHONE_VERIFIED_AT, time());
            }
        }
        if (!empty($street)) {
            update_user_meta($user_id, self::META_STREET, $street);
        }
        if (!empty($house_number)) {
            update_user_meta($user_id, self::META_HOUSE_NUMBER, $house_number);
        }
        if (!empty($zip)) {
            update_user_meta($user_id, self::META_ZIP, $zip);
        }
        if (!empty($city)) {
            update_user_meta($user_id, self::META_CITY, $city);
        }
        if (!empty($state)) {
            update_user_meta($user_id, self::META_STATE, $state);
        }
        if (!empty($country)) {
            update_user_meta($user_id, self::META_COUNTRY, $country);
        }
        if (!empty($address_lat)) {
            update_user_meta($user_id, self::META_ADDRESS_LAT, $address_lat);
        }
        if (!empty($address_lon)) {
            update_user_meta($user_id, self::META_ADDRESS_LON, $address_lon);
        }
        if (!empty($address_raw)) {
            update_user_meta($user_id, self::META_ADDRESS_RAW, wp_kses_post($address_raw));
        }

        foreach ($custom_values as $key => $value) {
            update_user_meta($user_id, $key, $value);
        }

        update_user_meta($user_id, self::META_TERMS_ACCEPTED_AT, time());
        update_user_meta($user_id, self::META_PRIVACY_ACCEPTED_AT, time());
        update_user_meta($user_id, self::META_ACCOUNT_STATUS, 'pending');
        self::sync_commonsbooking_legacy_user_meta($user_id);

        $has_pending_review = $this->maybe_flag_soft_duplicate($user_id, [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'street' => $street,
            'house_number' => $house_number,
            'zip' => $zip,
            'city' => $city,
        ]);

        $verification_sent = self::send_email_verification($user_id);

        $login_url = $this->get_page_url('login_page_id');
        if (!$verification_sent) {
            $this->redirect_with_notice($login_url ?: wp_login_url(), 'error', 'Registrierung abgeschlossen, aber die Bestätigungs-E-Mail konnte nicht versendet werden. Gib deine Zugangsdaten auf der Anmeldeseite erneut ein, um den Versand nochmals anzustoßen.');
        }

        $message = $has_pending_review
            ? 'Registrierung abgeschlossen. Bitte bestätige deine E-Mail-Adresse. Dein Account wird anschließend manuell geprüft.'
            : 'Registrierung abgeschlossen. Bitte bestätige deine E-Mail-Adresse.';
        $this->redirect_with_notice($login_url ?: wp_login_url(), 'success', $message);
    }

    public function handle_login(): void
    {
        if (!isset($_POST['afcb_login_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_login_nonce'])), 'afcb_login')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Ungültige Anfrage.');
        }

        if (!$this->verify_recaptcha()) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'reCAPTCHA Prüfung fehlgeschlagen.');
        }

        $login = sanitize_user(wp_unslash($_POST['user_login'] ?? ''), true);
        $password = (string) wp_unslash($_POST['user_pass'] ?? '');

        $user_by_login = get_user_by('login', $login);
        if (!$user_by_login && is_email($login)) {
            $user_by_login = get_user_by('email', $login);
        }
        $canonical_login = $user_by_login ? $user_by_login->user_login : $login;

        $verification_attempt = PendingEmailVerificationLogin::attempt(
            $canonical_login,
            $password,
            time(),
            self::EMAIL_RESEND_COOLDOWN,
            [self::class, 'send_email_verification']
        );
        if ($verification_attempt['state'] === PendingEmailVerificationLogin::STATE_AUTHENTICATION_FAILED) {
            $authentication_error = $verification_attempt['user'];
            if (is_wp_error($authentication_error)) {
                $this->redirect_with_notice(wp_get_referer(), 'error', $this->get_login_error_message($authentication_error));
            }
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Anmeldung fehlgeschlagen. Bitte prüfe deine Zugangsdaten.');
        }
        if ($verification_attempt['state'] === PendingEmailVerificationLogin::STATE_RESENT) {
            $this->redirect_with_notice(wp_get_referer(), 'success', 'Dein Passwort ist korrekt. Eine neue Bestätigungs-E-Mail wurde versendet.');
        }
        if ($verification_attempt['state'] === PendingEmailVerificationLogin::STATE_COOLDOWN) {
            $this->redirect_with_notice(wp_get_referer(), 'info', 'Dein Passwort ist korrekt. Eine Bestätigungs-E-Mail wurde bereits versendet. Bitte prüfe dein Postfach (auch den Spam-Ordner).');
        }
        if ($verification_attempt['state'] === PendingEmailVerificationLogin::STATE_SEND_FAILED) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Dein Passwort ist korrekt, aber die Bestätigungs-E-Mail konnte nicht versendet werden. Bitte versuche es später erneut.');
        }
        if ($verification_attempt['state'] !== PendingEmailVerificationLogin::STATE_VERIFIED) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte bestätige zuerst deine E-Mail-Adresse. Wende dich an das Verleihteam, wenn du keine Bestätigungs-E-Mail erhalten hast.');
        }

        $user = wp_signon([
            'user_login' => $canonical_login,
            'user_password' => $password,
            'remember' => !empty($_POST['rememberme']),
        ], is_ssl());

        if (is_wp_error($user)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', $this->get_login_error_message($user));
        }

        $redirect = !empty($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
        if (empty($redirect)) {
            $redirect = $this->get_page_url('profile_page_id') ?: home_url();
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_profile_update(): void
    {
        if (!is_user_logged_in()) {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'error', 'Bitte melde dich an.');
        }

        if (!isset($_POST['afcb_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_profile_nonce'])), 'afcb_profile_update')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Ungültige Anfrage.');
        }

        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        $email = sanitize_email(wp_unslash($_POST['user_email'] ?? ''));
        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $phone_input = trim((string) wp_unslash($_POST['phone'] ?? ''));
        $phone_error = null;
        $phone_requires_manual_review = false;
        $phone = self::normalize_account_phone($phone_input, $phone_error, $phone_requires_manual_review);

        $street = sanitize_text_field(wp_unslash($_POST['street'] ?? ''));
        $house_number = sanitize_text_field(wp_unslash($_POST['house_number'] ?? ''));
        $zip = sanitize_text_field(wp_unslash($_POST['zip'] ?? ''));
        $city = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $state = sanitize_text_field(wp_unslash($_POST['state'] ?? ''));
        $country = sanitize_text_field(wp_unslash($_POST['country'] ?? ''));
        $address_lat = sanitize_text_field(wp_unslash($_POST['address_lat'] ?? ''));
        $address_lon = sanitize_text_field(wp_unslash($_POST['address_lon'] ?? ''));
        $address_raw = wp_unslash($_POST['address_raw'] ?? '');

        if (!is_email($email)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte gib eine gültige E-Mail-Adresse an.');
        }

        if ($phone_error !== null) {
            $this->redirect_with_notice(wp_get_referer(), 'error', $phone_error);
        }

        $existing_phone_user = $this->find_user_by_phone($phone);
        if (!empty($phone) && $existing_phone_user && (int) $existing_phone_user !== (int) $user_id) {
            $this->send_contact_conflict_admin_notification($user_id, (int) $existing_phone_user, 'phone', $phone, 'Profiländerung');
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Telefonnummer ist bereits registriert.');
        }

        if ($email !== $user->user_email) {
            $existing_email_user_id = (int) email_exists($email);
            if ($existing_email_user_id && $existing_email_user_id !== (int) $user_id) {
                $this->send_contact_conflict_admin_notification($user_id, $existing_email_user_id, 'email', $email, 'Profiländerung');
                $this->redirect_with_notice(wp_get_referer(), 'error', 'E-Mail-Adresse ist bereits registriert.');
            }
        }

        $custom_fields = self::get_custom_fields();
        $custom_values = $this->sanitize_custom_fields($custom_fields, $_POST['custom_fields'] ?? []);

        $input_map = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'user_email' => $email,
            self::META_PHONE => $phone,
            self::META_STREET => $street,
            self::META_HOUSE_NUMBER => $house_number,
            self::META_ZIP => $zip,
            self::META_CITY => $city,
            self::META_STATE => $state,
            self::META_COUNTRY => $country,
        ];

        foreach ($custom_values as $key => $value) {
            $input_map[$key] = $value;
        }

        $missing = $this->get_missing_required_from_input($input_map);
        if (!empty($missing)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', $this->get_required_fields_error_message($missing));
        }

        $result = wp_update_user([
            'ID' => $user_id,
            'user_email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ]);

        if (is_wp_error($result)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', $result->get_error_message());
        }

        $previous_phone = (string) get_user_meta($user_id, self::META_PHONE, true);

        update_user_meta($user_id, self::META_PHONE, $phone);
        update_user_meta($user_id, self::META_STREET, $street);
        update_user_meta($user_id, self::META_HOUSE_NUMBER, $house_number);
        update_user_meta($user_id, self::META_ZIP, $zip);
        update_user_meta($user_id, self::META_CITY, $city);
        update_user_meta($user_id, self::META_STATE, $state);
        update_user_meta($user_id, self::META_COUNTRY, $country);
        update_user_meta($user_id, self::META_ADDRESS_LAT, $address_lat);
        update_user_meta($user_id, self::META_ADDRESS_LON, $address_lon);
        if (!empty($address_raw)) {
            update_user_meta($user_id, self::META_ADDRESS_RAW, wp_kses_post($address_raw));
        }

        foreach ($custom_values as $key => $value) {
            update_user_meta($user_id, $key, $value);
        }

        self::sync_commonsbooking_legacy_user_meta($user_id);

        if ($email !== $user->user_email) {
            delete_user_meta($user_id, self::META_EMAIL_VERIFIED_AT);
            if (!self::send_email_verification($user_id)) {
                $this->redirect_with_notice(wp_get_referer(), 'error', 'Profil aktualisiert, aber die neue Bestätigungs-E-Mail konnte nicht versendet werden.');
            }
        }

        if ($phone !== $previous_phone) {
            if ($phone_requires_manual_review) {
                delete_user_meta($user_id, self::META_PHONE_VERIFIED_AT);
            } elseif (self::is_sms_configured()) {
                delete_user_meta($user_id, self::META_PHONE_VERIFIED_AT);
            } elseif (!empty($phone)) {
                update_user_meta($user_id, self::META_PHONE_VERIFIED_AT, time());
            }
        } elseif (!empty($phone) && !self::is_sms_configured()) {
            update_user_meta($user_id, self::META_PHONE_VERIFIED_AT, time());
        }

        $this->redirect_with_notice(wp_get_referer(), 'success', 'Profil aktualisiert.');
    }

    public static function sync_commonsbooking_legacy_user_meta(int $user_id, bool $force_contact_cleanup = false): void
    {
        if (!get_userdata($user_id)) {
            return;
        }

        $phone = trim((string) get_user_meta($user_id, self::META_PHONE, true));
        $address = self::format_user_management_address($user_id);
        $is_managed_contact = $force_contact_cleanup || self::has_user_management_contact_meta($user_id);
        $terms_accepted_at = (int) get_user_meta($user_id, self::META_TERMS_ACCEPTED_AT, true);

        if ($phone !== '') {
            update_user_meta($user_id, 'phone', $phone);
        } elseif ($is_managed_contact) {
            delete_user_meta($user_id, 'phone');
        }

        if ($address !== '') {
            update_user_meta($user_id, 'address', $address);
        } elseif ($is_managed_contact) {
            delete_user_meta($user_id, 'address');
        }

        if ($terms_accepted_at > 0) {
            update_user_meta($user_id, 'terms_accepted', 'yes');
        } elseif (metadata_exists('user', $user_id, self::META_TERMS_ACCEPTED_AT)) {
            delete_user_meta($user_id, 'terms_accepted');
        }
    }

    public function handle_delete_profile_request(): void
    {
        if (!is_user_logged_in()) {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'error', 'Bitte melde dich an.');
        }

        if (!isset($_POST['afcb_delete_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_delete_profile_nonce'])), 'afcb_delete_profile')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Ungültige Anfrage.');
        }

        if (empty($_POST['delete_profile_confirm'])) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte bestätige die Profil-Löschung.');
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'error', 'Bitte melde dich an.');
        }

        if (user_can($user_id, 'manage_options')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Administratorkonten können nicht über die Profilseite gelöscht werden.');
        }

        $user = get_userdata($user_id);
        if (!$user || !is_email($user->user_email)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Für dein Profil ist keine gültige E-Mail-Adresse hinterlegt.');
        }

        $last_sent = (int) get_user_meta($user_id, self::META_DELETE_PROFILE_LAST_SENT, true);
        if ($last_sent && (time() - $last_sent) < self::DELETE_PROFILE_RESEND_COOLDOWN) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte warte kurz, bevor du erneut eine Lösch-Mail anforderst.');
        }

        if (!$this->send_delete_profile_confirmation($user)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Die Lösch-Mail konnte nicht versendet werden. Bitte versuche es später erneut.');
        }

        $this->redirect_with_notice(wp_get_referer(), 'success', 'Wir haben dir eine E-Mail mit dem Bestätigungslink zur Profil-Löschung gesendet.');
    }

    public function handle_delete_profile_confirmation(): void
    {
        if (empty($_GET['afcb_action']) || sanitize_key(wp_unslash($_GET['afcb_action'])) !== 'delete_profile') {
            return;
        }

        $user_id = isset($_GET['uid']) ? (int) $_GET['uid'] : 0;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if (!$user_id || $token === '') {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: home_url('/'), 'error', 'Löschlink ist ungültig.');
        }

        $user = get_userdata($user_id);
        if (!$user) {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: home_url('/'), 'error', 'Profil wurde nicht gefunden.');
        }

        if (user_can($user_id, 'manage_options')) {
            $this->redirect_with_notice($this->get_page_url('profile_page_id') ?: home_url('/'), 'error', 'Administratorkonten können nicht über die Profilseite gelöscht werden.');
        }

        $hash = (string) get_user_meta($user_id, self::META_DELETE_PROFILE_TOKEN_HASH, true);
        $expires = (int) get_user_meta($user_id, self::META_DELETE_PROFILE_TOKEN_EXPIRES, true);

        if (!$hash || !$expires || time() > $expires) {
            $this->clear_delete_profile_token($user_id);
            $this->redirect_with_notice($this->get_page_url('profile_page_id') ?: home_url('/'), 'error', 'Löschlink ist abgelaufen. Bitte fordere die Profil-Löschung erneut an.');
        }

        if (!wp_check_password($token, $hash)) {
            $this->redirect_with_notice($this->get_page_url('profile_page_id') ?: home_url('/'), 'error', 'Löschlink ist ungültig.');
        }

        $this->clear_delete_profile_token($user_id);
        $this->delete_profile_and_redirect($user_id);
    }

    private function delete_profile_and_redirect(int $user_id): void
    {
        $canceled_count = $this->cancel_user_bookings($user_id);

        require_once ABSPATH . 'wp-admin/includes/user.php';
        // Reassign to author 0 so canceled booking posts are retained instead of being deleted with the user.
        $deleted = wp_delete_user($user_id, 0);
        if (!$deleted) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Profil konnte nicht gelöscht werden.');
        }

        if (get_current_user_id() === $user_id) {
            wp_logout();
        }

        if ($canceled_count === 1) {
            $message = 'Profil gelöscht. Eine Buchung wurde storniert.';
        } elseif ($canceled_count > 1) {
            $message = sprintf('Profil gelöscht. %d Buchungen wurden storniert.', $canceled_count);
        } else {
            $message = 'Profil gelöscht.';
        }

        $this->redirect_with_notice($this->get_page_url('login_page_id') ?: home_url('/'), 'success', $message);
    }

    private function send_delete_profile_confirmation(\WP_User $user): bool
    {
        $token = wp_generate_password(32, false, false);
        update_user_meta($user->ID, self::META_DELETE_PROFILE_TOKEN_HASH, wp_hash_password($token));
        update_user_meta($user->ID, self::META_DELETE_PROFILE_TOKEN_EXPIRES, time() + self::DELETE_PROFILE_TOKEN_EXPIRY_SECONDS);
        update_user_meta($user->ID, self::META_DELETE_PROFILE_LAST_SENT, time());

        $delete_url = add_query_arg([
            'afcb_action' => 'delete_profile',
            'uid' => $user->ID,
            'token' => $token,
        ], home_url('/'));

        $tags = self::get_base_email_tags($user);
        $tags['delete_profile_link'] = esc_url($delete_url);

        return self::send_templated_mail($user->user_email, 'delete_profile', $tags);
    }

    private function clear_delete_profile_token(int $user_id): void
    {
        delete_user_meta($user_id, self::META_DELETE_PROFILE_TOKEN_HASH);
        delete_user_meta($user_id, self::META_DELETE_PROFILE_TOKEN_EXPIRES);
    }

    public function handle_resend_email_verification(): void
    {
        if (!is_user_logged_in()) {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'error', 'Bitte melde dich an.');
        }

        if (!isset($_POST['afcb_resend_email_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_resend_email_nonce'])), 'afcb_resend_email')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Ungültige Anfrage.');
        }

        $user_id = get_current_user_id();
        $last_sent = (int) get_user_meta($user_id, self::META_EMAIL_LAST_SENT, true);
        if ($last_sent && (time() - $last_sent) < self::EMAIL_RESEND_COOLDOWN) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte warte kurz, bevor du erneut sendest.');
        }

        if (!self::send_email_verification($user_id)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bestätigungs-E-Mail konnte nicht versendet werden.');
        }

        $this->redirect_with_notice(wp_get_referer(), 'success', 'Bestätigungs-E-Mail wurde erneut gesendet.');
    }

    public function handle_send_phone_code(): void
    {
        if (!is_user_logged_in()) {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'error', 'Bitte melde dich an.');
        }

        if (!isset($_POST['afcb_send_phone_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_send_phone_nonce'])), 'afcb_send_phone')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Ungültige Anfrage.');
        }

        $user_id = get_current_user_id();
        $phone_input = trim((string) wp_unslash($_POST['phone'] ?? ''));
        $phone_error = null;
        $requires_manual_review = false;
        $requested_channel = sanitize_key(wp_unslash($_POST['phone_channel'] ?? 'sms'));
        if ($phone_input !== '') {
            $phone = self::normalize_account_phone($phone_input, $phone_error, $requires_manual_review);
        } else {
            $phone = self::normalize_account_phone((string) get_user_meta($user_id, self::META_PHONE, true), $phone_error, $requires_manual_review);
        }

        if ($phone_error !== null) {
            $this->redirect_with_notice(wp_get_referer(), 'error', $phone_error);
        }

        if (empty($phone)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte gib deine Handynummer ein.');
        }

        if ($phone !== (string) get_user_meta($user_id, self::META_PHONE, true)) {
            $existing = $this->find_user_by_phone($phone);
            if ($existing && (int) $existing !== $user_id) {
                $this->send_contact_conflict_admin_notification($user_id, (int) $existing, 'phone', $phone, 'Telefonnummer verifizieren');
                $this->redirect_with_notice(wp_get_referer(), 'error', 'Diese Nummer wird bereits von einem anderen Konto genutzt.');
            }
            update_user_meta($user_id, self::META_PHONE, $phone);
            delete_user_meta($user_id, self::META_PHONE_VERIFIED_AT);
        }

        $last_sent = (int) get_user_meta($user_id, self::META_PHONE_CODE_LAST_SENT, true);
        if ($last_sent && (time() - $last_sent) < self::PHONE_CODE_RESEND_COOLDOWN) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte warte noch eine Minute, dann kannst du einen neuen Code anfordern.');
        }

        $send_lock = $this->acquire_phone_code_send_lock($user_id);
        if ($send_lock === '') {
            $this->redirect_with_notice(wp_get_referer(), 'info', 'Der Versand wird bereits verarbeitet. Bitte warte einen Moment.');
        }

        $code = (string) random_int(100000, 999999);
        update_user_meta($user_id, self::META_PHONE_CODE_HASH, wp_hash_password($code));
        update_user_meta($user_id, self::META_PHONE_CODE_EXPIRES, time() + self::PHONE_CODE_EXPIRY_SECONDS);
        update_user_meta($user_id, self::META_PHONE_CODE_LAST_SENT, time());

        $options = self::get_options();
        $template = $options['sms_verification_message'] ?? 'Hallo {{vorname}} {{nachname}}, der Code zum Verifizieren deiner Telefonnummer für {{page_title}} ist: {{code}}. Er ist 1 Stunde gültig.';
        $page_title = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $user = get_userdata($user_id);
        $vorname = $user ? (string) $user->first_name : '';
        $nachname = $user ? (string) $user->last_name : '';
        $message = self::render_template($template, [
            'vorname' => $vorname,
            'nachname' => $nachname,
            'page_title' => $page_title,
            'code' => $code,
        ]);

        $channel = $this->resolve_phone_verification_channel($requires_manual_review, $requested_channel);
        try {
            $sent = apply_filters($channel === 'signal' ? 'afcb_send_signal' : 'afcb_send_sms', false, $phone, $message, [
                'user_id' => $user_id,
                'context' => 'phone_verification',
                'channel' => $channel,
            ]);
        } finally {
            $this->release_phone_code_send_lock($user_id, $send_lock);
        }

        if (!$sent) {
            if (self::was_last_message_delivery_uncertain()) {
                $this->redirect_with_notice(wp_get_referer(), 'warning', self::MESSAGE_DELIVERY_UNCERTAIN_NOTICE);
            }
            if ($channel === 'signal') {
                $this->redirect_with_notice(wp_get_referer(), 'error', 'Die Signal-Nachricht konnte nicht gesendet werden. Du kannst stattdessen eine manuelle Prüfung beantragen.');
            }
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Die Nachricht konnte leider nicht gesendet werden. Bitte versuche es später erneut.');
        }

        $this->redirect_with_notice(wp_get_referer(), 'success', 'Der Code wurde per ' . $this->get_phone_channel_label($channel) . ' an deine Telefonnummer gesendet.');
    }

    /**
     * AJAX: Telefon-Code per SMS oder Signal anfordern. Gibt JSON zurück, Fehlermeldungen erscheinen im Modal.
     */
    public function handle_ajax_send_phone_code(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Bitte melde dich an.']);
        }

        if (!isset($_POST['afcb_send_phone_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_send_phone_nonce'])), 'afcb_send_phone')) {
            wp_send_json_error(['message' => 'Die Anfrage ist ungültig. Lade die Seite neu und versuche es erneut.']);
        }

        $user_id = get_current_user_id();
        $phone_input = trim((string) wp_unslash($_POST['phone'] ?? ''));
        $phone_error = null;
        $requires_manual_review = false;
        $requested_channel = sanitize_key(wp_unslash($_POST['phone_channel'] ?? 'sms'));
        if ($phone_input !== '') {
            $phone = self::normalize_account_phone($phone_input, $phone_error, $requires_manual_review);
        } else {
            $phone = self::normalize_account_phone((string) get_user_meta($user_id, self::META_PHONE, true), $phone_error, $requires_manual_review);
        }

        if ($phone_error !== null) {
            wp_send_json_error(['message' => $phone_error]);
        }

        if (empty($phone)) {
            wp_send_json_error(['message' => 'Bitte gib deine Handynummer ein.']);
        }

        if ($phone !== (string) get_user_meta($user_id, self::META_PHONE, true)) {
            $existing = $this->find_user_by_phone($phone);
            if ($existing && (int) $existing !== $user_id) {
                $this->send_contact_conflict_admin_notification($user_id, (int) $existing, 'phone', $phone, 'Telefonnummer verifizieren');
                wp_send_json_error(['message' => 'Diese Nummer wird bereits von einem anderen Konto genutzt.']);
            }
            update_user_meta($user_id, self::META_PHONE, $phone);
            delete_user_meta($user_id, self::META_PHONE_VERIFIED_AT);
        }

        $last_sent = (int) get_user_meta($user_id, self::META_PHONE_CODE_LAST_SENT, true);
        if ($last_sent && (time() - $last_sent) < self::PHONE_CODE_RESEND_COOLDOWN) {
            wp_send_json_error(['message' => 'Bitte warte noch eine Minute, dann kannst du einen neuen Code anfordern.']);
        }

        $send_lock = $this->acquire_phone_code_send_lock($user_id);
        if ($send_lock === '') {
            wp_send_json_error(['message' => 'Der Versand wird bereits verarbeitet. Bitte warte einen Moment.']);
        }

        $code = (string) random_int(100000, 999999);
        update_user_meta($user_id, self::META_PHONE_CODE_HASH, wp_hash_password($code));
        update_user_meta($user_id, self::META_PHONE_CODE_EXPIRES, time() + self::PHONE_CODE_EXPIRY_SECONDS);
        update_user_meta($user_id, self::META_PHONE_CODE_LAST_SENT, time());

        $options = self::get_options();
        $template = $options['sms_verification_message'] ?? 'Hallo {{vorname}} {{nachname}}, der Code zum Verifizieren deiner Telefonnummer für {{page_title}} ist: {{code}}. Er ist 1 Stunde gültig.';
        $page_title = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $user = get_userdata($user_id);
        $vorname = $user ? (string) $user->first_name : '';
        $nachname = $user ? (string) $user->last_name : '';
        $message = self::render_template($template, [
            'vorname' => $vorname,
            'nachname' => $nachname,
            'page_title' => $page_title,
            'code' => $code,
        ]);

        $channel = $this->resolve_phone_verification_channel($requires_manual_review, $requested_channel);
        try {
            $sent = apply_filters($channel === 'signal' ? 'afcb_send_signal' : 'afcb_send_sms', false, $phone, $message, [
                'user_id' => $user_id,
                'context' => 'phone_verification',
                'channel' => $channel,
            ]);
        } finally {
            $this->release_phone_code_send_lock($user_id, $send_lock);
        }

        if (!$sent) {
            if (self::was_last_message_delivery_uncertain()) {
                wp_send_json_success([
                    'message' => self::MESSAGE_DELIVERY_UNCERTAIN_NOTICE,
                    'delivery_status' => self::MESSAGE_DELIVERY_STATUS_UNCERTAIN,
                ]);
            }
            if ($channel === 'signal') {
                wp_send_json_error(['message' => 'Die Signal-Nachricht konnte nicht gesendet werden. Du kannst stattdessen eine manuelle Prüfung beantragen.']);
            }
            wp_send_json_error(['message' => 'Die Nachricht konnte leider nicht gesendet werden. Bitte versuche es später erneut.']);
        }

        wp_send_json_success(['message' => 'Der Code wurde per ' . $this->get_phone_channel_label($channel) . ' an deine Telefonnummer gesendet.']);
    }

    private function acquire_phone_code_send_lock(int $user_id): string
    {
        $existing_lock = (string) get_user_meta($user_id, self::META_PHONE_CODE_SEND_LOCK, true);
        if ($existing_lock !== '') {
            $lock_timestamp = (int) strtok($existing_lock, ':');
            if ($lock_timestamp > 0 && (time() - $lock_timestamp) < self::PHONE_CODE_SEND_LOCK_SECONDS) {
                return '';
            }

            delete_user_meta($user_id, self::META_PHONE_CODE_SEND_LOCK, $existing_lock);
        }

        $lock = time() . ':' . wp_generate_uuid4();
        return add_user_meta($user_id, self::META_PHONE_CODE_SEND_LOCK, $lock, true) ? $lock : '';
    }

    private function release_phone_code_send_lock(int $user_id, string $lock): void
    {
        if ($lock !== '') {
            delete_user_meta($user_id, self::META_PHONE_CODE_SEND_LOCK, $lock);
        }
    }

    private function resolve_phone_verification_channel(bool $requires_manual_review, string $requested_channel): string
    {
        if ($requested_channel === 'signal') {
            return 'signal';
        }

        if ($requested_channel === 'sms' && !$requires_manual_review) {
            return 'sms';
        }

        return 'signal';
    }

    private function get_phone_channel_label(string $channel): string
    {
        return $channel === 'signal' ? 'Signal' : 'SMS';
    }

    public function handle_manual_phone_verification_request(): void
    {
        if (!is_user_logged_in()) {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'error', 'Bitte melde dich an.');
        }

        if (!isset($_POST['afcb_manual_phone_review_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_manual_phone_review_nonce'])), 'afcb_manual_phone_review')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Ungültige Anfrage.');
        }

        $result = $this->request_manual_phone_review(get_current_user_id());
        $this->redirect_with_notice(
            wp_get_referer(),
            $result['success'] ? 'success' : 'error',
            $result['message']
        );
    }

    public function handle_ajax_manual_phone_verification_request(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Bitte melde dich an.']);
        }

        if (!isset($_POST['afcb_manual_phone_review_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_manual_phone_review_nonce'])), 'afcb_manual_phone_review')) {
            wp_send_json_error(['message' => 'Die Anfrage ist ungültig. Lade die Seite neu und versuche es erneut.']);
        }

        $result = $this->request_manual_phone_review(get_current_user_id());
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']]);
        }

        wp_send_json_success(['message' => $result['message']]);
    }

    private function request_manual_phone_review(int $user_id): array
    {
        $phone_input = trim((string) wp_unslash($_POST['phone'] ?? ''));
        $phone_error = null;
        $requires_manual_review = false;
        if ($phone_input !== '') {
            $phone = self::normalize_account_phone($phone_input, $phone_error, $requires_manual_review);
        } else {
            $phone = self::normalize_account_phone((string) get_user_meta($user_id, self::META_PHONE, true), $phone_error, $requires_manual_review);
        }

        if ($phone_error !== null) {
            return ['success' => false, 'message' => $phone_error];
        }

        if ($phone === '') {
            return ['success' => false, 'message' => 'Bitte gib deine Telefonnummer mit Landesvorwahl ein.'];
        }

        if (!$requires_manual_review) {
            return ['success' => false, 'message' => 'Die manuelle Prüfung ist für internationale Telefonnummern vorgesehen. Für deutsche Handynummern nutze bitte den SMS-Code.'];
        }

        $note = sanitize_textarea_field(wp_unslash($_POST['manual_review_note'] ?? ''));
        if (trim($note) === '') {
            return ['success' => false, 'message' => 'Bitte schreibe kurz dazu, wie wir deine Telefonnummer manuell prüfen können.'];
        }

        if ($phone !== (string) get_user_meta($user_id, self::META_PHONE, true)) {
            $existing = $this->find_user_by_phone($phone);
            if ($existing && (int) $existing !== $user_id) {
                $this->send_contact_conflict_admin_notification($user_id, (int) $existing, 'phone', $phone, 'Manuelle Telefonverifizierung');
                return ['success' => false, 'message' => 'Diese Nummer wird bereits von einem anderen Konto genutzt.'];
            }
            update_user_meta($user_id, self::META_PHONE, $phone);
            delete_user_meta($user_id, self::META_PHONE_VERIFIED_AT);
        }

        update_user_meta($user_id, self::META_PHONE_MANUAL_REVIEW_NOTE, $note);
        delete_user_meta($user_id, self::META_PHONE_CODE_HASH);
        delete_user_meta($user_id, self::META_PHONE_CODE_EXPIRES);

        $this->flag_user_for_review($user_id, 'foreign_phone_manual_verification', []);

        return ['success' => true, 'message' => 'Deine Anfrage zur manuellen Telefonprüfung wurde gesendet. Wir prüfen deinen Account manuell.'];
    }

    /**
     * AJAX: Telefon-Code prüfen. Gibt JSON zurück, Fehlermeldungen erscheinen im Modal.
     */
    public function handle_ajax_verify_phone_code(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Bitte melde dich an.']);
        }

        if (!isset($_POST['afcb_verify_phone_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_verify_phone_nonce'])), 'afcb_verify_phone')) {
            wp_send_json_error(['message' => 'Die Anfrage ist ungültig. Lade die Seite neu und versuche es erneut.']);
        }

        $user_id = get_current_user_id();
        $code = sanitize_text_field(wp_unslash($_POST['phone_code'] ?? ''));
        if (empty($code)) {
            wp_send_json_error(['message' => 'Bitte gib den Code ein, den du per Nachricht erhalten hast.']);
        }

        $hash = get_user_meta($user_id, self::META_PHONE_CODE_HASH, true);
        $expires = (int) get_user_meta($user_id, self::META_PHONE_CODE_EXPIRES, true);
        if (!$hash || !$expires || time() > $expires) {
            wp_send_json_error(['message' => 'Der Code ist abgelaufen. Bitte fordere einen neuen Code an.']);
        }

        if (!wp_check_password($code, $hash)) {
            wp_send_json_error(['message' => 'Der eingegebene Code ist falsch. Bitte prüfe die Ziffern und versuche es erneut.']);
        }

        update_user_meta($user_id, self::META_PHONE_VERIFIED_AT, time());
        delete_user_meta($user_id, self::META_PHONE_CODE_HASH);
        delete_user_meta($user_id, self::META_PHONE_CODE_EXPIRES);
        delete_user_meta($user_id, self::META_PHONE_MANUAL_REVIEW_NOTE);
        $this->activate_if_manual_phone_review_resolved($user_id);

        wp_send_json_success(['message' => 'Deine Handynummer wurde bestätigt.']);
    }

    public function handle_verify_phone_code(): void
    {
        if (!is_user_logged_in()) {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'error', 'Bitte melde dich an.');
        }

        if (!isset($_POST['afcb_verify_phone_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_verify_phone_nonce'])), 'afcb_verify_phone')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Ungültige Anfrage.');
        }

        $user_id = get_current_user_id();
        $code = sanitize_text_field(wp_unslash($_POST['phone_code'] ?? ''));
        if (empty($code)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte gib den Code ein, den du per Nachricht erhalten hast.');
        }

        $hash = get_user_meta($user_id, self::META_PHONE_CODE_HASH, true);
        $expires = (int) get_user_meta($user_id, self::META_PHONE_CODE_EXPIRES, true);
        if (!$hash || !$expires || time() > $expires) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Der Code ist abgelaufen. Bitte fordere einen neuen Code an.');
        }

        if (!wp_check_password($code, $hash)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Der eingegebene Code ist falsch. Bitte prüfe die Ziffern und versuche es erneut.');
        }

        update_user_meta($user_id, self::META_PHONE_VERIFIED_AT, time());
        delete_user_meta($user_id, self::META_PHONE_CODE_HASH);
        delete_user_meta($user_id, self::META_PHONE_CODE_EXPIRES);
        delete_user_meta($user_id, self::META_PHONE_MANUAL_REVIEW_NOTE);
        $this->activate_if_manual_phone_review_resolved($user_id);

        $this->redirect_with_notice(wp_get_referer(), 'success', 'Deine Handynummer wurde bestätigt.');
    }

    public function send_sms_via_api($sent, string $phone, string $message, array $context): bool
    {
        return $this->send_message_via_api($sent, 'sms', $phone, $message, $context);
    }

    public function send_signal_via_api($sent, string $phone, string $message, array $context): bool
    {
        return $this->send_message_via_api($sent, 'signal', $phone, $message, $context);
    }

    /**
     * Sendet Nachrichten über die ddvelop Messages API.
     * Für Freischaltung/Verifizierung wird immer /v1/messages/send genutzt; nur channel wechselt.
     *
     * @param bool   $sent    Ob bereits eine andere Integration die Nachricht versendet hat
     * @param string $channel sms oder signal
     * @param string $phone   Telefonnummer (z. B. +4915172389673)
     * @param string $message Nachrichtentext
     * @param array  $context Kontext (user_id, context)
     * @return bool True wenn Versand erfolgreich/queued (oder bereits gesendet), sonst false
     */
    private function send_message_via_api($sent, string $channel, string $phone, string $message, array $context): bool
    {
        self::$last_message_api_error = [];

        if ($sent) {
            $this->log_sms_event('skipped_already_sent', $phone, array_merge($context, ['channel' => $channel]));
            return true;
        }

        if (!in_array($channel, ['sms', 'signal'], true)) {
            $this->log_sms_event('invalid_channel', $phone, array_merge($context, ['channel' => $channel]));
            $this->remember_message_api_error($channel, 'invalid_channel', 'Ungültiger Nachrichtenkanal.');
            return false;
        }

        $delivery_allowed = (bool) apply_filters(
            'afcb_external_message_delivery_allowed',
            true,
            $channel,
            $phone,
            $message,
            $context
        );
        // Enforce the server-side policy again after all third-party filters.
        // No later hook may reopen Signal or non-opted-in staging delivery.
        $delivery_allowed = EnvironmentSafety::allow_external_message_delivery(
            $delivery_allowed,
            $channel,
            $phone,
            $message,
            $context
        );
        if (!$delivery_allowed) {
            $blocked_context = array_merge($context, ['channel' => $channel]);
            $this->log_sms_event('blocked_by_environment', $phone, $blocked_context, [
                'message_length' => strlen($message),
            ]);
            $this->remember_message_api_error(
                $channel,
                'blocked_by_environment',
                'Externer Nachrichtenversand ist in dieser Umgebung deaktiviert.'
            );
            do_action('afcb_external_message_blocked', $channel, $phone, $context);
            return false;
        }

        $options = self::get_options();
        $api_url = isset($options['sms_api_url']) ? self::normalize_messages_api_url((string) $options['sms_api_url']) : '';
        $api_key = self::get_sms_api_key($options);

        if ($api_url === '' || $api_key === '') {
            $this->log_sms_event('missing_configuration', $phone, array_merge($context, ['channel' => $channel]), [
                'api_url_set' => $api_url !== '',
                'api_key_set' => $api_key !== '',
            ]);
            $this->remember_message_api_error($channel, 'missing_configuration', 'API-URL oder API-Key fehlen.');
            return false;
        }

        $body = wp_json_encode([
            'channel' => $channel,
            'number' => $phone,
            'message' => $message,
        ]);

        $this->log_sms_event('request', $phone, array_merge($context, ['channel' => $channel]), [
            'api_url' => $api_url,
            'message_length' => strlen($message),
        ]);

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'ApiKey ' . $api_key,
                'X-Api-Key' => $api_key,
            ],
            'body' => $body,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $error_message = $this->redact_sms_log_value($response->get_error_message());
            $this->log_sms_event('transport_uncertain', $phone, array_merge($context, ['channel' => $channel]), [
                'error_code' => $response->get_error_code(),
                'error_message' => $error_message,
                'delivery_status' => self::MESSAGE_DELIVERY_STATUS_UNCERTAIN,
            ]);
            $this->remember_message_api_error(
                $channel,
                'transport_uncertain',
                $response->get_error_message(),
                0,
                self::MESSAGE_DELIVERY_STATUS_UNCERTAIN
            );
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($response_body, true);
        $status = is_array($decoded) && isset($decoded['status']) ? sanitize_key((string) $decoded['status']) : '';
        $success = $code >= 200 && $code < 300;
        if ($status !== '') {
            $success = in_array($status, ['sent', 'queued', 'sending'], true);
        }

        $delivery_status = $success
            ? ($status !== '' ? $status : 'sent')
            : ($this->is_uncertain_message_api_response($code) ? self::MESSAGE_DELIVERY_STATUS_UNCERTAIN : self::MESSAGE_DELIVERY_STATUS_FAILED);
        $error_message = $success ? '' : $this->extract_message_api_error($decoded, $response_body);
        $provider_message_id = '';
        if (is_array($decoded) && isset($decoded['result'][0]['id']) && is_scalar($decoded['result'][0]['id'])) {
            $provider_message_id = sanitize_text_field((string) $decoded['result'][0]['id']);
        }

        $this->log_sms_event(
            $success ? 'response_success' : ($delivery_status === self::MESSAGE_DELIVERY_STATUS_UNCERTAIN ? 'response_uncertain' : 'response_error'),
            $phone,
            array_merge($context, ['channel' => $channel]),
            [
                'http_code' => $code,
                'response_channel' => is_array($decoded) && isset($decoded['channel']) ? sanitize_key((string) $decoded['channel']) : '',
                'response_status' => $status,
                'job_id' => is_array($decoded) && isset($decoded['job_id']) ? sanitize_text_field((string) $decoded['job_id']) : '',
                'provider_message_id' => $provider_message_id,
                'delivery_status' => $delivery_status,
                'api_error' => $error_message !== '' ? $this->redact_sms_log_value($error_message) : '',
            ]
        );

        if (!$success) {
            $this->remember_message_api_error(
                $channel,
                $delivery_status === self::MESSAGE_DELIVERY_STATUS_UNCERTAIN ? 'response_uncertain' : 'response_error',
                $error_message,
                $code,
                $delivery_status
            );
        }

        return $success;
    }

    private function log_sms_event(string $event, string $phone, array $context = [], array $data = []): void
    {
        $payload = array_merge([
            'event' => $event,
            'phone_hash' => hash('sha256', $phone),
            'phone_suffix' => substr(preg_replace('/\D+/', '', $phone), -4),
            'channel' => isset($context['channel']) ? sanitize_key((string) $context['channel']) : '',
            'user_id' => isset($context['user_id']) ? (int) $context['user_id'] : 0,
            'context' => isset($context['context']) ? sanitize_key((string) $context['context']) : '',
        ], $data);

        $line = self::MESSAGE_LOG_PREFIX . wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
            error_log($line . PHP_EOL, 3, WP_DEBUG_LOG);
            return;
        }

        error_log($line);
    }

    private function is_uncertain_message_api_response(int $http_code): bool
    {
        return $http_code === 408 || ($http_code >= 500 && $http_code <= 599);
    }

    private function remember_message_api_error(
        string $channel,
        string $event,
        string $message = '',
        int $http_code = 0,
        string $delivery_status = self::MESSAGE_DELIVERY_STATUS_FAILED
    ): void
    {
        self::$last_message_api_error = [
            'channel' => sanitize_key($channel),
            'event' => sanitize_key($event),
            'message' => mb_substr(sanitize_text_field($message), 0, 300),
            'http_code' => $http_code,
            'delivery_status' => sanitize_key($delivery_status),
        ];
    }

    private function extract_message_api_error($decoded, string $response_body): string
    {
        if (is_array($decoded)) {
            foreach (['error', 'message', 'detail', 'title'] as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key]) && trim((string) $decoded[$key]) !== '') {
                    return (string) $decoded[$key];
                }
            }
        }

        $body = trim(wp_strip_all_tags($response_body));
        return $body !== '' ? mb_substr($body, 0, 300) : 'Unerwartete API-Antwort.';
    }

    private function redact_sms_log_value(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/("?(?:api_key|apikey|token|secret|password)"?\s*[:=]\s*)"[^"]+"/i', '$1"[redacted]"', $value);
        $value = preg_replace('/\+?\d(?:[\s().\/-]*\d){6,}/', '[redacted-number]', (string) $value);
        $value = preg_replace('/\b\d{6}\b/', '[redacted-code]', (string) $value);
        return mb_substr((string) $value, 0, 1000);
    }

    public function handle_forgot_password(): void
    {
        if (!isset($_POST['afcb_forgot_password_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_forgot_password_nonce'])), 'afcb_forgot_password')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Ungültige Anfrage.');
        }

        if (!$this->verify_recaptcha()) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'reCAPTCHA Prüfung fehlgeschlagen.');
        }

        $login = sanitize_text_field(wp_unslash($_POST['user_login'] ?? ''));
        if (empty($login)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte gib deinen Benutzernamen oder deine E-Mail-Adresse an.');
        }

        $user = get_user_by('login', $login);
        if (!$user && is_email($login)) {
            $user = get_user_by('email', $login);
        }

        if (!$user instanceof \WP_User) {
            $this->redirect_with_notice(wp_get_referer(), 'success', self::PASSWORD_RESET_REQUEST_NOTICE);
        }

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', $key->get_error_message());
        }

        $reset_url = add_query_arg([
            'afcb_action' => 'reset_password',
            'key' => $key,
            'login' => $user->user_login,
        ], $this->get_page_url('forgot_password_page_id') ?: wp_lostpassword_url());

        $tags = self::get_base_email_tags($user);
        $tags['reset_link'] = esc_url($reset_url);
        if (!self::send_templated_mail($user->user_email, 'reset_password', $tags)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'E-Mail zum Zurücksetzen des Passworts konnte nicht versendet werden.');
        }

        $this->redirect_with_notice(wp_get_referer(), 'success', self::PASSWORD_RESET_REQUEST_NOTICE);
    }

    public function handle_reset_password(): void
    {
        if (!isset($_POST['afcb_reset_password_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_reset_password_nonce'])), 'afcb_reset_password')) {
            $this->redirect_with_notice($this->get_page_url('forgot_password_page_id') ?: wp_lostpassword_url(), 'error', 'Ungültige Anfrage.');
        }

        $key = sanitize_text_field(wp_unslash($_POST['reset_key'] ?? ''));
        $login = sanitize_user(wp_unslash($_POST['reset_login'] ?? ''), true);
        $password = (string) wp_unslash($_POST['user_pass'] ?? '');
        $password_confirm = (string) wp_unslash($_POST['user_pass_confirm'] ?? '');

        $reset_url = add_query_arg([
            'afcb_action' => 'reset_password',
            'key' => $key,
            'login' => $login,
        ], $this->get_page_url('forgot_password_page_id') ?: wp_lostpassword_url());

        if ($key === '' || $login === '') {
            $this->redirect_with_notice($this->get_page_url('forgot_password_page_id') ?: wp_lostpassword_url(), 'error', 'Der Link zum Zurücksetzen ist ungültig.');
        }

        if ($password === '' || $password !== $password_confirm) {
            $this->redirect_with_notice($reset_url, 'error', 'Bitte gib zweimal dasselbe Passwort ein.');
        }

        $password_strength = $this->validate_password_strength($password);
        if (!$password_strength['valid']) {
            $this->redirect_with_notice($reset_url, 'error', $password_strength['message']);
        }

        $user = check_password_reset_key($key, $login);
        if (is_wp_error($user)) {
            $this->redirect_with_notice($this->get_page_url('forgot_password_page_id') ?: wp_lostpassword_url(), 'error', 'Der Link zum Zurücksetzen ist abgelaufen oder ungültig.');
        }

        reset_password($user, $password);

        $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'success', 'Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.');
    }

    public function handle_forgot_username(): void
    {
        if (!isset($_POST['afcb_forgot_username_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['afcb_forgot_username_nonce'])), 'afcb_forgot_username')) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Ungültige Anfrage.');
        }

        if (!$this->verify_recaptcha()) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'reCAPTCHA Prüfung fehlgeschlagen.');
        }

        if ($this->is_forgot_username_ip_rate_limited()) {
            $this->redirect_with_notice(wp_get_referer(), 'error', self::FORGOT_USERNAME_RATE_LIMIT_MESSAGE);
        }
        $this->record_forgot_username_ip_attempt();

        $phone_input = trim((string) wp_unslash($_POST['phone'] ?? ''));
        if ($phone_input === '') {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte gib deine Telefonnummer an.');
        }

        $phone = '';
        if (!self::is_german_mobile_number($phone_input, $phone)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', 'Bitte gib eine gültige deutsche Handynummer ein (z. B. 0176 12345678 oder +49 176 12345678).');
        }

        if ($this->is_forgot_username_phone_rate_limited($phone)) {
            $this->redirect_with_notice(wp_get_referer(), 'error', self::FORGOT_USERNAME_RATE_LIMIT_MESSAGE);
        }
        $this->record_forgot_username_phone_attempt($phone);

        $user_id = $this->find_user_by_phone($phone);
        if ($user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $message = sprintf('Dein Benutzername lautet: %s', $user->user_login);
                apply_filters('afcb_send_sms', false, $phone, $message, [
                    'user_id' => $user_id,
                    'context' => 'forgot_username',
                ]);
            }
        }

        $this->redirect_with_notice(wp_get_referer(), 'success', 'Wenn die Telefonnummer existiert, wurde eine SMS gesendet.');
    }

    public function handle_email_verification(): void
    {
        if (empty($_GET['afcb_action']) || $_GET['afcb_action'] !== 'verify_email') {
            return;
        }

        $user_id = isset($_GET['uid']) ? (int) $_GET['uid'] : 0;
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if (!$user_id || empty($token)) {
            return;
        }

        $hash = get_user_meta($user_id, self::META_EMAIL_TOKEN_HASH, true);
        $expires = (int) get_user_meta($user_id, self::META_EMAIL_TOKEN_EXPIRES, true);

        if (!$hash || !$expires || time() > $expires) {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'error', 'Bestätigungslink ist abgelaufen.');
        }

        if (!wp_check_password($token, $hash)) {
            $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'error', 'Bestätigungslink ist ungültig.');
        }

        update_user_meta($user_id, self::META_EMAIL_VERIFIED_AT, time());
        delete_user_meta($user_id, self::META_EMAIL_TOKEN_HASH);
        delete_user_meta($user_id, self::META_EMAIL_TOKEN_EXPIRES);

        $status = (string) get_user_meta($user_id, self::META_ACCOUNT_STATUS, true);
        $review_reason = (string) get_user_meta($user_id, self::META_ACCOUNT_REVIEW_REASON, true);
        $next_status = AccountStatusPolicy::status_after_email_verification($status, $review_reason);
        if ($next_status !== sanitize_key($status)) {
            update_user_meta($user_id, self::META_ACCOUNT_STATUS, $next_status);
        }

        $this->redirect_with_notice($this->get_page_url('login_page_id') ?: wp_login_url(), 'success', 'E-Mail-Adresse wurde bestätigt.');
    }

    public function enforce_profile_completion(): void
    {
        if (!is_user_logged_in()) {
            return;
        }
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (current_user_can('manage_options')) {
            return;
        }

        $user_id = get_current_user_id();
        if ($this->is_fully_authenticated($user_id)) {
            return;
        }

        $profile_url = $this->get_page_url('profile_page_id');
        if (!$profile_url) {
            return;
        }

        if (function_exists('is_page')) {
            $options = self::get_options();
            $allowed_ids = [
                (int) ($options['profile_page_id'] ?? 0),
                (int) ($options['login_page_id'] ?? 0),
                (int) ($options['registration_page_id'] ?? 0),
                (int) ($options['forgot_password_page_id'] ?? 0),
                (int) ($options['forgot_username_page_id'] ?? 0),
            ];
            $allowed_ids = array_filter($allowed_ids);
            if (!empty($allowed_ids) && is_page($allowed_ids)) {
                return;
            }
        }

        wp_safe_redirect($profile_url);
        exit;
    }

    public function handle_address_search(): void
    {
        check_ajax_referer('afcb_address_search', 'nonce');

        $query = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        if (strlen($query) < 3) {
            wp_send_json_success([]);
        }

        $options = self::get_options();
        $params = [
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => 5,
            'q' => $query,
        ];
        if (!empty($options['nominatim_email'])) {
            $params['email'] = sanitize_email($options['nominatim_email']);
        }

        $url = add_query_arg($params, 'https://nominatim.openstreetmap.org/search');

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'CB Additional Features (' . home_url() . ')',
                'Referer' => home_url(),
            ],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_success([]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            wp_send_json_success([]);
        }

        $results = [];
        foreach ($data as $item) {
            if (!isset($item['address'])) {
                continue;
            }
            $address = $item['address'];
            $street = $address['road']
                ?? $address['pedestrian']
                ?? $address['footway']
                ?? $address['cycleway']
                ?? $address['residential']
                ?? $address['path']
                ?? '';
            $city = $address['city']
                ?? $address['town']
                ?? $address['village']
                ?? $address['municipality']
                ?? '';

            $results[] = [
                'display_name' => $item['display_name'] ?? '',
                'lat' => $item['lat'] ?? '',
                'lon' => $item['lon'] ?? '',
                'street' => $street,
                'house_number' => $address['house_number'] ?? '',
                'zip' => $address['postcode'] ?? '',
                'city' => $city,
                'state' => $address['state'] ?? '',
                'country' => $address['country'] ?? '',
                'raw' => $item,
            ];
        }

        wp_send_json_success($results);
    }

    public function handle_registration_lookup(): void
    {
        check_ajax_referer('afcb_registration_lookup', 'nonce');

        $field = sanitize_key(wp_unslash($_GET['field'] ?? ''));
        $value = trim((string) wp_unslash($_GET['value'] ?? ''));

        if ($field === 'user_login') {
            $user_login = sanitize_user($value, true);
            if ($user_login === '') {
                wp_send_json_success(['available' => true, 'message' => '']);
            }
            $exists = (bool) username_exists($user_login);

            wp_send_json_success([
                'available' => !$exists,
                'message' => $exists ? 'Benutzername existiert bereits.' : '',
            ]);
        }

        if ($field === 'user_email') {
            $email = sanitize_email($value);
            if ($email === '' || !is_email($email)) {
                wp_send_json_success(['available' => true, 'message' => '']);
            }
            $exists = (bool) email_exists($email);

            wp_send_json_success([
                'available' => !$exists,
                'message' => $exists ? 'E-Mail-Adresse ist bereits registriert.' : '',
            ]);
        }

        wp_send_json_error(['message' => 'Ungültiges Feld.'], 400);
    }

    private function verify_recaptcha(): bool
    {
        $options = self::get_options();
        if (empty($options['recaptcha_secret_key'])) {
            return true;
        }

        $response = sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'] ?? ''));
        if (empty($response)) {
            return false;
        }

        $remote_ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $request = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'timeout' => 10,
            'body' => [
                'secret' => $options['recaptcha_secret_key'],
                'response' => $response,
                'remoteip' => $remote_ip,
            ],
        ]);

        if (is_wp_error($request)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($request), true);
        if (!is_array($body) || empty($body['success'])) {
            return false;
        }

        if (($options['recaptcha_version'] ?? 'v2') === 'v3') {
            $score = isset($body['score']) ? (float) $body['score'] : 0.0;
            $threshold = isset($options['recaptcha_threshold']) ? (float) $options['recaptcha_threshold'] : 0.5;
            if ($score < $threshold) {
                return false;
            }
        }

        return true;
    }

    private function is_forgot_username_ip_rate_limited(): bool
    {
        $attempts = (int) get_transient($this->get_forgot_username_ip_rate_limit_key());
        return $attempts >= self::FORGOT_USERNAME_IP_RATE_LIMIT_MAX_ATTEMPTS;
    }

    private function record_forgot_username_ip_attempt(): void
    {
        $key = $this->get_forgot_username_ip_rate_limit_key();
        $attempts = (int) get_transient($key);
        set_transient($key, $attempts + 1, self::FORGOT_USERNAME_IP_RATE_LIMIT_SECONDS);
    }

    private function is_forgot_username_phone_rate_limited(string $phone): bool
    {
        return (bool) get_transient($this->get_forgot_username_phone_rate_limit_key($phone));
    }

    private function record_forgot_username_phone_attempt(string $phone): void
    {
        set_transient($this->get_forgot_username_phone_rate_limit_key($phone), '1', self::FORGOT_USERNAME_PHONE_RATE_LIMIT_SECONDS);
    }

    private function get_forgot_username_ip_rate_limit_key(): string
    {
        return 'afcb_fu_ip_' . hash('sha256', $this->get_rate_limit_client_ip());
    }

    private function get_forgot_username_phone_rate_limit_key(string $phone): string
    {
        $rate_limit_phone = $phone;
        $normalized_e164 = null;
        if (self::is_german_mobile_number($phone, $normalized_e164) && $normalized_e164 !== null) {
            $rate_limit_phone = $normalized_e164;
        }

        return 'afcb_fu_phone_' . hash('sha256', $rate_limit_phone);
    }

    private function get_rate_limit_client_ip(): string
    {
        $remote_ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $filtered_ip = apply_filters('afcb_rate_limit_client_ip', $remote_ip);
        if (is_string($filtered_ip) && trim($filtered_ip) !== '') {
            return trim($filtered_ip);
        }

        return $remote_ip !== '' ? $remote_ip : 'unknown';
    }

    private function get_notice_message(): string
    {
        $message = isset($_GET['afcb_notice']) ? sanitize_text_field(wp_unslash($_GET['afcb_notice'])) : '';
        return $this->normalize_notice_message($message);
    }

    private function get_notice_type(): string
    {
        $type = isset($_GET['afcb_notice_type']) ? sanitize_text_field(wp_unslash($_GET['afcb_notice_type'])) : '';
        return in_array($type, ['success', 'warning', 'error', 'info'], true) ? $type : '';
    }

    private function get_login_notice_message(): string
    {
        $notice = $this->get_notice_message();
        if ($notice !== '') {
            return $notice;
        }

        if (!empty($_GET['loggedout'])) {
            return 'Du wurdest erfolgreich abgemeldet.';
        }

        if (!empty($_GET['reauth'])) {
            return 'Bitte melde dich erneut an.';
        }

        return '';
    }

    private function get_login_notice_type(): string
    {
        $type = $this->get_notice_type();
        if ($type !== '') {
            return $type;
        }

        if (!empty($_GET['loggedout'])) {
            return 'success';
        }

        if (!empty($_GET['reauth'])) {
            return 'info';
        }

        return '';
    }

    private function get_login_error_message(\WP_Error $error): string
    {
        $code = $error->get_error_code();

        if (in_array($code, ['empty_username', 'empty_email'], true)) {
            return 'Bitte gib deinen Benutzernamen oder deine E-Mail-Adresse ein.';
        }

        if ($code === 'empty_password') {
            return 'Bitte gib dein Passwort ein.';
        }

        if ($code === 'incorrect_password' || $code === 'invalid_username' || $code === 'invalid_email') {
            return 'Benutzername, E-Mail-Adresse oder Passwort ist nicht korrekt.';
        }

        if ($code === 'authentication_failed') {
            return 'Anmeldung fehlgeschlagen. Bitte prüfe deine Zugangsdaten.';
        }

        $message = html_entity_decode($error->get_error_message(), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $message = trim(wp_strip_all_tags($message));

        return $message !== '' ? $message : 'Anmeldung fehlgeschlagen. Bitte versuche es erneut.';
    }

    private function get_register_form_data(): array
    {
        $form_key = isset($_GET['afcb_form']) ? sanitize_key(wp_unslash($_GET['afcb_form'])) : '';
        if ($form_key === '') {
            return $this->get_empty_register_form_data();
        }

        $data = get_transient(self::REGISTER_FORM_TRANSIENT_PREFIX . $form_key);
        delete_transient(self::REGISTER_FORM_TRANSIENT_PREFIX . $form_key);

        if (!is_array($data)) {
            return $this->get_empty_register_form_data();
        }

        return array_merge($this->get_empty_register_form_data(), $data);
    }

    private function get_empty_register_form_data(): array
    {
        return [
            'user_login' => '',
            'user_email' => '',
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'address_search' => '',
            'street' => '',
            'house_number' => '',
            'zip' => '',
            'city' => '',
            'state' => '',
            'country' => '',
            'address_lat' => '',
            'address_lon' => '',
            'address_raw' => '',
            'privacy_accept' => false,
            'terms_accept' => false,
            'custom_fields' => [],
        ];
    }

    private function get_register_form_data_from_post(): array
    {
        $data = $this->get_empty_register_form_data();
        $fields = [
            'user_login',
            'user_email',
            'first_name',
            'last_name',
            'phone',
            'address_search',
            'street',
            'house_number',
            'zip',
            'city',
            'state',
            'country',
            'address_lat',
            'address_lon',
        ];

        foreach ($fields as $field) {
            $data[$field] = sanitize_text_field(wp_unslash($_POST[$field] ?? ''));
        }

        $data['user_login'] = sanitize_user($data['user_login'], true);
        $data['user_email'] = sanitize_email($data['user_email']);
        $data['address_raw'] = wp_kses_post(wp_unslash($_POST['address_raw'] ?? ''));
        $data['privacy_accept'] = !empty($_POST['privacy_accept']);
        $data['terms_accept'] = !empty($_POST['terms_accept']);
        $data['custom_fields'] = $this->sanitize_custom_fields(self::get_custom_fields(), $_POST['custom_fields'] ?? []);

        return $data;
    }

    private function redirect_register_error(string $message): void
    {
        $form_key = sanitize_key(wp_generate_password(20, false, false));
        set_transient(
            self::REGISTER_FORM_TRANSIENT_PREFIX . $form_key,
            $this->get_register_form_data_from_post(),
            10 * MINUTE_IN_SECONDS
        );

        $target = wp_get_referer() ?: ($this->get_page_url('registration_page_id') ?: home_url());
        $target = remove_query_arg(['afcb_form'], $target);
        $target = add_query_arg('afcb_form', $form_key, $target);

        $this->redirect_with_notice($target, 'error', $message);
    }

    private function redirect_with_notice(?string $url, string $type, string $message): void
    {
        $target = $url ?: home_url();
        $target = add_query_arg([
            'afcb_notice' => $this->normalize_notice_message($message),
            'afcb_notice_type' => $type,
        ], $target);
        wp_safe_redirect($target);
        exit;
    }

    private function normalize_notice_message(string $message): string
    {
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $message = wp_strip_all_tags($message);
        $message = preg_replace('/\b\/?(strong|em)\b/i', '', $message) ?? $message;
        $message = preg_replace('/\ba\s+href=\S+/i', '', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return trim($message);
    }

    private function get_page_url(string $key): string
    {
        $options = self::get_options();
        $page_id = isset($options[$key]) ? (int) $options[$key] : 0;
        return $page_id ? get_permalink($page_id) : '';
    }

    private static function get_booking_post_type(): string
    {
        if (class_exists('\\CommonsBooking\\Wordpress\\CustomPostType\\Booking')) {
            return \CommonsBooking\Wordpress\CustomPostType\Booking::$postType;
        }

        return 'cb_booking';
    }

    private function cancel_user_bookings(int $user_id): int
    {
        $query = new \WP_Query([
            'post_type' => self::get_booking_post_type(),
            'author' => $user_id,
            'posts_per_page' => -1,
            'post_status' => ['confirmed', 'unconfirmed'],
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        $booking_ids = array_map('intval', $query->posts ?? []);
        if (!$booking_ids) {
            return 0;
        }

        $canceled = 0;
        $use_model = class_exists('\\CommonsBooking\\Model\\Booking');

        foreach ($booking_ids as $booking_id) {
            $did_cancel = false;

            if ($use_model) {
                try {
                    $booking = new \CommonsBooking\Model\Booking($booking_id);
                    $booking->cancel();
                    clean_post_cache($booking_id);
                    $did_cancel = get_post_status($booking_id) === 'canceled';
                } catch (\Throwable $e) {
                    $did_cancel = false;
                }
            }

            if (!$did_cancel) {
                $did_cancel = $this->force_cancel_booking_post($booking_id);
            }

            if ($did_cancel) {
                $canceled++;
            }
        }

        return $canceled;
    }

    private function force_cancel_booking_post(int $booking_id): bool
    {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->posts,
            ['post_status' => 'canceled'],
            ['ID' => $booking_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return false;
        }

        update_post_meta($booking_id, 'cancellation_time', current_time('timestamp'));
        clean_post_cache($booking_id);

        return get_post_status($booking_id) === 'canceled';
    }

    private function get_legal_url(string $page_key, string $url_key): string
    {
        $page_url = $this->get_page_url($page_key);
        if ($page_url !== '') {
            return $page_url;
        }

        $options = self::get_options();
        return isset($options[$url_key]) ? (string) $options[$url_key] : '';
    }

    private function find_user_by_phone(string $phone): int
    {
        if (empty($phone)) {
            return 0;
        }

        $phone_values = self::get_phone_lookup_values($phone);
        if (!$phone_values) {
            return 0;
        }

        $users = get_users([
            'fields' => 'ID',
            'number' => 1,
            'meta_key' => self::META_PHONE,
            'meta_value' => $phone_values,
            'meta_compare' => 'IN',
        ]);

        return $users ? (int) $users[0] : 0;
    }

    private static function get_phone_lookup_values(string $phone): array
    {
        $normalized = self::normalize_phone($phone);
        if ($normalized === '') {
            return [];
        }

        $values = [$normalized];
        $normalized_e164 = null;

        if (self::is_german_mobile_number($normalized, $normalized_e164) && $normalized_e164 !== null) {
            $national = substr($normalized_e164, 3);
            $values[] = $normalized_e164;
            $values[] = '0' . $national;
            $values[] = '0049' . $national;
            $values[] = '49' . $national;
        }

        return array_values(array_unique(array_filter($values)));
    }

    private function get_account_status(int $user_id): string
    {
        $status = (string) get_user_meta($user_id, self::META_ACCOUNT_STATUS, true);
        return $status ?: 'active';
    }

    private function is_fully_authenticated(int $user_id): bool
    {
        if ($this->get_account_status($user_id) !== 'active') {
            return false;
        }

        if ($this->get_missing_required_fields($user_id)) {
            return false;
        }

        $email_verified = (bool) get_user_meta($user_id, self::META_EMAIL_VERIFIED_AT, true);
        $phone_verified = self::is_phone_verified($user_id);

        return $email_verified && $phone_verified;
    }

    private function get_missing_required_fields(int $user_id): array
    {
        $options = self::get_options();
        $required = $options['required_fields'] ?? [];
        if (!is_array($required)) {
            return [];
        }

        $user = get_userdata($user_id);
        $missing = [];

        foreach ($required as $field) {
            $value = '';
            switch ($field) {
                case 'first_name':
                    $value = $user ? $user->first_name : '';
                    break;
                case 'last_name':
                    $value = $user ? $user->last_name : '';
                    break;
                case 'user_email':
                    $value = $user ? $user->user_email : '';
                    break;
                case self::META_PHONE:
                case self::META_STREET:
                case self::META_HOUSE_NUMBER:
                case self::META_ZIP:
                case self::META_CITY:
                case self::META_STATE:
                case self::META_COUNTRY:
                    $value = (string) get_user_meta($user_id, $field, true);
                    break;
                default:
                    $value = (string) get_user_meta($user_id, $field, true);
                    break;
            }
            if ('' === trim((string) $value)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function get_missing_required_from_input(array $input_map): array
    {
        $options = self::get_options();
        $required = $options['required_fields'] ?? [];
        if (!is_array($required)) {
            return [];
        }

        $missing = [];
        foreach ($required as $field) {
            $value = $input_map[$field] ?? '';
            if ('' === trim((string) $value)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function get_required_fields_error_message(array $missing): string
    {
        if (!empty(array_intersect($missing, self::CORE_ADDRESS_FIELDS))) {
            return 'Bitte gib Straße, Hausnummer, PLZ und Ort vollständig an.';
        }

        return 'Bitte fülle alle Pflichtfelder aus.';
    }

    private function validate_password_strength(string $password): array
    {
        $length = strlen($password);
        $has_upper = preg_match('/[A-Z]/', $password);
        $has_lower = preg_match('/[a-z]/', $password);
        $has_number = preg_match('/[0-9]/', $password);
        $has_special = preg_match('/[^A-Za-z0-9]/', $password);

        if ($length < 8 || !$has_upper || !$has_lower || !$has_number || !$has_special) {
            return [
                'valid' => false,
                'message' => 'Passwort muss mindestens 8 Zeichen lang sein und Großbuchstaben, Kleinbuchstaben, Zahl sowie Sonderzeichen enthalten.',
            ];
        }

        return ['valid' => true, 'message' => ''];
    }

    public static function get_default_field_definitions(): array
    {
        return [
            'first_name' => 'Vorname',
            'last_name' => 'Nachname',
            'user_email' => 'E-Mail',
            self::META_PHONE => 'Telefon',
            self::META_STREET => 'Straße',
            self::META_HOUSE_NUMBER => 'Hausnummer',
            self::META_ZIP => 'PLZ',
            self::META_CITY => 'Ort',
            self::META_COUNTRY => 'Land',
        ];
    }

    public static function normalize_required_fields(array $required_fields): array
    {
        $required_fields = array_values(array_unique(array_filter($required_fields)));

        if (!empty(array_intersect($required_fields, self::CORE_ADDRESS_FIELDS))) {
            $required_fields = array_merge($required_fields, self::CORE_ADDRESS_FIELDS);
        }

        return array_values(array_unique($required_fields));
    }

    /**
     * Liefert alle bearbeitbaren Felder (Standard + benutzerdefiniert) mit key, label, value, type für Admin-Überschreibung.
     *
     * @return array<int, array{key: string, label: string, value: string, type: string}>
     */
    public static function get_user_editable_fields(int $user_id): array
    {
        $user = get_userdata($user_id);
        $defaults = self::get_default_field_definitions();
        $custom = self::get_custom_fields_with_values($user_id);
        $rows = [];

        foreach ($defaults as $key => $label) {
            $value = '';
            if ($user) {
                switch ($key) {
                    case 'first_name':
                        $value = (string) $user->first_name;
                        break;
                    case 'last_name':
                        $value = (string) $user->last_name;
                        break;
                    case 'user_email':
                        $value = (string) $user->user_email;
                        break;
                    default:
                        $value = (string) get_user_meta($user_id, $key, true);
                        break;
                }
            }
            $rows[] = ['key' => $key, 'label' => $label, 'value' => $value, 'type' => 'text'];
        }

        foreach ($custom as $field) {
            $key = $field['key'] ?? '';
            $label = $field['label'] ?? $key;
            $value = isset($field['value']) ? (string) $field['value'] : '';
            $type = ($field['type'] ?? 'text') === 'textarea' ? 'textarea' : 'text';
            $rows[] = ['key' => $key, 'label' => $label, 'value' => $value, 'type' => $type];
        }

        return $rows;
    }

    /**
     * Liefert alle Anzeige-Felder (Standard + benutzerdefiniert) mit Label und Wert für die Admin-Vergleichsansicht.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function get_user_display_data(int $user_id): array
    {
        $user = get_userdata($user_id);
        $defaults = self::get_default_field_definitions();
        $custom = self::get_custom_fields();
        $rows = [];

        foreach ($defaults as $key => $label) {
            $value = '';
            if ($user) {
                switch ($key) {
                    case 'first_name':
                        $value = (string) $user->first_name;
                        break;
                    case 'last_name':
                        $value = (string) $user->last_name;
                        break;
                    case 'user_email':
                        $value = (string) $user->user_email;
                        break;
                    default:
                        $value = (string) get_user_meta($user_id, $key, true);
                        break;
                }
            }
            $rows[] = ['label' => $label, 'value' => $value];
        }

        foreach ($custom as $field) {
            $key = $field['key'] ?? '';
            $label = $field['label'] ?? $key;
            $value = $user ? (string) get_user_meta($user_id, $key, true) : '';
            $rows[] = ['label' => $label, 'value' => $value];
        }

        return $rows;
    }

    public static function get_email_template_definitions(): array
    {
        return [
            'verification' => [
                'label' => 'E-Mail Verifizierung',
                'subject_key' => 'email_verification_subject',
                'body_key' => 'email_verification_body',
            ],
            'review_user' => [
                'label' => 'Account unter Prüfung (User)',
                'subject_key' => 'email_review_subject',
                'body_key' => 'email_review_body',
            ],
            'review_admin' => [
                'label' => 'Manuelle Prüfung (Admin)',
                'subject_key' => 'email_admin_review_subject',
                'body_key' => 'email_admin_review_body',
            ],
            'approved' => [
                'label' => 'Account freigegeben',
                'subject_key' => 'email_approved_subject',
                'body_key' => 'email_approved_body',
            ],
            'declined' => [
                'label' => 'Account abgelehnt',
                'subject_key' => 'email_declined_subject',
                'body_key' => 'email_declined_body',
            ],
            'reset_password' => [
                'label' => 'Passwort zurücksetzen',
                'subject_key' => 'email_reset_password_subject',
                'body_key' => 'email_reset_password_body',
            ],
            'delete_profile' => [
                'label' => 'Profil löschen bestätigen',
                'subject_key' => 'email_delete_profile_subject',
                'body_key' => 'email_delete_profile_body',
            ],
            'new_user_admin' => [
                'label' => 'Neuer Benutzer (Admin)',
                'subject_key' => 'email_new_user_admin_subject',
                'body_key' => 'email_new_user_admin_body',
            ],
            'password_changed_admin' => [
                'label' => 'Passwort geändert (Admin)',
                'subject_key' => 'email_password_changed_admin_subject',
                'body_key' => 'email_password_changed_admin_body',
            ],
            'contact_conflict_admin' => [
                'label' => 'Kontakt bereits vergeben (Admin)',
                'subject_key' => 'email_contact_conflict_admin_subject',
                'body_key' => 'email_contact_conflict_admin_body',
            ],
        ];
    }

    private static function get_email_template_defaults(): array
    {
        return [
            'email_html_template' => self::get_default_email_html_template(),
            'email_header_image_cid' => self::get_default_email_header_reference(),
            'email_header_image_alt' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'email_footer_text' => self::get_default_email_footer_text(),
            'email_verification_subject' => 'Bitte bestätige deine E-Mail-Adresse',
            'email_verification_body' => self::load_email_template(
                'email/user-management-verification.html.twig',
                "Hallo {{user_login}},\n\nbitte bestätige deine E-Mail-Adresse mit folgendem Link:\n{{verification_link}}"
            ),
            'email_review_subject' => 'Account unter Prüfung',
            'email_review_body' => self::load_email_template(
                'email/user-management-review-user.html.twig',
                "Hallo {{user_login}},\n\ndein Account wird geprüft.\n\nGrund: {{review_reason}}\n\nDiese Prüfung dauert in der Regel 1-2 Tage."
            ),
            'email_admin_review_subject' => 'Manuelle Account-Prüfung erforderlich',
            'email_admin_review_body' => self::load_email_template(
                'email/user-management-review-admin.html.twig',
                "Der Account {{user_login}} ({{user_email}}) steht unter Prüfung.\n\nGrund: {{review_reason}}\n\n{{match_list}}\n\nZur Prüfung: {{review_url}}"
            ),
            'email_approved_subject' => 'Account freigegeben',
            'email_approved_body' => self::load_email_template(
                'email/user-management-approved.html.twig',
                "Hallo {{user_login}},\n\ndein Account wurde freigegeben."
            ),
            'email_declined_subject' => 'Account abgelehnt',
            'email_declined_body' => self::load_email_template(
                'email/user-management-declined.html.twig',
                "Hallo {{user_login}},\n\ndein Account wurde abgelehnt. Bitte kontaktiere den Support."
            ),
            'email_reset_password_subject' => 'Passwort zurücksetzen',
            'email_reset_password_body' => self::load_email_template(
                'email/user-management-reset-password.html.twig',
                "Hallo {{user_login}},\n\nüber folgenden Link kannst du dein Passwort zurücksetzen:\n{{reset_link}}"
            ),
            'email_delete_profile_subject' => 'Profil-Löschung bestätigen',
            'email_delete_profile_body' => self::load_email_template(
                'email/user-management-delete-profile.html.twig',
                "Hallo {{user_login}},\n\nbitte bestätige die Löschung deines Profils mit folgendem Link:\n{{delete_profile_link}}\n\nDabei werden alle zugehörigen Buchungen storniert."
            ),
            'email_new_user_admin_subject' => 'Neuer Benutzer: {{user_login}}',
            'email_new_user_admin_body' => self::load_email_template(
                'email/user-management-new-user-admin.html.twig',
                "Ein neuer Benutzer wurde angelegt.\n\nBenutzer: {{user_login}}\nE-Mail: {{user_email}}\nRegistriert: {{registered_at}}\n\nNutzer prüfen: {{admin_user_url}}"
            ),
            'email_password_changed_admin_subject' => 'Passwort geändert: {{user_login}}',
            'email_password_changed_admin_body' => self::load_email_template(
                'email/user-management-password-changed-admin.html.twig',
                "Das Passwort eines Benutzers wurde geändert.\n\nBenutzer: {{user_login}}\nE-Mail: {{user_email}}\nZeitpunkt: {{changed_at}}\nAuslöser: {{password_change_source}}\n\nNutzer prüfen: {{admin_user_url}}"
            ),
            'email_contact_conflict_admin_subject' => '{{conflict_type}} bereits vergeben: {{conflict_value}}',
            'email_contact_conflict_admin_body' => self::load_email_template(
                'email/user-management-contact-conflict-admin.html.twig',
                "Ein Account wollte eine bereits vergebene Kontaktangabe verwenden.\n\nTyp: {{conflict_type}}\nWert: {{conflict_value}}\nKontext: {{conflict_context}}\n\nAnfragender Account: {{user_login}} ({{user_email}})\nBereits registriert bei: {{existing_user_login}} ({{existing_user_email}})\n\nAnfragenden Account prüfen: {{admin_user_url}}\nBestehenden Account prüfen: {{existing_user_url}}"
            ),
        ];
    }

    private static function get_default_email_html_template(): string
    {
        return self::load_email_template(
            'email/user-management-wrapper.html.twig',
            '{{content}}'
        );
    }

    private static function load_email_template(string $relative_path, string $fallback): string
    {
        $path = PluginPaths::asset_path('assets/templates/' . ltrim($relative_path, '/'));
        if (!is_readable($path)) {
            return $fallback;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return $fallback;
        }

        return trim($content);
    }

    public static function get_custom_fields(): array
    {
        $options = self::get_options();
        $fields = isset($options['custom_fields']) && is_array($options['custom_fields']) ? $options['custom_fields'] : [];
        $result = [];

        foreach ($fields as $field) {
            $key = sanitize_key($field['key'] ?? '');
            $label = sanitize_text_field($field['label'] ?? '');
            $type = sanitize_text_field($field['type'] ?? 'text');
            $required = !empty($field['required']);
            $options_raw = is_string($field['options'] ?? null) ? $field['options'] : '';
            $options_list = array_filter(array_map('trim', explode(',', $options_raw)));

            if ($key === '' || $label === '') {
                continue;
            }

            $result[] = [
                'key' => $key,
                'label' => $label,
                'type' => in_array($type, ['text', 'textarea', 'number', 'date', 'select'], true) ? $type : 'text',
                'required' => $required,
                'options' => $options_list,
                'options_raw' => $options_raw,
            ];
        }

        return $result;
    }

    public static function get_custom_fields_with_values(int $user_id): array
    {
        $fields = self::get_custom_fields();
        foreach ($fields as &$field) {
            $field['value'] = get_user_meta($user_id, $field['key'], true);
        }

        return $fields;
    }

    private function sanitize_custom_fields(array $custom_fields, $raw_input): array
    {
        $values = [];
        $raw_input = is_array($raw_input) ? wp_unslash($raw_input) : [];

        foreach ($custom_fields as $field) {
            $key = $field['key'];
            $type = $field['type'] ?? 'text';
            $value = $raw_input[$key] ?? '';
            if (is_array($value)) {
                $value = '';
            }

            if ($type === 'textarea') {
                $value = sanitize_textarea_field((string) $value);
            } else {
                $value = sanitize_text_field((string) $value);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    public static function find_possible_duplicate_users(int $user_id, array $data = []): array
    {
        $user = get_userdata($user_id);
        $street = self::normalize_duplicate_value((string) ($data['street'] ?? get_user_meta($user_id, self::META_STREET, true)));
        $house_number = self::normalize_duplicate_value((string) ($data['house_number'] ?? get_user_meta($user_id, self::META_HOUSE_NUMBER, true)));
        $zip = self::normalize_duplicate_value((string) ($data['zip'] ?? get_user_meta($user_id, self::META_ZIP, true)));
        $city = self::normalize_duplicate_value((string) ($data['city'] ?? get_user_meta($user_id, self::META_CITY, true)));
        $needle_first = self::normalize_duplicate_value((string) ($data['first_name'] ?? ($user ? $user->first_name : '')));
        $needle_last = self::normalize_duplicate_value((string) ($data['last_name'] ?? ($user ? $user->last_name : '')));

        if ((!$street || !$house_number || !$zip || !$city) && (!$needle_first || !$needle_last)) {
            return [];
        }

        $candidates = [];

        if ($street && $house_number && $zip && $city) {
            foreach (get_users([
                'fields' => 'all_with_meta',
                'meta_query' => [
                    [
                        'key' => self::META_STREET,
                        'value' => $street,
                        'compare' => 'LIKE',
                    ],
                    [
                        'key' => self::META_HOUSE_NUMBER,
                        'value' => $house_number,
                        'compare' => 'LIKE',
                    ],
                    [
                        'key' => self::META_ZIP,
                        'value' => $zip,
                        'compare' => 'LIKE',
                    ],
                    [
                        'key' => self::META_CITY,
                        'value' => $city,
                        'compare' => 'LIKE',
                    ],
                ],
            ]) as $candidate) {
                $candidates[(int) $candidate->ID] = $candidate;
            }
        }

        if ($needle_first && $needle_last) {
            foreach (get_users([
                'fields' => 'all_with_meta',
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => 'first_name',
                        'value' => $needle_first,
                        'compare' => 'LIKE',
                    ],
                    [
                        'key' => 'last_name',
                        'value' => $needle_last,
                        'compare' => 'LIKE',
                    ],
                ],
            ]) as $candidate) {
                $candidates[(int) $candidate->ID] = $candidate;
            }
        }

        $matches = [];

        foreach ($candidates as $candidate) {
            if ((int) $candidate->ID === $user_id) {
                continue;
            }
            $candidate_first = self::normalize_duplicate_value((string) $candidate->first_name);
            $candidate_last = self::normalize_duplicate_value((string) $candidate->last_name);
            $first_name_matches = $needle_first
                && $candidate_first
                && (strpos($candidate_first, $needle_first) !== false || strpos($needle_first, $candidate_first) !== false);
            $last_name_matches = $needle_last
                && $candidate_last
                && (strpos($candidate_last, $needle_last) !== false || strpos($needle_last, $candidate_last) !== false);
            $same_full_name = $first_name_matches && $last_name_matches;
            $same_address = $street
                && $house_number
                && $zip
                && $city
                && self::normalize_duplicate_value((string) get_user_meta((int) $candidate->ID, self::META_STREET, true)) === $street
                && self::normalize_duplicate_value((string) get_user_meta((int) $candidate->ID, self::META_HOUSE_NUMBER, true)) === $house_number
                && self::normalize_duplicate_value((string) get_user_meta((int) $candidate->ID, self::META_ZIP, true)) === $zip
                && self::normalize_duplicate_value((string) get_user_meta((int) $candidate->ID, self::META_CITY, true)) === $city;

            if ($same_full_name) {
                $matches[] = $candidate;
                continue;
            }

            if (!$same_address) {
                continue;
            }

            if ($last_name_matches) {
                $matches[] = $candidate;
                continue;
            }
            if ($first_name_matches) {
                $matches[] = $candidate;
            }
        }

        return $matches;
    }

    private static function normalize_duplicate_value(string $value): string
    {
        $value = remove_accents($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/\s+/', ' ', trim($value));

        return $value ?? '';
    }

    private function maybe_flag_soft_duplicate(int $user_id, array $data): bool
    {
        $matches = self::find_possible_duplicate_users($user_id, $data);

        if (!$matches) {
            return false;
        }

        return $this->flag_user_for_review($user_id, 'possible_duplicate', $matches);
    }

    private function flag_user_for_review(int $user_id, string $reason, array $matches): bool
    {
        $existing_match_ids = get_user_meta($user_id, self::META_ACCOUNT_REVIEW_MATCH_IDS, true);
        $existing_match_ids = is_array($existing_match_ids) ? array_filter(array_map('intval', $existing_match_ids)) : [];
        $match_ids = array_map(function (\WP_User $u) {
            return (int) $u->ID;
        }, $matches);
        $match_ids = $match_ids ?: $existing_match_ids;

        update_user_meta($user_id, self::META_ACCOUNT_STATUS, 'pending');
        update_user_meta($user_id, self::META_ACCOUNT_REVIEW_REASON, $reason);
        update_user_meta($user_id, self::META_ACCOUNT_REVIEW_AT, time());
        update_user_meta($user_id, self::META_ACCOUNT_REVIEW_MATCH_IDS, $match_ids);

        $this->send_review_notifications($user_id, $matches, $reason);
        return true;
    }

    private function activate_if_manual_phone_review_resolved(int $user_id): void
    {
        $status = (string) get_user_meta($user_id, self::META_ACCOUNT_STATUS, true);
        $reason = (string) get_user_meta($user_id, self::META_ACCOUNT_REVIEW_REASON, true);
        if ($status !== 'pending' || $reason !== 'foreign_phone_manual_verification') {
            return;
        }

        update_user_meta($user_id, self::META_ACCOUNT_STATUS, 'active');
        update_user_meta($user_id, self::META_ACCOUNT_REVIEW_AT, time());
    }

    private function send_review_notifications(int $user_id, array $matches, string $reason): void
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $tags = array_merge(self::get_base_email_tags($user), [
            'review_reason' => esc_html(self::get_review_reason_label($reason)),
        ]);
        $user_mail_sent = self::send_templated_mail($user->user_email, 'review_user', $tags);

        $match_lines = [];
        foreach ($matches as $match) {
            $match_lines[] = sprintf('%s (%s)', $match->user_login, $match->user_email);
        }
        $manual_phone_note = trim((string) get_user_meta($user_id, self::META_PHONE_MANUAL_REVIEW_NOTE, true));
        if ($manual_phone_note !== '') {
            $match_lines[] = 'Hinweis zur manuellen Telefonprüfung: ' . $manual_phone_note;
        }
        $match_list = '';
        if (!empty($match_lines)) {
            $match_list = TemplateLoader::load()->render('email/user-management-match-list.html.twig', [
                'lines' => $match_lines,
            ]);
        }

        $admin_tags = array_merge($tags, [
            'match_list' => $match_list ?: 'Keine Duplikat-Treffer.',
            'review_reason' => esc_html(self::get_review_reason_label($reason)),
            'review_url' => esc_url(add_query_arg(
                ['page' => 'cbadf-user-management', 'afcb_user_id' => $user_id],
                admin_url('admin.php')
            )),
        ]);

        $admin_sent = $this->send_admin_templated_mail('review_admin', $admin_tags);
        if (!$user_mail_sent || $admin_sent === 0) {
            self::log_mail_event('review_notification_incomplete', [
                'user_id' => $user_id,
                'user_mail_sent' => $user_mail_sent ? '1' : '0',
                'admin_sent_count' => (string) $admin_sent,
            ]);
        }
    }

    public static function get_review_reason_label(string $reason): string
    {
        $labels = [
            'possible_duplicate' => 'Mögliche Mehrfachanmeldung',
            'foreign_phone_manual_verification' => 'Ausländische Telefonnummer - manuelle Telefonverifizierung erforderlich',
            'legacy_unknown_account_status' => 'Unbekannter Ultimate-Member-Status - manuelle Prüfung erforderlich',
        ];

        return $labels[$reason] ?? 'Manuelle Account-Prüfung';
    }

    public static function parse_review_notification_emails(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw);
        if (!is_array($parts)) {
            return [];
        }

        $emails = [];
        foreach ($parts as $part) {
            $email = sanitize_email($part);
            if ($email !== '' && is_email($email)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    public static function send_email_verification(int $user_id): bool
    {
        $user = get_userdata($user_id);
        if (!$user) {
            self::log_mail_event('verification_user_missing', [
                'user_id' => $user_id,
            ]);
            return false;
        }

        $token = wp_generate_password(32, false, false);
        update_user_meta($user_id, self::META_EMAIL_TOKEN_HASH, wp_hash_password($token));
        update_user_meta($user_id, self::META_EMAIL_TOKEN_EXPIRES, time() + DAY_IN_SECONDS);
        update_user_meta($user_id, self::META_EMAIL_LAST_SENT, time());

        $verify_url = add_query_arg([
            'afcb_action' => 'verify_email',
            'uid' => $user_id,
            'token' => $token,
        ], home_url('/'));

        $tags = self::get_base_email_tags($user);
        $tags['verification_link'] = esc_url($verify_url);

        return self::send_templated_mail($user->user_email, 'verification', $tags);
    }

    public function queue_new_user_notification(int $user_id, $userdata = []): void
    {
        if ($user_id <= 0) {
            return;
        }

        self::$pending_new_user_notifications[$user_id] = $userdata;
    }

    public function queue_password_change_notification($password, int $user_id, $old_user_data = null): void
    {
        unset($password);

        if ($user_id <= 0) {
            return;
        }

        self::$pending_password_change_notifications[$user_id] = [
            'changed_at' => time(),
            'source' => self::detect_password_change_source(),
            'actor' => self::get_current_actor_label(),
            'old_user_login' => $old_user_data instanceof \WP_User ? $old_user_data->user_login : '',
        ];
    }

    public function send_pending_admin_event_notifications(): void
    {
        if (empty(self::$pending_new_user_notifications) && empty(self::$pending_password_change_notifications)) {
            return;
        }

        $new_user_ids = array_keys(self::$pending_new_user_notifications);
        foreach ($new_user_ids as $user_id) {
            $this->send_new_user_admin_notification((int) $user_id);
        }

        foreach (self::$pending_password_change_notifications as $user_id => $event) {
            if (isset(self::$pending_new_user_notifications[(int) $user_id])) {
                continue;
            }

            $this->send_password_changed_admin_notification((int) $user_id, is_array($event) ? $event : []);
        }

        self::$pending_new_user_notifications = [];
        self::$pending_password_change_notifications = [];
    }

    private function send_new_user_admin_notification(int $user_id): void
    {
        $user = get_userdata($user_id);
        if (!$user) {
            self::log_mail_event('new_user_admin_notification_user_missing', [
                'user_id' => $user_id,
            ]);
            return;
        }

        $sent = $this->send_admin_templated_mail('new_user_admin', $this->get_admin_user_event_tags($user));
        if ($sent === 0) {
            self::log_mail_event('new_user_admin_notification_failed', [
                'user_id' => $user_id,
            ]);
        }
    }

    private function send_password_changed_admin_notification(int $user_id, array $event): void
    {
        $user = get_userdata($user_id);
        if (!$user) {
            self::log_mail_event('password_changed_admin_notification_user_missing', [
                'user_id' => $user_id,
            ]);
            return;
        }

        $changed_at = isset($event['changed_at']) ? (int) $event['changed_at'] : time();
        $tags = $this->get_admin_user_event_tags($user, [
            'changed_at' => esc_html(wp_date('d.m.Y H:i', $changed_at)),
            'password_change_source' => esc_html((string) ($event['source'] ?? 'Passwortänderung')),
            'changed_by' => esc_html((string) ($event['actor'] ?? self::get_current_actor_label())),
        ]);

        $sent = $this->send_admin_templated_mail('password_changed_admin', $tags);
        if ($sent === 0) {
            self::log_mail_event('password_changed_admin_notification_failed', [
                'user_id' => $user_id,
            ]);
        }
    }

    private function send_contact_conflict_admin_notification(
        int $actor_user_id,
        int $existing_user_id,
        string $field,
        string $attempted_value,
        string $context_label,
        array $submitted_data = []
    ): void {
        $field = $field === 'phone' ? 'phone' : 'email';
        $attempted_value = trim($attempted_value);
        if ($existing_user_id <= 0 || $attempted_value === '') {
            return;
        }

        $existing_user = get_userdata($existing_user_id);
        if (!$existing_user) {
            self::log_mail_event('contact_conflict_admin_notification_existing_user_missing', [
                'existing_user_id' => $existing_user_id,
                'field' => $field,
            ]);
            return;
        }

        $rate_limit_key = $this->get_contact_conflict_notification_rate_limit_key(
            $actor_user_id,
            $existing_user_id,
            $field,
            $attempted_value,
            $context_label
        );
        if (get_transient($rate_limit_key)) {
            return;
        }
        set_transient($rate_limit_key, '1', self::CONTACT_CONFLICT_NOTIFICATION_COOLDOWN_SECONDS);

        $actor_user = $actor_user_id > 0 ? get_userdata($actor_user_id) : null;
        $tags = $actor_user instanceof \WP_User
            ? $this->get_admin_user_event_tags($actor_user)
            : $this->get_submitted_contact_conflict_actor_tags($submitted_data);

        $existing_registered_at = strtotime((string) $existing_user->user_registered);
        $existing_display_name = trim((string) $existing_user->display_name);
        if ($existing_display_name === '') {
            $existing_display_name = $existing_user->user_login;
        }

        $tags = array_merge($tags, [
            'conflict_type' => $field === 'phone' ? 'Telefonnummer' : 'E-Mail-Adresse',
            'conflict_value' => esc_html($attempted_value),
            'conflict_context' => esc_html($context_label),
            'attempted_email' => esc_html((string) ($submitted_data['user_email'] ?? ($field === 'email' ? $attempted_value : ($actor_user instanceof \WP_User ? $actor_user->user_email : '')))),
            'attempted_phone' => esc_html((string) ($field === 'phone' ? $attempted_value : ($actor_user instanceof \WP_User ? get_user_meta($actor_user->ID, self::META_PHONE, true) : ($submitted_data['phone'] ?? '')))),
            'existing_user_id' => (string) $existing_user->ID,
            'existing_user_login' => esc_html($existing_user->user_login),
            'existing_user_email' => esc_html($existing_user->user_email),
            'existing_user_phone' => esc_html((string) get_user_meta($existing_user->ID, self::META_PHONE, true)),
            'existing_user_display_name' => esc_html($existing_display_name),
            'existing_registered_at' => esc_html($existing_registered_at ? wp_date('d.m.Y H:i', $existing_registered_at) : 'Unbekannt'),
            'existing_user_url' => esc_url(add_query_arg(
                ['page' => 'cbadf-user-management', 'afcb_user_id' => $existing_user->ID],
                admin_url('admin.php')
            )),
        ]);

        $sent = $this->send_admin_templated_mail('contact_conflict_admin', $tags);
        if ($sent === 0) {
            self::log_mail_event('contact_conflict_admin_notification_failed', [
                'actor_user_id' => $actor_user_id,
                'existing_user_id' => $existing_user_id,
                'field' => $field,
                'context' => $context_label,
            ]);
        }
    }

    private function get_submitted_contact_conflict_actor_tags(array $submitted_data): array
    {
        $user_login = sanitize_user((string) ($submitted_data['user_login'] ?? ''), true);
        $user_email = sanitize_email((string) ($submitted_data['user_email'] ?? ''));
        $phone = trim((string) ($submitted_data['phone'] ?? ''));
        $first_name = sanitize_text_field((string) ($submitted_data['first_name'] ?? ''));
        $last_name = sanitize_text_field((string) ($submitted_data['last_name'] ?? ''));
        $display_name = trim($first_name . ' ' . $last_name);
        if ($display_name === '') {
            $display_name = $user_login !== '' ? $user_login : 'Nicht angemeldet / Registrierung';
        }

        return array_merge(self::get_common_email_tags(), [
            'user_login' => esc_html($user_login !== '' ? $user_login : 'Nicht angemeldet / Registrierung'),
            'user_email' => esc_html($user_email),
            'user_phone' => esc_html($phone),
            'user_display_name' => esc_html($display_name),
            'user_first_name' => esc_html($first_name),
            'user_last_name' => esc_html($last_name),
            'user_roles' => 'Keine Rolle',
            'admin_user_url' => 'Noch nicht angelegt',
            'event_time' => esc_html(wp_date('d.m.Y H:i')),
        ]);
    }

    private function get_contact_conflict_notification_rate_limit_key(
        int $actor_user_id,
        int $existing_user_id,
        string $field,
        string $attempted_value,
        string $context_label
    ): string {
        $normalized_value = $field === 'phone'
            ? self::normalize_phone($attempted_value)
            : strtolower(trim($attempted_value));

        return 'afcb_contact_conflict_' . hash('sha256', implode('|', [
            $actor_user_id,
            $existing_user_id,
            $field,
            $normalized_value,
            $context_label,
        ]));
    }

    private function get_admin_user_event_tags(\WP_User $user, array $extra = []): array
    {
        $registered_at = strtotime((string) $user->user_registered);
        $roles = self::get_user_role_labels($user);
        $display_name = trim((string) $user->display_name);
        if ($display_name === '') {
            $display_name = $user->user_login;
        }

        return array_merge(self::get_base_email_tags($user), [
            'user_id' => (string) $user->ID,
            'user_display_name' => esc_html($display_name),
            'user_first_name' => esc_html((string) $user->first_name),
            'user_last_name' => esc_html((string) $user->last_name),
            'user_roles' => esc_html($roles ? implode(', ', $roles) : 'Keine Rolle'),
            'registered_at' => esc_html($registered_at ? wp_date('d.m.Y H:i', $registered_at) : 'Unbekannt'),
            'admin_user_url' => esc_url(add_query_arg(
                ['page' => 'cbadf-user-management', 'afcb_user_id' => $user->ID],
                admin_url('admin.php')
            )),
            'account_status' => esc_html($this->get_account_status((int) $user->ID)),
            'email_verified' => (bool) get_user_meta($user->ID, self::META_EMAIL_VERIFIED_AT, true) ? 'ja' : 'nein',
            'phone_verified' => self::is_phone_verified((int) $user->ID) ? 'ja' : 'nein',
            'event_time' => esc_html(wp_date('d.m.Y H:i')),
            'changed_at' => '',
            'password_change_source' => '',
            'changed_by' => '',
        ], $extra);
    }

    private static function detect_password_change_source(): string
    {
        if (did_action('password_reset') > did_action('after_password_reset')) {
            return 'Passwort-Reset-Link';
        }

        if (is_admin()) {
            return 'WordPress-Admin';
        }

        if (wp_doing_cron()) {
            return 'WordPress-Cron';
        }

        return 'Frontend oder System';
    }

    private static function get_current_actor_label(): string
    {
        $current_user = wp_get_current_user();
        if ($current_user instanceof \WP_User && $current_user->exists()) {
            $display_name = trim((string) $current_user->display_name);
            if ($display_name === '') {
                $display_name = $current_user->user_login;
            }

            return sprintf('%s (%s)', $display_name, $current_user->user_login);
        }

        return 'Nicht angemeldet oder System';
    }

    private static function get_user_role_labels(\WP_User $user): array
    {
        $wp_roles = wp_roles();
        $labels = [];

        foreach ((array) $user->roles as $role) {
            $role_name = $wp_roles->roles[$role]['name'] ?? $role;
            $labels[] = translate_user_role($role_name);
        }

        return $labels;
    }

    private function send_admin_templated_mail(string $context, array $tags): int
    {
        $sent = 0;
        $options = self::get_options();
        $recipients = self::parse_review_notification_emails((string) ($options['review_notification_emails'] ?? ''));
        if ($recipients) {
            foreach ($recipients as $email) {
                if (self::send_templated_mail($email, $context, $tags)) {
                    $sent++;
                }
            }
            return $sent;
        }

        $admins = get_users([
            'role__in' => ['administrator'],
            'fields' => ['user_email'],
        ]);

        foreach ($admins as $admin) {
            if (!empty($admin->user_email)) {
                if (self::send_templated_mail($admin->user_email, $context, $tags)) {
                    $sent++;
                }
            }
        }

        if ($sent === 0) {
            self::log_mail_event('admin_mail_no_recipient_or_failed', [
                'context' => $context,
            ]);
        }

        return $sent;
    }

    public static function send_mail(string $to, string $subject, string $message, string $context = 'mail'): bool
    {
        self::log_mail_event('send_attempt', [
            'context' => $context,
            'to' => $to,
            'subject' => wp_strip_all_tags($subject),
        ]);

        try {
            self::prepare_inline_email_images($message);
            $sent = (bool) wp_mail(
                $to,
                $subject,
                $message,
                ['Content-Type: text/html; charset=UTF-8']
            );
        } catch (\Throwable $e) {
            self::log_mail_event('send_exception', [
                'context' => $context,
                'to' => $to,
                'subject' => wp_strip_all_tags($subject),
                'exception' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            return false;
        } finally {
            self::clear_pending_inline_email_images();
        }

        self::log_mail_event($sent ? 'send_success' : 'send_failed', array_merge([
            'context' => $context,
            'to' => $to,
            'subject' => wp_strip_all_tags($subject),
        ], self::get_post_smtp_result_summary()));

        return $sent;
    }

    private static function get_common_email_tags(): array
    {
        return [
            'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'site_url' => home_url(),
            'year' => date('Y'),
            'user_id' => '',
            'user_login' => '',
            'user_email' => '',
            'user_phone' => '',
            'user_display_name' => '',
            'user_first_name' => '',
            'user_last_name' => '',
            'user_roles' => '',
            'registered_at' => '',
            'admin_user_url' => '',
            'account_status' => '',
            'email_verified' => '',
            'phone_verified' => '',
            'event_time' => '',
            'changed_at' => '',
            'changed_by' => '',
            'password_change_source' => '',
            'conflict_type' => '',
            'conflict_value' => '',
            'conflict_context' => '',
            'attempted_email' => '',
            'attempted_phone' => '',
            'existing_user_id' => '',
            'existing_user_login' => '',
            'existing_user_email' => '',
            'existing_user_phone' => '',
            'existing_user_display_name' => '',
            'existing_registered_at' => '',
            'existing_user_url' => '',
            'verification_link' => '',
            'reset_link' => '',
            'delete_profile_link' => '',
            'review_reason' => '',
            'review_url' => '',
            'match_list' => '',
            'subject' => '',
            'content' => '',
            'header_image_html' => self::get_email_header_image_html(),
            'footer_text' => self::get_email_footer_text(),
        ];
    }

    private static function get_base_email_tags(\WP_User $user): array
    {
        return array_merge(self::get_common_email_tags(), [
            'user_login' => esc_html($user->user_login),
            'user_email' => esc_html($user->user_email),
            'user_phone' => esc_html((string) get_user_meta($user->ID, self::META_PHONE, true)),
        ]);
    }

    private static function get_email_header_image_html(): string
    {
        $options = self::get_options();
        $cid = trim((string) ($options['email_header_image_cid'] ?? ''));
        if ($cid === '') {
            return '';
        }

        $src = stripos($cid, 'cid:') === 0 || preg_match('/^https?:\/\//i', $cid)
            ? $cid
            : 'cid:' . $cid;
        $alt = trim((string) ($options['email_header_image_alt'] ?? ''));
        if ($alt === '') {
            $alt = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        }

        return sprintf(
            '<img src="%s" alt="%s" style="display:block;max-width:240px;width:100%%;height:auto;border:0;">',
            esc_attr($src),
            esc_attr($alt)
        );
    }

    private static function prepare_inline_email_images(string $message): void
    {
        self::clear_pending_inline_email_images();

        $options = self::get_options();
        $upload_dir = wp_get_upload_dir();
        $uploads_base_dir = isset($upload_dir['basedir']) && is_string($upload_dir['basedir'])
            ? $upload_dir['basedir']
            : '';
        $image = EmailHeaderImage::inline_attachment(
            self::get_site_key(),
            (string) ($options['email_header_image_cid'] ?? ''),
            $message,
            self::get_default_email_header_image_path(),
            $uploads_base_dir
        );
        if ($image === null) {
            return;
        }

        if (!is_readable($image['path'])) {
            self::log_mail_event('inline_image_missing', [
                'cid' => $image['cid'],
                'path' => $image['path'],
            ]);
            return;
        }

        self::$pending_inline_email_images[] = $image;
    }

    private static function clear_pending_inline_email_images(): void
    {
        self::$pending_inline_email_images = [];
    }

    private static function get_default_email_header_image_path(): string
    {
        return PluginPaths::asset_path(self::DEFAULT_EMAIL_HEADER_IMAGE_PATH);
    }

    private static function get_default_email_header_reference(): string
    {
        return EmailHeaderImage::default_reference(self::get_site_key());
    }

    private static function get_site_key(): string
    {
        return defined('LASTENRAD_SITE_KEY')
            ? sanitize_key((string) LASTENRAD_SITE_KEY)
            : 'main';
    }

    private static function get_email_footer_text(): string
    {
        $options = self::get_options();
        $footer = trim((string) ($options['email_footer_text'] ?? ''));
        return $footer !== '' ? wp_kses_post($footer) : '';
    }

    private static function get_default_email_footer_text(): string
    {
        if (defined('LASTENRAD_SITE_KEY') && sanitize_key((string) LASTENRAD_SITE_KEY) === 'wetterau') {
            return implode('', [
                '<p style="margin:0 0 6px;font-weight:700;color:#183e30;">WETTERAU-LASTENRAD</p>',
                '<p style="margin:0 0 10px;">Kostenloser Lastenradverleih in der Wetterau</p>',
                '<p style="margin:0;">Ein Angebot des VCD Wetterau-Vogelsberg e. V.</p>',
            ]);
        }

        return implode('', [
            '<p style="margin:0 0 6px;font-weight:700;color:#183e30;">MAIN-LASTENRAD</p>',
            '<p style="margin:0 0 10px;">Kostenloser Lastenradverleih in Frankfurt und Offenbach am Main</p>',
            '<p style="margin:0;">Eine Initiative der Regionalgruppe Rhein-Main<br>des Verkehrsclub Deutschland Landesverband Hessen e. V.</p>',
        ]);
    }

    private static function should_replace_default_email_footer(string $footer): bool
    {
        $plain = strtolower(wp_strip_all_tags($footer));

        $is_legacy_footer = strpos($plain, 'wilhelmstraße 2') !== false
            && strpos($plain, '05 61') !== false
            && strpos($plain, 'datenschutz@main-lastenrad.de') !== false;
        $is_requested_footer_with_contact = strpos($plain, '0151 2684 6475') !== false
            || strpos($plain, 'mbi@main-lastenrad.de') !== false
            || strpos($plain, 'mobil:') !== false;

        return $is_legacy_footer || $is_requested_footer_with_contact;
    }

    private static function replace_legacy_default_email_bodies(array $options, array $email_defaults): array
    {
        $legacy_bodies = [
            'email_verification_body' => [
                'Hallo {{user_login}},<br><br>bitte bestätige deine E-Mail-Adresse mit folgendem Link:<br><a href="{{verification_link}}">E-Mail bestätigen</a>',
                "Hallo {{user_login}},\n\nbitte bestätige deine E-Mail-Adresse mit folgendem Link:\n{{verification_link}}",
            ],
            'email_review_body' => [
                'Hallo {{user_login}},<br><br>dein Account wird geprüft.<br><br>Grund: {{review_reason}}<br><br>Diese Prüfung dauert in der Regel 1-2 Tage.',
                "Hallo {{user_login}},\n\ndein Account wird geprüft.\n\nGrund: {{review_reason}}\n\nDiese Prüfung dauert in der Regel 1-2 Tage.",
            ],
            'email_admin_review_body' => [
                'Der Account {{user_login}} ({{user_email}}) steht unter Prüfung.<br><br>Grund: {{review_reason}}<br><br>{{match_list}}<br><br><a href="{{review_url}}">Account prüfen und freischalten</a>',
                "Der Account {{user_login}} ({{user_email}}) steht unter Prüfung.\n\nGrund: {{review_reason}}\n\n{{match_list}}\n\nZur Prüfung: {{review_url}}",
            ],
            'email_approved_body' => [
                'Hallo {{user_login}},<br><br>dein Account wurde freigegeben.',
                "Hallo {{user_login}},\n\ndein Account wurde freigegeben.",
            ],
            'email_declined_body' => [
                'Hallo {{user_login}},<br><br>dein Account wurde abgelehnt. Bitte kontaktiere den Support.',
                "Hallo {{user_login}},\n\ndein Account wurde abgelehnt. Bitte kontaktiere den Support.",
            ],
            'email_reset_password_body' => [
                '<p>Hallo {{user_login}},</p><p>du hast angefordert, dein Passwort fuer {{site_name}} zurueckzusetzen.</p><p style="margin:28px 0;"><a href="{{reset_link}}" style="display:inline-block;background:#008f6b;color:#ffffff;padding:12px 18px;border-radius:6px;text-decoration:none;font-weight:bold;">Passwort zuruecksetzen</a></p><p>Falls du diese Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.</p>',
                "Hallo {{user_login}},\n\nüber folgenden Link kannst du dein Passwort zurücksetzen:\n{{reset_link}}",
            ],
            'email_delete_profile_body' => [
                '<p>Hallo {{user_login}},</p><p>du hast angefordert, dein Profil fuer {{site_name}} zu loeschen.</p><p>Wenn du die Loeschung bestaetigst, wird dein Profil dauerhaft entfernt. Alle zugehoerigen Buchungen werden storniert.</p><p style="margin:28px 0;"><a href="{{delete_profile_link}}" style="display:inline-block;background:#dc2626;color:#ffffff;padding:12px 18px;border-radius:6px;text-decoration:none;font-weight:bold;">Profil loeschen</a></p><p>Falls du diese Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.</p>',
                "Hallo {{user_login}},\n\nbitte bestätige die Löschung deines Profils mit folgendem Link:\n{{delete_profile_link}}\n\nDabei werden alle zugehörigen Buchungen storniert.",
            ],
        ];

        foreach ($legacy_bodies as $key => $legacy_values) {
            if (!isset($email_defaults[$key]) || !array_key_exists($key, $options)) {
                continue;
            }

            $current = self::normalize_email_template_for_comparison((string) $options[$key]);
            foreach ($legacy_values as $legacy_value) {
                if ($current === self::normalize_email_template_for_comparison($legacy_value)) {
                    $options[$key] = $email_defaults[$key];
                    break;
                }
            }
        }

        return $options;
    }

    private static function normalize_email_template_for_comparison(string $template): string
    {
        $template = str_replace(["\r\n", "\r"], "\n", trim($template));
        $template = preg_replace('/>\s+</', '><', $template) ?? $template;
        $template = preg_replace('/\s+/', ' ', $template) ?? $template;

        return trim($template);
    }

    private static function render_template(string $template, array $tags): string
    {
        $replacements = [];
        foreach ($tags as $key => $value) {
            $replacements['{{' . $key . '}}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    public static function send_templated_mail(string $to, string $context, array $tags): bool
    {
        $options = self::get_options();
        $definitions = self::get_email_template_definitions();
        if (!isset($definitions[$context])) {
            self::log_mail_event('template_context_missing', [
                'context' => $context,
                'to' => $to,
            ]);
            return false;
        }

        $tags = array_merge(self::get_common_email_tags(), $tags);
        $subject_key = $definitions[$context]['subject_key'];
        $body_key = $definitions[$context]['body_key'];
        $subject_template = $options[$subject_key] ?? '';
        $body_template = $options[$body_key] ?? '';
        $html_wrapper = $options['email_html_template'] ?? self::get_default_email_html_template();

        $subject = self::render_template($subject_template, $tags);
        $body = self::render_template($body_template, $tags);
        $html = self::render_template($html_wrapper, array_merge($tags, ['content' => $body]));

        self::$is_sending_templated_mail = true;
        try {
            return self::send_mail($to, wp_strip_all_tags($subject), $html, $context);
        } finally {
            self::$is_sending_templated_mail = false;
        }
    }

    public function wrap_external_mail(array $args): array
    {
        if (self::$is_sending_templated_mail) {
            return $args;
        }

        $message = isset($args['message']) ? (string) $args['message'] : '';
        if ($message === '' || $this->is_newsletter_mail($args)) {
            self::clear_pending_inline_email_images();
            return $args;
        }

        if ($this->is_already_wrapped_mail($message)) {
            self::prepare_inline_email_images($message);
            return $args;
        }

        $subject = isset($args['subject']) ? (string) $args['subject'] : '';
        $content = $this->normalize_mail_content($message);
        $tags = array_merge(self::get_common_email_tags(), [
            'subject' => esc_html(wp_strip_all_tags($subject)),
            'content' => $content,
        ]);

        $options = self::get_options();
        $html_wrapper = $options['email_html_template'] ?? self::get_default_email_html_template();
        $args['message'] = self::render_template($html_wrapper, $tags);
        $args['headers'] = $this->ensure_html_mail_headers($args['headers'] ?? []);
        self::prepare_inline_email_images($args['message']);

        return $args;
    }

    private function is_already_wrapped_mail(string $message): bool
    {
        return strpos($message, 'data-afcb-email-wrapper') !== false;
    }

    private function is_newsletter_mail(array $args): bool
    {
        // Newsletter mails are complete campaign/service layouts and must keep their own HTML.
        foreach ($this->normalize_mail_headers($args['headers'] ?? []) as $key => $header) {
            $header_name = is_string($key) ? strtolower(trim($key)) : '';
            $normalized = strtolower(trim((string) $header));
            if (
                strpos($header_name, 'x-newsletter') === 0 ||
                strpos($header_name, 'x-tnp') === 0 ||
                strpos($normalized, 'x-newsletter') === 0 ||
                strpos($normalized, 'x-tnp') === 0
            ) {
                return true;
            }
        }

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $trace) {
            $class = isset($trace['class']) ? (string) $trace['class'] : '';
            if ($class !== '' && (strpos($class, 'Newsletter') === 0 || strpos($class, 'TNP_') === 0)) {
                return true;
            }

            $file = isset($trace['file']) ? wp_normalize_path((string) $trace['file']) : '';
            if ($file !== '' && preg_match('#/wp-content/plugins/newsletter(?:-[^/]*)?/#', $file)) {
                return true;
            }
        }

        return false;
    }

    private function normalize_mail_content(string $message): string
    {
        if ($message !== wp_strip_all_tags($message)) {
            return wp_kses_post($message);
        }

        return nl2br(esc_html($message));
    }

    private function ensure_html_mail_headers($headers): array
    {
        $headers = $this->normalize_mail_headers($headers);

        foreach ($headers as $index => $header) {
            if (is_string($header) && stripos($header, 'content-type:') === 0) {
                $headers[$index] = 'Content-Type: text/html; charset=UTF-8';
                return $headers;
            }
        }

        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        return $headers;
    }

    private function normalize_mail_headers($headers): array
    {
        if (is_string($headers)) {
            $headers = trim($headers) === '' ? [] : preg_split('/\r\n|\r|\n/', $headers);
        }

        return is_array($headers) ? $headers : [];
    }

    public function handle_wp_mail_failed(\WP_Error $error): void
    {
        $data = $error->get_error_data();
        $payload = [
            'error_code' => $error->get_error_code(),
            'error_message' => $error->get_error_message(),
        ];

        if (is_array($data)) {
            $payload['to'] = $data['to'] ?? '';
            $payload['subject'] = isset($data['subject']) ? wp_strip_all_tags((string) $data['subject']) : '';
            if (isset($data['phpmailer_exception_code'])) {
                $payload['phpmailer_exception_code'] = $data['phpmailer_exception_code'];
            }
        }

        self::log_mail_event('wp_mail_failed_hook', $payload);
        self::clear_pending_inline_email_images();
    }

    public function handle_wp_mail_succeeded(array $mail_data): void
    {
        self::log_mail_event('wp_mail_succeeded_hook', [
            'to' => $mail_data['to'] ?? '',
            'subject' => isset($mail_data['subject']) ? wp_strip_all_tags((string) $mail_data['subject']) : '',
        ]);
        self::clear_pending_inline_email_images();
    }

    public function attach_inline_email_images($phpmailer): void
    {
        foreach (self::$pending_inline_email_images as $image) {
            try {
                if (!method_exists($phpmailer, 'addEmbeddedImage')) {
                    continue;
                }

                $phpmailer->addEmbeddedImage(
                    $image['path'],
                    $image['cid'],
                    $image['name'],
                    'base64',
                    $image['mime'],
                    'inline'
                );
            } catch (\Throwable $e) {
                self::log_mail_event('inline_image_attach_failed', [
                    'cid' => $image['cid'] ?? '',
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    public function handle_post_smtp_failed($log, $message, $transcript, $transport, $error): void
    {
        self::log_mail_event('post_smtp_failed_hook', [
            'error_message' => (string) $error,
            'transcript' => self::truncate_log_value((string) $transcript, 1200),
        ]);
    }

    public function handle_post_smtp_success($log, $message, $transcript, $transport): void
    {
        self::log_mail_event('post_smtp_success_hook', [
            'transcript' => self::truncate_log_value((string) $transcript, 600),
        ]);
    }

    private static function get_post_smtp_result_summary(): array
    {
        $result = apply_filters('postman_wp_mail_result', null);
        if (!is_array($result)) {
            return [];
        }

        $summary = [];
        if (isset($result['exception']) && $result['exception'] instanceof \Throwable) {
            $summary['post_smtp_exception'] = $result['exception']->getMessage();
            $summary['post_smtp_exception_code'] = $result['exception']->getCode();
        }
        if (!empty($result['transcript'])) {
            $summary['post_smtp_transcript'] = self::truncate_log_value((string) $result['transcript'], 1200);
        }

        return $summary;
    }

    private static function log_mail_event(string $event, array $data = []): void
    {
        $payload = array_merge([
            'event' => $event,
        ], self::sanitize_mail_log_payload($data));

        error_log(self::MAIL_LOG_PREFIX . wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function sanitize_mail_log_payload(array $data): array
    {
        $payload = [];

        foreach ($data as $key => $value) {
            if ($key === 'to') {
                $payload['to'] = self::summarize_mail_recipient($value);
                continue;
            }

            if ($value instanceof \Throwable) {
                $payload[$key] = $value->getMessage();
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = self::truncate_log_value(wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $payload[$key] = self::truncate_log_value((string) $value);
            }
        }

        return $payload;
    }

    private static function summarize_mail_recipient($to): array
    {
        $recipients = is_array($to) ? $to : preg_split('/,/', (string) $to);
        $summary = [];

        foreach ((array) $recipients as $recipient) {
            $email = sanitize_email(trim((string) $recipient));
            if ($email === '') {
                continue;
            }

            $domain = '';
            $parts = explode('@', $email);
            if (count($parts) === 2) {
                $domain = strtolower($parts[1]);
            }

            $summary[] = [
                'hash' => hash('sha256', strtolower($email)),
                'domain' => $domain,
            ];
        }

        return $summary;
    }

    private static function truncate_log_value(string $value, int $max_length = 500): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if (strlen($value) <= $max_length) {
            return $value;
        }

        return substr($value, 0, $max_length) . '...';
    }
}
