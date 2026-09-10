<?php

declare(strict_types=1);

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement;

/**
 * Authenticates credentials without creating a session and handles the only
 * allowed unauthenticated resend case: a pending account with a valid password.
 */
final class PendingEmailVerificationLogin
{
    public const STATE_AUTHENTICATION_FAILED = 'authentication_failed';
    public const STATE_VERIFIED = 'verified';
    public const STATE_UNVERIFIED = 'unverified';
    public const STATE_RESENT = 'resent';
    public const STATE_COOLDOWN = 'cooldown';
    public const STATE_SEND_FAILED = 'send_failed';

    /**
     * No authentication cookie is created by this method.
     *
     * @param callable(int): bool $send_verification
     * @return array{state: string, user: mixed}
     */
    public static function attempt(
        string $login,
        string $password,
        int $now,
        int $cooldown_seconds,
        callable $send_verification
    ): array {
        $user = wp_authenticate($login, $password);
        if (is_wp_error($user)) {
            return [
                'state' => self::STATE_AUTHENTICATION_FAILED,
                'user' => $user,
            ];
        }

        $user_id = isset($user->ID) ? (int) $user->ID : 0;
        if ($user_id <= 0) {
            return [
                'state' => self::STATE_AUTHENTICATION_FAILED,
                'user' => $user,
            ];
        }

        $email_verified = (int) get_user_meta($user_id, UserManagement::META_EMAIL_VERIFIED_AT, true) > 0;
        $status = sanitize_key((string) get_user_meta($user_id, UserManagement::META_ACCOUNT_STATUS, true));

        if (AccountStatusPolicy::can_start_session($status, $email_verified)) {
            return [
                'state' => self::STATE_VERIFIED,
                'user' => $user,
            ];
        }

        if (!AccountStatusPolicy::can_resend_email_verification($status, $email_verified)) {
            return [
                'state' => self::STATE_UNVERIFIED,
                'user' => $user,
            ];
        }

        $last_sent = (int) get_user_meta($user_id, UserManagement::META_EMAIL_LAST_SENT, true);
        if ($last_sent > 0 && ($now - $last_sent) < max(1, $cooldown_seconds)) {
            return [
                'state' => self::STATE_COOLDOWN,
                'user' => $user,
            ];
        }

        return [
            'state' => $send_verification($user_id) ? self::STATE_RESENT : self::STATE_SEND_FAILED,
            'user' => $user,
        ];
    }
}
