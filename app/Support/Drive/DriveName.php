<?php

namespace App\Support\Drive;

/**
 * Naming contract for Drive nodes, mirroring Lumi's `normalizeDriveName` and
 * `validateDriveName` so client and server reject the same names.
 */
final class DriveName
{
    public const MAX_LENGTH = 160;

    /** No path separators, shell wildcards, or control characters. */
    private const SHAPE = 'regex:/^[^<>:"\/\\\\|?*\x00-\x1F]+$/u';

    public static function normalize(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    /**
     * @return list<string>
     */
    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'string',
            'max:'.self::MAX_LENGTH,
            'not_in:.,..',
            self::SHAPE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $attribute): array
    {
        return [
            $attribute.'.required' => 'El nombre es obligatorio.',
            $attribute.'.max' => 'El nombre no puede superar los '.self::MAX_LENGTH.' caracteres.',
            $attribute.'.not_in' => 'Nombre inválido.',
            $attribute.'.regex' => 'El nombre contiene caracteres inválidos.',
        ];
    }
}
