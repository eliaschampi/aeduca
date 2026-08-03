<?php

namespace App\Actions;

use App\Models\User;
use App\Support\PrivateProfilePhoto;
use Illuminate\Http\UploadedFile;
use Throwable;

final class UpdateEmployeePhoto
{
    public function __construct(
        private readonly PrivateProfilePhoto $photos,
    ) {}

    public function handle(User $employee, UploadedFile $photo): User
    {
        $newPath = $this->photos->store($photo, PrivateProfilePhoto::EMPLOYEE_DIRECTORY);
        $oldPath = $employee->photo_path;

        try {
            $employee->forceFill(['photo_path' => $newPath])->save();
        } catch (Throwable $exception) {
            $this->photos->delete($newPath);

            throw $exception;
        }

        $this->photos->replace($oldPath, $newPath);

        return $employee;
    }
}
