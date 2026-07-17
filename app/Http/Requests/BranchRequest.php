<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'user_codes' => ['required', 'array', 'min:1'],
            'user_codes.*' => ['uuid', Rule::exists('users', 'code')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la sede es obligatorio.',
            'name.max' => 'El nombre no puede superar los 120 caracteres.',
            'is_active.required' => 'Indica si la sede está activa.',
            'is_active.boolean' => 'El estado de la sede no es válido.',
            'user_codes.required' => 'Asigna al menos un usuario a la sede.',
            'user_codes.min' => 'Asigna al menos un usuario a la sede.',
            'user_codes.*.exists' => 'Uno de los usuarios seleccionados no existe.',
            'user_codes.*.uuid' => 'El identificador de usuario no es válido.',
        ];
    }
}
