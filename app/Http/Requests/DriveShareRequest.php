<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DriveShareRequest extends FormRequest
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
            'user_code' => [
                'required',
                'uuid',
                Rule::exists('users', 'code')->where('is_active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_code.required' => 'Selecciona a quién compartir.',
            'user_code.exists' => 'El usuario seleccionado no está disponible.',
        ];
    }
}
