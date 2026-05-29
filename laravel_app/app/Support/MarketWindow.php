<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class MarketWindow
{
    public static function timezone(): string
    {
        return (string) config('services.market_window.timezone', 'America/New_York');
    }

    public static function isWeekdayEt(): bool
    {
        return now(self::timezone())->isWeekday();
    }

    public static function isWithinEtWindow(string $start, string $end): bool
    {
        $currentTime = now(self::timezone())->format('H:i');

        return self::isWeekdayEt()
            && $currentTime >= self::normalizeTime($start)
            && $currentTime <= self::normalizeTime($end);
    }

    public static function nowEtString(): string
    {
        return now(self::timezone())->format('Y-m-d H:i:s');
    }

    public static function nowEt(): Carbon
    {
        return now(self::timezone());
    }

    private static function normalizeTime(string $time): string
    {
        return Carbon::createFromFormat('H:i', $time, self::timezone())->format('H:i');
    }
}
