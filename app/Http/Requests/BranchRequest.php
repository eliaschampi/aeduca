<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BranchRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la sede es obligatorio.',
            'name.max' => 'El nombre no puede superar los 120 caracteres.',
            'is_active.required' => 'Indica si la sede está activa.',
            'is_active.boolean' => 'El estado de la sede no es válido.',
        ];
    }
}
