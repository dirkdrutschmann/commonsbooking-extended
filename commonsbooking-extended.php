<?php
/*
Plugin Name: CommonsBooking Extended
Description: Dieses Plugin erweitert Common Bookings um zusätzliche Verwaltungsfunktionen, Buchungshistorie und Sidebar-Funktionen.
Author: Dirk Drutschmann
Version: 0.7.12
*/

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Plugin\Plugin;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined('ABSPATH') or die("Thanks for visting");

require 'vendor/autoload.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);

$loader = new Plugin();

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://updates.drutschmann.dev/?action=get_metadata&slug=commonsbooking-extended',
    __FILE__, //Full path to the main plugin file or functions.php.
    'commonsbooking-extended'
);
