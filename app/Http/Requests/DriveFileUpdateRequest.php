<?php

namespace App\Http\Requests;

use App\Support\Drive\DriveName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename, move, and trash/restore. Every field is optional; the controller
 * applies only the keys actually present, so a move never renames by accident.
 */
class DriveFileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => DriveName::normalize($this->input('name'))]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => DriveName::rules(required: false),
            'parent_code' => ['nullable', 'uuid'],
            'trashed' => ['sometimes', 'boolean'],
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
