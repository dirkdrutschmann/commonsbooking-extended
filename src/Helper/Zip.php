<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

class Zip
{
    static function getPLZ($zip)
    {

        if (!self::isFiveDigits($zip)) {
            return array();
        }
        // Check if the data is already cached
        if (get_transient('common_bookings_additional_features_create_zip_'.$zip)) {
            return get_transient('common_bookings_additional_features_create_zip_'.$zip);
        }

        // URL for the OpenPLZAPI
        $url = 'https://openplzapi.org/de/Localities?postalCode=' . $zip;

        // Initialize cURL session
        $curl = curl_init($url);

        // Set cURL options
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // Execute cURL session and get the response
        $response = curl_exec($curl);

        // Decode the JSON response
        $data = json_decode($response, true);

        // Check if the response contains data
        if (!empty($data)) {
            curl_close($curl);
            // Cache the results
            set_transient('common_bookings_additional_features_create_zip_'.$zip, $data);
            return $data;
        } else {
            curl_close($curl);
            return array();
        }
    }

    private static function isFiveDigits($str)
    {
        return preg_match('/^\d{5}$/', $str) === 1;
    }

}