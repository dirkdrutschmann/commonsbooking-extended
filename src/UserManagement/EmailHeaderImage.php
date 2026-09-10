<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement;

final class EmailHeaderImage
{
    public const MAIN_CID = 'afcb-main-lastenrad-logo';
    public const WETTERAU_CID = 'afcb-wetterau-lastenrad-logo';

    private const MAIN_NAME = 'main-lastenrad-logo-main-green.png';
    private const WETTERAU_NAME = 'wetterau-lastenrad-logo.png';

    public static function default_reference(string $site_key): string
    {
        return self::is_wetterau($site_key) ? self::WETTERAU_CID : self::MAIN_CID;
    }

    public static function normalize_reference(string $site_key, string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return self::default_reference($site_key);
        }

        $cid = preg_replace('/^cid:/i', '', $reference) ?? '';
        if (in_array($cid, [self::MAIN_CID, self::WETTERAU_CID], true)) {
            return self::default_reference($site_key);
        }

        if (self::is_wetterau($site_key) && self::is_legacy_wetterau_logo_url($reference)) {
            return self::WETTERAU_CID;
        }

        return $reference;
    }

    public static function inline_attachment(
        string $site_key,
        string $reference,
        string $message,
        string $main_image_path,
        string $uploads_base_dir
    ): ?array {
        $reference = self::normalize_reference($site_key, $reference);
        if (preg_match('/^https?:\/\//i', $reference)) {
            return null;
        }

        $cid = preg_replace('/^cid:/i', '', $reference) ?? '';
        $definition = self::definition($site_key, $main_image_path, $uploads_base_dir);
        if ($cid !== $definition['cid'] || strpos($message, 'cid:' . $cid) === false) {
            return null;
        }

        return $definition;
    }

    public static function definition(
        string $site_key,
        string $main_image_path,
        string $uploads_base_dir
    ): array {
        if (self::is_wetterau($site_key)) {
            return [
                'cid' => self::WETTERAU_CID,
                'path' => dirname($main_image_path) . DIRECTORY_SEPARATOR . self::WETTERAU_NAME,
                'name' => self::WETTERAU_NAME,
                'mime' => 'image/png',
            ];
        }

        return [
            'cid' => self::MAIN_CID,
            'path' => $main_image_path,
            'name' => self::MAIN_NAME,
            'mime' => 'image/png',
        ];
    }

    private static function is_wetterau(string $site_key): bool
    {
        return strtolower(trim($site_key)) === 'wetterau';
    }

    private static function is_legacy_wetterau_logo_url(string $reference): bool
    {
        if (!preg_match('/^https?:\/\//i', $reference)) {
            return false;
        }

        $path = parse_url($reference, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        return (bool) preg_match('#/wp-content/uploads/logo\.(?:png|svg)$#i', $path);
    }
}
