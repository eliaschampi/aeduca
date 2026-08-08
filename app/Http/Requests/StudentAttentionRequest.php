<?php

namespace App\Http\Requests;

use App\Support\StudentAttentions\StudentAttentionType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StudentAttentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'student_code' => trim((string) $this->input('student_code')),
            'type' => trim((string) $this->input('type')),
            'reason' => trim((string) $this->input('reason')),
            'development' => trim((string) $this->input('development')),
            'conclusion' => trim((string) $this->input('conclusion')),
            'occurred_at' => trim((string) $this->input('occurred_at')),
            'drive_file_code' => $this->filled('drive_file_code')
                ? trim((string) $this->input('drive_file_code'))
                : null,
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'student_code' => ['required', 'uuid', Rule::exists('students', 'code')],
            'type' => ['required', Rule::enum(StudentAttentionType::class)],
            'reason' => ['required', 'string', 'min:5', 'max:100'],
            'development' => ['required', 'string', 'min:10', 'max:1500'],
            'conclusion' => ['required', 'string', 'min:5', 'max:500'],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'drive_file_code' => ['nullable', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('occurred_at')) {
                return;
            }

            $occurredAt = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                (string) $this->input('occurred_at'),
                (string) config('aeduca.business_timezone', 'America/Lima'),
            );

            if ($occurredAt && $occurredAt->isFuture()) {
                $validator->errors()->add(
                    'occurred_at',
                    'La fecha de la atención no puede estar en el futuro.',
                );
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_code.required' => 'Selecciona un alumno.',
            'student_code.exists' => 'El alumno seleccionado no existe.',
            'type.required' => 'Selecciona el tipo de atención.',
            'type.enum' => 'El tipo de atención no es válido.',
            'reason.required' => 'El motivo es obligatorio.',
            'reason.min' => 'El motivo debe tener al menos 5 caracteres.',
            'development.required' => 'El desarrollo es obligatorio.',
            'development.min' => 'El desarrollo debe tener al menos 10 caracteres.',
            'conclusion.required' => 'La conclusión o los acuerdos son obligatorios.',
            'conclusion.min' => 'La conclusión debe tener al menos 5 caracteres.',
            'occurred_at.required' => 'La fecha y hora son obligatorias.',
            'occurred_at.date_format' => 'La fecha y hora no tienen un formato válido.',
            'drive_file_code.uuid' => 'El archivo adjunto no es válido.',
        ];
    }
}
