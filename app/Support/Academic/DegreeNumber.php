<?php

namespace App\Support\Academic;

/**
 * Supported cycle degree numbers and their presentation labels.
 */
final class DegreeNumber
{
    public const int MIN = 1;

    public const int MAX = 6;

    /**
     * @return list<int>
     */
    public static function values(): array
    {
        return range(self::MIN, self::MAX);
    }

    /**
     * @return list<array{number: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (int $number): array => ['number' => $number, 'label' => "{$number}°"],
            self::values(),
        );
    }
}
