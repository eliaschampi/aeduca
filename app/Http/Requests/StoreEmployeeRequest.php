<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'login' => Str::lower(trim((string) $this->input('login'))),
        ]);
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
            'login' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_.]*$/',
                Rule::unique('auth_accounts', 'login'),
            ],
            'password' => ['required', 'string', 'min:8', 'max:255'],
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
            'login.required' => 'El usuario de acceso es obligatorio.',
            'login.regex' => 'El usuario solo admite minúsculas, números, punto y guion bajo.',
            'login.unique' => 'Ese usuario de acceso ya está en uso.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ];
    }
}
