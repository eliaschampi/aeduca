<?php

namespace App\Http\Requests;

use App\Support\EmployeeAttendance\EmployeeAttendanceState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EmployeeAttendanceManualRequest extends FormRequest
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

    public function rules(): array
    {
        $operation = (string) $this->input('operation');
        $state = (string) $this->input('state');
        $writesFact = in_array($operation, ['create', 'update'], true);
        $recordsArrival = in_array($state, ['present', 'late'], true);

        return [
            'operation' => ['required', Rule::in(['create', 'update', 'delete'])],
            'schedule_code' => [
                Rule::requiredIf($operation === 'create'),
                'nullable',
                'uuid',
            ],
            'attendance_code' => [
                Rule::requiredIf(in_array($operation, ['update', 'delete'], true)),
                'nullable',
                'uuid',
            ],
            'state' => [
                Rule::requiredIf(in_array($operation, ['create', 'update'], true)),
                'nullable',
                Rule::enum(EmployeeAttendanceState::class),
            ],
            'entry_time' => [
                Rule::requiredIf($writesFact && $recordsArrival),
                Rule::prohibitedIf($writesFact && ! $recordsArrival),
                'nullable',
                'date_format:H:i',
            ],
            'observation' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'operation.required' => 'Selecciona una operación válida.',
            'state.required' => 'Selecciona un estado.',
            'entry_time.required' => 'Indica la hora de ingreso.',
            'entry_time.prohibited' => 'Este estado no registra una hora de ingreso.',
            'entry_time.date_format' => 'La hora de ingreso no es válida.',
        ];
    }
}
