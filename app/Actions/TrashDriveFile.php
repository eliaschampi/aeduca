<?php

namespace App\Actions;

use App\Models\DriveFile;
use App\Support\Drive\DriveTree;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a Drive node in and out of the trash together with its whole subtree.
 *
 * Keeping the subtree consistent is what lets every other rule stay simple:
 * because a trashed folder always has a fully trashed subtree, "some ancestor
 * is trashed" is equivalent to "the immediate parent is trashed".
 */
final class TrashDriveFile
{
    public function __construct(
        private readonly DriveTree $tree,
    ) {}

    public function trash(DriveFile $file): void
    {
        $this->setTrashedAt($file, now());
    }

    public function restore(DriveFile $file): void
    {
        $file->loadMissing('parent');

        if ($file->parent?->isTrashed()) {
            throw ValidationException::withMessages([
                'trashed' => 'No se puede restaurar mientras la carpeta que lo contiene esté en la papelera.',
            ]);
        }

        $this->setTrashedAt($file, null);
    }

    private function setTrashedAt(DriveFile $file, ?DateTimeInterface $deletedAt): void
    {
        if (! $file->isDirectory()) {
            $file->forceFill(['deleted_at' => $deletedAt])->save();

            return;
        }

        $codes = array_map(
            static fn (object $node): string => $node->code,
            $this->tree->subtree($file->code),
        );

        DB::transaction(function () use ($codes, $deletedAt): void {
            DriveFile::query()
                ->whereIn('code', $codes)
                ->update([
                    'deleted_at' => $deletedAt,
                    'updated_at' => now(),
                ]);
        });

        $file->deleted_at = $deletedAt;
    }
}
