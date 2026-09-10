<?php

declare(strict_types=1);

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement;

/**
 * Central authorization policy for AFCB account states.
 */
final class AccountStatusPolicy
{
    public static function can_start_session(string $status, bool $email_verified): bool
    {
        return self::normalize($status) === 'active' && $email_verified;
    }

    public static function can_resend_email_verification(string $status, bool $email_verified): bool
    {
        return self::normalize($status) === 'pending' && !$email_verified;
    }

    /**
     * Email verification activates only accounts without a separate review hold.
     */
    public static function status_after_email_verification(string $status, string $review_reason): string
    {
        $status = self::normalize($status);
        $review_reason = self::normalize($review_reason);

        if ($status === 'pending' && $review_reason === '') {
            return 'active';
        }

        return $status;
    }

    private static function normalize(string $value): string
    {
        return function_exists('sanitize_key')
            ? sanitize_key($value)
            : (preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($value))) ?? '');
    }
}
