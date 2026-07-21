<?php

namespace App\Http\Requests;

use App\Support\Academic\AcademicLevel;
use App\Support\Academic\CycleModality;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'level' => ['required', 'string', Rule::enum(AcademicLevel::class)],
            'modality' => ['required', 'string', Rule::enum(CycleModality::class)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_active' => ['required', 'boolean'],

            'shifts' => ['required', 'array', 'min:1', 'max:2'],
            'shifts.*.code' => ['nullable', 'string'],
            'shifts.*.name' => ['required', 'string', 'max:60'],
            'shifts.*.entry_time' => ['required', 'date_format:H:i'],
            'shifts.*.tolerance_minutes' => ['required', 'integer', 'min:0', 'max:600'],

            'degrees' => ['required', 'array', 'min:1'],
            'degrees.*.number' => ['required', 'integer', 'min:1', 'max:6'],
            'degrees.*.groups' => ['present', 'array'],
            'degrees.*.groups.*.code' => ['nullable', 'string'],
            'degrees.*.groups.*.name' => ['required', 'string', 'max:60'],
        ];
    }

    /**
     * Cross-field invariants: grade validity per level, duplicate grades,
     * and case-insensitive duplicate group names within one degree.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $level = AcademicLevel::tryFrom((string) $this->input('level'));

                if ($level === null) {
                    return;
                }

                $validNumbers = $level->gradeNumbers();
                $seenNumbers = [];

                foreach ((array) $this->input('degrees', []) as $index => $degree) {
                    $number = (int) ($degree['number'] ?? 0);

                    if ($number !== 0 && ! in_array($number, $validNumbers, true)) {
                        $validator->errors()->add(
                            "degrees.{$index}.number",
                            "El grado {$number} no es válido para {$level->label()}.",
                        );
                    }

                    if (in_array($number, $seenNumbers, true)) {
                        $validator->errors()->add("degrees.{$index}.number", 'El grado está repetido en el ciclo.');
                    }

                    $seenNumbers[] = $number;

                    $seenNames = [];

                    foreach ((array) ($degree['groups'] ?? []) as $groupIndex => $group) {
                        $normalized = mb_strtolower(trim((string) ($group['name'] ?? '')));

                        if ($normalized === '') {
                            continue;
                        }

                        if (in_array($normalized, $seenNames, true)) {
                            $validator->errors()->add(
                                "degrees.{$index}.groups.{$groupIndex}.name",
                                'El nombre de la sección está repetido en el mismo grado.',
                            );
                        }

                        $seenNames[] = $normalized;
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del ciclo es obligatorio.',
            'name.max' => 'El nombre no puede superar los 120 caracteres.',
            'level.required' => 'Selecciona el nivel del ciclo.',
            'modality.required' => 'Selecciona la modalidad del ciclo.',
            'start_date.required' => 'La fecha de inicio es obligatoria.',
            'end_date.required' => 'La fecha de fin es obligatoria.',
            'end_date.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
            'shifts.required' => 'El ciclo necesita al menos un turno.',
            'shifts.min' => 'El ciclo necesita al menos un turno.',
            'shifts.max' => 'El ciclo puede tener como máximo dos turnos.',
            'shifts.*.name.required' => 'El nombre del turno es obligatorio.',
            'shifts.*.entry_time.required' => 'La hora de entrada es obligatoria.',
            'shifts.*.entry_time.date_format' => 'La hora de entrada no es válida.',
            'shifts.*.tolerance_minutes.min' => 'La tolerancia no puede ser negativa.',
            'degrees.required' => 'Selecciona al menos un grado para el ciclo.',
            'degrees.min' => 'Selecciona al menos un grado para el ciclo.',
            'degrees.*.number.required' => 'El grado no es válido.',
            'degrees.*.groups.*.name.required' => 'El nombre de la sección es obligatorio.',
            'degrees.*.groups.*.name.max' => 'El nombre de la sección no puede superar los 60 caracteres.',
        ];
    }
}
