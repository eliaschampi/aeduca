<?php

namespace App\Support\Attendance;

/**
 * Stored attendance outcomes. pending/absent are read-time only.
 */
enum AttendanceState: string
{
    case Present = 'present';
    case Late = 'late';
    case Permission = 'permission';
    case Justified = 'justified';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Presente',
            self::Late => 'Tardanza',
            self::Permission => 'Permiso',
            self::Justified => 'Justificado',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
