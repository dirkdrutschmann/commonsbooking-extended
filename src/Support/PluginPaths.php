<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Support;

class PluginPaths
{
    public static function base_path(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function base_url(): string
    {
        return plugin_dir_url(self::base_path() . '/commonsbooking-extended.php');
    }

    public static function asset_path(string $relative): string
    {
        return self::base_path() . '/' . ltrim($relative, '/');
    }

    public static function asset_url(string $relative): string
    {
        return self::base_url() . ltrim($relative, '/');
    }
}
