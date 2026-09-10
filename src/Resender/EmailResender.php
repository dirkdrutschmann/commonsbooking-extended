<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Resender;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\TemplateLoader;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Support\PluginPaths;

class EmailResender
{
    private const PLUGIN_SLUG = 'cbadf-email-resender';
    private const TEXT_DOMAIN = 'cb-additional-features';
    private const ADMIN_SCRIPT_HANDLE = 'afcb-email-resender';
    private const ACTION_SCRIPT_HANDLE = 'afcb-email-resender-actions';
    private const ACTION_STYLE_HANDLE = 'afcb-email-resender-actions';

    public function __construct()
    {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu'], 25);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_cbadf_resend_emails', [$this, 'handle_ajax_resend']);
        add_filter('post_row_actions', [$this, 'add_resend_email_action'], 10, 2);
        add_action('wp_ajax_cbadf_resend_single_email', [$this, 'handle_single_email_resend']);
    }

    public function init()
    {
        if (!class_exists('CommonsBooking\Repository\Booking')) {
            add_action('admin_notices', [$this, 'commonsbooking_missing_notice']);
        }
    }

    public function add_admin_menu()
    {
        add_submenu_page(
            'cbadf',
            __('CommonBooking E-Mail Resender', self::TEXT_DOMAIN),
            __('E-Mail Resender', self::TEXT_DOMAIN),
            'manage_options',
            self::PLUGIN_SLUG,
            [$this, 'admin_page']
        );
    }

    public function enqueue_admin_scripts($hook)
    {
        if (strpos($hook, self::PLUGIN_SLUG) !== false) {
            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('wp-jquery-ui-dialog');

            wp_enqueue_script(
                self::ADMIN_SCRIPT_HANDLE,
                PluginPaths::asset_url('assets/js/afcb-email-resender.js'),
                ['jquery'],
                '0.1',
                true
            );
            wp_localize_script(self::ADMIN_SCRIPT_HANDLE, 'afcbEmailResender', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cbadf_resend_emails'),
                'texts' => [
                    'missingId' => __('Bitte geben Sie eine Buchungs-ID ein.', self::TEXT_DOMAIN),
                    'missingDate' => __('Bitte geben Sie ein Startdatum ein.', self::TEXT_DOMAIN),
                    'errorLabel' => __('Fehler:', self::TEXT_DOMAIN),
                    'ajaxErrorLabel' => __('AJAX Fehler:', self::TEXT_DOMAIN),
                ],
            ]);
        }

        if ($this->is_booking_list_screen($hook)) {
            wp_enqueue_script('jquery');
            wp_enqueue_script(
                self::ACTION_SCRIPT_HANDLE,
                PluginPaths::asset_url('assets/js/afcb-email-resender-actions.js'),
                ['jquery'],
                '0.1',
                true
            );
            wp_localize_script(self::ACTION_SCRIPT_HANDLE, 'afcbEmailResenderActions', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cbadf_resend_single_email'),
                'texts' => [
                    'confirm' => __('Moechten Sie die Bestaetigungs-E-Mail fuer diese Buchung wirklich erneut versenden?', self::TEXT_DOMAIN),
                    'sending' => __('Wird versendet...', self::TEXT_DOMAIN),
                    'sent' => __('Versendet', self::TEXT_DOMAIN),
                    'errorLabel' => __('Fehler:', self::TEXT_DOMAIN),
                    'ajaxErrorLabel' => __('AJAX-Fehler:', self::TEXT_DOMAIN),
                    'dismissLabel' => __('Diese Benachrichtigung ausblenden', self::TEXT_DOMAIN),
                ],
            ]);
            wp_enqueue_style(
                self::ACTION_STYLE_HANDLE,
                PluginPaths::asset_url('assets/css/afcb-email-resender-actions.css'),
                [],
                '0.1'
            );
        }
    }

    public function admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sie haben keine Berechtigung fuer diese Seite.', self::TEXT_DOMAIN));
        }
        echo TemplateLoader::load()->render('admin/email-resender.html.twig', [
            'page_title' => __('CommonBooking E-Mail Resender', self::TEXT_DOMAIN),
            'form_title' => __('Buchungs-E-Mail erneut versenden', self::TEXT_DOMAIN),
            'form_subtitle' => __('Geben Sie die Buchungs-ID ein, um die Bestaetigungs-E-Mail fuer diese Buchung erneut zu versenden:', self::TEXT_DOMAIN),
            'field_label' => __('Buchungs-ID', self::TEXT_DOMAIN),
            'field_help' => __('Geben Sie die ID einer spezifischen Buchung ein.', self::TEXT_DOMAIN),
            'field_placeholder' => __('z.B. 123', self::TEXT_DOMAIN),
            'submit_label' => __('E-Mail versenden', self::TEXT_DOMAIN),
            'range_title' => __('Buchungen im Zeitraum', self::TEXT_DOMAIN),
            'range_subtitle' => __('Versendet E-Mails fuer alle Buchungen ab einem Startdatum.', self::TEXT_DOMAIN),
            'date_label' => __('Startdatum', self::TEXT_DOMAIN),
            'date_help' => __('Alle Buchungen ab diesem Datum (inklusive) werden beruecksichtigt.', self::TEXT_DOMAIN),
            'date_placeholder' => __('YYYY-MM-DD', self::TEXT_DOMAIN),
            'status_label' => __('Status-Filter', self::TEXT_DOMAIN),
            'status_help' => __('Optional: auch unbestaetigte Buchungen einbeziehen.', self::TEXT_DOMAIN),
            'status_options' => [
                [
                    'value' => 'confirmed',
                    'label' => __('Nur bestaetigte Buchungen', self::TEXT_DOMAIN),
                    'selected' => true,
                ],
                [
                    'value' => 'all',
                    'label' => __('Alle Buchungen (bestaetigt + unbestaetigt)', self::TEXT_DOMAIN),
                ],
            ],
            'batch_label' => __('Batch-Groesse', self::TEXT_DOMAIN),
            'batch_help' => __('Begrenzt die Anzahl der E-Mails pro Lauf.', self::TEXT_DOMAIN),
            'batch_options' => [
                ['value' => '10', 'label' => '10', 'selected' => true],
                ['value' => '25', 'label' => '25'],
                ['value' => '50', 'label' => '50'],
                ['value' => '100', 'label' => '100'],
                ['value' => 'all', 'label' => __('Alle', self::TEXT_DOMAIN)],
            ],
            'preview_label' => __('Vorschau-Modus (keine E-Mails senden)', self::TEXT_DOMAIN),
            'preview_help' => __('Im Vorschau-Modus werden keine E-Mails versendet.', self::TEXT_DOMAIN),
            'range_submit_label' => __('E-Mails versenden', self::TEXT_DOMAIN),
            'loading_title' => __('Verarbeitung', self::TEXT_DOMAIN),
            'loading_text' => __('Verarbeitung laeuft...', self::TEXT_DOMAIN),
            'results_title' => __('Ergebnisse', self::TEXT_DOMAIN),
            'nonce_field' => wp_nonce_field('cbadf_resend_emails', 'cbadf_resend_nonce', true, false),
        ]);
    }

    public function handle_ajax_resend()
    {
        if (!check_ajax_referer('cbadf_resend_emails', 'cbadf_resend_nonce', false)) {
            wp_send_json_error(__('Sicherheitspruefung fehlgeschlagen.', self::TEXT_DOMAIN));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Keine Berechtigung.', self::TEXT_DOMAIN));
        }

        if (!class_exists('CommonsBooking\Repository\Booking')) {
            wp_send_json_error(__('CommonBooking Plugin ist nicht aktiv.', self::TEXT_DOMAIN));
        }

        $request = [
            'resend_type' => isset($_POST['resend_type']) ? sanitize_text_field($_POST['resend_type']) : '',
            'post_id' => isset($_POST['post_id']) ? intval($_POST['post_id']) : 0,
            'start_date' => isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '',
            'preview_start_date' => isset($_POST['preview_start_date']) ? sanitize_text_field($_POST['preview_start_date']) : '',
            'booking_status' => isset($_POST['booking_status']) ? sanitize_text_field($_POST['booking_status']) : '',
            'batch_size' => isset($_POST['batch_size']) ? sanitize_text_field($_POST['batch_size']) : '10',
        ];

        try {
            $processor = new EmailResenderProcessor();
            $result = $processor->process($request);
            $html = TemplateLoader::load()->render('admin/email-resender-results.html.twig', [
                'result' => $result,
            ]);
            wp_send_json_success(['html' => $html]);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'afcb_json_sent') {
                throw $e;
            }
            wp_send_json_error($e->getMessage());
        } catch (\Exception $e) {
            wp_send_json_error(__('Kritischer Fehler: ', self::TEXT_DOMAIN) . $e->getMessage());
        }
    }

    public function add_resend_email_action($actions, $post)
    {
        if ($post->post_type !== 'cb_booking') {
            return $actions;
        }

        if (get_post_meta($post->ID, 'type', true) !== '6') {
            return $actions;
        }

        if (!current_user_can('manage_options')) {
            return $actions;
        }

        $actions['cbadf_resend_email'] = TemplateLoader::load()->render('admin/resend-email-action.html.twig', [
            'booking_id' => (int) $post->ID,
            'title' => __('Bestaetigungs-E-Mail fuer diese Buchung erneut versenden', self::TEXT_DOMAIN),
            'label' => __('Email erneut versenden', self::TEXT_DOMAIN),
        ]);

        return $actions;
    }

    public function handle_single_email_resend()
    {
        if (!check_ajax_referer('cbadf_resend_single_email', 'nonce', false)) {
            wp_send_json_error(__('Sicherheitspruefung fehlgeschlagen.', self::TEXT_DOMAIN));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Keine Berechtigung.', self::TEXT_DOMAIN));
        }

        if (!class_exists('CommonsBooking\Repository\Booking')) {
            wp_send_json_error(__('CommonBooking Plugin ist nicht aktiv.', self::TEXT_DOMAIN));
        }

        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;

        try {
            $booking = \CommonsBooking\Repository\Booking::getPostById($booking_id);

            if (!$booking || get_post_meta($booking_id, 'type', true) !== '6') {
                wp_send_json_error(__('Buchung wurde nicht gefunden oder ist keine bestaetigte Buchung.', self::TEXT_DOMAIN));
            }

            $booking_message = new \CommonsBooking\Messages\BookingMessage($booking_id, 'confirmed');
            $booking_message->triggerMail();

            $user_data = $booking->getUserData();
            $success_message = sprintf(
                __('E-Mail erfolgreich versendet an %s fuer Buchung #%d', self::TEXT_DOMAIN),
                $user_data->user_email,
                $booking_id
            );

            wp_send_json_success($success_message);

        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'afcb_json_sent') {
                throw $e;
            }
            wp_send_json_error(__('Fehler beim Versenden: ', self::TEXT_DOMAIN) . $e->getMessage());
        } catch (\Exception $e) {
            wp_send_json_error(__('Fehler beim Versenden: ', self::TEXT_DOMAIN) . $e->getMessage());
        }
    }

    public function commonsbooking_missing_notice()
    {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        if (!in_array($screen->id, ['cbadf_page_' . self::PLUGIN_SLUG, 'edit-cb_booking'], true)) {
            return;
        }
        echo TemplateLoader::load()->render('admin/wp-notice.html.twig', [
            'type' => 'warning',
            'title' => __('CommonBooking E-Mail Resender:', self::TEXT_DOMAIN),
            'message' => __('Das CommonBooking Plugin muss installiert und aktiviert sein fuer die volle Funktionalitaet.', self::TEXT_DOMAIN),
        ]);
    }

    private function is_booking_list_screen(string $hook): bool
    {
        if ($hook !== 'edit.php' || !function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        return $screen->id === 'edit-' . $this->get_booking_post_type();
    }

    private function get_booking_post_type(): string
    {
        if (class_exists('\\CommonsBooking\\Wordpress\\CustomPostType\\Booking')) {
            return \CommonsBooking\Wordpress\CustomPostType\Booking::$postType;
        }

        return 'cb_booking';
    }
}
