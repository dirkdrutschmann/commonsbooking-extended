<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Resender;

class EmailResenderProcessor
{
    private const TEXT_DOMAIN = 'cb-additional-features';

    public function process(array $request): array
    {
        $resend_type = $request['resend_type'] ?? '';
        $is_preview = ($resend_type === 'preview');

        $bookings = $this->resolve_bookings($resend_type, $request);
        if (empty($bookings)) {
            return [
                'empty' => true,
                'warnings' => [],
                'summary' => [],
                'bookings' => [],
                'errors' => [],
                'completed_at' => date('d.m.Y H:i:s'),
            ];
        }

        $warnings = [];
        $batch_size = $request['batch_size'] ?? '10';
        if ($batch_size !== 'all' && !$is_preview && count($bookings) > intval($batch_size)) {
            $batch_size = intval($batch_size);
            $total_bookings = count($bookings);
            $bookings = array_slice($bookings, 0, $batch_size);
            $warnings[] = sprintf(__('Batch-Verarbeitung aktiv: %d von %d Buchungen werden verarbeitet.', self::TEXT_DOMAIN), count($bookings), $total_bookings);
            $warnings[] = sprintf(__('Verbleibende Buchungen: %d', self::TEXT_DOMAIN), $total_bookings - $batch_size);
            $warnings[] = __('Fuehren Sie das Plugin erneut aus, um weitere Buchungen zu verarbeiten.', self::TEXT_DOMAIN);
        }

        $success_count = 0;
        $error_count = 0;
        $errors = [];
        $booking_rows = [];

        foreach ($bookings as $booking) {
            $booking_id = $booking->ID;
            $messages = [];

            try {
                $user_data = $booking->getUserData();
                $item = $booking->getItem();
                $location = $booking->getLocation();
                $start_date_formatted = date('d.m.Y H:i', $booking->getStartDate());
                $end_date_formatted = date('d.m.Y H:i', $booking->getEndDate());
                $status = $booking->getPost()->post_status;

                if (!$is_preview) {
                    try {
                        $booking_message = new \CommonsBooking\Messages\BookingMessage($booking_id, 'confirmed');
                        $booking_message->triggerMail();
                        $messages[] = ['type' => 'success', 'text' => __('OK E-Mail versendet', self::TEXT_DOMAIN)];
                        $success_count++;
                        sleep(2);
                    } catch (\Exception $mail_exception) {
                        $error_message = $mail_exception->getMessage();

                        if (
                            strpos($error_message, 'mail send limit exceeded') !== false ||
                            strpos($error_message, 'mailbox unavailable') !== false ||
                            strpos($error_message, '450') !== false
                        ) {
                            $messages[] = ['type' => 'error', 'text' => __('PAUSE E-Mail-Limit erreicht - pausiere 30 Sekunden', self::TEXT_DOMAIN)];
                            sleep(30);

                            try {
                                $booking_message = new \CommonsBooking\Messages\BookingMessage($booking_id, 'confirmed');
                                $booking_message->triggerMail();
                                $messages[] = ['type' => 'success', 'text' => __('OK E-Mail nach Pause erfolgreich versendet', self::TEXT_DOMAIN)];
                                $success_count++;
                            } catch (\Exception $retry_exception) {
                                $messages[] = ['type' => 'error', 'text' => sprintf(__('FEHLER E-Mail auch nach Pause fehlgeschlagen: %s', self::TEXT_DOMAIN), $retry_exception->getMessage())];
                                $errors[] = sprintf(__('Buchung #%d: %s', self::TEXT_DOMAIN), $booking_id, $retry_exception->getMessage());
                                $error_count++;
                            }
                        } else {
                            $messages[] = ['type' => 'error', 'text' => sprintf(__('FEHLER E-Mail-Fehler: %s', self::TEXT_DOMAIN), $error_message)];
                            $errors[] = sprintf(__('Buchung #%d: %s', self::TEXT_DOMAIN), $booking_id, $error_message);
                            $error_count++;
                        }

                        sleep(1);
                    }
                } else {
                    $messages[] = ['type' => 'warning', 'text' => __('PREVIEW Vorschau (E-Mail nicht versendet)', self::TEXT_DOMAIN)];
                }

                $booking_rows[] = [
                    'id' => $booking_id,
                    'user' => $user_data->display_name,
                    'email' => $user_data->user_email,
                    'item' => $item->post_title,
                    'location' => $location->post_title,
                    'start_date' => $start_date_formatted,
                    'end_date' => $end_date_formatted,
                    'status' => $status,
                    'messages' => $messages,
                ];
            } catch (\Exception $e) {
                $errors[] = sprintf(__('Buchung #%d: %s', self::TEXT_DOMAIN), $booking_id, $e->getMessage());
                $error_count++;
                $booking_rows[] = [
                    'id' => $booking_id,
                    'user' => '',
                    'email' => '',
                    'item' => '',
                    'location' => '',
                    'start_date' => '',
                    'end_date' => '',
                    'status' => '',
                    'messages' => [
                        ['type' => 'error', 'text' => sprintf(__('FEHLER Fehler: %s', self::TEXT_DOMAIN), $e->getMessage())],
                    ],
                ];
            }
        }

        return [
            'empty' => false,
            'warnings' => $warnings,
            'summary' => [
                'total' => count($bookings),
                'preview' => $is_preview,
                'success_count' => $success_count,
                'error_count' => $error_count,
            ],
            'bookings' => $booking_rows,
            'errors' => $errors,
            'completed_at' => date('d.m.Y H:i:s'),
        ];
    }

    private function resolve_bookings(string $resend_type, array $request): array
    {
        switch ($resend_type) {
            case 'post_id':
                $post_id = isset($request['post_id']) ? intval($request['post_id']) : 0;
                if ($post_id <= 0) {
                    throw new \RuntimeException(__('Bitte geben Sie eine gueltige Buchungs-ID an.', self::TEXT_DOMAIN));
                }

                $booking = \CommonsBooking\Repository\Booking::getPostById($post_id);
                if (!$booking || get_post_meta($post_id, 'type', true) !== '6') {
                    throw new \RuntimeException(__('Buchung mit dieser ID wurde nicht gefunden.', self::TEXT_DOMAIN));
                }

                return [$booking];

            case 'date_range':
            case 'preview':
                $start_key = ($resend_type === 'preview') ? 'preview_start_date' : 'start_date';
                $start_date_str = isset($request[$start_key]) ? sanitize_text_field($request[$start_key]) : '';
                $start_date = strtotime($start_date_str . ' 00:00:00');
                $end_date = time();

                if (!$start_date) {
                    throw new \RuntimeException(__('Ungueltiges Startdatum.', self::TEXT_DOMAIN));
                }

                $booking_status = isset($request['booking_status']) ? sanitize_text_field($request['booking_status']) : 'confirmed';
                $status_filter = ($resend_type === 'date_range' && $booking_status === 'all')
                    ? ['confirmed', 'unconfirmed']
                    : ['confirmed'];

                return \CommonsBooking\Repository\Booking::getByTimerange(
                    $start_date,
                    $end_date,
                    null,
                    null,
                    [],
                    $status_filter
                );

            default:
                throw new \RuntimeException(__('Ungueltige Anfrage.', self::TEXT_DOMAIN));
        }
    }
}
