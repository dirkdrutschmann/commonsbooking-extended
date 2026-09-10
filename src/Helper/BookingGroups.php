<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

final class BookingGroups
{
    public const OPTION_KEY = 'booking_groups';

    /**
     * Normalize configured groups and ensure that every item belongs to at most one group.
     *
     * @param mixed $groups
     * @param callable|null $itemExists Receives an item ID and returns whether it is valid.
     * @return array<int, array{name: string, item_ids: array<int, int>}>
     */
    public static function normalize($groups, ?callable $itemExists = null): array
    {
        if (!is_array($groups)) {
            return [];
        }

        $normalizedGroups = [];
        $claimedItemIds = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $name = trim((string) ($group['name'] ?? ''));
            $rawItemIds = $group['item_ids'] ?? [];
            if (!is_array($rawItemIds)) {
                $rawItemIds = [$rawItemIds];
            }

            $itemIds = [];
            foreach ($rawItemIds as $rawItemId) {
                $itemId = abs((int) $rawItemId);
                if (
                    $itemId <= 0
                    || isset($claimedItemIds[$itemId])
                    || isset($itemIds[$itemId])
                    || ($itemExists !== null && !$itemExists($itemId))
                ) {
                    continue;
                }

                $itemIds[$itemId] = $itemId;
            }

            $itemIds = array_values($itemIds);
            if (count($itemIds) < 2) {
                continue;
            }

            if ($name === '') {
                $name = 'Verbund ' . (count($normalizedGroups) + 1);
            }

            foreach ($itemIds as $itemId) {
                $claimedItemIds[$itemId] = true;
            }

            $normalizedGroups[] = [
                'name' => $name,
                'item_ids' => $itemIds,
            ];
        }

        return $normalizedGroups;
    }

    /**
     * Collapse matching bookings from one group into a single limit interval.
     *
     * Only intervals with the same group, location, start and end are combined.
     * A single booking from a group remains unchanged.
     *
     * @param array<int, array<string, mixed>> $intervals
     * @param array<int, array{name: string, item_ids: array<int, int>}> $groups
     * @return array<int, array<string, mixed>>
     */
    public static function collapseIntervals(array $intervals, array $groups): array
    {
        $itemGroupMap = self::buildItemGroupMap($groups);
        if (!$itemGroupMap) {
            return array_values($intervals);
        }

        $collapsedIntervals = [];
        $groupedIntervalIndexes = [];

        foreach ($intervals as $interval) {
            $itemId = abs((int) ($interval['item_id'] ?? 0));
            $locationId = abs((int) ($interval['location_id'] ?? 0));
            $start = (int) ($interval['start'] ?? 0);
            $end = (int) ($interval['end'] ?? 0);
            $group = $itemGroupMap[$itemId] ?? null;

            if ($group === null || $locationId <= 0 || $start <= 0 || $end <= 0) {
                $collapsedIntervals[] = $interval;
                continue;
            }

            $intervalKey = implode(':', [
                $group['key'],
                $locationId,
                $start,
                $end,
            ]);

            if (!isset($groupedIntervalIndexes[$intervalKey])) {
                $groupedIntervalIndexes[$intervalKey] = count($collapsedIntervals);
                $collapsedIntervals[] = $interval;
                continue;
            }

            $existingIndex = $groupedIntervalIndexes[$intervalKey];
            $existingInterval = $collapsedIntervals[$existingIndex];
            $groupedItemIds = $existingInterval['grouped_item_ids'] ?? [
                abs((int) ($existingInterval['item_id'] ?? 0)),
            ];
            if (in_array($itemId, $groupedItemIds, true)) {
                $collapsedIntervals[] = $interval;
                continue;
            }

            $groupedItemIds[] = $itemId;
            $groupedItemIds = array_values(array_unique(array_filter($groupedItemIds)));

            $existingInterval['grouped_item_ids'] = $groupedItemIds;
            $existingInterval['item_name'] = $group['name'];

            if (
                empty($existingInterval['booking'])
                && !empty($interval['booking'])
            ) {
                $existingInterval['booking'] = $interval['booking'];
            }

            $collapsedIntervals[$existingIndex] = $existingInterval;
        }

        return array_values($collapsedIntervals);
    }

    /**
     * @param array<int, array{name: string, item_ids: array<int, int>}> $groups
     * @return array<int, array{key: string, name: string}>
     */
    private static function buildItemGroupMap(array $groups): array
    {
        $map = [];

        foreach (self::normalize($groups) as $group) {
            $keyItemIds = $group['item_ids'];
            sort($keyItemIds, SORT_NUMERIC);
            $groupKey = 'group-' . implode('-', $keyItemIds);

            foreach ($group['item_ids'] as $itemId) {
                $map[$itemId] = [
                    'key' => $groupKey,
                    'name' => $group['name'],
                ];
            }
        }

        return $map;
    }
}
