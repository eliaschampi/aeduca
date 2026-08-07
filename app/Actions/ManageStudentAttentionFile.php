<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\DriveFile;
use App\Models\StudentAttention;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManageStudentAttentionFile
{
    public function attach(
        StudentAttention $attention,
        DriveFile $file,
        Branch $branch,
        User $actor,
    ): void {
        DB::transaction(function () use ($attention, $file, $branch, $actor): void {
            $attention = StudentAttention::query()->lockForUpdate()->findOrFail($attention->code);
            abort_unless($attention->branch_code === $branch->code, 404);

            $file = DriveFile::query()->lockForUpdate()->findOrFail($file->code);

            if ($file->user_code !== $actor->code) {
                throw ValidationException::withMessages([
                    'file_code' => 'Solo puedes adjuntar archivos de tu propio Drive.',
                ]);
            }

            if ($file->isDirectory() || $file->isTrashed()) {
                throw ValidationException::withMessages([
                    'file_code' => 'Selecciona un archivo disponible que no esté en la papelera.',
                ]);
            }

            DB::table('student_attention_files')->insertOrIgnore([
                'student_attention_code' => $attention->code,
                'drive_file_code' => $file->code,
                'created_at' => now(),
            ]);
        });
    }

    public function detach(
        StudentAttention $attention,
        DriveFile $file,
        Branch $branch,
    ): void {
        DB::transaction(function () use ($attention, $file, $branch): void {
            $attention = StudentAttention::query()->lockForUpdate()->findOrFail($attention->code);
            abort_unless($attention->branch_code === $branch->code, 404);

            $deleted = DB::table('student_attention_files')
                ->where('student_attention_code', $attention->code)
                ->where('drive_file_code', $file->code)
                ->delete();

            abort_unless($deleted === 1, 404);
        });
    }
}
