<?php

if (!defined('ABSPATH')) exit;

class KLP_Holidays {
    private static $cache = [];

    public static function init() {
    }

    public static function get_dutch_holidays($year = null) {
        if (!$year) $year = (int) date('Y');

        $cache_key = "dutch_holidays_{$year}";
        if (isset(self::$cache[$cache_key])) return self::$cache[$cache_key];

        $easter = self::easter_date($year);
        $easter_monday = $easter + 86400;
        $good_friday = $easter - 86400 * 2;
        $ascension = $easter + 86400 * 39;
        $pentecost = $easter + 86400 * 49;
        $pentecost_monday = $easter + 86400 * 50;

        $result = [
            date('Y-m-d', $easter) => 'Eerste Paasdag',
            date('Y-m-d', $easter_monday) => 'Tweede Paasdag',
            date('Y-m-d', $ascension) => 'Hemelvaartsdag',
            date('Y-m-d', $pentecost) => 'Eerste Pinksterdag',
            date('Y-m-d', $pentecost_monday) => 'Tweede Pinksterdag',
            "{$year}-01-01" => 'Nieuwjaarsdag',
            "{$year}-04-27" => 'Koningsdag',
            "{$year}-05-05" => 'Bevrijdingsdag',
            "{$year}-12-25" => 'Eerste Kerstdag',
            "{$year}-12-26" => 'Tweede Kerstdag',
        ];

        self::$cache[$cache_key] = $result;
        return $result;
    }

    public static function get_closed_dates() {
        $settings = KLP_Settings::get();
        $closed = $settings['closed_dates'] ?? '';

        $dates = [];
        if (!empty($closed)) {
            $lines = explode("\n", $closed);
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $line, $m)) {
                    $dates["{$m[3]}-{$m[2]}-{$m[1]}"] = true;
                }
            }
        }

        $now = current_time('timestamp');
        for ($y = (int) date('Y', $now); $y <= (int) date('Y', $now) + 1; $y++) {
            foreach (self::get_dutch_holidays($y) as $ymd => $name) {
                $dates[$ymd] = true;
            }
        }

        return $dates;
    }

    public static function is_closed($date_ymd) {
        $closed = self::get_closed_dates();
        return isset($closed[$date_ymd]);
    }

    public static function is_holiday($date_ymd) {
        $year = (int) substr($date_ymd, 0, 4);
        $holidays = self::get_dutch_holidays($year);
        return isset($holidays[$date_ymd]);
    }

    public static function is_sunday($date_ymd) {
        $ts = strtotime($date_ymd);
        return (int) date('w', $ts) === 0;
    }

    private static function easter_date($year) {
        $a = $year % 19;
        $b = (int) ($year / 100);
        $c = $year % 100;
        $d = (int) ($b / 4);
        $e = $b % 4;
        $f = (int) (($b + 8) / 25);
        $g = (int) (($b - $f + 1) / 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = (int) ($c / 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = (int) (($a + 11 * $h + 22 * $l) / 451);
        $month = (int) (($h + $l - 7 * $m + 114) / 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return mktime(0, 0, 0, $month, $day, $year);
    }
}
