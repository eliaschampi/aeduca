<?php

namespace App\Actions;

use App\Models\DriveFile;
use App\Models\User;
use App\Support\Drive\DriveMimeType;
use App\Support\Drive\DriveStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores an uploaded blob and its owning row together: the row never exists
 * without its blob, and a failed insert never leaves an orphan on disk.
 */
final class StoreDriveFile
{
    public function __construct(
        private readonly DriveStorage $storage,
    ) {}

    public function handle(
        User $owner,
        UploadedFile $upload,
        ?DriveFile $parent,
        string $name,
    ): DriveFile {
        $this->assertQuota($owner, $upload->getSize() ?: 0);

        $mimeType = $upload->getMimeType() ?: 'application/octet-stream';
        $size = $upload->getSize() ?: 0;
        $storagePath = $this->storage->store($upload);

        try {
            return DB::transaction(function () use (
                $owner,
                $parent,
                $name,
                $mimeType,
                $size,
                $storagePath,
            ): DriveFile {
                $file = new DriveFile([
                    'parent_code' => $parent?->code,
                    'name' => $name,
                    'type' => DriveMimeType::fileType($mimeType),
                    'size' => $size,
                    'storage_path' => $storagePath,
                    'mime_type' => $mimeType,
                ]);
                $file->user_code = $owner->code;
                $file->save();

                return $file;
            });
        } catch (Throwable $exception) {
            $this->storage->delete($storagePath);

            throw $exception;
        }
    }

    private function assertQuota(User $owner, int $incomingBytes): void
    {
        $usage = $this->storage->usage($owner);

        if ($usage['total'] >= $usage['used'] + $incomingBytes) {
            return;
        }

        throw ValidationException::withMessages([
            'file' => 'No hay espacio suficiente en tu Drive.',
        ]);
    }
}
