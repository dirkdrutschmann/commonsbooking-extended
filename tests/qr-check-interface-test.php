<?php

declare(strict_types=1);

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('Europe/Berlin');
}

function wp_date(string $format, int $timestamp, ?DateTimeZone $timezone = null): string
{
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone($timezone ?? wp_timezone())
        ->format($format);
}

function __(string $text, string $domain = ''): string
{
    return $text;
}

$pluginRoot = dirname(__DIR__);
$templateRoot = $pluginRoot . '/assets/templates/qr';
$senderSource = file_get_contents($pluginRoot . '/src/QrCode/QrCodeSender.php');
$stylesheet = file_get_contents($pluginRoot . '/assets/css/commonsbooking-qr-check.css');

if ($senderSource === false || $stylesheet === false) {
    throw new RuntimeException('QR check sources could not be read.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    strpos($senderSource, 'cdn.tailwindcss.com') === false,
    'The QR check page must not load Tailwind from a CDN.'
);
$assert(
    strpos($senderSource, 'afcb-commonsbooking-qr-check') !== false,
    'The scoped QR check stylesheet must remain enqueued.'
);
$assert(
    strpos($senderSource, "wp_parse_url(home_url('/'), PHP_URL_SCHEME)") !== false
        && strpos($senderSource, 'set_url_scheme($cssUrl, $siteScheme)') !== false,
    'The QR stylesheet URL must inherit the canonical site scheme to prevent mixed-content blocking.'
);
$assert(
    strpos($senderSource, "hash_file('sha256', \$cssPath)") !== false
        && strpos($senderSource, 'filemtime($cssPath)') === false,
    'The deterministic release package needs content-based QR stylesheet cache busting.'
);
$assert(
    strpos($stylesheet, '.cb-qr-check') !== false
        && strpos($stylesheet, '.cb-qr-status--valid') !== false
        && strpos($stylesheet, '.cb-qr-status--canceled') !== false,
    'The QR stylesheet must cover the shared, valid and canceled states.'
);
$assert(
    strpos($stylesheet, '@media (max-width: 520px)') !== false,
    'The QR detail layout must include a narrow mobile fallback.'
);

require $pluginRoot . '/vendor/autoload.php';
require $pluginRoot . '/src/QrCode/QrCodeSender.php';

$sender = (new ReflectionClass(DirkDrutschmann\CommonbookingsAdditionalFeatures\QrCode\QrCodeSender::class))
    ->newInstanceWithoutConstructor();
$timingMethod = new ReflectionMethod($sender, 'getBookingTiming');
$fixedNow = (new DateTimeImmutable('2026-07-16 12:00:00', wp_timezone()))->getTimestamp();
$timestamp = static fn(string $value): int => (new DateTimeImmutable($value, wp_timezone()))->getTimestamp();
$timingCases = [
    ['2026-07-15 08:00:00', '2026-07-15 10:00:00', 'Vergangen'],
    ['2026-07-16 10:00:00', '2026-07-16 18:00:00', 'Läuft gerade'],
    ['2026-07-16 13:00:00', '2026-07-16 16:00:00', 'Heute Nachmittag'],
    ['2026-07-16 18:00:00', '2026-07-16 20:00:00', 'Heute Abend'],
    ['2026-07-17 08:00:00', '2026-07-17 10:00:00', 'Morgen'],
    ['2026-07-20 08:00:00', '2026-07-20 10:00:00', 'Bevorstehend'],
];

foreach ($timingCases as [$start, $end, $expectedLabel]) {
    $timing = $timingMethod->invoke($sender, $timestamp($start), $timestamp($end), $fixedNow);
    $assert(
        $timing['label'] === $expectedLabel,
        sprintf('Expected timing label "%s", received "%s".', $expectedLabel, $timing['label'])
    );
    $assert($timing['detail'] !== '', 'Every timing state must include an exact date or time range.');
}

$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader($templateRoot));
$labels = [
    'booking_info' => 'Buchungsinformationen',
    'booking_details' => 'Buchungsdetails',
    'booking_id' => 'Buchungs-ID',
    'status' => 'Status',
    'user' => 'Nutzer',
    'item' => 'Artikel',
    'location' => 'Standort',
    'from' => 'Von',
    'to' => 'Bis',
    'period_access' => 'Zeitraum & Zugang',
    'pickup' => 'Abholung',
    'return' => 'Rückgabe',
    'booking_code' => 'Buchungscode',
    'location_info' => 'Standortinformationen',
    'address' => 'Adresse',
    'pickup_notes' => 'Abholhinweise',
    'contact' => 'Kontakt',
    'user_info' => 'Nutzerinformationen',
    'name' => 'Name',
    'email' => 'E-Mail',
    'username' => 'Benutzername',
    'profile_details' => 'Profilangaben',
    'created_by' => 'Erstellt durch',
    'comments' => 'Kommentare',
    'user_note' => 'Nutzerhinweis',
    'internal_note' => 'Interner Kommentar',
];

$publicHtml = $twig->render('check-public.html.twig', [
    'eyebrow' => 'QR-Buchungscheck',
    'status_label' => 'Buchung gültig',
    'badge_label' => 'Bestätigt',
    'description' => 'Diese Buchung ist gültig.',
    'status_class' => 'cb-qr-status--valid',
    'timing_label' => 'Heute Morgen',
    'timing_detail' => '10:00–18:00 Uhr',
    'timing_class' => 'cb-qr-timing--today',
    'item' => 'Lastenrad',
    'location' => 'Teststation',
    'start_date' => '16.07.2026 10:00',
    'end_date' => '16.07.2026 18:00',
    'labels' => $labels,
]);

$adminHtml = $twig->render('check-admin.html.twig', [
    'eyebrow' => 'Interne Buchungsprüfung',
    'status_label' => 'Gültig',
    'badge_label' => 'Freigegeben',
    'description' => 'Interne Ansicht',
    'status_class' => 'cb-qr-status--valid',
    'timing_label' => 'Vergangen',
    'timing_detail' => '15.07.2026 · 10:00–18:00 Uhr',
    'timing_class' => 'cb-qr-timing--past',
    'booking_id' => 42,
    'status' => 'Confirmed',
    'user_display' => 'Test Person',
    'user_email' => 'test@example.test',
    'item' => 'Lastenrad',
    'location' => 'Teststation',
    'start_date' => '16.07.2026 10:00',
    'end_date' => '16.07.2026 18:00',
    'pickup_datetime' => '16.07.2026 10:00',
    'return_datetime' => '16.07.2026 18:00',
    'booking_code' => 'ABC123',
    'location_address' => 'Teststraße 1',
    'location_pickup_instructions' => '',
    'location_contact' => '',
    'user_full_name' => 'Test Person',
    'user_login' => 'testperson',
    'formatted_user_info' => '',
    'admin_booking_display' => '',
    'booking_comment' => '',
    'internal_comment' => '',
    'labels' => $labels,
]);

$invalidHtml = $twig->render('check-invalid.html.twig', [
    'eyebrow' => 'QR-Buchungscheck',
    'status_label' => 'Buchung nicht gefunden',
    'description' => 'Ungültiger Link.',
    'hint' => 'Vollständigen Link verwenden.',
]);

foreach ([$publicHtml, $adminHtml, $invalidHtml] as $html) {
    $assert(strpos($html, 'cb-qr-hero') !== false, 'Every QR state must render the shared status hero.');
    $assert(strpos($html, 'text-slate-') === false, 'QR templates must not depend on Tailwind utility output.');
    $svgCount = preg_match_all('/<svg\b/', $html);
    $safeSvgCount = preg_match_all('/<svg\b[^>]*\bwidth="[^"]+"[^>]*\bheight="[^"]+"[^>]*\bfill="none"[^>]*\bstroke="currentColor"/', $html);
    $assert(
        $svgCount === $safeSvgCount,
        'Every QR SVG must remain size-bounded and stroke-only even if the stylesheet is unavailable.'
    );
}

$assert(strpos($publicHtml, '<dl class="cb-qr-data-list">') !== false, 'The public view must use semantic booking details.');
$assert(substr_count($publicHtml, 'cb-qr-data-row__icon') === 4, 'The public detail rows must retain their compact visual labels.');
$assert(strpos($publicHtml, 'Heute Morgen') !== false, 'The public view must show the relative booking time prominently.');
$assert(substr_count($adminHtml, '<details class="cb-qr-disclosure">') === 3, 'The authorized view must render three compact disclosure groups.');
$assert(strpos($adminHtml, 'Vergangen') !== false, 'The authorized view must show past bookings prominently.');
$assert(strpos($invalidHtml, 'cb-qr-hint') !== false, 'The invalid state must provide a useful next step.');

echo "QR check interface tests passed.\n";
