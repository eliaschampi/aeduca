<?php

namespace App\Support\Academic;

/**
 * Cycle modality domain. Program type — distinct from cycle identity and degrees.
 * Values confirmed from Carrión operation and Coedula evidence.
 */
enum CycleModality: string
{
    case Regular = 'regular';
    case Verano = 'verano';
    case Intensivo = 'intensivo';
    case Reforzamiento = 'reforzamiento';
    case Virtual = 'virtual';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Verano => 'Verano',
            self::Intensivo => 'Intensivo',
            self::Reforzamiento => 'Reforzamiento',
            self::Virtual => 'Virtual',
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
