<?php

declare(strict_types=1);

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Support;

/**
 * Fail-safe boundaries for staging/development communication.
 *
 * Mail redirection requires LASTENRAD_DEV_MAIL_SINK and fails closed when the
 * sink is missing or invalid. External message delivery is denied outside
 * production before a provider
 * request is created. A staging installation may explicitly enable SMS via
 * the server-only LASTENRAD_DEV_SMS_DELIVERY_ENABLED constant. Signal remains
 * blocked in every non-production environment.
 */
final class EnvironmentSafety
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_filter('wp_mail', [self::class, 'redirect_mail_to_sink'], PHP_INT_MAX);
        add_filter('pre_wp_mail', [self::class, 'block_mail_for_invalid_sink'], PHP_INT_MAX, 2);
        add_filter(
            'afcb_external_message_delivery_allowed',
            [self::class, 'allow_external_message_delivery'],
            10,
            5
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function redirect_mail_to_sink(array $args): array
    {
        if (!self::is_non_production_environment()) {
            return $args;
        }

        $sink = self::mail_sink();
        if ($sink === null) {
            return $args;
        }

        $already_redirected = self::has_redirect_marker($args['headers'] ?? []);
        $original_to = $already_redirected ? '' : self::format_recipient_header($args['to'] ?? '');
        $args['to'] = $sink;
        $args['headers'] = self::sanitize_redirect_headers($args['headers'] ?? [], $original_to);

        if ($already_redirected) {
            return $args;
        }

        $prefix = '[' . strtoupper(self::environment_type()) . ' ' . self::site_key() . '] ';
        $subject = isset($args['subject']) ? (string) $args['subject'] : '';
        if (strpos($subject, $prefix) !== 0) {
            $args['subject'] = $prefix . $subject;
        }

        return $args;
    }

    /**
     * Missing and invalid sinks both fail closed. An MU redirect may run first,
     * but the plugin must also be safe when deployed without that extra layer.
     *
     * @param mixed $short_circuit
     * @param array<string, mixed> $mail
     * @return mixed
     */
    public static function block_mail_for_invalid_sink($short_circuit, array $mail)
    {
        if ($short_circuit !== null || !self::is_non_production_environment()) {
            return $short_circuit;
        }
        if (self::mail_sink() !== null) {
            return $short_circuit;
        }

        error_log('[AFCB Safety] Staging mail blocked because LASTENRAD_DEV_MAIL_SINK is missing or invalid.');

        return false;
    }

    /**
     * @param mixed $allowed
     * @param array<string, mixed> $context
     */
    public static function allow_external_message_delivery(
        $allowed,
        string $channel,
        string $phone,
        string $message,
        array $context
    ): bool {
        return self::resolve_external_message_delivery(
            (bool) $allowed,
            self::environment_type(),
            $channel,
            self::dev_sms_delivery_enabled()
        );
    }

    public static function resolve_external_message_delivery(
        bool $already_allowed,
        string $environment,
        string $channel,
        bool $dev_sms_enabled
    ): bool {
        if (!$already_allowed) {
            return false;
        }

        $environment = function_exists('sanitize_key')
            ? sanitize_key($environment)
            : strtolower(trim($environment));
        if (!in_array($environment, ['local', 'development', 'staging'], true)) {
            return true;
        }

        $channel = function_exists('sanitize_key')
            ? sanitize_key($channel)
            : strtolower(trim($channel));

        return $dev_sms_enabled && $channel === 'sms';
    }

    public static function is_non_production_environment(): bool
    {
        return in_array(self::environment_type(), ['local', 'development', 'staging'], true);
    }

    private static function environment_type(): string
    {
        if (function_exists('wp_get_environment_type')) {
            $environment = (string) wp_get_environment_type();
        } elseif (defined('WP_ENVIRONMENT_TYPE')) {
            $environment = (string) WP_ENVIRONMENT_TYPE;
        } else {
            $environment = 'production';
        }

        $environment = function_exists('sanitize_key') ? sanitize_key($environment) : strtolower(trim($environment));

        return $environment !== '' ? $environment : 'production';
    }

    private static function site_key(): string
    {
        $site_key = defined('LASTENRAD_SITE_KEY') ? (string) LASTENRAD_SITE_KEY : 'lastenrad';
        $site_key = function_exists('sanitize_key') ? sanitize_key($site_key) : strtolower(trim($site_key));

        return $site_key !== '' ? $site_key : 'lastenrad';
    }

    private static function dev_sms_delivery_enabled(): bool
    {
        return defined('LASTENRAD_DEV_SMS_DELIVERY_ENABLED')
            && LASTENRAD_DEV_SMS_DELIVERY_ENABLED === true;
    }

    private static function mail_sink(): ?string
    {
        if (!defined('LASTENRAD_DEV_MAIL_SINK')) {
            return null;
        }

        $sink = trim((string) LASTENRAD_DEV_MAIL_SINK);
        if ($sink === '') {
            return null;
        }

        $sink = function_exists('sanitize_email') ? sanitize_email($sink) : $sink;
        $valid = function_exists('is_email') ? is_email($sink) : filter_var($sink, FILTER_VALIDATE_EMAIL);

        return $valid ? $sink : null;
    }

    /**
     * @param mixed $recipients
     */
    private static function format_recipient_header($recipients): string
    {
        if (is_array($recipients)) {
            $recipients = implode(', ', array_map('strval', $recipients));
        } elseif (!is_scalar($recipients)) {
            $recipients = '';
        }

        $recipients = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', (string) $recipients);

        return mb_substr(trim((string) $recipients), 0, 900);
    }

    /**
     * @param mixed $headers
     */
    private static function has_redirect_marker($headers): bool
    {
        if (is_string($headers)) {
            $headers = preg_split('/\r?\n/', $headers) ?: [];
        }

        foreach ((array) $headers as $name => $header) {
            if (!is_int($name)) {
                $header = (string) $name . ': ' . (is_scalar($header) ? (string) $header : '');
            }
            if (is_scalar($header) && preg_match('/^X-Lastenrad-Dev-Redirected\s*:\s*1\s*$/i', trim((string) $header))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $headers
     * @return list<string>
     */
    private static function sanitize_redirect_headers($headers, string $original_to): array
    {
        if (is_string($headers)) {
            $headers = preg_split('/\r?\n/', $headers) ?: [];
        }

        $normalized = [];
        foreach ((array) $headers as $name => $header) {
            if (!is_int($name)) {
                if (!is_scalar($header)) {
                    continue;
                }
                $header = (string) $name . ': ' . (string) $header;
            }
            if (!is_scalar($header)) {
                continue;
            }

            $header = trim((string) $header);
            if ($header === '' || preg_match('/^(?:cc|bcc|x-afcb-original-to)\s*:/i', $header)) {
                continue;
            }
            $normalized[] = $header;
        }

        if ($original_to !== '') {
            $normalized[] = 'X-AFCB-Original-To: ' . $original_to;
        }

        return $normalized;
    }
}
