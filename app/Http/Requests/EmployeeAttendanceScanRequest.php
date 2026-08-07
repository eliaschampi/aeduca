<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EmployeeAttendanceScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['dni' => trim((string) $this->input('dni'))]);
    }

    public function rules(): array
    {
        return ['dni' => ['required', 'regex:/^\d{8}$/']];
    }

    public function messages(): array
    {
        return [
            'dni.required' => 'Ingresa el DNI.',
            'dni.regex' => 'Ingresa un DNI de ocho dígitos.',
        ];
    }
}
