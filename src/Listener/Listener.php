<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Listener;

use CommonsBooking\Model\Booking as ModelBooking;
use CommonsBooking\Model\Item as ItemModel;
use CommonsBooking\Model\Location as LocationModel;
use CommonsBooking\Model\Timeframe;
use CommonsBooking\Wordpress\CustomPostType\Booking as BookingPostType;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\BlacklistLog;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\BlacklistSettings;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\BookingGroups;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\Log;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\UserManagement;

class Listener
{
    private bool $hasValidatedRequest = false;

    public function __construct()
    {
        add_action('init', [$this, 'handleFrontendBooking'], 40);
    }

    public function handleFrontendBooking(): void
    {
        if ($this->hasValidatedRequest || !is_user_logged_in()) {
            return;
        }

        if (!$this->isBookingRequest()) {
            return;
        }

        $this->hasValidatedRequest = true;

        $request = $this->parseRequest();

        if (!$request) {
            return;
        }

        if (!$this->isUserFullyVerified($request['user_id'])) {
            $message = __('Bitte bestätige zuerst deine E-Mail-Adresse und deine Telefonnummer im Profil, um buchen zu können.', 'cb-additional-features');
            set_transient(
                BookingPostType::ERROR_TYPE . '-' . $request['user_id'],
                $message,
                30
            );
            $redirectUrl = add_query_arg('cb-location', $request['location_id'], get_permalink($request['item_id']));
            wp_safe_redirect($redirectUrl);
            exit;
        }

        $context = $this->evaluateRules($request);

        if (!$context) {
            return;
        }

        $this->logBlockedAttempt($request, $context);

        $message = $this->buildNoticeMessage($request, $context);

        set_transient(
            BookingPostType::ERROR_TYPE . '-' . $request['user_id'],
            $message,
            30
        );

        $redirectUrl = add_query_arg('cb-location', $request['location_id'], get_permalink($request['item_id']));
        wp_safe_redirect($redirectUrl);
        exit;
    }

    private function isUserFullyVerified(int $user_id): bool
    {
        $email_verified = (bool) get_user_meta($user_id, UserManagement::META_EMAIL_VERIFIED_AT, true);
        $phone_verified = UserManagement::is_phone_verified($user_id);
        return $email_verified && $phone_verified;
    }

    private function isBookingRequest(): bool
    {
        if (!function_exists('wp_verify_nonce')) {
            return false;
        }

        $nonceId = BookingPostType::getWPNonceId();
        $action = BookingPostType::getWPAction();

        if (
            empty($_REQUEST[$nonceId]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST[$nonceId])), $action)
        ) {
            return false;
        }

        return isset($_REQUEST['booking-update']);
    }

    private function parseRequest(): ?array
    {
        $itemId = isset($_REQUEST['item-id']) ? intval($_REQUEST['item-id']) : 0;
        $locationId = isset($_REQUEST['location-id']) ? intval($_REQUEST['location-id']) : 0;
        $start = isset($_REQUEST[\CommonsBooking\Model\Timeframe::REPETITION_START])
            ? intval($_REQUEST[\CommonsBooking\Model\Timeframe::REPETITION_START])
            : 0;
        $end = isset($_REQUEST[\CommonsBooking\Model\Timeframe::REPETITION_END])
            ? intval($_REQUEST[\CommonsBooking\Model\Timeframe::REPETITION_END])
            : 0;

        if (!$itemId || !$locationId || !$start || !$end) {
            return null;
        }

        return [
            'item_id' => $itemId,
            'location_id' => $locationId,
            'start' => $start,
            'end' => $end,
            'user_id' => get_current_user_id(),
        ];
    }

    private function evaluateRules(array $request): ?array
    {
        $options = BlacklistSettings::getOptions();

        if (!$this->hasActiveRestrictionRules($options)) {
            return null;
        }

        $bookings = $this->getRelevantConfirmedBookings($request, $options);

        $followupGapContext = $this->checkSameItemFollowupGap($request, $options, $bookings);
        if ($followupGapContext) {
            return $followupGapContext;
        }

        foreach (['item', 'location', 'global'] as $rule) {
            $context = $this->checkRule($rule, $request, $options, $bookings);
            if ($context) {
                return $context;
            }
        }

        return null;
    }

    private function hasActiveRestrictionRules(array $options): bool
    {
        return !empty($options['blacklist_item_followup_gap_active'])
            || !empty($options['blacklist_item_active'])
            || !empty($options['blacklist_location_active'])
            || !empty($options['blacklist_global_active']);
    }

    private function checkSameItemFollowupGap(array $request, array $options, array $bookings): ?array
    {
        if (empty($options['blacklist_item_followup_gap_active'])) {
            return null;
        }

        if (empty($options['blacklist_item_include_admins']) && $this->isItemAdmin($request['user_id'], $request['item_id'])) {
            return null;
        }

        $previousBooking = $this->getPreviousSameItemBooking($bookings, $request);
        if (!$previousBooking) {
            return null;
        }

        $newStartDay = $this->getLocalMidnightTimestamp($request['start']);
        $earliestStartDay = $this->getLocalMidnightTimestampPlusDays($previousBooking->getEndDate(), 3);

        if ($newStartDay >= $earliestStartDay) {
            return null;
        }

        return [
            'rule' => 'following_item_gap',
            'limit' => 2,
            'interval' => 2,
            'item_id' => $request['item_id'],
            'location_id' => $request['location_id'],
            'booking_details' => $this->buildBookingDetailsForNotice([
                [
                    'start' => $previousBooking->getStartDate(),
                    'end' => $previousBooking->getEndDate(),
                    'booking' => $previousBooking,
                ],
            ]),
            'next_available_timestamp' => $earliestStartDay,
            'notice_text' => __('Diese Folgebuchung ist noch nicht möglich. Zwischen dem Ende deiner letzten Buchung und der neuen Buchung desselben Artikels müssen mindestens 2 buchbare Tage liegen.', 'cb-additional-features'),
        ];
    }

    private function getPreviousSameItemBooking(array $bookings, array $request): ?ModelBooking
    {
        $sameItemBookings = array_filter($bookings, function (ModelBooking $booking) use ($request) {
            if ((int) $booking->post_author !== (int) $request['user_id']) {
                return false;
            }

            if ($booking->getEndDate() >= $request['start']) {
                return false;
            }

            try {
                return $booking->getItemID() === (int) $request['item_id'];
            } catch (\Exception $e) {
                return false;
            }
        });

        if (!$sameItemBookings) {
            return null;
        }

        usort($sameItemBookings, function (ModelBooking $a, ModelBooking $b) {
            return $b->getEndDate() <=> $a->getEndDate();
        });

        return reset($sameItemBookings) ?: null;
    }

    private function getLocalMidnightTimestamp(int $timestamp): int
    {
        if (function_exists('wp_timezone')) {
            $dt = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone());
            return $dt->setTime(0, 0, 0)->getTimestamp();
        }

        return strtotime('midnight', $timestamp);
    }

    private function getLocalMidnightTimestampPlusDays(int $timestamp, int $days): int
    {
        if (function_exists('wp_timezone')) {
            $dt = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone());
            return $dt->setTime(0, 0, 0)->modify('+' . $days . ' days')->getTimestamp();
        }

        return strtotime('+' . $days . ' days', strtotime('midnight', $timestamp));
    }

    private function checkRule(string $rule, array $request, array $options, array $bookings): ?array
    {
        $config = $this->getRuleConfig($rule, $options, $request);

        if (!$config['active'] || $config['limit'] <= 0 || $config['interval'] <= 0) {
            return null;
        }

        if ($this->shouldSkipForAdmins($rule, $request, $config)) {
            return null;
        }

        $intervals = $this->buildIntervals($bookings, $config['filter'], $request);
        if ($rule === 'location' || $rule === 'global') {
            $intervals = BookingGroups::collapseIntervals(
                $intervals,
                BookingGroups::normalize($options[BookingGroups::OPTION_KEY] ?? [])
            );
        }

        if (!$intervals) {
            return null;
        }

        $intervalSeconds = $config['interval'] * DAY_IN_SECONDS;
        [$violates, $violatingIntervals, $nextAvailable] = $this->getViolationDetails(
            $intervals,
            $request['end'],
            $intervalSeconds,
            $config['limit']
        );

        if (!$violates) {
            return null;
        }

        $bookingDetails = $this->buildBookingDetailsForNotice($violatingIntervals);

        return [
            'rule' => $rule,
            'limit' => $config['limit'],
            'interval' => $config['interval'],
            'item_id' => $request['item_id'],
            'location_id' => $request['location_id'],
            'booking_details' => $bookingDetails,
            'next_available_timestamp' => $nextAvailable,
        ];
    }

    /**
     * @param array<int, array{start?: int, end?: int, item_name?: string, booking?: ModelBooking|null}> $violatingIntervals
     * @return list<array{item_name: string, date: string, time: string}>
     */
    private function buildBookingDetailsForNotice(array $violatingIntervals): array
    {
        $details = [];
        foreach ($violatingIntervals as $interval) {
            $booking = $interval['booking'] ?? null;
            $start = $interval['start'] ?? 0;
            $end = $interval['end'] ?? 0;
            $itemName = (string) ($interval['item_name'] ?? '');
            if ($itemName === '' && $booking instanceof ModelBooking) {
                try {
                    $item = $booking->getItem();
                    $itemName = $item && $item->post_title ? $item->post_title : __('Artikel', 'cb-additional-features');
                } catch (\Exception $e) {
                    $itemName = __('Artikel', 'cb-additional-features');
                }
            } elseif ($itemName === '') {
                $itemName = __('(diese Buchung)', 'cb-additional-features');
            }
            $details[] = [
                'item_name' => $itemName,
                'date' => $start ? date_i18n(get_option('date_format'), $start) : '',
                'time' => ($start && $end && !$this->isFullDayInterval($start, $end))
                    ? date_i18n(get_option('time_format'), $start) . ' – ' . date_i18n(get_option('time_format'), $end)
                    : '',
            ];
        }
        return $details;
    }

    private function isFullDayInterval(int $start, int $end): bool
    {
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone(wp_timezone_string() ?: 'UTC');
        $startDate = (new \DateTimeImmutable('@' . $start))->setTimezone($timezone);
        $endDate = (new \DateTimeImmutable('@' . $end))->setTimezone($timezone);

        return $startDate->format('H:i') === '00:00'
            && $endDate->format('H:i') === '23:59';
    }

    private function getRuleConfig(string $rule, array $options, array $request): array
    {
        $config = [
            'active' => false,
            'limit' => 0,
            'interval' => 0,
            'include_admins' => false,
            'filter' => static function ($booking) {
                return false;
            },
        ];

        switch ($rule) {
            case 'item':
                $config['active'] = !empty($options['blacklist_item_active']);
                $config['limit'] = isset($options['blacklist_item']) ? intval($options['blacklist_item']) : 0;
                $config['interval'] = isset($options['blacklist_item_interval']) ? intval($options['blacklist_item_interval']) : 0;
                $config['include_admins'] = !empty($options['blacklist_item_include_admins']);
                $itemId = $request['item_id'] ?? 0;
                $config['filter'] = static function (ModelBooking $booking) use ($itemId) {
                    try {
                        return $booking->getItemID() === (int) $itemId;
                    } catch (\Exception $e) {
                        return false;
                    }
                };
                break;
            case 'location':
                $config['active'] = !empty($options['blacklist_location_active']);
                $config['limit'] = isset($options['blacklist_location']) ? intval($options['blacklist_location']) : 0;
                $config['interval'] = isset($options['blacklist_location_interval']) ? intval($options['blacklist_location_interval']) : 0;
                $config['include_admins'] = !empty($options['blacklist_location_include_admins']);
                $locationId = $request['location_id'] ?? 0;
                $config['filter'] = static function (ModelBooking $booking) use ($locationId) {
                    try {
                        return $booking->getLocationID() === (int) $locationId;
                    } catch (\Exception $e) {
                        return false;
                    }
                };
                break;
            case 'global':
                $config['active'] = !empty($options['blacklist_global_active']);
                $config['limit'] = isset($options['blacklist_global']) ? intval($options['blacklist_global']) : 0;
                $config['interval'] = isset($options['blacklist_global_interval']) ? intval($options['blacklist_global_interval']) : 0;
                $config['include_admins'] = !empty($options['blacklist_global_include_admins']);
                $userId = $request['user_id'] ?? 0;
                $config['filter'] = function (ModelBooking $booking) use ($userId) {
                    try {
                        // Buchungen herausfiltern, wenn Nutzer Admin für Item oder Location ist
                        $item = $booking->getItem();
                        $location = $booking->getLocation();
                        
                        if ($item && $this->isItemAdmin($userId, $item->ID)) {
                            return false; // Nicht zählen, wenn Item-Admin
                        }
                        
                        if ($location && $this->isLocationAdmin($userId, $location->ID)) {
                            return false; // Nicht zählen, wenn Location-Admin
                        }
                        
                        return true; // Zählen, wenn nicht Admin
                    } catch (\Exception $e) {
                        return true; // Bei Fehler zählen (sicherer Fall)
                    }
                };
                break;
        }

        return $config;
    }

    private function shouldSkipForAdmins(string $rule, array $request, array $config): bool
    {
        if ($config['include_admins']) {
            return false;
        }

        switch ($rule) {
            case 'item':
                return $this->isItemAdmin($request['user_id'], $request['item_id']);
            case 'location':
                return $this->isLocationAdmin($request['user_id'], $request['location_id']);
            case 'global':
                return $this->isGlobalAdmin();
            default:
                return false;
        }
    }

    private function getRelevantConfirmedBookings(array $request, array $options): array
    {
        $userId = get_current_user_id();
        $queryWindow = $this->getRestrictionQueryWindow($request, $options);

        if (!$userId || !$queryWindow) {
            return [];
        }

        [$minEnd, $maxEnd] = $queryWindow;

        $posts = get_posts([
            'post_type' => BookingPostType::getPostType(),
            'post_status' => 'confirmed',
            'author' => $userId,
            'posts_per_page' => -1,
            'orderby' => 'meta_value_num',
            'meta_key' => Timeframe::REPETITION_END,
            'order' => 'ASC',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'type',
                    'value' => \CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKING_ID,
                    'compare' => '=',
                ],
                [
                    'key' => Timeframe::REPETITION_END,
                    'value' => [$minEnd, $maxEnd],
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC',
                ],
            ],
        ]);

        if (!$posts) {
            return [];
        }

        return array_map(
            static fn(\WP_Post $post): ModelBooking => new ModelBooking($post),
            $posts
        );
    }

    private function getRestrictionQueryWindow(array $request, array $options): ?array
    {
        $minEnd = [];
        $maxEnd = [];

        if (!empty($options['blacklist_item_followup_gap_active'])) {
            $minEnd[] = max(0, (int) $request['start'] - (4 * DAY_IN_SECONDS));
            $maxEnd[] = (int) $request['start'];
        }

        foreach (['item', 'location', 'global'] as $rule) {
            if (empty($options['blacklist_' . $rule . '_active'])) {
                continue;
            }

            $interval = isset($options['blacklist_' . $rule . '_interval'])
                ? max(0, (int) $options['blacklist_' . $rule . '_interval'])
                : 0;

            if (!$interval) {
                continue;
            }

            $intervalSeconds = $interval * DAY_IN_SECONDS;
            $minEnd[] = max(0, (int) $request['end'] - $intervalSeconds);
            $maxEnd[] = (int) $request['end'] + $intervalSeconds;
        }

        if (!$minEnd || !$maxEnd) {
            return null;
        }

        return [min($minEnd), max($maxEnd)];
    }

    private function buildIntervals(array $bookings, callable $filter, array $request): array
    {
        $intervals = [];

        foreach ($bookings as $booking) {
            if ($filter($booking)) {
                try {
                    $intervals[] = [
                        'start' => $booking->getStartDate(),
                        'end' => $booking->getEndDate(),
                        'item_id' => $booking->getItemID(),
                        'location_id' => $booking->getLocationID(),
                        'booking' => $booking,
                    ];
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        if (!empty($request['start']) && !empty($request['end'])) {
            $intervals[] = [
                'start' => $request['start'],
                'end' => $request['end'],
                'item_id' => $request['item_id'] ?? 0,
                'location_id' => $request['location_id'] ?? 0,
                'booking' => null,
            ];
        }

        return $intervals;
    }

    /**
     * Returns violation details: whether limit is exceeded, the intervals that count toward it, and when a new booking is possible again.
     *
     * @return array{0: bool, 1: array, 2: int} [violates, violatingIntervals, nextAvailableTimestamp]
     */
    private function getViolationDetails(array $intervals, int $baseEnd, int $intervalSeconds, int $limit): array
    {
        if ($intervalSeconds <= 0 || $limit <= 0 || !$baseEnd) {
            return [false, [], 0];
        }

        $intervals = array_values(
            array_filter($intervals, function ($interval) use ($baseEnd, $intervalSeconds) {
                if (empty($interval['end'])) {
                    return false;
                }
                return abs($interval['end'] - $baseEnd) < $intervalSeconds;
            })
        );

        if (!$intervals) {
            return [false, [], 0];
        }

        usort($intervals, function ($a, $b) {
            return ($a['start'] ?? 0) <=> ($b['start'] ?? 0);
        });

        $count = 0;
        $violatingIntervals = [];
        foreach ($intervals as $index => $interval) {
            if ($index > 0) {
                $gap = ($intervals[$index]['start'] ?? 0) - ($intervals[$index - 1]['end'] ?? 0);
                if ($gap > $intervalSeconds) {
                    $count = 0;
                    $violatingIntervals = [];
                }
            }
            $count++;
            $violatingIntervals[] = $interval;
            if (count($violatingIntervals) > $limit) {
                array_shift($violatingIntervals);
            }
            if ($count > $limit) {
                $earliestEnd = PHP_INT_MAX;
                foreach ($violatingIntervals as $vi) {
                    $e = $vi['end'] ?? 0;
                    if ($e > 0 && $e < $earliestEnd) {
                        $earliestEnd = $e;
                    }
                }
                $nextAvailable = $earliestEnd > 0 && $earliestEnd < PHP_INT_MAX
                    ? $earliestEnd + $intervalSeconds
                    : 0;
                // Auf Tagesanfang (Mitternacht) setzen: ab diesem Tag wieder buchbar, ohne Uhrzeit
                if ($nextAvailable > 0 && function_exists('wp_timezone')) {
                    $tz = wp_timezone();
                    $dt = (new \DateTimeImmutable('@' . $nextAvailable))->setTimezone($tz);
                    $nextAvailable = $dt->setTime(0, 0, 0)->getTimestamp();
                }
                return [true, $violatingIntervals, $nextAvailable];
            }
        }

        return [false, [], 0];
    }

    private function violatesThreshold(array $intervals, int $baseEnd, int $intervalSeconds, int $limit): bool
    {
        [$violates] = $this->getViolationDetails($intervals, $baseEnd, $intervalSeconds, $limit);
        return $violates;
    }

    private function isItemAdmin(int $userId, int $itemId): bool
    {
        try {
            $post = get_post($itemId);
            if (!$post) {
                return false;
            }
            $item = new ItemModel($post);
            return in_array($userId, $item->getAdmins(), true);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isLocationAdmin(int $userId, int $locationId): bool
    {
        try {
            $post = get_post($locationId);
            if (!$post) {
                return false;
            }
            $location = new LocationModel($post);
            return in_array($userId, $location->getAdmins(), true);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isGlobalAdmin(): bool
    {
        $user = wp_get_current_user();

        if ($user instanceof \WP_User && $user->exists() && function_exists('commonsbooking_isUserAdmin')) {
            return (bool) commonsbooking_isUserAdmin($user);
        }

        if (function_exists('commonsbooking_isCurrentUserAdmin')) {
            return (bool) commonsbooking_isCurrentUserAdmin();
        }

        return current_user_can('manage_options');
    }

    private function logBlockedAttempt(array $request, array $context): void
    {
        $message = sprintf(
            '[Blacklist] User %d blocked for %s rule (item %d, location %d, start %s, end %s, limit %d/%d days)',
            $request['user_id'],
            $context['rule'],
            $request['item_id'],
            $request['location_id'],
            date_i18n('Y-m-d H:i', $request['start']),
            date_i18n('Y-m-d H:i', $request['end']),
            $context['limit'],
            $context['interval']
        );

        Log::log($message);

        BlacklistLog::addEntry([
            'user_id' => $request['user_id'],
            'item_id' => $request['item_id'],
            'location_id' => $request['location_id'],
            'rule' => $context['rule'],
            'start' => $request['start'],
            'end' => $request['end'],
        ]);
    }

    private function buildNoticeMessage(array $request, array $context): string
    {
        $reasonLabels = [
            'item' => __('Artikel', 'cb-additional-features'),
            'following_item_gap' => __('Artikel', 'cb-additional-features'),
            'location' => __('Standort', 'cb-additional-features'),
            'global' => __('Buchung', 'cb-additional-features'),
        ];

        $template = $context['notice_text'] ?? BlacklistSettings::getNoticeText();
        $template = strtr($template, [
            '{{reason}}' => $reasonLabels[$context['rule']] ?? $context['rule'],
            '{{limit}}' => $context['limit'],
            '{{interval}}' => $context['interval'],
        ]);

        if (function_exists('commonsbooking_parse_template')) {
            $templateObjects = [
                'item' => $this->getItemModel($request['item_id']),
                'location' => $this->getLocationModel($request['location_id']),
                'user' => wp_get_current_user(),
            ];
            $template = commonsbooking_parse_template($template, $templateObjects);
        }

        $bookingDetails = $context['booking_details'] ?? [];
        $nextAvailableTs = $context['next_available_timestamp'] ?? 0;
        $nextAvailableStr = $nextAvailableTs > 0
            ? date_i18n(get_option('date_format'), $nextAvailableTs)
            : '';

        return $this->wrapBlacklistNoticeHtml($template, $bookingDetails, $nextAvailableStr);
    }

    /**
     * Wraps the notice text, booking list and next-available date in styled HTML.
     */
    private function wrapBlacklistNoticeHtml(string $mainText, array $bookingDetails, string $nextAvailableStr): string
    {
        $boxStyle = 'margin:1em 0;padding:1em 1.25em;background:#fef7f0;border:1px solid #e96656;border-left:4px solid #e96656;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,.06);';
        $titleStyle = 'margin:0 0 .5em;font-size:1.05em;font-weight:600;color:#b45342;';
        $listStyle = 'margin:.5em 0 0;padding-left:1.25em;';
        $rowStyle = 'margin:.35em 0;color:#333;';
        $footerStyle = 'margin:.75em 0 0;padding-top:.75em;border-top:1px solid rgba(233,102,86,.25);font-size:.95em;font-weight:600;color:#049dab;';

        $html = '<div class="afcb-blacklist-notice" style="' . esc_attr($boxStyle) . '">';
        $html .= '<p class="afcb-blacklist-notice-main" style="margin:0;color:#333;">' . wp_kses_post($mainText) . '</p>';

        if (!empty($bookingDetails)) {
            $html .= '<p class="afcb-blacklist-notice-title" style="' . esc_attr($titleStyle) . '">' . esc_html__('Einberechnete Buchungen:', 'cb-additional-features') . '</p>';
            $html .= '<ul class="afcb-blacklist-notice-list" style="' . esc_attr($listStyle) . '">';
            foreach ($bookingDetails as $row) {
                $line = esc_html($row['item_name']);
                if (!empty($row['date'])) {
                    $line .= ', ' . esc_html($row['date']);
                }
                if (!empty($row['time'])) {
                    $line .= ' ' . esc_html($row['time']);
                }
                $html .= '<li style="' . esc_attr($rowStyle) . '">' . $line . '</li>';
            }
            $html .= '</ul>';
        }

        if ($nextAvailableStr !== '') {
            $html .= '<p class="afcb-blacklist-notice-next" style="' . esc_attr($footerStyle) . '">';
            $html .= esc_html__('Neue Buchung wieder möglich ab:', 'cb-additional-features') . ' ' . esc_html($nextAvailableStr);
            $html .= '</p>';
        }

        $html .= '</div>';
        return $html;
    }

    private function getItemModel(int $itemId): ?ItemModel
    {
        try {
            $post = get_post($itemId);
            return $post ? new ItemModel($post) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getLocationModel(int $locationId): ?LocationModel
    {
        try {
            $post = get_post($locationId);
            return $post ? new LocationModel($post) : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
