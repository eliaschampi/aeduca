<?php

namespace App\Support\Drive;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private disk owner for Drive blobs. Shares the disk with
 * {@see PrivateProfilePhoto} but owns a different responsibility:
 * a user-owned file graph with a per-owner quota.
 */
final class DriveStorage
{
    public const DISK = 'local';

    public const DIRECTORY = 'drive';

    /** Per-owner quota, matching Lumi's DRIVE_PROJECT_STORAGE_LIMIT_BYTES (2 GB). */
    public const QUOTA_BYTES = 2 * 1024 * 1024 * 1024;

    /** Matching Lumi's MAX_FILE_SIZE (50 MB). */
    public const MAX_FILE_BYTES = 50 * 1024 * 1024;

    public function store(UploadedFile $file): string
    {
        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            self::DIRECTORY,
            Str::uuid().'.'.$extension,
            self::DISK,
        );

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('No se pudo almacenar el archivo.');
        }

        return $path;
    }

    /**
     * @param  list<string>  $paths
     */
    public function deleteMany(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk(self::DISK)->delete($paths);
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

    /**
     * Bytes charged to an owner. Trashed files still occupy the quota until
     * they are permanently deleted.
     *
     * @return array{used: int, total: int, percentage: int}
     */
    public function usage(User $owner): array
    {
        $used = (int) DB::table('drive_files')
            ->where('user_code', $owner->code)
            ->sum('size');

        return [
            'used' => $used,
            'total' => self::QUOTA_BYTES,
            'percentage' => min(100, (int) round($used / self::QUOTA_BYTES * 100)),
        ];
    }
}
