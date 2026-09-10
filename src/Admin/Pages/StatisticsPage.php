<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\UserStatsDashboard;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Reports\UserStatisticsExporter;

class StatisticsPage
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'handle_excel_request']);
    }

    public function register_page(): void
    {
        add_submenu_page(
            'cbadf',
            'Statistik',
            'Statistik',
            'manage_options',
            'cbadf-statistik',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        $force_refresh = isset($_GET['afcb_refresh_stats']);
        $stats = UserStatsDashboard::get_stats($force_refresh);
        $refresh_url = add_query_arg(
            'afcb_refresh_stats',
            1,
            admin_url('admin.php?page=cbadf-statistik')
        );
        $timestamp = !empty($stats['generated_at']) ? (int) $stats['generated_at'] : 0;
        if ($timestamp > 0) {
            $generated_at = function_exists('wp_date')
                ? wp_date('d.m.Y H:i', $timestamp)
                : date_i18n('d.m.Y H:i', $timestamp);
        } else {
            $generated_at = '';
        }

        $context = [
            'refresh_url' => esc_url($refresh_url),
            'generated_at' => $generated_at ?: 'unbekannt',
            'stats' => $stats,
        ];

        echo TemplateLoader::load()->render('admin/statistics.html.twig', $context);
    }

    public function handle_excel_request(): void
    {
        if (!isset($_POST['generate_excel'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Sie haben keine Berechtigung fuer diese Aktion.', 'cb-additional-features'));
        }

        (new UserStatisticsExporter())->generate_and_stream(true);
    }
}
