<?php

namespace App\Http\Requests;

use App\Support\Drive\DriveName;
use Illuminate\Foundation\Http\FormRequest;

class DriveFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => DriveName::normalize($this->input('name'))]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => DriveName::rules(),
            'parent_code' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return DriveName::messages('name');
    }
}
