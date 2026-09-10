<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Plugin;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Cron\CronJob;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\BookingUserFilter;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Listener\Listener;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\QrCode\QrCodeSender;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Resender\EmailResender;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\AdminMenu;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages\BlacklistSettingsPage;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages\BorrowListSettingsPage;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages\QrCodeSettingsPage;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages\StatisticsPage;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Shortcodes\Shortcode;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\UserManagement;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\UserManagementAdmin;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Support\EnvironmentSafety;
use PhpOffice\PhpSpreadsheet\Exception;

defined('ABSPATH') or die("Thanks for visting");

class Plugin
{

    public function __construct()
    {
        EnvironmentSafety::register();
        add_action('wp_enqueue_scripts', [$this, 'scripts']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_action( 'plugins_loaded', [$this,'my_plugin_init'] );     
        $shortcode = new Shortcode();
        $listener = new Listener();
        $cronjob = new CronJob();
        $qrCodeSender = new QrCodeSender();
        $userManagement = new UserManagement();
        // Check if the class exists
        if (is_admin()) {
            $adminMenu = new AdminMenu();
            $blacklistSettings = new BlacklistSettingsPage();
            $borrowListSettings = new BorrowListSettingsPage();
            $qrCodeSettings = new QrCodeSettingsPage();
            $statisticsPage = new StatisticsPage();
            $emailResender = new EmailResender();
            $bookingUserFilter = new BookingUserFilter();
            $userManagementAdmin = new UserManagementAdmin();
        }
    }

    public static function activate(): void
    {
        if (class_exists(UserManagement::class)) {
            UserManagement::activate();
        }
    }


    function my_plugin_init() {
        if( !class_exists('CommonsBooking\Plugin') ) {
            add_action('admin_notices', [$this, 'custom_admin_notice']);
        }
    }

    function custom_admin_notice()
    {
        echo TemplateLoader::load()->render('admin/wp-notice.html.twig', [
            'type' => 'error',
            'message' => 'Das Plugin "Common Bookings" von wielebenwir e.V. wurde nicht gefunden! Additonal Features sind nur mit installiertem und aktiviertem Plugin möglich! ',
            'link_url' => 'https://wordpress.org/plugins/commonsbooking/',
            'link_label' => 'Zum Plugin',
        ]);
    }

    public function scripts()
    {
        wp_enqueue_script('jquery');
        wp_register_style(
            'my-stylesheet',
            plugin_dir_url(dirname(__DIR__)) . '/assets/css/styles.css'
        );

        wp_enqueue_style('my-stylesheet');

        wp_register_style(
            'bootstrap-style',
            plugin_dir_url(dirname(__DIR__)) . '/assets/css/bootstrap.min.css',
            [],
            '5.1.3'
        );
      
    }

    public function admin_scripts($hook = '')
    {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page === '' || strpos($page, 'cbadf') !== 0) {
            return;
        }

        wp_enqueue_script('jquery');

        wp_enqueue_script('afcb-tailwind', 'https://cdn.tailwindcss.com', [], null, false);
        wp_add_inline_script(
            'afcb-tailwind',
            'window.tailwind = window.tailwind || {}; window.tailwind.config = { corePlugins: { preflight: false } };',
            'before'
        );

        wp_register_style(
            'admin-stylesheet',
            plugin_dir_url(dirname(__DIR__)) . '/assets/css/admin-styles.css'
        );
        wp_enqueue_style('admin-stylesheet');

        if ($page === 'cbadf-blacklist') {
            $scriptPath = dirname(__DIR__, 2) . '/assets/js/afcb-booking-groups.js';
            wp_enqueue_script(
                'afcb-booking-groups',
                plugin_dir_url(dirname(__DIR__)) . '/assets/js/afcb-booking-groups.js',
                [],
                is_readable($scriptPath) ? (string) filemtime($scriptPath) : null,
                true
            );
        }

    }
}
