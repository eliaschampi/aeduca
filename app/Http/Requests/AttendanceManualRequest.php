<?php

namespace App\Http\Requests;

use App\Support\Attendance\AttendanceOperation;
use App\Support\Attendance\AttendanceState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = trim((string) $this->input('reason', ''));
        $this->merge([
            'reason' => $reason === '' ? null : $reason,
            'arrival_at' => $this->input('arrival_at') ?: null,
            'state' => $this->input('state') ?: null,
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $operation = (string) $this->input('operation');
        $needsReason = in_array($operation, [
            AttendanceOperation::Permission->value,
            AttendanceOperation::Justify->value,
            AttendanceOperation::Correct->value,
        ], true);

        return [
            'operation' => ['required', Rule::in(AttendanceOperation::values())],
            'enrollment_code' => ['required', 'uuid', Rule::exists('enrollments', 'code')],
            'cycle_shift_code' => ['required', 'uuid', Rule::exists('cycle_shifts', 'code')],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'arrival_at' => ['nullable', 'string', 'max:32'],
            'reason' => array_values(array_filter([
                $needsReason ? 'required' : 'nullable',
                'string',
                'max:1000',
            ])),
            'state' => [
                Rule::requiredIf($operation === AttendanceOperation::Correct->value),
                'nullable',
                Rule::in(AttendanceState::values()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'operation.required' => 'Selecciona una operación.',
            'operation.in' => 'La operación no es válida.',
            'enrollment_code.required' => 'Falta la matrícula.',
            'cycle_shift_code.required' => 'Falta el turno.',
            'attendance_date.required' => 'Indica la fecha.',
            'reason.required' => 'Indica el motivo.',
            'state.required' => 'Selecciona el estado corregido.',
        ];
    }
}
