<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EmployeeScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule_code' => ['nullable', 'uuid'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'entry_time' => ['required', 'date_format:H:i'],
            'to_time' => ['required', 'date_format:H:i', 'after:entry_time'],
        ];
    }

    public function messages(): array
    {
        return [
            'weekday.required' => 'Selecciona un día.',
            'weekday.between' => 'El día no es válido.',
            'entry_time.required' => 'Indica la hora de inicio.',
            'entry_time.date_format' => 'La hora de inicio no es válida.',
            'to_time.required' => 'Indica la hora de fin.',
            'to_time.date_format' => 'La hora de fin no es válida.',
            'to_time.after' => 'La hora de fin debe ser posterior al inicio.',
        ];
    }
}
