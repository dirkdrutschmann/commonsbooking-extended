<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Reports;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\ConfirmedPosts;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper\UserStatistik;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class UserStatisticsExporter
{
    /**
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function generate_and_stream(bool $output = true): void
    {
        if (!get_transient('common_bookings_additional_features_create_user_data')) {
            (new UserStatistik())->getUserStat();
        }
        if (!get_transient('common_bookings_additional_features_create_confirmed_posts')) {
            (new ConfirmedPosts())->getConfirmedPosts();
        }

        $bookings = get_transient('common_bookings_additional_features_create_confirmed_posts');
        $monthlyStatistics = get_transient('common_bookings_additional_features_create_user_stat');

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Nutzer");
        $white = new Color(Color::COLOR_WHITE);
        $black = new Color(Color::COLOR_BLACK);

        $sheet->setCellValue([1, 1], 'Monat');
        $sheet->getStyle([1, 1])->getFont()->setBold(true);
        $sheet->getStyle([1, 1])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle([1, 1])->getFont()->setColor($white);
        $sheet->getStyle([1, 1])->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle([1, 1])->getFill()->setStartColor($black);

        $sheet->setCellValue([2, 1], 'Gesamtanzahl');
        $sheet->getStyle([2, 1])->getFont()->setBold(true);
        $sheet->getStyle([2, 1])->getFont()->setColor($white);
        $sheet->getStyle([2, 1])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle([2, 1])->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle([2, 1])->getFill()->setStartColor($black);

        $sheet->setCellValue([3, 1], 'Anstieg');
        $sheet->getStyle([3, 1])->getFont()->setBold(true);
        $sheet->getStyle([3, 1])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle([3, 1])->getFont()->setColor($white);
        $sheet->getStyle([3, 1])->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle([3, 1])->getFill()->setStartColor($black);

        $sheet->setCellValue([4, 1], 'bereinigte Gesamtanzahl');
        $sheet->getStyle([4, 1])->getFont()->setBold(true);
        $sheet->getStyle([4, 1])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle([4, 1])->getFont()->setColor($white);
        $sheet->getStyle([4, 1])->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle([4, 1])->getFill()->setStartColor($black);

        $sheet->setCellValue([5, 1], 'bereinigter Anstieg');
        $sheet->getStyle([5, 1])->getFont()->setBold(true);
        $sheet->getStyle([5, 1])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle([5, 1])->getFont()->setColor($white);
        $sheet->getStyle([5, 1])->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle([5, 1])->getFill()->setStartColor($black);

        $row = 2;
        if (!empty($monthlyStatistics) && is_array($monthlyStatistics)) {
            foreach ($monthlyStatistics as $monthYear => $stats) {
                $sheet->setCellValue([1, $row], $monthYear);
                $sheet->setCellValue([2, $row], $stats['registered_users']);
                $sheet->setCellValue([3, $row], $stats['increase']);
                $sheet->setCellValue([4, $row], $stats['cleanup_total']);
                $sheet->setCellValue([5, $row], $stats['cleaned_up_increase']);
                $row++;
            }
        }

        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle("Buchungen");
        $sheet2->setCellValue([1, 1], 'Monat');
        $sheet2->setCellValue([2, 1], 'Anzahl');
        $sheet2->getStyle([1, 1])->getFont()->setBold(true);
        $sheet2->getStyle([2, 1])->getFont()->setBold(true);

        $row = 2;
        if (!empty($bookings) && is_array($bookings)) {
            foreach ($bookings as $monthYear => $stats) {
                $sheet2->setCellValue([1, $row], $monthYear);
                $sheet2->setCellValue([2, $row], $stats['count']);
                $row++;
            }
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        if ($output) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="afcb-user-statistik.xlsx"');
            $writer->save('php://output');
            exit;
        }
    }
}
