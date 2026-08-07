<?php

namespace App\Actions;

use App\Models\DriveFile;
use App\Models\User;
use App\Support\Drive\DriveStorage;
use App\Support\Drive\DriveTree;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Permanent deletion. Rows go first — the FK cascade removes descendants — and
 * blobs are unlinked afterwards, so a failed delete never loses a live file.
 */
final class DeleteDriveFiles
{
    public function __construct(
        private readonly DriveStorage $storage,
        private readonly DriveTree $tree,
    ) {}

    /**
     * @return int deleted rows, including descendants
     */
    public function permanentlyDelete(DriveFile $file): int
    {
        $nodes = $this->tree->subtree($file->code);

        $this->deleteRows(static function () use ($file): void {
            DriveFile::query()->where('code', $file->code)->delete();
        });

        $this->storage->deleteMany($this->storagePaths($nodes));

        return count($nodes);
    }

    /**
     * @return int deleted rows
     */
    public function emptyTrash(User $owner): int
    {
        $nodes = DB::table('drive_files')
            ->select(['code', 'storage_path'])
            ->where('user_code', $owner->code)
            ->whereNotNull('deleted_at')
            ->get()
            ->all();

        if ($nodes === []) {
            return 0;
        }

        $this->deleteRows(static function () use ($owner): void {
            DriveFile::query()
                ->where('user_code', $owner->code)
                ->whereNotNull('deleted_at')
                ->delete();
        });

        $this->storage->deleteMany($this->storagePaths($nodes));

        return count($nodes);
    }

    /**
     * The FK is the race-free backstop: a linked institutional file cannot be
     * permanently deleted until the attention explicitly detaches it.
     */
    private function deleteRows(callable $delete): void
    {
        try {
            DB::transaction($delete);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23503') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'file' => 'No se puede eliminar un archivo vinculado a una atención.',
            ]);
        }
    }

    /**
     * @param  list<object>  $nodes
     * @return list<string>
     */
    private function storagePaths(array $nodes): array
    {
        return array_values(array_filter(array_map(
            static fn (object $node): ?string => $node->storage_path,
            $nodes,
        )));
    }
}
