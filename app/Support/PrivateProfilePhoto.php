<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private disk owner for entity profile photos (students, employees).
 * Not Drive: no file graph, shares, or variants.
 */
final class PrivateProfilePhoto
{
    public const DISK = 'local';

    public const STUDENT_DIRECTORY = 'student-photos';

    public const EMPLOYEE_DIRECTORY = 'employee-photos';

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function versionedUrl(
        ?string $photoPath,
        string $routeName,
        array $parameters,
    ): ?string {
        if (! $photoPath) {
            return null;
        }

        return route($routeName, [
            ...$parameters,
            'v' => substr(hash('sha256', $photoPath), 0, 16),
        ]);
    }

    public function store(UploadedFile $photo, string $directory): string
    {
        $extension = $photo->guessExtension() ?: 'webp';
        $path = $photo->storeAs(
            $directory,
            Str::uuid().'.'.$extension,
            self::DISK,
        );

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('No se pudo almacenar la foto de perfil.');
        }

        return $path;
    }

    public function replace(?string $oldPath, string $newPath): void
    {
        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk(self::DISK)->delete($oldPath);
        }
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return is_string($path)
            && $path !== ''
            && Storage::disk(self::DISK)->exists($path);
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }
}
