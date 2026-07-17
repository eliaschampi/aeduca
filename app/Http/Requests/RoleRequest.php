<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'permission_codes' => ['present', 'array'],
            'permission_codes.*' => ['uuid', Rule::exists('permissions', 'code')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del rol es obligatorio.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
            'description.max' => 'La descripción no puede superar los 255 caracteres.',
            'is_active.required' => 'Indica si el rol está activo.',
            'is_active.boolean' => 'El estado del rol no es válido.',
            'permission_codes.present' => 'Debes enviar la lista de permisos del rol.',
            'permission_codes.array' => 'Los permisos del rol no son válidos.',
            'permission_codes.*.exists' => 'Uno de los permisos seleccionados no existe.',
        ];
    }
}
