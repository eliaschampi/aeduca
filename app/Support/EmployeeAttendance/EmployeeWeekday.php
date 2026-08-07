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

    public static function label(int $weekday): string
    {
        return self::LABELS[$weekday] ?? (string) $weekday;
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
