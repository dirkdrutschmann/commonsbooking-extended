<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Shortcodes;

use CommonsBooking\Repository\Booking;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;


class Shortcode
{
    public function __construct()
    {
        add_shortcode('afcb_bookings', [$this, 'bookings']);
        add_shortcode('cb_bookings', [$this, 'bookings']);
        add_shortcode('cbaf_bookings', [$this, 'bookings']);
    }

    private function enqueue_tailwind(): void
    {
        if (wp_style_is('mlr-modern-theme', 'enqueued')) {
            return;
        }

        wp_enqueue_script('afcb-tailwind', 'https://cdn.tailwindcss.com', [], null, false);
        wp_add_inline_script(
            'afcb-tailwind',
            'window.tailwind = window.tailwind || {}; window.tailwind.config = { corePlugins: { preflight: false } };',
            'before'
        );
    }

    public function bookings($atts, $content, $tag)
    {
        $this->enqueue_tailwind();

        $bookings = [];
        if (is_user_logged_in()) {
            $bookings = Booking::getForUserPaginated(
                wp_get_current_user(),
                1,
                50,
                [
                    'meta_query' => [
                        'relation' => 'AND',
                        [
                            'key' => 'type',
                            'value' => \CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKING_ID,
                            'compare' => '=',
                        ],
                        [
                            'key' => \CommonsBooking\Model\Timeframe::REPETITION_END,
                            'value' => strtotime('-23 Hours -59 Minutes -59 Seconds'),
                            'type' => 'NUMERIC',
                            'compare' => '>=',
                        ],
                    ],
                    'meta_key' => \CommonsBooking\Model\Timeframe::REPETITION_START,
                    'orderby' => 'meta_value_num',
                    'order' => 'ASC',
                    'no_found_rows' => true,
                    'update_post_meta_cache' => true,
                    'update_post_term_cache' => false,
                ],
                ['confirmed', 'unconfirmed']
            );
        }

        $bookingArray = [];
        foreach ($bookings as $booking) {
            $item = null;
            try {
                $item = $booking->getItem();
            } catch (\Throwable $exception) {
                // Keep the booking accessible even if its former item was removed.
            }

            $location = null;
            try {
                $location = $booking->getLocation();
            } catch (\Throwable $exception) {
                // Keep the booking accessible even if its former location was removed.
            }

            try {
                $isFullDay = $booking->isFullDay();
                $startTime = $isFullDay ? '' : trim((string) $booking->getStartTime());
            } catch (\Throwable $exception) {
                $startTime = '';
            }

            $bookingArray[] = [
                'booking' => $booking,
                'picture' => $item ? (int) $item->ID : 0,
                'item' => $item ? (string) $item->post_title : 'Nicht mehr verfügbares Lastenrad',
                'location' => $location ? (string) $location->post_title : 'Nicht mehr verfügbarer Standort',
                'startTime' => $startTime,
                'startDate' => date_i18n('d.m.Y', $booking->getStartDate()),
                'endDate' => $booking->getEndDate(),
                'link' => $booking->bookingLinkUrl(),
            ];
        }
        $commonbookings_additional_features_options = get_option(
            'commonbookings_additional_features_option_name'
        );

        $history_page_id = (int) ($commonbookings_additional_features_options['buchung_historie_1'] ?? 0);
        $link = $history_page_id > 0 ? get_page_link($history_page_id) : '';

        return TemplateLoader::load()->render('booking.html.twig', [
            'bookings' => $bookingArray,
            'historie' => $link,
        ]);
    }

}
