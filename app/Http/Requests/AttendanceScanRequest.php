<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'dni' => trim((string) $this->input('dni')),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'dni' => ['required', 'digits:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'dni.required' => 'Ingresa un DNI de ocho dígitos.',
            'dni.digits' => 'Ingresa un DNI de ocho dígitos.',
        ];
    }
}
