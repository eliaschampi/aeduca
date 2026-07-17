<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:30'],
            'employee_role_code' => ['required', 'uuid', Rule::exists('employee_roles', 'code')],
            'is_active' => ['required', 'boolean'],
            'branch_codes' => ['required', 'array', 'min:1'],
            'branch_codes.*' => ['uuid', Rule::exists('branches', 'code')],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'El nombre es obligatorio.',
            'last_name.required' => 'El apellido es obligatorio.',
            'email.email' => 'El correo no es válido.',
            'employee_role_code.required' => 'Selecciona un rol.',
            'employee_role_code.exists' => 'El rol seleccionado no existe.',
            'is_active.required' => 'Indica si el acceso está activo.',
            'branch_codes.required' => 'Asigna al menos una sede.',
            'branch_codes.min' => 'Asigna al menos una sede.',
            'branch_codes.*.exists' => 'Una de las sedes seleccionadas no existe.',
        ];
    }
}
