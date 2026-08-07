<?php

namespace App\Support\EmployeeAttendance;

enum EmployeeAttendanceState: string
{
    case Present = 'present';
    case Late = 'late';
    case Permission = 'permission';
    case Justified = 'justified';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Presente',
            self::Late => 'Tarde',
            self::Permission => 'Permiso',
            self::Justified => 'Justificado',
        };
    }

    public static function effectiveLabel(string $state): string
    {
        return match ($state) {
            'pending' => 'Pendiente',
            'absent' => 'Falta',
            default => self::tryFrom($state)?->label() ?? $state,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
