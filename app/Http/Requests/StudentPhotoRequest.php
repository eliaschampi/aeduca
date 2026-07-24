<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentPhotoRequest extends FormRequest
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
            'photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:ratio=1/1',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Selecciona y recorta una foto.',
            'photo.image' => 'La foto debe ser una imagen válida.',
            'photo.mimes' => 'La foto debe ser JPG, PNG o WebP.',
            'photo.max' => 'La foto procesada no puede superar los 2 MB.',
            'photo.dimensions' => 'La foto procesada debe ser cuadrada.',
        ];
    }
}
