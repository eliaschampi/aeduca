<?php

namespace App\Support\Attendance;

/** Semantic manual mutations — not a free state picker. */
enum AttendanceOperation: string
{
    case Arrival = 'arrival';
    case Permission = 'permission';
    case Justify = 'justify';
    case Correct = 'correct';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
