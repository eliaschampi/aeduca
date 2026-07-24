<?php

namespace App\Http\Requests;

use App\Models\Enrollment;
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
        $observation = trim((string) $this->input('observation'));
        $this->merge([
            'observation' => $observation === '' ? null : $observation,
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $isUpdate = $this->route('enrollment') instanceof Enrollment;

        return [
            'academic_group_code' => [
                'required',
                'uuid',
                Rule::exists('academic_groups', 'code'),
            ],
            'shift_codes' => ['required', 'array', 'min:1', 'max:2'],
            'shift_codes.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('cycle_shifts', 'code'),
            ],
            'is_active' => $isUpdate
                ? ['required', 'boolean']
                : ['prohibited'],
            'observation' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_group_code.required' => 'Selecciona una sección.',
            'academic_group_code.exists' => 'La sección seleccionada no existe.',
            'shift_codes.required' => 'Selecciona al menos un turno.',
            'shift_codes.min' => 'Selecciona al menos un turno.',
            'shift_codes.max' => 'Puedes seleccionar hasta dos turnos.',
            'shift_codes.*.distinct' => 'No repitas un turno.',
            'shift_codes.*.exists' => 'Uno de los turnos no existe.',
            'is_active.required' => 'Indica el estado de la matrícula.',
            'is_active.prohibited' => 'Una matrícula nueva siempre se registra activa.',
        ];
    }
}
