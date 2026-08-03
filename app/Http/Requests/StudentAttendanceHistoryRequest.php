<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentAttendanceHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enrollment' => $this->emptyToNull('enrollment'),
            'shift' => $this->emptyToNull('shift'),
            'from' => $this->emptyToNull('from'),
            'to' => $this->emptyToNull('to'),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'enrollment' => ['nullable', 'uuid'],
            'shift' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    private function emptyToNull(string $key): ?string
    {
        $value = trim((string) $this->query($key, ''));

        return $value === '' ? null : $value;
    }
}
