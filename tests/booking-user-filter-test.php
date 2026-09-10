<?php

declare(strict_types=1);

$GLOBALS['afcb_booking_filter_users'] = [];
$GLOBALS['pagenow'] = 'edit.php';

final class WP_User
{
    public int $ID;
    public string $user_login;
    public string $user_email;
    public string $display_name;
    public string $first_name;
    public string $last_name;

    public function __construct(
        int $id,
        string $userLogin,
        string $userEmail,
        string $displayName,
        string $firstName = '',
        string $lastName = ''
    ) {
        $this->ID = $id;
        $this->user_login = $userLogin;
        $this->user_email = $userEmail;
        $this->display_name = $displayName;
        $this->first_name = $firstName;
        $this->last_name = $lastName;
    }
}

final class WP_User_Query
{
    private array $args;

    public function __construct(array $args)
    {
        $this->args = $args;
    }

    public function get_results(): array
    {
        $term = '';
        foreach ($this->args['meta_query'] ?? [] as $condition) {
            if (is_array($condition) && isset($condition['value'])) {
                $term = (string) $condition['value'];
                break;
            }
        }

        $users = array_values(array_filter(
            $GLOBALS['afcb_booking_filter_users'],
            static function (WP_User $user) use ($term): bool {
                return stripos($user->first_name, $term) !== false
                    || stripos($user->last_name, $term) !== false;
            }
        ));

        usort($users, static fn(WP_User $left, WP_User $right): int => strcasecmp(
            $left->user_login,
            $right->user_login
        ));

        $users = array_slice($users, 0, (int) ($this->args['number'] ?? count($users)));

        if (($this->args['fields'] ?? '') === 'ID') {
            return array_map(static fn(WP_User $user): int => $user->ID, $users);
        }

        return $users;
    }
}

final class WP_Query
{
    private array $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function is_main_query(): bool
    {
        return true;
    }

    public function get(string $key)
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, $value): void
    {
        $this->values[$key] = $value;
    }
}

function add_action(string $hook, $callback): void
{
}

function is_admin(): bool
{
    return true;
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

function get_user_by(string $field, $value)
{
    if (strtoupper($field) !== 'ID') {
        return false;
    }

    return $GLOBALS['afcb_booking_filter_users'][(int) $value] ?? false;
}

function get_users(array $args = []): array
{
    $term = trim((string) ($args['search'] ?? ''), '*');
    $columns = $args['search_columns'] ?? ['user_login'];

    $users = array_values(array_filter(
        $GLOBALS['afcb_booking_filter_users'],
        static function (WP_User $user) use ($columns, $term): bool {
            foreach ($columns as $column) {
                if (isset($user->{$column}) && stripos((string) $user->{$column}, $term) !== false) {
                    return true;
                }
            }

            return false;
        }
    ));

    usort($users, static fn(WP_User $left, WP_User $right): int => strcasecmp(
        $left->user_login,
        $right->user_login
    ));

    $users = array_slice($users, 0, (int) ($args['number'] ?? count($users)));

    if (($args['fields'] ?? '') === 'ID') {
        return array_map(static fn(WP_User $user): int => $user->ID, $users);
    }

    return $users;
}

function afcb_booking_filter_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

require dirname(__DIR__) . '/src/Admin/BookingUserFilter.php';

$GLOBALS['afcb_booking_filter_users'] = [
    7 => new WP_User(7, 'anna', 'anna@example.test', 'Anna Meyer', 'Anna', 'Meyer'),
    8 => new WP_User(8, 'annabelle', 'user8@example.test', 'Annabelle König', 'Annabelle', 'König'),
    9 => new WP_User(9, 'bert', 'ann-team@example.test', 'Bert Beispiel', 'Bert', 'Beispiel'),
    10 => new WP_User(10, 'zeta', 'zeta@example.test', 'Zeta User', 'Annika', 'Schmidt'),
];

$filter = new DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\BookingUserFilter();
$findUsers = new ReflectionMethod($filter, 'findUsersForAutocomplete');
$formatResult = new ReflectionMethod($filter, 'formatAutocompleteResult');

$matches = $findUsers->invoke($filter, 'ann', 20);
afcb_booking_filter_assert_same(
    ['anna', 'annabelle', 'bert', 'zeta'],
    array_map(static fn(WP_User $user): string => $user->user_login, $matches),
    'Autocomplete must merge core fields and name metadata, remove duplicates and sort by username.'
);

$limitedMatches = $findUsers->invoke($filter, 'ann', 2);
afcb_booking_filter_assert_same(
    ['anna', 'annabelle'],
    array_map(static fn(WP_User $user): string => $user->user_login, $limitedMatches),
    'Autocomplete must enforce its result limit.'
);

afcb_booking_filter_assert_same(
    [
        'id' => 7,
        'label' => 'anna — Anna Meyer · anna@example.test',
        'value' => 'anna',
    ],
    $formatResult->invoke($filter, $GLOBALS['afcb_booking_filter_users'][7]),
    'Autocomplete results must use the login as value and expose enough context to distinguish users.'
);

$_GET = [
    'post_type' => 'cb_booking',
    'admin_filter_user' => 'ann',
    'admin_filter_user_id' => '7',
];
$query = new WP_Query([
    'post_type' => 'cb_booking',
    's' => 'ann',
]);
$filter->applyFilter($query);

afcb_booking_filter_assert_same(
    [7],
    $query->get('author__in'),
    'Selecting an autocomplete result must filter by the exact user ID.'
);
afcb_booking_filter_assert_same('', $query->get('s'), 'The booking title search must be cleared for a user filter.');

$_GET = [
    'post_type' => 'cb_booking',
    'admin_filter_user' => 'Schmidt',
];
$query = new WP_Query([
    'post_type' => 'cb_booking',
]);
$filter->applyFilter($query);

afcb_booking_filter_assert_same(
    [10],
    $query->get('author__in'),
    'Free text filtering by first or last name must remain available without an autocomplete selection.'
);

fwrite(STDOUT, "PASS booking user autocomplete and exact selection\n");
