<?php

namespace App\Support\Attendance;

enum AttendanceMethod: string
{
    case Scan = 'scan';
    case Manual = 'manual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
