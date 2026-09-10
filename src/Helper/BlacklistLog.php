<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

class BlacklistLog
{
    private const OPTION_KEY = 'commonbookings_additional_features_blacklist_log';
    private const MAX_ENTRIES = 250;

    public static function addEntry(array $entry): void
    {
        $defaults = [
            'timestamp' => current_time('timestamp'),
            'user_id' => 0,
            'item_id' => 0,
            'location_id' => 0,
            'rule' => '',
            'start' => 0,
            'end' => 0,
        ];

        $entry = array_merge($defaults, $entry);

        $log = get_option(self::OPTION_KEY, []);
        if (!is_array($log)) {
            $log = [];
        }

        $log[] = $entry;

        if (count($log) > self::MAX_ENTRIES) {
            $log = array_slice($log, -self::MAX_ENTRIES);
        }

        update_option(self::OPTION_KEY, $log, false);
    }

    public static function getEntries(): array
    {
        $log = get_option(self::OPTION_KEY, []);
        if (!is_array($log)) {
            return [];
        }

        return $log;
    }

    public static function getRecentEntries(int $limit = 50): array
    {
        $entries = array_reverse(self::getEntries());

        if ($limit > 0) {
            return array_slice($entries, 0, $limit);
        }

        return $entries;
    }

    public static function getStats(): array
    {
        $stats = [];

        foreach (self::getEntries() as $entry) {
            $userId = isset($entry['user_id']) ? (int) $entry['user_id'] : 0;

            if (!isset($stats[$userId])) {
                $stats[$userId] = [
                    'total' => 0,
                    'rules' => [],
                    'last_attempt' => 0,
                ];
            }

            $stats[$userId]['total']++;

            $rule = $entry['rule'] ?? 'unknown';
            if (!isset($stats[$userId]['rules'][$rule])) {
                $stats[$userId]['rules'][$rule] = 0;
            }
            $stats[$userId]['rules'][$rule]++;

            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            if ($timestamp > $stats[$userId]['last_attempt']) {
                $stats[$userId]['last_attempt'] = $timestamp;
            }
        }

        return $stats;
    }
}
