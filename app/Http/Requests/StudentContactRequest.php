<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => $this->nullableText($this->input('phone')),
            'note' => $this->nullableText($this->input('note')),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Indica el nombre del contacto.',
            'name.max' => 'El nombre del contacto no puede superar 150 caracteres.',
            'phone.max' => 'El teléfono del contacto no puede superar 50 caracteres.',
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
