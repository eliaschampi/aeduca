<?php

namespace App\Support\StudentAttentions;

enum StudentAttentionType: string
{
    case Medical = 'medical';
    case Conduct = 'conduct';
    case Attention = 'attention';
    case Search = 'search';
    case AttendancePermission = 'attendance_permission';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Medical => 'Incidencia médica',
            self::Conduct => 'Incidencia por conducta',
            self::Attention => 'Atención',
            self::Search => 'Requisa',
            self::AttendancePermission => 'Permiso de asistencia',
            self::Other => 'Otro',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ],
            self::cases(),
        );
    }
}
