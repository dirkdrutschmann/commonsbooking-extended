<?php

declare(strict_types=1);

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement;

/**
 * Migrates Ultimate Member user metadata to AFCB-owned metadata.
 *
 * Source metadata is deliberately retained. Existing, non-empty AFCB values
 * always win, which makes preview/import safe to run repeatedly.
 */
final class UltimateMemberImporter
{
    public const META_KEY_PREFIX = 'afcb_';

    private const MIGRATION_VERSION = 2;
    private const SOURCE_NAME = 'ultimate-member';
    private const UNKNOWN_STATUS_REVIEW_REASON = 'legacy_unknown_account_status';
    private const EARLIEST_VALID_TIMESTAMP = 946684800; // 2000-01-01 00:00:00 UTC.

    /**
     * @return array<string, list<string>>
     */
    public static function get_default_mapping(?string $site_key = null): array
    {
        $site_key = $site_key ?? (defined('LASTENRAD_SITE_KEY') ? (string) LASTENRAD_SITE_KEY : 'main');
        $site_key = sanitize_key($site_key);
        $phone_sources = $site_key === 'wetterau'
            ? ['phone_number', 'phone', 'um_phone_number', 'um_phone', 'mobile_number']
            : ['phone', 'phone_number', 'um_phone', 'um_phone_number', 'mobile_number'];
        $street_sources = $site_key === 'wetterau'
            ? ['street', 'address', 'um_street', 'um_address', 'um_addr']
            : ['address', 'street', 'um_address', 'um_street', 'um_addr'];

        return [
            UserManagement::META_PHONE => $phone_sources,
            UserManagement::META_STREET => $street_sources,
            UserManagement::META_HOUSE_NUMBER => [
                'street_number',
                'address_number',
                'house_number',
                'um_street_number',
                'um_address_number',
                'um_house_number',
            ],
            UserManagement::META_ZIP => [
                'zip_code',
                'postal_code',
                'zip',
                'um_zip_code',
                'um_zip',
                'um_postal_code',
                'um_plz',
            ],
            UserManagement::META_CITY => ['city', 'um_city'],
            UserManagement::META_STATE => ['state', 'um_state'],
            UserManagement::META_COUNTRY => ['country', 'um_country'],
            UserManagement::META_LAST_LOGIN => [
                'when_last_login',
                '_um_last_login',
                '_um_last_login_backup',
                'um_last_login',
                'last_login',
            ],
        ];
    }

    /**
     * Reports changes without writing metadata or compatibility fields.
     *
     * @param list<int>|null $user_ids Optional restricted user set.
     * @return array<string, mixed>
     */
    public static function preview(?array $user_ids = null): array
    {
        return self::run(true, $user_ids);
    }

    /**
     * Applies the non-destructive migration.
     *
     * @param list<int>|null $user_ids Optional restricted user set.
     * @return array<string, mixed>
     */
    public static function import(?array $user_ids = null): array
    {
        return self::run(false, $user_ids);
    }

    /**
     * @param list<int>|null $requested_user_ids
     * @return array<string, mixed>
     */
    private static function run(bool $dry_run, ?array $requested_user_ids): array
    {
        $mapping = apply_filters('afcb_um_meta_map', self::get_default_mapping());
        $mapping = is_array($mapping) ? self::sanitize_mapping($mapping) : self::get_default_mapping();
        $user_ids = self::resolve_user_ids($requested_user_ids);
        $now = time();

        $result = [
            'dry_run' => $dry_run,
            'total' => count($user_ids),
            'updated' => 0,
            'skipped' => 0,
            'fields_updated' => 0,
            'compatibility_fields_updated' => 0,
            'statuses_mapped' => 0,
            'pending_review' => 0,
            'grandfathered' => 0,
            'consent_timestamps' => 0,
            'errors' => [],
        ];

        if (!$dry_run) {
            UserManagement::suspend_commonsbooking_legacy_sync();
        }

        try {
            foreach ($user_ids as $user_id) {
                $plan = self::build_user_plan($user_id, $mapping, $now);
                if ($plan['updates'] === [] && $plan['compatibility_updates'] === []) {
                    $result['skipped']++;
                    continue;
                }

                $result['updated']++;
                $result['fields_updated'] += count($plan['updates']);
                $result['compatibility_fields_updated'] += count($plan['compatibility_updates']);
                $result['statuses_mapped'] += $plan['status_mapped'] ? 1 : 0;
                $result['pending_review'] += $plan['pending_review'] ? 1 : 0;
                $result['grandfathered'] += $plan['grandfathered'] ? 1 : 0;
                $result['consent_timestamps'] += (int) $plan['consent_timestamps'];

                if ($dry_run) {
                    continue;
                }

                foreach (array_merge($plan['updates'], $plan['compatibility_updates']) as $meta_key => $value) {
                    $updated = update_user_meta($user_id, $meta_key, $value);
                    if ($updated === false) {
                        $result['errors'][] = [
                            'user_id' => $user_id,
                            'meta_key' => $meta_key,
                        ];
                    }
                }
            }
        } finally {
            if (!$dry_run) {
                UserManagement::resume_commonsbooking_legacy_sync();
            }
        }

        return $result;
    }

    /**
     * @param array<string, list<string>> $mapping
     * @return array{updates: array<string, mixed>, compatibility_updates: array<string, mixed>, status_mapped: bool, pending_review: bool, grandfathered: bool, consent_timestamps: int}
     */
    private static function build_user_plan(int $user_id, array $mapping, int $now): array
    {
        $updates = [];
        $field_sources = [];

        foreach ($mapping as $target_key => $source_keys) {
            if (self::has_meaningful_meta_value($user_id, $target_key)) {
                continue;
            }

            $source = self::find_text_source($user_id, $source_keys);
            if ($source === null) {
                continue;
            }

            $value = $source['value'];
            if ($target_key === UserManagement::META_PHONE) {
                $value = UserManagement::normalize_phone($value);
            }

            if ($target_key === UserManagement::META_LAST_LOGIN) {
                $timestamp = self::normalize_legacy_timestamp($value, $now);
                if ($timestamp === null) {
                    continue;
                }
                $value = $timestamp;
            } else {
                $value = sanitize_text_field($value);
                if ($value === '') {
                    continue;
                }
            }

            $updates[$target_key] = $value;
            $field_sources[$target_key] = $source['key'];
        }

        $consent_sources = [];
        $consent_timestamps = 0;
        $terms = self::find_consent_timestamp(
            $user_id,
            ['use_terms_conditions_agreement', 'um_use_terms_conditions_agreement_backup'],
            'use_terms_conditions_agreement',
            $now
        );
        if (!self::has_positive_timestamp_meta($user_id, UserManagement::META_TERMS_ACCEPTED_AT) && $terms !== null) {
            $updates[UserManagement::META_TERMS_ACCEPTED_AT] = $terms['timestamp'];
            $consent_sources[UserManagement::META_TERMS_ACCEPTED_AT] = $terms['source'];
            $consent_timestamps++;
        }

        $privacy = self::find_consent_timestamp(
            $user_id,
            ['use_gdpr_agreement', 'um_use_gdpr_agreement_backup'],
            'use_gdpr_agreement',
            $now
        );
        if (!self::has_positive_timestamp_meta($user_id, UserManagement::META_PRIVACY_ACCEPTED_AT) && $privacy !== null) {
            $updates[UserManagement::META_PRIVACY_ACCEPTED_AT] = $privacy['timestamp'];
            $consent_sources[UserManagement::META_PRIVACY_ACCEPTED_AT] = $privacy['source'];
            $consent_timestamps++;
        }

        $legacy_status = self::find_text_source($user_id, ['account_status', 'um_account_status']);
        $status_mapped = false;
        $pending_review = false;
        $grandfathered = false;
        $status_mapping = '';

        $legacy_status_key = $legacy_status !== null ? sanitize_key($legacy_status['value']) : '';
        $has_existing_status = self::has_meaningful_meta_value($user_id, UserManagement::META_ACCOUNT_STATUS);
        $effective_status = $has_existing_status
            ? sanitize_key((string) get_user_meta($user_id, UserManagement::META_ACCOUNT_STATUS, true))
            : '';

        if ($legacy_status !== null && !$has_existing_status) {
            $mapped_status = self::map_account_status($legacy_status_key);
            $updates[UserManagement::META_ACCOUNT_STATUS] = $mapped_status;
            $effective_status = $mapped_status;
            $status_mapped = true;
            $status_mapping = $legacy_status_key . '_to_' . $mapped_status;
        }

        if ($legacy_status_key === 'approved' && $effective_status === 'active') {
            $grandfathered = true;

            if (!self::has_positive_timestamp_meta($user_id, UserManagement::META_EMAIL_VERIFIED_AT)) {
                $updates[UserManagement::META_EMAIL_VERIFIED_AT] = $now;
            }

            $phone = isset($updates[UserManagement::META_PHONE])
                ? (string) $updates[UserManagement::META_PHONE]
                : trim((string) get_user_meta($user_id, UserManagement::META_PHONE, true));
            if ($phone !== '' && !self::has_positive_timestamp_meta($user_id, UserManagement::META_PHONE_VERIFIED_AT)) {
                $updates[UserManagement::META_PHONE_VERIFIED_AT] = $now;
            }
        } elseif ($legacy_status !== null && $effective_status === 'pending' && $legacy_status_key !== 'awaiting_email_confirmation') {
            $pending_review = true;
            if (!self::has_meaningful_meta_value($user_id, UserManagement::META_ACCOUNT_REVIEW_REASON)) {
                $updates[UserManagement::META_ACCOUNT_REVIEW_REASON] = self::UNKNOWN_STATUS_REVIEW_REASON;
            }
            if (!self::has_positive_timestamp_meta($user_id, UserManagement::META_ACCOUNT_REVIEW_AT)) {
                $updates[UserManagement::META_ACCOUNT_REVIEW_AT] = $now;
            }
        }

        if ($legacy_status !== null || $field_sources !== [] || $consent_sources !== []) {
            $provenance = self::build_provenance(
                $user_id,
                $now,
                $legacy_status,
                $status_mapping,
                $grandfathered,
                $field_sources,
                $consent_sources
            );
            if ($provenance !== null) {
                $updates[UserManagement::META_LEGACY_MIGRATION_PROVENANCE] = $provenance;
            }
        }

        $compatibility_updates = self::build_compatibility_updates($user_id, $updates);

        return [
            'updates' => $updates,
            'compatibility_updates' => $compatibility_updates,
            'status_mapped' => $status_mapped,
            'pending_review' => $pending_review,
            'grandfathered' => $grandfathered,
            'consent_timestamps' => $consent_timestamps,
        ];
    }

    /**
     * Builds only missing CommonsBooking compatibility values. Existing legacy
     * source metadata is never overwritten during the rollback window.
     *
     * @param array<string, mixed> $updates
     * @return array<string, string>
     */
    private static function build_compatibility_updates(int $user_id, array $updates): array
    {
        $compatibility = [];
        $projected = static function (string $key) use ($user_id, $updates) {
            return array_key_exists($key, $updates)
                ? $updates[$key]
                : get_user_meta($user_id, $key, true);
        };

        $phone = trim((string) $projected(UserManagement::META_PHONE));
        if ($phone !== '' && !self::has_meaningful_meta_value($user_id, 'phone')) {
            $compatibility['phone'] = $phone;
        }

        $street_line = trim((string) $projected(UserManagement::META_STREET) . ' ' . (string) $projected(UserManagement::META_HOUSE_NUMBER));
        $city_line = trim((string) $projected(UserManagement::META_ZIP) . ' ' . (string) $projected(UserManagement::META_CITY));
        $address = sanitize_text_field(implode(', ', array_filter([$street_line, $city_line], static fn(string $part): bool => $part !== '')));
        if ($address !== '' && !self::has_meaningful_meta_value($user_id, 'address')) {
            $compatibility['address'] = $address;
        }

        if ((int) $projected(UserManagement::META_TERMS_ACCEPTED_AT) > 0
            && !self::has_meaningful_meta_value($user_id, 'terms_accepted')) {
            $compatibility['terms_accepted'] = 'yes';
        }

        return $compatibility;
    }

    /**
     * @param array{key: string, value: string}|null $legacy_status
     * @param array<string, string> $field_sources
     * @param array<string, string> $consent_sources
     * @return array<string, mixed>|null
     */
    private static function build_provenance(
        int $user_id,
        int $now,
        ?array $legacy_status,
        string $status_mapping,
        bool $grandfathered,
        array $field_sources,
        array $consent_sources
    ): ?array {
        $existing = get_user_meta($user_id, UserManagement::META_LEGACY_MIGRATION_PROVENANCE, true);
        $existing = self::maybe_unserialize_value($existing);
        $provenance = is_array($existing) ? $existing : [];
        $original = $provenance;

        if (!isset($provenance['source'])) {
            $provenance['source'] = self::SOURCE_NAME;
        }
        if (!isset($provenance['migration_version'])) {
            $provenance['migration_version'] = self::MIGRATION_VERSION;
        }
        if (!isset($provenance['migrated_at'])) {
            $provenance['migrated_at'] = $now;
        }

        if ($legacy_status !== null && !isset($provenance['legacy_account_status'])) {
            $provenance['legacy_account_status'] = sanitize_key($legacy_status['value']);
        }
        if ($status_mapping !== '' && !isset($provenance['status_mapping'])) {
            $provenance['status_mapping'] = $status_mapping;
        }
        if ($grandfathered && empty($provenance['grandfathered'])) {
            $provenance['grandfathered'] = true;
            $provenance['grandfathered_at'] = $now;
        }

        $provenance['field_sources'] = self::merge_source_maps($provenance['field_sources'] ?? [], $field_sources);
        $provenance['consent_sources'] = self::merge_source_maps($provenance['consent_sources'] ?? [], $consent_sources);

        return $provenance !== $original ? $provenance : null;
    }

    /**
     * @param mixed $existing
     * @param array<string, string> $additional
     * @return array<string, string>
     */
    private static function merge_source_maps($existing, array $additional): array
    {
        $merged = is_array($existing) ? $existing : [];
        foreach ($additional as $target => $source) {
            if (!isset($merged[$target])) {
                $merged[$target] = $source;
            }
        }
        ksort($merged);

        return $merged;
    }

    private static function map_account_status(string $legacy_status): string
    {
        switch (sanitize_key($legacy_status)) {
            case 'approved':
                return 'active';
            case 'inactive':
                return 'declined';
            case 'awaiting_email_confirmation':
                return 'pending';
            default:
                return 'pending';
        }
    }

    /**
     * @param list<string> $source_keys
     * @return array{key: string, value: string}|null
     */
    private static function find_text_source(int $user_id, array $source_keys): ?array
    {
        foreach ($source_keys as $source_key) {
            if (!is_string($source_key) || $source_key === '') {
                continue;
            }

            $value = get_user_meta($user_id, $source_key, true);
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return ['key' => $source_key, 'value' => $value];
            }
        }

        return null;
    }

    /**
     * @param list<string> $direct_keys
     * @return array{timestamp: int, source: string}|null
     */
    private static function find_consent_timestamp(
        int $user_id,
        array $direct_keys,
        string $snapshot_key,
        int $now
    ): ?array {
        foreach ($direct_keys as $source_key) {
            $timestamp = self::normalize_consent_timestamp(get_user_meta($user_id, $source_key, true), $now);
            if ($timestamp !== null) {
                return ['timestamp' => $timestamp, 'source' => $source_key];
            }
        }

        foreach (['submitted', 'submitted_backup'] as $snapshot_meta_key) {
            $snapshot = self::maybe_unserialize_value(get_user_meta($user_id, $snapshot_meta_key, true));
            if (!is_array($snapshot) || !array_key_exists($snapshot_key, $snapshot)) {
                continue;
            }

            $timestamp = self::normalize_consent_timestamp($snapshot[$snapshot_key], $now);
            if ($timestamp !== null) {
                return [
                    'timestamp' => $timestamp,
                    'source' => $snapshot_meta_key . '.' . $snapshot_key,
                ];
            }
        }

        return null;
    }

    /**
     * Boolean legacy evidence is intentionally not converted into a made-up timestamp.
     *
     * @param mixed $value
     */
    private static function normalize_consent_timestamp($value, int $now): ?int
    {
        if (is_bool($value) || !is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $timestamp = (int) $value;
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T\s].*)?$/', $value)) {
                return null;
            }
            try {
                $timestamp = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
            } catch (\Exception $exception) {
                return null;
            }
        }

        if ($timestamp < self::EARLIEST_VALID_TIMESTAMP || $timestamp > $now + DAY_IN_SECONDS) {
            return null;
        }

        return $timestamp;
    }

    private static function normalize_legacy_timestamp(string $value, int $now): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $timestamp = (int) $value;
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T\s].*)?$/', $value)) {
                return null;
            }

            try {
                $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
                $timestamp = (new \DateTimeImmutable($value, $timezone))->getTimestamp();
            } catch (\Exception $exception) {
                return null;
            }
        }

        if ($timestamp < self::EARLIEST_VALID_TIMESTAMP || $timestamp > $now + DAY_IN_SECONDS) {
            return null;
        }

        return $timestamp;
    }

    /**
     * @param array<mixed, mixed> $mapping
     * @return array<string, list<string>>
     */
    private static function sanitize_mapping(array $mapping): array
    {
        $sanitized = [];
        foreach ($mapping as $target_key => $source_keys) {
            if (!is_string($target_key) || strpos($target_key, self::META_KEY_PREFIX) !== 0) {
                continue;
            }

            $keys = [];
            foreach ((array) $source_keys as $source_key) {
                if (is_string($source_key) && $source_key !== '' && $source_key !== $target_key) {
                    $keys[] = $source_key;
                }
            }

            if ($keys !== []) {
                $sanitized[$target_key] = array_values(array_unique($keys));
            }
        }

        return $sanitized;
    }

    /**
     * @param list<int>|null $requested_user_ids
     * @return list<int>
     */
    private static function resolve_user_ids(?array $requested_user_ids): array
    {
        $users = $requested_user_ids ?? get_users([
            'fields' => 'ID',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $user_ids = [];
        foreach ((array) $users as $user) {
            $user_id = is_object($user) && isset($user->ID) ? (int) $user->ID : (int) $user;
            if ($user_id > 0) {
                $user_ids[] = $user_id;
            }
        }

        $user_ids = array_values(array_unique($user_ids));
        sort($user_ids, SORT_NUMERIC);

        return $user_ids;
    }

    private static function has_meaningful_meta_value(int $user_id, string $meta_key): bool
    {
        $value = get_user_meta($user_id, $meta_key, true);
        if (is_array($value)) {
            return $value !== [];
        }

        return !($value === null || $value === false || (is_string($value) && trim($value) === ''));
    }

    private static function has_positive_timestamp_meta(int $user_id, string $meta_key): bool
    {
        return (int) get_user_meta($user_id, $meta_key, true) > 0;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function maybe_unserialize_value($value)
    {
        return function_exists('maybe_unserialize') ? maybe_unserialize($value) : $value;
    }
}
