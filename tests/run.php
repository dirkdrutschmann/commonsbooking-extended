<?php

declare(strict_types=1);

namespace {
    define('DAY_IN_SECONDS', 86400);
    define('LASTENRAD_DEV_MAIL_SINK', 'sink@example.test');
    define('LASTENRAD_SITE_KEY', 'wetterau');

    $GLOBALS['afcb_test_environment'] = 'staging';
    $GLOBALS['afcb_test_users'] = [];
    $GLOBALS['afcb_test_deleted_meta'] = [];
    $GLOBALS['afcb_test_sync_calls'] = [];
    $GLOBALS['afcb_test_authenticator'] = null;
    $GLOBALS['afcb_test_logged_in'] = false;
    $GLOBALS['afcb_test_auth_cookie_calls'] = 0;

    final class WP_User
    {
        public int $ID;
        public string $user_login;

        public function __construct(int $user_id, string $user_login = 'test-user')
        {
            $this->ID = $user_id;
            $this->user_login = $user_login;
        }
    }

    class WP_Error
    {
        public string $code;

        public function __construct(string $code = 'authentication_failed')
        {
            $this->code = $code;
        }
    }

    function apply_filters(string $hook, $value, ...$args)
    {
        return $value;
    }

    function get_users(array $args = []): array
    {
        return array_keys($GLOBALS['afcb_test_users']);
    }

    function get_user_meta(int $user_id, string $key, bool $single = false)
    {
        return $GLOBALS['afcb_test_users'][$user_id][$key] ?? '';
    }

    function wp_authenticate(string $login, string $password)
    {
        $authenticator = $GLOBALS['afcb_test_authenticator'];

        return is_callable($authenticator) ? $authenticator($login, $password) : new WP_Error();
    }

    function is_wp_error($value): bool
    {
        return $value instanceof WP_Error;
    }

    function is_user_logged_in(): bool
    {
        return (bool) $GLOBALS['afcb_test_logged_in'];
    }

    function wp_set_auth_cookie(int $user_id): void
    {
        $GLOBALS['afcb_test_auth_cookie_calls']++;
        $GLOBALS['afcb_test_logged_in'] = true;
    }

    function update_user_meta(int $user_id, string $key, $value)
    {
        $current = $GLOBALS['afcb_test_users'][$user_id][$key] ?? null;
        if ($current === $value) {
            return false;
        }
        $GLOBALS['afcb_test_users'][$user_id][$key] = $value;

        return 1;
    }

    function delete_user_meta(int $user_id, string $key): bool
    {
        $GLOBALS['afcb_test_deleted_meta'][] = [$user_id, $key];
        unset($GLOBALS['afcb_test_users'][$user_id][$key]);

        return true;
    }

    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function sanitize_key(string $value): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? '';
    }

    function maybe_unserialize($value)
    {
        if (!is_string($value) || !preg_match('/^[aObisdN]:/', $value)) {
            return $value;
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);

        return $unserialized === false && $value !== 'b:0;' ? $value : $unserialized;
    }

    function wp_get_environment_type(): string
    {
        return $GLOBALS['afcb_test_environment'];
    }

    function sanitize_email(string $email): string
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }

    function is_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    function has_shortcode(string $content, string $shortcode): bool
    {
        return (bool) preg_match('/\\[' . preg_quote($shortcode, '/') . '(?:[\\s\\]\\/])/', $content);
    }

    function afcb_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function afcb_assert_same($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true)
            );
        }
    }
}

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement {
    final class UserManagement
    {
        public const META_PHONE = 'afcb_phone';
        public const META_PHONE_VERIFIED_AT = 'afcb_phone_verified_at';
        public const META_EMAIL_VERIFIED_AT = 'afcb_email_verified_at';
        public const META_EMAIL_LAST_SENT = 'afcb_email_verification_last_sent';
        public const META_ACCOUNT_STATUS = 'afcb_account_status';
        public const META_ACCOUNT_REVIEW_REASON = 'afcb_account_review_reason';
        public const META_ACCOUNT_REVIEW_AT = 'afcb_account_review_at';
        public const META_TERMS_ACCEPTED_AT = 'afcb_terms_accepted_at';
        public const META_PRIVACY_ACCEPTED_AT = 'afcb_privacy_accepted_at';
        public const META_LEGACY_MIGRATION_PROVENANCE = 'afcb_legacy_migration_provenance';
        public const META_STREET = 'afcb_street';
        public const META_HOUSE_NUMBER = 'afcb_house_number';
        public const META_ZIP = 'afcb_zip';
        public const META_CITY = 'afcb_city';
        public const META_STATE = 'afcb_state';
        public const META_COUNTRY = 'afcb_country';
        public const META_LAST_LOGIN = 'afcb_last_login';

        public static function normalize_phone(string $phone): string
        {
            $phone = preg_replace('/[^0-9+]/', '', $phone) ?? '';
            if (strpos($phone, '00') === 0) {
                return '+' . substr($phone, 2);
            }

            return $phone;
        }

        public static function sync_commonsbooking_legacy_user_meta(int $user_id): void
        {
            $GLOBALS['afcb_test_sync_calls'][] = $user_id;
            $phone = trim((string) get_user_meta($user_id, self::META_PHONE, true));
            if ($phone !== '') {
                update_user_meta($user_id, 'phone', $phone);
            }
            if ((int) get_user_meta($user_id, self::META_TERMS_ACCEPTED_AT, true) > 0) {
                update_user_meta($user_id, 'terms_accepted', 'yes');
            }
        }

        public static function suspend_commonsbooking_legacy_sync(): void
        {
        }

        public static function resume_commonsbooking_legacy_sync(): void
        {
        }
    }

    require dirname(__DIR__) . '/src/UserManagement/UltimateMemberImporter.php';
    require dirname(__DIR__) . '/src/UserManagement/AccountStatusPolicy.php';
    require dirname(__DIR__) . '/src/UserManagement/EmailHeaderImage.php';
    require dirname(__DIR__) . '/src/UserManagement/EmailTemplatePolicy.php';
    require dirname(__DIR__) . '/src/UserManagement/PendingEmailVerificationLogin.php';
    require dirname(__DIR__) . '/src/UserManagement/FrontendAssetRequirements.php';
}

namespace {
    use DirkDrutschmann\CommonbookingsAdditionalFeatures\Support\EnvironmentSafety;
    use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\UltimateMemberImporter;
    use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\UserManagement;
    use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\PendingEmailVerificationLogin;
    use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\EmailHeaderImage;
    use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\EmailTemplatePolicy;
    use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\FrontendAssetRequirements;

    require dirname(__DIR__) . '/src/Support/EnvironmentSafety.php';

    $tests = [];

    $tests['frontend assets are resolved before shortcode rendering'] = static function (): void {
        $registration = FrontendAssetRequirements::resolve(
            42,
            '',
            ['registration_page_id' => 42]
        );
        afcb_assert_same(
            ['address_lookup' => true, 'recaptcha' => true, 'phone_input' => true],
            $registration,
            'The configured registration page must enqueue every dependency before wp_head.'
        );

        $combined = FrontendAssetRequirements::resolve(
            77,
            '[afcb_login] [cbaf_forgot_username]',
            []
        );
        afcb_assert_same(
            ['address_lookup' => false, 'recaptcha' => true, 'phone_input' => true],
            $combined,
            'Multiple AFCB shortcodes must merge their frontend dependencies.'
        );

        afcb_assert_same(
            null,
            FrontendAssetRequirements::resolve(99, 'Keine AFCB-Formulare', []),
            'Unrelated pages must not receive AFCB frontend assets.'
        );
    };

    $tests['email header images use site-specific embedded PNGs'] = static function (): void {
        $main = EmailHeaderImage::inline_attachment(
            'main',
            EmailHeaderImage::MAIN_CID,
            '<img src="cid:' . EmailHeaderImage::MAIN_CID . '">',
            '/plugin/assets/main-logo.png',
            '/uploads'
        );
        $wetterau = EmailHeaderImage::inline_attachment(
            'wetterau',
            'https://dev.wetterau-lastenrad.de/wp-content/uploads/logo.svg',
            '<img src="cid:' . EmailHeaderImage::WETTERAU_CID . '">',
            '/plugin/assets/main-logo.png',
            '/uploads/'
        );

        afcb_assert_same(EmailHeaderImage::MAIN_CID, $main['cid'] ?? '', 'Main must retain its existing CID.');
        afcb_assert_same('/plugin/assets/main-logo.png', $main['path'] ?? '', 'Main must use the immutable plugin PNG.');
        afcb_assert_same(EmailHeaderImage::WETTERAU_CID, $wetterau['cid'] ?? '', 'Wetterau must use its own CID.');
        afcb_assert_same('/plugin/assets/wetterau-lastenrad-logo.png', $wetterau['path'] ?? '', 'Wetterau must embed the immutable plugin PNG.');
        afcb_assert_same('image/png', $wetterau['mime'] ?? '', 'Wetterau must use a mail-compatible PNG MIME type.');

        $plugin_email_asset = EmailHeaderImage::definition(
            'wetterau',
            dirname(__DIR__) . '/assets/img/email/main-lastenrad-logo-main-green.png',
            '/unused-uploads'
        );
        $image_size = is_readable($plugin_email_asset['path'] ?? '')
            ? getimagesize((string) $plugin_email_asset['path'])
            : false;
        afcb_assert(is_array($image_size), 'Wetterau immutable email logo must be a readable image.');
        afcb_assert_same('image/png', $image_size['mime'] ?? '', 'Wetterau immutable email logo must be a PNG.');
        afcb_assert_same(1480, $image_size[0] ?? 0, 'Wetterau email logo width changed unexpectedly.');
        afcb_assert_same(448, $image_size[1] ?? 0, 'Wetterau email logo height changed unexpectedly.');
        afcb_assert_same(
            'be45ce48aa58af27c2e6319f47c204b7a0a738240d51935b8e8117ef51258430',
            hash_file('sha256', (string) $plugin_email_asset['path']),
            'The Wetterau mail asset must remain the approved logo with cargo bike.'
        );
    };

    $tests['email header image normalization preserves custom URLs'] = static function (): void {
        afcb_assert_same(
            EmailHeaderImage::WETTERAU_CID,
            EmailHeaderImage::normalize_reference('wetterau', 'cid:' . EmailHeaderImage::MAIN_CID),
            'A legacy Main default must not leak into Wetterau mail.'
        );
        afcb_assert_same(
            EmailHeaderImage::MAIN_CID,
            EmailHeaderImage::normalize_reference('main', 'cid:' . EmailHeaderImage::WETTERAU_CID),
            'A legacy Wetterau default must not leak into Main mail.'
        );
        afcb_assert_same(
            'https://cdn.example.test/custom-brand.png',
            EmailHeaderImage::normalize_reference('wetterau', 'https://cdn.example.test/custom-brand.png'),
            'An intentional custom HTTPS logo must remain configurable.'
        );
        afcb_assert_same(
            null,
            EmailHeaderImage::inline_attachment(
                'wetterau',
                'https://cdn.example.test/custom-brand.png',
                '<img src="https://cdn.example.test/custom-brand.png">',
                '/plugin/assets/main-logo.png',
                '/uploads'
            ),
            'External custom images must not be treated as local CID attachments.'
        );
    };

    $tests['legacy default email wrapper upgrades without replacing custom layouts'] = static function (): void {
        $legacy = <<<'HTML'
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fb;padding:24px 0;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;box-shadow:0 6px 20px rgba(15,23,42,0.08);overflow:hidden;">
<tr><td style="padding:28px 32px;border-bottom:1px solid #eef2f6;">
<h1 style="margin:0;font-size:20px;color:#0f172a;">{{site_name}}</h1>
</td></tr>
<tr><td style="padding:28px 32px;color:#334155;font-size:15px;line-height:1.6;">{{content}}</td></tr>
<tr><td style="padding:20px 32px;border-top:1px solid #eef2f6;font-size:12px;color:#94a3b8;">{{site_url}} · {{year}}</td></tr>
</table></td></tr></table></body></html>
HTML;
        $default = '<div>{{header_image_html}}{{content}}{{footer_text}}</div>';
        $custom = '<div class="custom">{{content}}</div>';

        afcb_assert_same($default, EmailTemplatePolicy::normalize_wrapper($legacy, $default), 'The former bundled wrapper must gain the branded header and footer.');
        afcb_assert_same($custom, EmailTemplatePolicy::normalize_wrapper($custom, $default), 'A genuinely custom wrapper must remain untouched.');
    };

    $tests['preview is side-effect free'] = static function (): void {
        $GLOBALS['afcb_test_users'] = [
            9 => [
                'phone_number' => '+49 170 1234567',
                'account_status' => 'approved',
            ],
        ];
        $GLOBALS['afcb_test_sync_calls'] = [];
        $before = $GLOBALS['afcb_test_users'];

        $result = UltimateMemberImporter::preview([9]);

        afcb_assert_same(true, $result['dry_run'], 'Preview must identify itself as dry-run.');
        afcb_assert_same(1, $result['updated'], 'Preview must report the pending user update.');
        afcb_assert_same($before, $GLOBALS['afcb_test_users'], 'Preview must not write metadata.');
        afcb_assert_same([], $GLOBALS['afcb_test_sync_calls'], 'Preview must not run compatibility sync.');
    };

    $tests['imports Main and Wetterau fields, statuses and consents'] = static function (): void {
        $terms_timestamp = 1710000000;
        $GLOBALS['afcb_test_users'] = [
            1 => [
                'phone' => '0176 12345678',
                'address' => 'Mainstraße',
                'address_number' => '7a',
                'zip_code' => '60311',
                'city' => 'Frankfurt',
                'account_status' => 'approved',
                '_um_last_login' => '2024-02-03 04:05:06',
                'use_terms_conditions_agreement' => (string) $terms_timestamp,
                'use_gdpr_agreement' => '2024-01-02 03:04:05',
            ],
            2 => [
                'phone_number' => '+49 160 2222222',
                'street' => 'Wetterauer Weg',
                'street_number' => '12',
                'zip_code' => '61169',
                'city' => 'Friedberg',
                'account_status' => 'awaiting_email_confirmation',
                'submitted' => [
                    'use_terms_conditions_agreement' => '2023-02-03 04:05:06',
                    'use_gdpr_agreement' => '2023-02-03 04:05:07',
                ],
            ],
            3 => ['account_status' => 'inactive'],
            4 => [
                'account_status' => 'unexpected_status',
                'use_terms_conditions_agreement' => true,
                'use_gdpr_agreement' => '1',
            ],
            5 => [
                'account_status' => 'approved',
                'street' => 'Legacy-Straße',
                UserManagement::META_ACCOUNT_STATUS => 'declined',
                UserManagement::META_STREET => 'AFCB bleibt führend',
            ],
        ];
        $GLOBALS['afcb_test_deleted_meta'] = [];
        $GLOBALS['afcb_test_sync_calls'] = [];

        $result = UltimateMemberImporter::import();

        afcb_assert_same(false, $result['dry_run'], 'Import must not identify itself as preview.');
        afcb_assert_same(5, $result['total'], 'All requested users must be processed.');
        afcb_assert_same(4, $result['statuses_mapped'], 'Only users without an AFCB status are mapped.');
        afcb_assert_same(1, $result['grandfathered'], 'Only newly mapped approved users are grandfathered.');
        afcb_assert_same(1, $result['pending_review'], 'Unknown states require one manual review.');
        afcb_assert_same([], $result['errors'], 'Metadata writes must succeed.');

        afcb_assert_same('Mainstraße', get_user_meta(1, UserManagement::META_STREET, true), 'Main address must migrate.');
        afcb_assert_same('7a', get_user_meta(1, UserManagement::META_HOUSE_NUMBER, true), 'Main house number must migrate.');
        afcb_assert_same('active', get_user_meta(1, UserManagement::META_ACCOUNT_STATUS, true), 'Approved maps to active.');
        afcb_assert((int) get_user_meta(1, UserManagement::META_EMAIL_VERIFIED_AT, true) > 0, 'Approved account needs grandfathered email verification.');
        afcb_assert((int) get_user_meta(1, UserManagement::META_PHONE_VERIFIED_AT, true) > 0, 'Approved account needs grandfathered phone verification.');
        afcb_assert_same($terms_timestamp, get_user_meta(1, UserManagement::META_TERMS_ACCEPTED_AT, true), 'Terms timestamp must be preserved exactly.');
        afcb_assert_same(strtotime('2024-01-02 03:04:05 UTC'), get_user_meta(1, UserManagement::META_PRIVACY_ACCEPTED_AT, true), 'Privacy date must be normalized to its real timestamp.');
        afcb_assert_same(strtotime('2024-02-03 04:05:06 UTC'), get_user_meta(1, UserManagement::META_LAST_LOGIN, true), 'UM last-login date must be normalized.');
        afcb_assert_same('0176 12345678', get_user_meta(1, 'phone', true), 'A Main source phone must remain byte-for-byte intact during migration.');

        $provenance = get_user_meta(1, UserManagement::META_LEGACY_MIGRATION_PROVENANCE, true);
        afcb_assert_same(true, $provenance['grandfathered'], 'Grandfathering must be traceable.');
        afcb_assert_same('approved', $provenance['legacy_account_status'], 'Legacy status must be recorded.');
        afcb_assert_same('address_number', $provenance['field_sources'][UserManagement::META_HOUSE_NUMBER], 'Field source must be recorded.');

        afcb_assert_same('Wetterauer Weg', get_user_meta(2, UserManagement::META_STREET, true), 'Wetterau street must migrate.');
        afcb_assert_same('12', get_user_meta(2, UserManagement::META_HOUSE_NUMBER, true), 'Wetterau street number must migrate.');
        afcb_assert_same('+491602222222', get_user_meta(2, 'phone', true), 'Missing CommonsBooking phone compatibility must be filled.');
        afcb_assert_same('Wetterauer Weg 12, 61169 Friedberg', get_user_meta(2, 'address', true), 'Missing CommonsBooking address compatibility must be filled.');
        afcb_assert_same('pending', get_user_meta(2, UserManagement::META_ACCOUNT_STATUS, true), 'Awaiting email confirmation maps to pending.');
        afcb_assert_same('', get_user_meta(2, UserManagement::META_EMAIL_VERIFIED_AT, true), 'Pending users must not be grandfathered.');

        afcb_assert_same('declined', get_user_meta(3, UserManagement::META_ACCOUNT_STATUS, true), 'Inactive maps to declined.');
        afcb_assert_same('pending', get_user_meta(4, UserManagement::META_ACCOUNT_STATUS, true), 'Unknown state maps to pending.');
        afcb_assert_same('legacy_unknown_account_status', get_user_meta(4, UserManagement::META_ACCOUNT_REVIEW_REASON, true), 'Unknown state needs a review marker.');
        afcb_assert_same('', get_user_meta(4, UserManagement::META_TERMS_ACCEPTED_AT, true), 'Boolean terms evidence must not become a fake timestamp.');
        afcb_assert_same('', get_user_meta(4, UserManagement::META_PRIVACY_ACCEPTED_AT, true), 'Boolean-like privacy evidence must not become a fake timestamp.');

        afcb_assert_same('declined', get_user_meta(5, UserManagement::META_ACCOUNT_STATUS, true), 'Existing AFCB status must win.');
        afcb_assert_same('AFCB bleibt führend', get_user_meta(5, UserManagement::META_STREET, true), 'Existing AFCB field must win.');
        afcb_assert_same('Legacy-Straße', get_user_meta(5, 'street', true), 'Legacy source must remain intact.');
        afcb_assert_same([], $GLOBALS['afcb_test_deleted_meta'], 'Importer must never delete source metadata.');
        afcb_assert_same('yes', get_user_meta(1, 'terms_accepted', true), 'CommonsBooking terms compatibility must be synchronized.');

        $second = UltimateMemberImporter::import();
        afcb_assert_same(0, $second['updated'], 'Second import must be idempotent.');
        afcb_assert_same(5, $second['skipped'], 'Second import must skip all unchanged users.');
    };

    $tests['repairs partially migrated approved accounts without overwriting AFCB data'] = static function (): void {
        $GLOBALS['afcb_test_users'] = [
            6 => [
                'account_status' => 'approved',
                'phone' => '0176 55555555',
                UserManagement::META_PHONE => '+4917655555555',
                UserManagement::META_ACCOUNT_STATUS => 'active',
            ],
        ];

        $result = UltimateMemberImporter::import([6]);

        afcb_assert_same(1, $result['updated'], 'A partial approved migration must be completed.');
        afcb_assert_same(0, $result['statuses_mapped'], 'An existing AFCB status must not be remapped.');
        afcb_assert((int) get_user_meta(6, UserManagement::META_EMAIL_VERIFIED_AT, true) > 0, 'Grandfathered email verification must be backfilled.');
        afcb_assert((int) get_user_meta(6, UserManagement::META_PHONE_VERIFIED_AT, true) > 0, 'Grandfathered phone verification must be backfilled.');
        afcb_assert_same('0176 55555555', get_user_meta(6, 'phone', true), 'Existing legacy phone must not be normalized or overwritten.');

        $second = UltimateMemberImporter::preview([6]);
        afcb_assert_same(0, $second['updated'], 'Completed partial migration must become idempotent.');
    };

    $tests['site profile controls legacy source priority'] = static function (): void {
        $main = UltimateMemberImporter::get_default_mapping('main');
        $wetterau = UltimateMemberImporter::get_default_mapping('wetterau');

        afcb_assert_same('phone', $main[UserManagement::META_PHONE][0], 'Main must prefer its historic phone key.');
        afcb_assert_same('address', $main[UserManagement::META_STREET][0], 'Main must prefer its historic address key.');
        afcb_assert_same('phone_number', $wetterau[UserManagement::META_PHONE][0], 'Wetterau must prefer phone_number.');
        afcb_assert_same('street', $wetterau[UserManagement::META_STREET][0], 'Wetterau must prefer street.');
    };

    $tests['dry-run and apply report identical migration deltas'] = static function (): void {
        $GLOBALS['afcb_test_users'] = [
            7 => [
                'phone_number' => '+49 160 7777777',
                'street' => 'Testweg',
                'street_number' => '7',
                'zip_code' => '61169',
                'city' => 'Friedberg',
                'account_status' => 'approved',
            ],
        ];

        $preview = UltimateMemberImporter::preview([7]);
        $apply = UltimateMemberImporter::import([7]);

        foreach (['total', 'updated', 'skipped', 'fields_updated', 'compatibility_fields_updated', 'statuses_mapped', 'pending_review', 'grandfathered', 'consent_timestamps'] as $key) {
            afcb_assert_same($preview[$key], $apply[$key], 'Preview/apply delta differs for ' . $key . '.');
        }
        afcb_assert_same([], $apply['errors'], 'Apply must not report metadata errors.');
    };

    $tests['environment safety redirects mail and gates non-production SMS explicitly'] = static function (): void {
        $GLOBALS['afcb_test_environment'] = 'staging';
        $mail = EnvironmentSafety::redirect_mail_to_sink([
            'to' => ['person@example.org', 'second@example.org'],
            'subject' => 'Testnachricht',
            'message' => 'Inhalt',
            'headers' => [
                'Content-Type: text/plain; charset=UTF-8',
                'Cc: cc@example.org',
                'Bcc: bcc@example.org',
            ],
        ]);

        afcb_assert_same('sink@example.test', $mail['to'], 'Staging mail must use the configured sink.');
        afcb_assert_same('[STAGING wetterau] Testnachricht', $mail['subject'], 'Staging subject must be clearly marked.');
        afcb_assert(!preg_grep('/^(?:Cc|Bcc):/i', $mail['headers']), 'Cc/Bcc must be removed to prevent leakage.');
        afcb_assert((bool) preg_grep('/^X-AFCB-Original-To:/', $mail['headers']), 'Original recipient must remain traceable in the sink message.');

        $already_redirected = EnvironmentSafety::redirect_mail_to_sink([
            'to' => 'sink@example.test',
            'subject' => '[STAGING wetterau] Bereits umgeleitet',
            'message' => 'Inhalt',
            'headers' => [
                'X-Lastenrad-Dev-Redirected: 1',
                'X-Lastenrad-Original-To: original@example.org',
            ],
        ]);
        afcb_assert_same('[STAGING wetterau] Bereits umgeleitet', $already_redirected['subject'], 'A prior MU redirect must not add a second prefix.');
        afcb_assert((bool) preg_grep('/^X-Lastenrad-Original-To: original@example.org$/', $already_redirected['headers']), 'A prior MU redirect must preserve the real original recipient.');
        afcb_assert(!preg_grep('/^X-AFCB-Original-To: sink@example.test$/', $already_redirected['headers']), 'Plugin must not relabel the sink as the original recipient.');
        afcb_assert_same(
            false,
            EnvironmentSafety::allow_external_message_delivery(true, 'sms', '+491601234567', 'code', []),
            'The runtime callback must fail closed when the server switch is absent.'
        );

        afcb_assert_same(
            false,
            EnvironmentSafety::resolve_external_message_delivery(true, 'staging', 'sms', false),
            'Staging SMS must fail closed without the explicit server switch.'
        );
        afcb_assert_same(
            true,
            EnvironmentSafety::resolve_external_message_delivery(true, 'staging', 'sms', true),
            'The explicit server switch must allow staging SMS.'
        );
        afcb_assert_same(
            false,
            EnvironmentSafety::resolve_external_message_delivery(true, 'staging', 'signal', true),
            'Signal must remain blocked on staging even when SMS is enabled.'
        );
        afcb_assert_same(
            false,
            EnvironmentSafety::resolve_external_message_delivery(false, 'staging', 'sms', true),
            'The staging switch must not override an earlier transport denial.'
        );

        $GLOBALS['afcb_test_environment'] = 'production';
        $production_mail = EnvironmentSafety::redirect_mail_to_sink([
            'to' => 'person@example.org',
            'subject' => 'Live',
        ]);
        afcb_assert_same('person@example.org', $production_mail['to'], 'Production recipients must remain untouched.');
        afcb_assert_same(
            true,
            EnvironmentSafety::resolve_external_message_delivery(true, 'production', 'sms', false),
            'Production provider delivery must remain enabled independently of the dev switch.'
        );
    };

    $tests['pending verification login rejects a wrong password without mail or session'] = static function (): void {
        $GLOBALS['afcb_test_users'] = [
            20 => [UserManagement::META_ACCOUNT_STATUS => 'pending'],
        ];
        $GLOBALS['afcb_test_logged_in'] = false;
        $GLOBALS['afcb_test_auth_cookie_calls'] = 0;
        $GLOBALS['afcb_test_authenticator'] = static fn(string $login, string $password): WP_Error => new WP_Error('incorrect_password');
        $sent = 0;

        $result = PendingEmailVerificationLogin::attempt(
            'pending-user',
            'wrong-password',
            1700000000,
            60,
            static function (int $user_id) use (&$sent): bool {
                $sent++;
                return true;
            }
        );

        afcb_assert_same(PendingEmailVerificationLogin::STATE_AUTHENTICATION_FAILED, $result['state'], 'Wrong password must stop before resend.');
        afcb_assert_same(0, $sent, 'Wrong password must not trigger verification mail.');
        afcb_assert_same(0, $GLOBALS['afcb_test_auth_cookie_calls'], 'Credential check must not create an auth cookie.');
        afcb_assert_same(false, is_user_logged_in(), 'Wrong-password attempt must not leave a logged-in user.');
    };

    $tests['pending verification login respects resend cooldown without a session'] = static function (): void {
        $now = 1700000000;
        $GLOBALS['afcb_test_users'] = [
            21 => [
                UserManagement::META_ACCOUNT_STATUS => 'pending',
                UserManagement::META_EMAIL_LAST_SENT => $now - 10,
            ],
        ];
        $GLOBALS['afcb_test_logged_in'] = false;
        $GLOBALS['afcb_test_auth_cookie_calls'] = 0;
        $GLOBALS['afcb_test_authenticator'] = static fn(string $login, string $password): WP_User => new WP_User(21, $login);
        $sent = 0;

        $result = PendingEmailVerificationLogin::attempt(
            'pending-user',
            'correct-password',
            $now,
            60,
            static function (int $user_id) use (&$sent): bool {
                $sent++;
                return true;
            }
        );

        afcb_assert_same(PendingEmailVerificationLogin::STATE_COOLDOWN, $result['state'], 'Recent verification mail must activate cooldown.');
        afcb_assert_same(0, $sent, 'Cooldown must suppress another mail.');
        afcb_assert_same(0, $GLOBALS['afcb_test_auth_cookie_calls'], 'Pending credential check must not create an auth cookie.');
        afcb_assert_same(false, is_user_logged_in(), 'Pending cooldown attempt must not leave a logged-in user.');
    };

    $tests['pending verification login can resend after password check without a session'] = static function (): void {
        $GLOBALS['afcb_test_users'] = [
            22 => [UserManagement::META_ACCOUNT_STATUS => 'pending'],
        ];
        $GLOBALS['afcb_test_logged_in'] = false;
        $GLOBALS['afcb_test_auth_cookie_calls'] = 0;
        $GLOBALS['afcb_test_authenticator'] = static fn(string $login, string $password): WP_User => new WP_User(22, $login);
        $sent = 0;

        $result = PendingEmailVerificationLogin::attempt(
            'pending-user',
            'correct-password',
            1700000000,
            60,
            static function (int $user_id) use (&$sent): bool {
                $sent++;
                return $user_id === 22;
            }
        );

        afcb_assert_same(PendingEmailVerificationLogin::STATE_RESENT, $result['state'], 'Valid pending credentials may trigger a resend.');
        afcb_assert_same(1, $sent, 'Exactly one verification mail must be requested.');
        afcb_assert_same(0, $GLOBALS['afcb_test_auth_cookie_calls'], 'Resend path must not create an auth cookie.');
        afcb_assert_same(false, is_user_logged_in(), 'Resend path must not leave a logged-in user.');
    };

    $tests['declined account cannot login even when email is verified'] = static function (): void {
        $GLOBALS['afcb_test_users'] = [
            23 => [
                UserManagement::META_ACCOUNT_STATUS => 'declined',
                UserManagement::META_EMAIL_VERIFIED_AT => 1700000000,
            ],
        ];
        $GLOBALS['afcb_test_authenticator'] = static fn(string $login, string $password): WP_User => new WP_User(23, $login);
        $sent = 0;

        $result = PendingEmailVerificationLogin::attempt(
            'declined-user',
            'correct-password',
            1700000100,
            60,
            static function (int $user_id) use (&$sent): bool {
                $sent++;
                return true;
            }
        );

        afcb_assert_same(PendingEmailVerificationLogin::STATE_UNVERIFIED, $result['state'], 'Declined status must block session creation.');
        afcb_assert_same(0, $sent, 'Declined accounts must not receive verification mail.');
    };

    $tests['manual-review account cannot login after email verification'] = static function (): void {
        $GLOBALS['afcb_test_users'] = [
            24 => [
                UserManagement::META_ACCOUNT_STATUS => 'pending',
                UserManagement::META_ACCOUNT_REVIEW_REASON => 'possible_duplicate',
                UserManagement::META_EMAIL_VERIFIED_AT => 1700000000,
            ],
        ];
        $GLOBALS['afcb_test_authenticator'] = static fn(string $login, string $password): WP_User => new WP_User(24, $login);

        $result = PendingEmailVerificationLogin::attempt(
            'review-user',
            'correct-password',
            1700000100,
            60,
            static fn(int $user_id): bool => true
        );

        afcb_assert_same(PendingEmailVerificationLogin::STATE_UNVERIFIED, $result['state'], 'Pending review must block session creation after email verification.');
    };

    $tests['email verification activates only unheld pending accounts'] = static function (): void {
        afcb_assert_same(
            'active',
            \DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\AccountStatusPolicy::status_after_email_verification('pending', ''),
            'A pending account without review hold becomes active.'
        );
        afcb_assert_same(
            'pending',
            \DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\AccountStatusPolicy::status_after_email_verification('pending', 'possible_duplicate'),
            'A manual review hold must survive email verification.'
        );
        afcb_assert_same(
            'declined',
            \DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\AccountStatusPolicy::status_after_email_verification('declined', ''),
            'Email verification must never reactivate a declined account.'
        );
    };

    $failures = 0;
    foreach ($tests as $name => $test) {
        try {
            $test();
            fwrite(STDOUT, "PASS {$name}\n");
        } catch (\Throwable $throwable) {
            $failures++;
            fwrite(STDERR, "FAIL {$name}: {$throwable->getMessage()}\n");
        }
    }

    fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
    exit($failures === 0 ? 0 : 1);
}
