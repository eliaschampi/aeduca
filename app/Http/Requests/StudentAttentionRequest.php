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
            'type' => trim((string) $this->input('type')),
            'reason' => trim((string) $this->input('reason')),
            'development' => trim((string) $this->input('development')),
            'conclusion' => trim((string) $this->input('conclusion')),
            'occurred_at' => trim((string) $this->input('occurred_at')),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(StudentAttentionType::class)],
            'reason' => ['required', 'string', 'max:100'],
            'development' => ['required', 'string', 'min:10', 'max:20000'],
            'conclusion' => ['required', 'string', 'min:5', 'max:10000'],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('occurred_at')) {
                return;
            }

            $timezone = (string) config('aeduca.business_timezone', 'America/Lima');
            $occurredAt = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                (string) $this->input('occurred_at'),
                $timezone,
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
            'type.required' => 'Selecciona el tipo de atención.',
            'type.enum' => 'El tipo de atención no es válido.',
            'reason.required' => 'El motivo es obligatorio.',
            'development.required' => 'El desarrollo es obligatorio.',
            'development.min' => 'El desarrollo debe tener al menos 10 caracteres.',
            'conclusion.required' => 'La conclusión o los acuerdos son obligatorios.',
            'conclusion.min' => 'La conclusión debe tener al menos 5 caracteres.',
            'occurred_at.required' => 'La fecha y hora son obligatorias.',
            'occurred_at.date_format' => 'La fecha y hora no tienen un formato válido.',
        ];
    }
}
