<?php

namespace App\Http\Requests;

use App\Support\Drive\DriveMimeType;
use App\Support\Drive\DriveName;
use App\Support\Drive\DriveStorage;
use Illuminate\Foundation\Http\FormRequest;

class StudentAttentionUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => DriveName::normalize($this->file('file')?->getClientOriginalName()),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(int) (DriveStorage::MAX_FILE_BYTES / 1024),
                'mimetypes:'.implode(',', DriveMimeType::allowed()),
            ],
            'name' => DriveName::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...DriveName::messages('name'),
            'file.required' => 'Selecciona un archivo.',
            'file.max' => 'El archivo supera el tamaño máximo de 50 MB.',
            'file.mimetypes' => 'Tipo de archivo no permitido.',
        ];
    }
}
