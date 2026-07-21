<?php

namespace App\Support\Academic;

/**
 * Academic level domain. Owns the valid grade range per level
 * and the Spanish display labels used across backend and UI payloads.
 */
enum AcademicLevel: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primaria',
            self::Secondary => 'Secundaria',
        };
    }

    /**
     * Valid grade numbers offered within a cycle of this level.
     *
     * @return list<int>
     */
    public function gradeNumbers(): array
    {
        return match ($this) {
            self::Primary => [1, 2, 3, 4, 5, 6],
            self::Secondary => [1, 2, 3, 4, 5],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
