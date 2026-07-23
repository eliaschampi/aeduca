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
            'dni' => trim((string) $this->input('dni')),
            'first_name' => trim((string) $this->input('first_name')),
            'last_name' => trim((string) $this->input('last_name')),
            'birth_date' => $this->nullableText($this->input('birth_date')),
            'phone' => $this->nullableText($this->input('phone')),
            'address' => $this->nullableText($this->input('address')),
            'observation' => $this->nullableText($this->input('observation')),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Student|null $student */
        $student = $this->route('student');

        return [
            'dni' => [
                'required',
                'digits:8',
                Rule::unique('students', 'dni')->ignore($student?->code, 'code'),
            ],
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:80'],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:150'],
            'observation' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'dni.required' => 'El DNI es obligatorio.',
            'dni.digits' => 'El DNI debe tener exactamente 8 dígitos.',
            'dni.unique' => 'Ya existe un estudiante con este DNI.',
            'first_name.required' => 'Los nombres son obligatorios.',
            'first_name.max' => 'Los nombres no pueden superar 50 caracteres.',
            'last_name.required' => 'Los apellidos son obligatorios.',
            'last_name.max' => 'Los apellidos no pueden superar 80 caracteres.',
            'birth_date.date_format' => 'La fecha de nacimiento no es válida.',
            'phone.max' => 'El teléfono no puede superar 50 caracteres.',
            'address.max' => 'La dirección no puede superar 150 caracteres.',
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
