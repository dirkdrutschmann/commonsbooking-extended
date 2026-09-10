<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Cron;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\ConfirmedPosts;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\UserStatistik;
use PhpOffice\PhpSpreadsheet\Exception;

class CronJob
{
    public function __construct()
    {
        add_action('common_bookings_additional_features_statistik_posts', [$this, 'getPosts']);
        add_action('common_bookings_additional_features_statistik_user', [$this, 'getUser']);
        if (!wp_next_scheduled('common_bookings_additional_features_statistik_posts')) {
            wp_schedule_event(time(), 'hourly', 'common_bookings_additional_features_statistik_posts');
        }
        if (!wp_next_scheduled('common_bookings_additional_features_statistik_user')) {
            wp_schedule_event(time(), 'hourly', 'common_bookings_additional_features_statistik_user');
        }
    }


    /**
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    function getPosts(): bool
    {
        delete_transient('common_bookings_additional_features_create_confirmed_posts');
        return (new ConfirmedPosts())->getConfirmedPosts();
    }

    /**
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    function getUser(): bool
    {
        delete_transient('common_bookings_additional_features_create_user_stat');
        return (new UserStatistik())->getUserStat();
    }




}
