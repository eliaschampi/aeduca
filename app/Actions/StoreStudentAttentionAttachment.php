<?php

namespace App\Actions;

use App\Models\DriveFile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class StoreStudentAttentionAttachment
{
    private const string FOLDER_NAME = 'Atenciones';

    public function __construct(private readonly StoreDriveFile $storeDriveFile) {}

    public function handle(User $owner, UploadedFile $upload, string $name): DriveFile
    {
        $folder = $this->folder($owner);

        try {
            return $this->storeDriveFile->handle($owner, $upload, $folder, $name);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'file' => 'Ya existe un archivo con ese nombre en Atenciones. Puedes elegirlo desde Drive.',
            ]);
        }
    }

    private function folder(User $owner): DriveFile
    {
        $existing = $this->findRootNode($owner);

        if ($existing) {
            return $this->requireDirectory($existing);
        }

        try {
            $folder = new DriveFile([
                'name' => self::FOLDER_NAME,
                'type' => 'dir',
                'size' => 0,
            ]);
            $folder->user_code = $owner->code;
            $folder->save();

            return $folder;
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            $folder = $this->findRootNode($owner);
            abort_unless($folder, 409);

            return $this->requireDirectory($folder);
        }
    }

    private function findRootNode(User $owner): ?DriveFile
    {
        return DriveFile::query()
            ->where('user_code', $owner->code)
            ->whereNull('parent_code')
            ->whereNull('deleted_at')
            ->whereRaw('lower(name) = lower(?)', [self::FOLDER_NAME])
            ->first();
    }

    private function requireDirectory(DriveFile $node): DriveFile
    {
        if (! $node->isDirectory()) {
            throw ValidationException::withMessages([
                'file' => 'En tu Drive ya existe un archivo llamado Atenciones. Renómbralo para poder crear la carpeta.',
            ]);
        }

        return $node;
    }
}
