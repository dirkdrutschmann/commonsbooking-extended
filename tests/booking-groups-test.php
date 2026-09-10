<?php

declare(strict_types=1);

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\BookingGroups;

$GLOBALS['afcb_booking_group_posts'] = [];
$GLOBALS['afcb_booking_group_settings_errors'] = [];

final class WP_Post
{
    public int $ID;
    public string $post_type;

    public function __construct(int $id, string $postType = 'cb_item')
    {
        $this->ID = $id;
        $this->post_type = $postType;
    }
}

function add_action(string $hook, $callback): void
{
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

function wp_unslash($value)
{
    return $value;
}

function absint($value): int
{
    return abs((int) $value);
}

function wp_kses_post(string $value): string
{
    return $value;
}

function get_post(int $itemId)
{
    return $GLOBALS['afcb_booking_group_posts'][$itemId] ?? null;
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    $GLOBALS['afcb_booking_group_settings_errors'][] = compact('setting', 'code', 'message', 'type');
}

function __(string $text, string $domain = ''): string
{
    return $text;
}

function afcb_booking_groups_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

require dirname(__DIR__) . '/src/Helper/BookingGroups.php';
require dirname(__DIR__) . '/src/Admin/Pages/BlacklistSettingsPage.php';

$normalizedGroups = BookingGroups::normalize(
    [
        [
            'name' => 'Fahrrad + Anhänger',
            'item_ids' => [10, '11', 11],
        ],
        [
            'name' => 'Ungültige Überschneidung',
            'item_ids' => [11, 12],
        ],
        [
            'name' => 'Weiterer Verbund',
            'item_ids' => [12, 13],
        ],
        [
            'name' => 'Zu klein',
            'item_ids' => [14],
        ],
        [
            'name' => '',
            'item_ids' => [20, 21],
        ],
    ],
    static fn(int $itemId): bool => $itemId !== 13
);

afcb_booking_groups_assert_same(
    [
        [
            'name' => 'Fahrrad + Anhänger',
            'item_ids' => [10, 11],
        ],
        [
            'name' => 'Verbund 2',
            'item_ids' => [20, 21],
        ],
    ],
    $normalizedGroups,
    'Normalization must remove duplicate, invalid and undersized groups without claiming items from rejected groups.'
);

$booking = (object) ['id' => 77];
$collapsed = BookingGroups::collapseIntervals(
    [
        [
            'start' => 1700000000,
            'end' => 1700086400,
            'item_id' => 10,
            'location_id' => 5,
            'booking' => $booking,
        ],
        [
            'start' => 1700000000,
            'end' => 1700086400,
            'item_id' => 11,
            'location_id' => 5,
            'booking' => null,
        ],
        [
            'start' => 1700000000,
            'end' => 1700086400,
            'item_id' => 99,
            'location_id' => 5,
            'booking' => null,
        ],
    ],
    [
        [
            'name' => 'Fahrrad + Anhänger',
            'item_ids' => [10, 11],
        ],
    ]
);

afcb_booking_groups_assert_same(2, count($collapsed), 'Matching grouped articles must consume one interval.');
afcb_booking_groups_assert_same(
    'Fahrrad + Anhänger',
    $collapsed[0]['item_name'] ?? '',
    'A collapsed interval must expose the configured group name.'
);
afcb_booking_groups_assert_same(
    [10, 11],
    $collapsed[0]['grouped_item_ids'] ?? [],
    'A collapsed interval must retain all grouped item IDs.'
);
afcb_booking_groups_assert_same(
    $booking,
    $collapsed[0]['booking'] ?? null,
    'A collapsed interval must preserve an existing booking for the frontend explanation.'
);

$notCollapsed = BookingGroups::collapseIntervals(
    [
        [
            'start' => 1700000000,
            'end' => 1700086400,
            'item_id' => 10,
            'location_id' => 5,
            'booking' => null,
        ],
        [
            'start' => 1700000000,
            'end' => 1700086400,
            'item_id' => 11,
            'location_id' => 6,
            'booking' => null,
        ],
        [
            'start' => 1700000100,
            'end' => 1700086500,
            'item_id' => 11,
            'location_id' => 5,
            'booking' => null,
        ],
        [
            'start' => 1700000000,
            'end' => 1700086400,
            'item_id' => 10,
            'location_id' => 5,
            'booking' => null,
        ],
    ],
    [
        [
            'name' => 'Fahrrad + Anhänger',
            'item_ids' => [10, 11],
        ],
    ]
);

afcb_booking_groups_assert_same(
    4,
    count($notCollapsed),
    'Different locations, booking periods or duplicate bookings of the same item must continue to count separately.'
);
afcb_booking_groups_assert_same(
    false,
    isset($notCollapsed[0]['item_name']),
    'A single article booking must not be presented as the whole group.'
);

$GLOBALS['afcb_booking_group_posts'] = [
    10 => new WP_Post(10),
    11 => new WP_Post(11),
    12 => new WP_Post(12),
    99 => new WP_Post(99, 'post'),
];

$settingsPage = new DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages\BlacklistSettingsPage();
$sanitized = $settingsPage->sanitize([
    'blacklist_location_active' => '1',
    'blacklist_location' => '3',
    'blacklist_location_interval' => '30',
    BookingGroups::OPTION_KEY => [
        [
            'name' => '<strong>Fahrrad + Anhänger</strong>',
            'item_ids' => ['10', '11'],
        ],
        [
            'name' => 'Doppelbelegung',
            'item_ids' => ['11', '12'],
        ],
        [
            'name' => 'Falscher Beitragstyp',
            'item_ids' => ['12', '99'],
        ],
    ],
]);

afcb_booking_groups_assert_same(
    'on',
    $sanitized['blacklist_location_active'] ?? '',
    'Enabled restriction checkboxes must be stored in the format expected by the listener.'
);
afcb_booking_groups_assert_same(
    3,
    $sanitized['blacklist_location'] ?? null,
    'Restriction limits must be normalized to non-negative integers.'
);
afcb_booking_groups_assert_same(
    [
        [
            'name' => 'Fahrrad + Anhänger',
            'item_ids' => [10, 11],
        ],
    ],
    $sanitized[BookingGroups::OPTION_KEY] ?? [],
    'The settings page must sanitize names, reject non-items and prevent overlapping groups.'
);
afcb_booking_groups_assert_same(
    1,
    count($GLOBALS['afcb_booking_group_settings_errors']),
    'Invalid or overlapping group settings must produce one visible admin warning.'
);

fwrite(STDOUT, "PASS booking group normalization and interval collapsing\n");
