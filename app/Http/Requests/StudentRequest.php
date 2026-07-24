<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'dni' => preg_replace('/[\s.-]+/', '', trim((string) $this->input('dni'))),
            'first_name' => trim((string) $this->input('first_name')),
            'last_name' => trim((string) $this->input('last_name')),
            'phone' => $this->nullableText('phone'),
            'address' => $this->nullableText('address'),
            'observation' => $this->nullableText('observation'),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $student = $this->route('student');
        $accountCode = $student instanceof Student
            ? $student->authAccount()->value('code')
            : null;

        return [
            'dni' => [
                'required',
                'regex:/^[0-9]{8}$/',
                Rule::unique('students', 'dni')
                    ->ignore($student instanceof Student ? $student->code : null, 'code'),
                Rule::unique('auth_accounts', 'login')->ignore($accountCode, 'code'),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:250'],
            'observation' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'dni.required' => 'El DNI es obligatorio.',
            'dni.regex' => 'El DNI debe contener exactamente ocho dígitos.',
            'dni.unique' => 'Ya existe un alumno con ese DNI.',
            'first_name.required' => 'Los nombres son obligatorios.',
            'last_name.required' => 'Los apellidos son obligatorios.',
            'birth_date.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'is_active.required' => 'Indica el estado del alumno.',
        ];
    }

    private function nullableText(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
