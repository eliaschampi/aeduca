<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncUserPermissionOverridesRequest extends FormRequest
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
            'overrides' => ['present', 'array'],
            'overrides.*.permission_code' => ['required', 'uuid', Rule::exists('permissions', 'code')],
            'overrides.*.is_allowed' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'overrides.present' => 'Debes enviar las excepciones de permisos.',
            'overrides.array' => 'Las excepciones de permisos no son válidas.',
            'overrides.*.permission_code.required' => 'Falta el permiso de una excepción.',
            'overrides.*.permission_code.exists' => 'Uno de los permisos no existe.',
            'overrides.*.is_allowed.required' => 'Indica si la excepción permite o deniega.',
            'overrides.*.is_allowed.boolean' => 'El valor de la excepción no es válido.',
        ];
    }
}
