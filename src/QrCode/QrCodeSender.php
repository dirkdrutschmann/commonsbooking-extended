<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\QrCode;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\QrCodeSettings;

class QrCodeSender
{
    private const CHECK_PAGE_SLUG = 'check';
    private ?int $currentBookingId = null;

    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'initPlugin'], 10);
    }

    public function initPlugin(): void
    {
        if (!QrCodeSettings::isEnabled()) {
            return;
        }

        if (!class_exists('CommonsBooking\\Repository\\Booking')) {
            return;
        }

        add_action('wp', [$this, 'maybeCreateCheckPage']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('transition_post_status', [$this, 'captureBookingContextFromTransition'], 10, 3);
        add_filter('commonsbooking_mail_body', [$this, 'addQrCodeToEmail'], 10, 2);
        add_shortcode('afcb_qr_check', [$this, 'qrCheckShortcode']);
        add_shortcode('cb_qr_check', [$this, 'qrCheckShortcode']);
        add_action('commonsbooking_mail_sent', [$this, 'resetCurrentBookingContext'], 10, 2);
        add_filter('commonsbooking_template_tag', [$this, 'extractBookingIdFromTemplateTag'], 10, 1);
        add_action('init', [$this, 'handleQrImageRequest']);
    }

    public function maybeCreateCheckPage(): void
    {
        $checkPage = get_page_by_path(self::CHECK_PAGE_SLUG);

        if (!$checkPage) {
            $pageData = [
                'post_title' => __('Buchung prüfen', 'cb-additional-features'),
                'post_content' => '[afcb_qr_check]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => self::CHECK_PAGE_SLUG,
                'post_author' => 1,
            ];

            $pageId = wp_insert_post($pageData);

            if ($pageId) {
                update_option('cb_qr_check_page_id', $pageId);
            }
        } else {
            if (!get_option('cb_qr_check_page_id')) {
                update_option('cb_qr_check_page_id', $checkPage->ID);
            }
        }
    }

    public function enqueueAssets(): void
    {
        if (!$this->isCheckPage()) {
            return;
        }

        $cssPath = dirname(__DIR__, 2) . '/assets/css/commonsbooking-qr-check.css';
        $cssVersion = is_readable($cssPath)
            ? substr((string) hash_file('sha256', $cssPath), 0, 12)
            : '1.0.0';
        $cssUrl = plugins_url(
            'assets/css/commonsbooking-qr-check.css',
            dirname(__DIR__, 2) . '/cb-additional-features.php'
        );
        $siteScheme = wp_parse_url(home_url('/'), PHP_URL_SCHEME);

        if (is_string($siteScheme) && in_array($siteScheme, ['http', 'https'], true)) {
            $cssUrl = set_url_scheme($cssUrl, $siteScheme);
        }

        wp_enqueue_style(
            'afcb-commonsbooking-qr-check',
            $cssUrl,
            [],
            $cssVersion
        );
    }

    private function isCheckPage(): bool
    {
        if (!function_exists('is_page') || !is_page()) {
            return false;
        }

        $currentId = get_queried_object_id();
        $checkPageId = $this->getCheckPageId();

        if ($checkPageId && intval($currentId) === $checkPageId) {
            return true;
        }

        $checkPage = get_page_by_path(self::CHECK_PAGE_SLUG);
        if ($checkPage && intval($checkPage->ID) === intval($currentId)) {
            return true;
        }

        return false;
    }

    private function getCheckPageId(): ?int
    {
        $pageId = intval(get_option('cb_qr_check_page_id'));
        return $pageId > 0 ? $pageId : null;
    }

    public function captureBookingContextFromTransition($newStatus, $oldStatus, $post): void
    {
        if (!$this->isBookingPost($post)) {
            return;
        }

        if (!in_array($newStatus, ['confirmed', 'canceled'], true)) {
            return;
        }

        $this->setCurrentBookingId(intval($post->ID));
    }

    public function addQrCodeToEmail($body, $messageAction)
    {
        if ($messageAction !== 'confirmed') {
            return $body;
        }

        if (strpos($body, 'cb-qr-code-wrapper') !== false) {
            return $body;
        }

        $bookingId = $this->resolveBookingId($body);

        if (!$bookingId) {
            error_log('CB QR Code: Konnte Booking-ID nicht finden. Action: ' . $messageAction);
            return $body;
        }

        $checkUrl = $this->getCheckUrl($bookingId);
        $qrSize = 200;
        $qrUrl = $this->getQrCodeImageUrl($checkUrl, $qrSize);

        $body .= TemplateLoader::load()->render('email/qr-code.html.twig', [
            'title' => __('Buchungsbestätigung scannen', 'cb-additional-features'),
            'description' => __('Scannen Sie diesen QR-Code, um Ihre Buchung zu prüfen.', 'cb-additional-features'),
            'qr_url' => $qrUrl,
            'qr_size' => $qrSize,
            'alt_text' => __('QR-Code', 'cb-additional-features'),
        ]);

        return $body;
    }

    public function extractBookingIdFromTemplateTag($template)
    {
        if (!$this->currentBookingId) {
            $bookingId = $this->getBookingIdFromBacktrace(20);
            if ($bookingId) {
                $this->setCurrentBookingId($bookingId);
            }
        }

        return $template;
    }

    private function resolveBookingId(string $body = ''): ?int
    {
        if ($this->currentBookingId) {
            return $this->currentBookingId;
        }

        $bookingId = $this->getBookingIdFromGlobalPost();
        if ($bookingId) {
            $this->setCurrentBookingId($bookingId);
            return $bookingId;
        }

        $bookingId = $this->getBookingIdFromBacktrace();
        if ($bookingId) {
            $this->setCurrentBookingId($bookingId);
            return $bookingId;
        }

        $bookingId = $this->extractBookingIdFromBody($body);
        if ($bookingId) {
            $this->setCurrentBookingId($bookingId);
            return $bookingId;
        }

        return null;
    }

    private function getBookingIdFromGlobalPost(): ?int
    {
        global $post;
        if ($this->isBookingPost($post)) {
            return intval($post->ID);
        }

        return null;
    }

    private function isBookingPost($post): bool
    {
        if (!($post instanceof \WP_Post)) {
            return false;
        }

        return $post->post_type === $this->getBookingPostType();
    }

    private function getBookingPostType(): string
    {
        if (class_exists('\\CommonsBooking\\Wordpress\\CustomPostType\\Booking')) {
            return \CommonsBooking\Wordpress\CustomPostType\Booking::$postType;
        }

        return 'cb_booking';
    }

    private function getBookingIdFromBacktrace(int $depth = 40): ?int
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth);

        foreach ($backtrace as $trace) {
            if (!isset($trace['class'], $trace['object'])) {
                continue;
            }

            if (!method_exists($trace['object'], 'getPostId')) {
                continue;
            }

            if ($trace['class'] === 'CommonsBooking\\Messages\\BookingMessage' || $trace['class'] === 'CommonsBooking\\Messages\\Message') {
                $bookingId = intval($trace['object']->getPostId());
                if ($bookingId > 0) {
                    return $bookingId;
                }
            }
        }

        return null;
    }

    private function extractBookingIdFromBody(string $body): ?int
    {
        if (preg_match('/booking[#:]([0-9]+)/i', $body, $matches)) {
            return intval($matches[1]);
        }

        return null;
    }

    private function getQrCodeImageUrl(string $data, int $size = 200): string
    {
        $encodedData = urlencode($data);
        return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encodedData}";
    }

    private function getCheckUrl(int $bookingId): string
    {
        $checkPageId = get_option('cb_qr_check_page_id');

        if ($checkPageId) {
            $checkUrl = get_permalink($checkPageId);
        } else {
            $checkUrl = home_url('/' . self::CHECK_PAGE_SLUG . '/');
        }

        $token = $this->generateBookingToken($bookingId);

        return add_query_arg([
            'booking' => $bookingId,
            'token' => $token,
        ], $checkUrl);
    }

    private function generateBookingToken(int $bookingId): string
    {
        $secret = get_option('cb_qr_secret_key', wp_generate_password(32, false));
        if (!get_option('cb_qr_secret_key')) {
            update_option('cb_qr_secret_key', $secret);
        }

        return hash_hmac('sha256', $bookingId, $secret);
    }

    private function validateBookingToken(int $bookingId, string $token): bool
    {
        $expectedToken = $this->generateBookingToken($bookingId);
        return hash_equals($expectedToken, $token);
    }

    public function qrCheckShortcode($atts, $content = null, $tag = ''): string
    {
        $bookingId = isset($_GET['booking']) ? intval($_GET['booking']) : 0;
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

        if (!$bookingId || !$token) {
            return $this->renderInvalidBooking();
        }

        if (!$this->validateBookingToken($bookingId, $token)) {
            return $this->renderInvalidBooking();
        }

        try {
            $booking = \CommonsBooking\Repository\Booking::getPostById($bookingId);

            if (!$booking) {
                return $this->renderInvalidBooking();
            }

            $status = $booking->getPost()->post_status;
            $isValid = ($status === 'confirmed' || $status === 'unconfirmed');

            $hasAdminAccess = false;
            if (is_user_logged_in()) {
                $currentUserId = get_current_user_id();
                $hasAdminAccess = $this->userHasBookingAccess($currentUserId, $booking);
            }

            if ($hasAdminAccess) {
                return $this->renderAdminView($booking);
            }

            return $this->renderPublicView($booking, $isValid);
        } catch (\Throwable $e) {
            return $this->renderInvalidBooking();
        }
    }

    private function userHasBookingAccess(int $userId, $booking): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $locationId = $booking->getMetaInt('location-id');
        if ($locationId) {
            $locationAdmins = get_post_meta($locationId, '_cb_location_admins', true);

            if (is_string($locationAdmins)) {
                $locationAdmins = strlen($locationAdmins) > 0 ? [$locationAdmins] : [];
            }

            if (in_array($userId, $this->normalizeUserIdList($locationAdmins), true)) {
                return true;
            }

            $locationAuthor = get_post_field('post_author', $locationId);
            if ($locationAuthor == $userId) {
                return true;
            }
        }

        $itemId = $booking->getMetaInt('item-id');
        if ($itemId) {
            $itemAdmins = get_post_meta($itemId, '_cb_item_admins', true);

            if (is_string($itemAdmins)) {
                $itemAdmins = strlen($itemAdmins) > 0 ? [$itemAdmins] : [];
            }

            if (in_array($userId, $this->normalizeUserIdList($itemAdmins), true)) {
                return true;
            }

            $itemAuthor = get_post_field('post_author', $itemId);
            if ($itemAuthor == $userId) {
                return true;
            }
        }

        return false;
    }

    private function normalizeUserIdList($value): array
    {
        if (is_string($value)) {
            $value = strlen($value) > 0 ? [$value] : [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $value)));
    }

    private function renderPublicView($booking, bool $isValid): string
    {
        $item = $booking->getItem();
        $location = $booking->getLocation();
        $startDate = date_i18n('d.m.Y H:i', $booking->getStartDate());
        $endDate = date_i18n('d.m.Y H:i', $booking->getEndDate());
        $statusClass = $isValid ? 'cb-qr-status--valid' : 'cb-qr-status--invalid';
        $timing = $this->getBookingTiming(
            (int) $booking->getStartDate(),
            (int) $booking->getEndDate()
        );

        return TemplateLoader::load()->render('qr/check-public.html.twig', [
            'eyebrow' => __('QR-Buchungscheck', 'cb-additional-features'),
            'status_label' => $isValid ? __('Buchung gültig', 'cb-additional-features') : __('Buchung nicht gültig', 'cb-additional-features'),
            'badge_label' => $isValid ? __('Bestätigt', 'cb-additional-features') : __('Nicht gültig', 'cb-additional-features'),
            'description' => $isValid
                ? __('Diese Buchung ist gültig.', 'cb-additional-features')
                : __('Diese Buchung ist nicht mehr gültig oder wurde storniert.', 'cb-additional-features'),
            'status_class' => $statusClass,
            'timing_label' => $timing['label'],
            'timing_detail' => $timing['detail'],
            'timing_class' => $timing['class'],
            'item' => $item->post_title,
            'location' => $location->post_title,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'labels' => [
                'booking_info' => __('Buchungsinformationen', 'cb-additional-features'),
                'item' => __('Artikel', 'cb-additional-features'),
                'location' => __('Standort', 'cb-additional-features'),
                'from' => __('Von', 'cb-additional-features'),
                'to' => __('Bis', 'cb-additional-features'),
            ],
        ]);
    }

    private function getFormattedUserInfo($booking): string
    {
        if (!function_exists('commonsbooking_parse_template') || !class_exists('\\CommonsBooking\\Settings\\Settings')) {
            return '';
        }

        $template = \CommonsBooking\Settings\Settings::getOption('commonsbooking_options_templates', 'user_details_template');
        if (!$template) {
            return '';
        }

        $objects = [
            'booking' => $booking,
            'item' => $booking->getItem(),
            'location' => $booking->getLocation(),
            'user' => $booking->getUserData(),
        ];

        try {
            return commonsbooking_parse_template($template, $objects);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function renderAdminView($booking): string
    {
        $status = $booking->getPost()->post_status;
        $isCanceled = ($status === 'canceled');
        $statusClass = $isCanceled ? 'cb-qr-status--canceled' : 'cb-qr-status--valid';

        $userData = $booking->getUserData();
        $item = $booking->getItem();
        $location = $booking->getLocation();

        $startDate = date_i18n('d.m.Y H:i', $booking->getStartDate());
        $endDate = date_i18n('d.m.Y H:i', $booking->getEndDate());
        $timing = $this->getBookingTiming(
            (int) $booking->getStartDate(),
            (int) $booking->getEndDate()
        );
        $pickupDatetime = $booking->pickupDatetime();
        $returnDatetime = $booking->returnDatetime();
        $bookingCode = $booking->getBookingCode();
        $locationAddress = method_exists($location, 'formattedAddressOneLine') ? $location->formattedAddressOneLine() : '';
        $locationPickupInstructions = method_exists($location, 'formattedPickupInstructionsOneLine') ? $location->formattedPickupInstructionsOneLine() : '';
        $locationContact = method_exists($location, 'formattedContactInfoOneLine') ? $location->formattedContactInfoOneLine() : '';
        $formattedUserInfo = $this->getFormattedUserInfo($booking);
        $bookingComment = $booking->returnComment();
        $internalComment = $booking->getMeta('internal-comment');
        $adminBookingId = $booking->getMeta('admin_booking_id');
        $adminBookingUser = $adminBookingId ? get_user_by('ID', $adminBookingId) : null;
        $adminBookingDisplay = '';
        if ($adminBookingUser instanceof \WP_User) {
            $adminBookingDisplay = sprintf(
                '%s (%s %s)',
                $adminBookingUser->user_login,
                $adminBookingUser->first_name,
                $adminBookingUser->last_name
            );
        }

        $formattedUserInfo = $formattedUserInfo ? commonsbooking_sanitizeHTML($formattedUserInfo) : '';
        $locationAddress = $locationAddress ? commonsbooking_sanitizeHTML($locationAddress) : '';
        $locationPickupInstructions = $locationPickupInstructions ? commonsbooking_sanitizeHTML($locationPickupInstructions) : '';
        $locationContact = $locationContact ? commonsbooking_sanitizeHTML($locationContact) : '';
        $bookingComment = $bookingComment ? wp_kses_post(nl2br($bookingComment)) : '';
        $internalComment = $internalComment ? wp_kses_post(nl2br(commonsbooking_sanitizeHTML($internalComment))) : '';

        return TemplateLoader::load()->render('qr/check-admin.html.twig', [
            'eyebrow' => __('Interne Buchungsprüfung', 'cb-additional-features'),
            'status_label' => $isCanceled ? __('Storniert', 'cb-additional-features') : __('Gültig', 'cb-additional-features'),
            'badge_label' => $isCanceled ? __('Storniert', 'cb-additional-features') : __('Freigegeben', 'cb-additional-features'),
            'description' => $isCanceled
                ? __('Diese Buchung wurde storniert und darf nicht mehr ausgegeben werden.', 'cb-additional-features')
                : __('Die Buchung ist gültig. Die internen Angaben sind nur für berechtigte Standort- und Artikelverantwortliche sichtbar.', 'cb-additional-features'),
            'status_class' => $statusClass,
            'timing_label' => $timing['label'],
            'timing_detail' => $timing['detail'],
            'timing_class' => $timing['class'],
            'booking_id' => $booking->ID,
            'status' => $this->formatBookingStatus($status),
            'user_display' => $userData->display_name,
            'user_email' => $userData->user_email,
            'item' => $item->post_title,
            'location' => $location->post_title,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'pickup_datetime' => $pickupDatetime,
            'return_datetime' => $returnDatetime,
            'booking_code' => $bookingCode,
            'location_address' => $locationAddress,
            'location_pickup_instructions' => $locationPickupInstructions,
            'location_contact' => $locationContact,
            'user_full_name' => trim($userData->first_name . ' ' . $userData->last_name),
            'user_login' => $userData->user_login,
            'formatted_user_info' => $formattedUserInfo,
            'admin_booking_display' => $adminBookingDisplay,
            'booking_comment' => $bookingComment,
            'internal_comment' => $internalComment,
            'labels' => [
                'booking_details' => __('Buchungsdetails', 'cb-additional-features'),
                'booking_id' => __('Buchungs-ID', 'cb-additional-features'),
                'status' => __('Status', 'cb-additional-features'),
                'user' => __('Nutzer', 'cb-additional-features'),
                'item' => __('Artikel', 'cb-additional-features'),
                'location' => __('Standort', 'cb-additional-features'),
                'from' => __('Von', 'cb-additional-features'),
                'to' => __('Bis', 'cb-additional-features'),
                'period_access' => __('Zeitraum & Zugang', 'cb-additional-features'),
                'pickup' => __('Abholung', 'cb-additional-features'),
                'return' => __('Rückgabe', 'cb-additional-features'),
                'booking_code' => __('Buchungscode', 'cb-additional-features'),
                'location_info' => __('Standortinformationen', 'cb-additional-features'),
                'address' => __('Adresse', 'cb-additional-features'),
                'pickup_notes' => __('Abholhinweise', 'cb-additional-features'),
                'contact' => __('Kontakt', 'cb-additional-features'),
                'user_info' => __('Nutzerinformationen', 'cb-additional-features'),
                'name' => __('Name', 'cb-additional-features'),
                'email' => __('E-Mail', 'cb-additional-features'),
                'username' => __('Benutzername', 'cb-additional-features'),
                'profile_details' => __('Profilangaben', 'cb-additional-features'),
                'created_by' => __('Erstellt durch', 'cb-additional-features'),
                'comments' => __('Kommentare', 'cb-additional-features'),
                'user_note' => __('Nutzerhinweis', 'cb-additional-features'),
                'internal_note' => __('Interner Kommentar', 'cb-additional-features'),
            ],
        ]);
    }

    private function getBookingTiming(
        int $startTimestamp,
        int $endTimestamp,
        ?int $nowTimestamp = null
    ): array
    {
        $timezone = function_exists('wp_timezone')
            ? wp_timezone()
            : new \DateTimeZone(date_default_timezone_get());
        $nowTimestamp = $nowTimestamp ?? (int) current_time('timestamp', true);
        $now = (new \DateTimeImmutable('@' . $nowTimestamp))->setTimezone($timezone);
        $today = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $startDay = wp_date('Y-m-d', $startTimestamp, $timezone);
        $endDay = wp_date('Y-m-d', $endTimestamp, $timezone);
        $sameDay = $startDay === $endDay;
        $timeRange = $sameDay
            ? sprintf(
                __('%1$s–%2$s Uhr', 'cb-additional-features'),
                wp_date('H:i', $startTimestamp, $timezone),
                wp_date('H:i', $endTimestamp, $timezone)
            )
            : sprintf(
                __('%1$s, %2$s Uhr bis %3$s, %4$s Uhr', 'cb-additional-features'),
                wp_date('d.m.Y', $startTimestamp, $timezone),
                wp_date('H:i', $startTimestamp, $timezone),
                wp_date('d.m.Y', $endTimestamp, $timezone),
                wp_date('H:i', $endTimestamp, $timezone)
            );

        if ($endTimestamp < $nowTimestamp) {
            return [
                'label' => __('Vergangen', 'cb-additional-features'),
                'detail' => $sameDay
                    ? sprintf(
                        __('%1$s · %2$s', 'cb-additional-features'),
                        wp_date('d.m.Y', $startTimestamp, $timezone),
                        $timeRange
                    )
                    : $timeRange,
                'class' => 'cb-qr-timing--past',
            ];
        }

        if ($startTimestamp <= $nowTimestamp) {
            return [
                'label' => __('Läuft gerade', 'cb-additional-features'),
                'detail' => $endDay === $today
                    ? sprintf(
                        __('Heute · bis %s Uhr', 'cb-additional-features'),
                        wp_date('H:i', $endTimestamp, $timezone)
                    )
                    : sprintf(
                        __('Bis %1$s, %2$s Uhr', 'cb-additional-features'),
                        wp_date('d.m.Y', $endTimestamp, $timezone),
                        wp_date('H:i', $endTimestamp, $timezone)
                    ),
                'class' => 'cb-qr-timing--active',
            ];
        }

        if ($startDay === $today) {
            $hour = (int) wp_date('G', $startTimestamp, $timezone);
            if ($hour < 12) {
                $label = __('Heute Morgen', 'cb-additional-features');
            } elseif ($hour < 17) {
                $label = __('Heute Nachmittag', 'cb-additional-features');
            } else {
                $label = __('Heute Abend', 'cb-additional-features');
            }

            return [
                'label' => $label,
                'detail' => $timeRange,
                'class' => 'cb-qr-timing--today',
            ];
        }

        if ($startDay === $tomorrow) {
            return [
                'label' => __('Morgen', 'cb-additional-features'),
                'detail' => $timeRange,
                'class' => 'cb-qr-timing--upcoming',
            ];
        }

        return [
            'label' => __('Bevorstehend', 'cb-additional-features'),
            'detail' => $sameDay
                ? sprintf(
                    __('%1$s · %2$s', 'cb-additional-features'),
                    wp_date('d.m.Y', $startTimestamp, $timezone),
                    $timeRange
                )
                : $timeRange,
            'class' => 'cb-qr-timing--upcoming',
        ];
    }

    private function formatBookingStatus(string $status): string
    {
        $labels = [
            'confirmed' => __('Bestätigt', 'cb-additional-features'),
            'unconfirmed' => __('Unbestätigt', 'cb-additional-features'),
            'canceled' => __('Storniert', 'cb-additional-features'),
        ];

        return $labels[$status] ?? ucfirst($status);
    }

    private function renderInvalidBooking(): string
    {
        return TemplateLoader::load()->render('qr/check-invalid.html.twig', [
            'eyebrow' => __('QR-Buchungscheck', 'cb-additional-features'),
            'status_label' => __('Buchung nicht gefunden', 'cb-additional-features'),
            'description' => __('Diese Buchung konnte nicht gefunden werden oder der Link ist ungültig.', 'cb-additional-features'),
            'hint' => __('Prüfe, ob du den vollständigen Link aus der Buchungsbestätigung geöffnet hast.', 'cb-additional-features'),
        ]);
    }

    public function resetCurrentBookingContext($action = null, $result = null): void
    {
        $this->clearCurrentBookingId();
    }

    public function handleQrImageRequest(): void
    {
        // Placeholder for local QR-code generation.
    }

    private function setCurrentBookingId(int $bookingId): void
    {
        if ($bookingId <= 0) {
            return;
        }

        $this->currentBookingId = $bookingId;
    }

    private function clearCurrentBookingId(): void
    {
        $this->currentBookingId = null;
    }
}
