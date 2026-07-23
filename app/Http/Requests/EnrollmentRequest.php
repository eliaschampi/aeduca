<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'academic_group_code' => trim((string) $this->input('academic_group_code')),
            'is_active' => $this->boolean('is_active'),
            'observation' => $this->nullableText($this->input('observation')),
            'shift_codes' => collect($this->input('shift_codes', []))
                ->map(fn (mixed $code): string => trim((string) $code))
                ->values()
                ->all(),
            'obligations' => collect($this->input('obligations', []))
                ->map(fn (mixed $obligation): array => [
                    'code' => $this->nullableText(data_get($obligation, 'code')),
                    'concept' => trim((string) data_get($obligation, 'concept')),
                    'amount' => data_get($obligation, 'amount'),
                    'due_date' => trim((string) data_get($obligation, 'due_date')),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'academic_group_code' => [
                'required',
                'uuid',
                Rule::exists('academic_groups', 'code'),
            ],
            'is_active' => ['required', 'boolean'],
            'observation' => ['nullable', 'string'],
            'shift_codes' => ['required', 'array', 'min:1', 'max:2'],
            'shift_codes.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('cycle_shifts', 'code'),
            ],
            'obligations' => ['required', 'array', 'min:1'],
            'obligations.*.code' => ['nullable', 'uuid', 'distinct'],
            'obligations.*.concept' => ['required', 'string', 'max:150'],
            'obligations.*.amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:9999999999.99',
            ],
            'obligations.*.due_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'academic_group_code.required' => 'Selecciona el grado y la sección.',
            'academic_group_code.exists' => 'La sección seleccionada ya no está disponible.',
            'shift_codes.required' => 'Selecciona al menos un turno.',
            'shift_codes.min' => 'Selecciona al menos un turno.',
            'shift_codes.max' => 'Solo puedes seleccionar los dos turnos del ciclo.',
            'shift_codes.*.distinct' => 'No repitas un turno.',
            'shift_codes.*.exists' => 'Uno de los turnos ya no está disponible.',
            'obligations.required' => 'Registra al menos una obligación.',
            'obligations.min' => 'Registra al menos una obligación.',
            'obligations.*.concept.required' => 'El concepto es obligatorio.',
            'obligations.*.concept.max' => 'El concepto no puede superar 150 caracteres.',
            'obligations.*.amount.required' => 'El importe es obligatorio.',
            'obligations.*.amount.numeric' => 'El importe debe ser numérico.',
            'obligations.*.amount.decimal' => 'El importe puede tener hasta dos decimales.',
            'obligations.*.amount.gt' => 'El importe debe ser mayor que cero.',
            'obligations.*.amount.max' => 'El importe supera el máximo permitido.',
            'obligations.*.due_date.required' => 'La fecha de vencimiento es obligatoria.',
            'obligations.*.due_date.date_format' => 'La fecha de vencimiento no es válida.',
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
