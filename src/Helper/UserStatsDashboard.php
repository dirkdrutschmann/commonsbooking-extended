<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

use DateTimeImmutable;
use DateTimeZone;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\UserManagement;
use WP_User;

class UserStatsDashboard
{
    private const TRANSIENT_KEY = 'afcb_user_stats_dashboard';
    private const CACHE_SECONDS = 300;

    public static function get_stats(bool $force = false): array
    {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached)) {
                return self::normalize_stats($cached);
            }
        }

        $stats = self::normalize_stats(self::compute_stats());
        set_transient(self::TRANSIENT_KEY, $stats, self::CACHE_SECONDS);

        return $stats;
    }

    private static function compute_stats(): array
    {
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $timezone);
        $start_day = $now->setTime(0, 0, 0);
        $start_week = self::get_start_of_week($start_day);
        $start_month = $start_day->modify('first day of this month');
        $start_year = $start_day->setDate((int) $start_day->format('Y'), 1, 1);

        $rolling_7 = $now->modify('-7 days');
        $rolling_30 = $now->modify('-30 days');
        $rolling_90 = $now->modify('-90 days');

        $options = UserManagement::get_options();
        $required_fields = isset($options['required_fields']) && is_array($options['required_fields']) ? $options['required_fields'] : [];

        $stats = self::get_default_stats($now->getTimestamp());

        $users = get_users([
            'fields' => 'all_with_meta',
        ]);

        $stats['total_users'] = count($users);

        foreach ($users as $user) {
            if (!$user instanceof WP_User) {
                continue;
            }

            $registered_at = strtotime($user->user_registered ?? '') ?: 0;
            if ($registered_at) {
                if ($registered_at >= $start_day->getTimestamp()) {
                    $stats['registrations']['today']++;
                }
                if ($registered_at >= $start_week->getTimestamp()) {
                    $stats['registrations']['week']++;
                }
                if ($registered_at >= $start_month->getTimestamp()) {
                    $stats['registrations']['month']++;
                }
                if ($registered_at >= $start_year->getTimestamp()) {
                    $stats['registrations']['year']++;
                }
                if ($registered_at >= $rolling_7->getTimestamp()) {
                    $stats['registrations']['last_7_days']++;
                }
                if ($registered_at >= $rolling_30->getTimestamp()) {
                    $stats['registrations']['last_30_days']++;
                }
            }

            $last_login = self::get_last_login($user);
            if ($last_login > 0) {
                if ($last_login >= $start_day->getTimestamp()) {
                    $stats['activity']['today']++;
                }
                if ($last_login >= $start_week->getTimestamp()) {
                    $stats['activity']['week']++;
                }
                if ($last_login >= $start_month->getTimestamp()) {
                    $stats['activity']['month']++;
                }
                if ($last_login >= $rolling_7->getTimestamp()) {
                    $stats['activity']['last_7_days']++;
                }
                if ($last_login >= $rolling_30->getTimestamp()) {
                    $stats['activity']['last_30_days']++;
                }
                if ($last_login < $rolling_90->getTimestamp()) {
                    $stats['login']['inactive_90_days']++;
                }
            } else {
                $stats['login']['never_logged_in']++;
            }

            $email_verified = (bool) $user->get(UserManagement::META_EMAIL_VERIFIED_AT);
            $phone_verified = UserManagement::is_phone_verified($user->ID);

            if ($email_verified) {
                $stats['verification']['email_verified']++;
            }
            if ($phone_verified) {
                $stats['verification']['phone_verified']++;
            }

            $status = (string) $user->get(UserManagement::META_ACCOUNT_STATUS);
            if ($status === 'pending') {
                $stats['status']['pending']++;
            } elseif ($status === 'declined') {
                $stats['status']['declined']++;
            }

            $has_required = self::has_required_fields($user, $required_fields);
            if (!$has_required) {
                $stats['verification']['missing_required']++;
            }

            $effective_status = $status ?: 'active';
            if ($effective_status === 'active' && $email_verified && $phone_verified && $has_required) {
                $stats['verification']['fully_authenticated']++;
            }
        }

        return $stats;
    }

    private static function normalize_stats(array $stats): array
    {
        $defaults = self::get_default_stats($stats['generated_at'] ?? time());

        return array_replace_recursive($defaults, $stats);
    }

    private static function get_default_stats(int $generated_at): array
    {
        return [
            'total_users' => 0,
            'registrations' => [
                'today' => 0,
                'week' => 0,
                'month' => 0,
                'year' => 0,
                'last_7_days' => 0,
                'last_30_days' => 0,
            ],
            'activity' => [
                'today' => 0,
                'week' => 0,
                'month' => 0,
                'last_7_days' => 0,
                'last_30_days' => 0,
            ],
            'verification' => [
                'email_verified' => 0,
                'phone_verified' => 0,
                'fully_authenticated' => 0,
                'missing_required' => 0,
            ],
            'status' => [
                'pending' => 0,
                'declined' => 0,
            ],
            'login' => [
                'never_logged_in' => 0,
                'inactive_90_days' => 0,
            ],
            'generated_at' => $generated_at,
        ];
    }

    private static function get_start_of_week(DateTimeImmutable $date): DateTimeImmutable
    {
        $start_of_week = (int) get_option('start_of_week', 1);
        $week_day = (int) $date->format('w');
        $diff = ($week_day - $start_of_week + 7) % 7;

        return $date->modify('-' . $diff . ' days');
    }

    private static function get_last_login(WP_User $user): int
    {
        $last_login = (int) $user->get(UserManagement::META_LAST_LOGIN);
        if ($last_login > 0) {
            return $last_login;
        }
        return (int) $user->get('when_last_login');
    }

    private static function has_required_fields(WP_User $user, array $required_fields): bool
    {
        foreach ($required_fields as $field) {
            $value = '';
            switch ($field) {
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
                    $value = (string) $user->get($field);
                    break;
            }

            if ('' === trim($value)) {
                return false;
            }
        }

        return true;
    }
}
