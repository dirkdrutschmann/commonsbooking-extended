<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

use CommonsBooking\Model\Booking as ModelBooking;
use CommonsBooking\Wordpress\CustomPostType\Booking as CustomPostTypeBooking;
class ConfirmedPosts
{
    function getConfirmedPosts(): bool
    {

        $args = array(
            'post_type' => CustomPostTypeBooking::$postType, // Ändere dies, wenn du nach einem anderen Post-Typ suchen möchtest
            'posts_per_page' => -1,
            'post_status' => 'confirmed', // Anzahl der zurückgegebenen Beiträge (-1 für alle Beiträge)
            'meta_key' => 'repetition-start', // Add meta key to sort by
            'orderby' => 'meta_value_num', // Sort by meta value as a number
            'order' => 'DESC',
        );
        $query = new \WP_Query($args);
        $cleaned = $this->cleanUpBookings($query->posts);
        return set_transient('common_bookings_additional_features_create_confirmed_posts', $cleaned, 60*60*24);
    }

    private function cleanUpBookings($bookings): array
    {
        $newBookings = array();

        foreach ($bookings as $booking) {
            $model = new ModelBooking($booking->ID);
            $author_data = get_userdata($model->post_author);


            $newBookings[] = [
                'item' => $model->getItem()->ID ?? "noItem",
                'itemName' => $model->getItem()->post_title ?? "noItem",
                'location' => $model->getLocation()->ID ?? "noLocation",
                'locationName' => $model->getLocation()->post_title ?? "noLocation",
                'startDate' => $model->getStartDate(),
                'endDate' => $model->getEndDate(),
                'user' => $model->post_author ?? "noUser",
                'userFirst' => $author_data->first_name ?? "noFirst",
                'userLast' => $author_data->last_name ?? "noLast",
                'userNick' => $author_data->user_login ?? "noLogin"
            ];
        }
        return $newBookings;

    }
}