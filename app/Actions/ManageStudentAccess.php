<?php

namespace App\Actions;

use App\Models\AuthAccount;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManageStudentAccess
{
    /**
     * @return array{login: string, temporary_password: string}|null
     */
    public function handle(Student $student, string $operation): ?array
    {
        return DB::transaction(function () use ($student, $operation): ?array {
            $student = Student::query()->lockForUpdate()->findOrFail($student->code);
            $account = $student->authAccount()->lockForUpdate()->first();

            if ($operation === 'disable') {
                if ($account?->is_active) {
                    $account->update(['is_active' => false]);
                }

                return null;
            }

            if ($operation === 'enable' && $account?->is_active) {
                $this->fail('El alumno ya tiene acceso habilitado. Restablece la clave si es necesario.');
            }

            if ($operation === 'reset') {
                if (! $account) {
                    $this->fail('El alumno todavía no tiene acceso habilitado.');
                }

                if (! $account->is_active) {
                    $this->fail('El acceso está deshabilitado. Habilítalo para generar una nueva clave.');
                }
            }

            if (
                AuthAccount::query()
                    ->where('login', $student->dni)
                    ->when(
                        $account,
                        fn ($query) => $query->where('code', '!=', $account->code),
                    )
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'access' => 'El DNI ya está en uso por otra cuenta de acceso.',
                ]);
            }

            $password = $this->temporaryPassword();

            if ($account) {
                $attributes = [
                    'login' => $student->dni,
                    'password' => $password,
                ];

                if ($operation === 'enable') {
                    $attributes['is_active'] = true;
                }

                $account->update($attributes);
            } else {
                AuthAccount::query()->create([
                    'student_code' => $student->code,
                    'login' => $student->dni,
                    'password' => $password,
                    'is_active' => true,
                ]);
            }

            return [
                'login' => $student->dni,
                'temporary_password' => $password,
            ];
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'access' => $message,
        ]);
    }

    private function temporaryPassword(): string
    {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $alphabet = $uppercase.$lowercase.$digits;
        $characters = [
            $uppercase[random_int(0, strlen($uppercase) - 1)],
            $lowercase[random_int(0, strlen($lowercase) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        while (count($characters) < 12) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$characters[$index], $characters[$swap]] = [$characters[$swap], $characters[$index]];
        }

        return implode('', $characters);
    }
}
