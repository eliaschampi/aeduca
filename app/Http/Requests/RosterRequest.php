<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => trim(preg_replace('/\s+/', ' ', (string) $this->query('q', '')) ?? ''),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'cycle' => ['nullable', 'uuid'],
            'degree' => ['nullable', 'integer', 'between:1,6'],
            'group' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
