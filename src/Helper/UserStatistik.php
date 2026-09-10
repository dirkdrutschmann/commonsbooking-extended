<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

class UserStatistik
{
    function getUserStat(): bool
    {
        $users = get_users(array('fields' => array('ID')));
        // Assuming $users is your array of user objects

        // Create an array to store user registration and last login dates
        $userDates = array();


        // Loop through each user
        foreach ($users as $user) {
            $meta = get_user_meta($user->ID);
            $lastLoginTimestamp = isset($meta['when_last_login'][0]) ? $meta['when_last_login'][0] : 0;
            $registrationTimestamp = strtotime(get_userdata($user->ID)->user_registered);
            $zipCode = get_user_meta($user->ID)['zip_code'][0] ?? '';
            $data = Zip::getPLZ($zipCode);
            $city = $data[0]['name'] ?? '';
            $state = $data[0]['federalState']['name'] ?? '';

            // Store registration and last login dates in the array
            $userDates[] = array(
                'user_id' => $user->ID,
                'registration_date' => $registrationTimestamp,
                'last_login_date' => $lastLoginTimestamp,
                'zip-code' => $zipCode,
                'city' => $city,
                'state' => $state
            );
        }

        // Sort the array based on registration dates
        usort($userDates, function ($a, $b) {
            return $a['registration_date'] - $b['registration_date'];
        });

        // Create an array to store monthly statistics
        $monthlyStatistics = array();

        // Initialize variables to keep track of the total registered users and cleaned up users
        $totalRegisteredUsers = 0;
        $cleanedUpUsers = 0;

        // Loop through the sorted userDates array
        foreach ($userDates as $userDate) {
            $monthYear = date('F Y', $userDate['registration_date']);
            $monthYear = Date::replaceEnglishMonths($monthYear);
            // If the month is not yet in the monthlyStatistics array, add it
            if (!isset($monthlyStatistics[$monthYear])) {
                $monthlyStatistics[$monthYear] = array(
                    'registered_users' => $totalRegisteredUsers,
                    'increase' => 0,
                    'cleanup_total' => $cleanedUpUsers,
                    'cleaned_up_increase' => 0
                );
            }

            // Increment registered users and calculate increase
            $totalRegisteredUsers++;
            $monthlyStatistics[$monthYear]['increase']++;

            // Update total registered users for the current month
            $monthlyStatistics[$monthYear]['registered_users'] = $totalRegisteredUsers;

            // Check if the user has been last logged in within the last two years
            if ($userDate['last_login_date'] > strtotime('-2 years')) {
                $cleanedUpUsers++;
                $monthlyStatistics[$monthYear]['cleaned_up_increase']++;
            }

            // Update cleanup total for the current month
            $monthlyStatistics[$monthYear]['cleanup_total'] = $cleanedUpUsers;
        }
        return set_transient('common_bookings_additional_features_create_user_stat', $monthlyStatistics, 60*60*24);

    }
}