<?php

namespace App\Support\Business;

use Carbon\CarbonImmutable;

/**
 * Carrión's operational date owner. Database timestamps remain timezone-aware;
 * academic day decisions use the institution's Lima calendar.
 */
final class BusinessCalendar
{
    private const string TIMEZONE = 'America/Lima';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    public static function parseDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
    }
}
