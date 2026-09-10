<?php
namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

class Date
{
    public static function diff($start, $end)
    {
        $first = strtotime($start);
        $second = strtotime($end);
        $kleiner = '';
        $groesser = '';
        if ($first > $second) {
            $kleiner = $second;
            $groesser = $first;
        } else {
            $kleiner = $first;
            $groesser = $second;
        }

        $y = round(($groesser - $kleiner) / 60 / 60 / 24 / 365, 0);
        $m = round(
            ($groesser - $kleiner - $y * 60 * 60 * 24 * 365) /
                60 /
                60 /
                24 /
                30,
            0
        );
        $d = round(
            ($groesser -
                $kleiner -
                $y * 60 * 60 * 24 * 365 -
                $m * 60 * 60 * 24 * 30) /
                60 /
                60 /
                24,
            0
        );
        $h = round(
            ($groesser -
                $kleiner -
                $y * 60 * 60 * 24 * 365 -
                $m * 60 * 60 * 24 * 30 -
                $d * 60 * 60 * 24) /
                60 /
                60,
            0
        );
        $min = round(
            ($groesser -
                $kleiner -
                $y * 60 * 60 * 24 * 365 -
                $m * 60 * 60 * 24 * 30 -
                $d * 60 * 60 * 24 -
                $h * 60 * 60) /
                60,
            0
        );
        $sec = round(
            $groesser -
                $kleiner -
                $y * 60 * 60 * 24 * 365 -
                $m * 60 * 60 * 24 * 30 -
                $d * 60 * 60 * 24 -
                $h * 60 * 60 -
                $min * 60,
            0
        );
        $return = '';
        if ($y > 1) {
            $return .= $y . ' Jahre ';
        } elseif ($y > 0) {
            $return .= $y . ' Jahr ';
        }
        if ($m > 1) {
            $return .= $m . ' Monate ';
        } elseif ($m > 0) {
            $return .= $m . ' Monat ';
        }
        if ($d > 1) {
            $return .= $d . ' Tage ';
        } elseif ($d > 0) {
            $return .= $d . ' Tag ';
        }
        if ($h > 1) {
            $return .= $h . ' Stunden ';
        } elseif ($h > 0) {
            $return .= $h . ' Stunde ';
        }
        if ($min > 1) {
            $return .= $min . ' Minuten ';
        } elseif ($min > 0) {
            $return .= $min . ' Minute ';
        }
        if ($sec > 1) {
            $return .= $sec . ' Sekunden ';
        } elseif ($sec > 0) {
            $return .= $sec . ' Sekunde';
        }
        return $return;
    }

    static function replaceEnglishMonths($input)
    {
        $englishMonths = array(
            'January', 'February', 'March', 'April',
            'May', 'June', 'July', 'August',
            'September', 'October', 'November', 'December'
        );

        $germanMonths = array(
            'Jan', 'Feb', 'Mär', 'Apr',
            'Mai', 'Jun', 'Jul', 'Aug',
            'Sep', 'Okt', 'Nov', 'Dez'
        );

        $output = str_replace($englishMonths, $germanMonths, $input);

        return $output;
    }
}
