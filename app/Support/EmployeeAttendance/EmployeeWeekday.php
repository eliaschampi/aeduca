<?php

namespace App\Support\EmployeeAttendance;

/** ISO weekday 1=Monday … 7=Sunday (PostgreSQL EXTRACT(ISODOW)). */
final class EmployeeWeekday
{
    /** @var array<int, string> */
    public const LABELS = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    /** @var array<int, string> */
    public const SHORT = [
        1 => 'Lun',
        2 => 'Mar',
        3 => 'Mié',
        4 => 'Jue',
        5 => 'Vie',
        6 => 'Sáb',
        7 => 'Dom',
    ];

    public static function label(int $weekday): string
    {
        return self::LABELS[$weekday] ?? (string) $weekday;
    }

    public static function short(int $weekday): string
    {
        return self::SHORT[$weekday] ?? (string) $weekday;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        $options = [];
        foreach (self::LABELS as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }
}
