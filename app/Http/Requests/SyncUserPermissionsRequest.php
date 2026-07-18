<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncUserPermissionsRequest extends FormRequest
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
            'permission_codes' => ['present', 'array'],
            'permission_codes.*' => ['uuid', Rule::exists('permissions', 'code')],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_codes.present' => 'Indica la lista de permisos (puede ir vacía).',
            'permission_codes.array' => 'Los permisos deben enviarse como lista.',
            'permission_codes.*.exists' => 'Uno de los permisos no existe.',
            'permission_codes.*.uuid' => 'El identificador de permiso no es válido.',
        ];
    }
}
