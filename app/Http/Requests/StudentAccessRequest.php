<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(['enable', 'reset', 'disable'])],
        ];
    }

    public function messages(): array
    {
        return [
            'operation.required' => 'Selecciona una acción de acceso.',
            'operation.in' => 'La acción de acceso no es válida.',
        ];
    }
}
