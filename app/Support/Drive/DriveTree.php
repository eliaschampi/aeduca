<?php

namespace App\Support\Drive;

use Illuminate\Support\Facades\DB;

/**
 * Reads over the Drive folder graph. The recursion lives in the PostgreSQL
 * functions created with the Drive tables; this class only calls them.
 */
final class DriveTree
{
    /**
     * The folder chain from the root down to `$code`, inclusive — the
     * breadcrumb trail, rebuilt from the graph so a reload or a deep link
     * shows the same path as a click-through.
     *
     * @return list<object{code: string, name: string}>
     */
    public function ancestors(string $code): array
    {
        /** @var list<object{code: string, name: string}> */
        return DB::select(
            'SELECT code::text AS code, name FROM drive_folder_path(?)',
            [$code],
        );
    }

    /**
     * Every live folder of one owner, labelled with its full path, so the move
     * dialog shows where a folder actually sits instead of a bare name.
     *
     * @return list<object{code: string, label: string}>
     */
    public function folderPaths(string $ownerCode, ?string $excludeSubtreeCode = null): array
    {
        /** @var list<object{code: string, label: string}> */
        return DB::select(
            'SELECT code::text AS code, label FROM drive_folder_options(?, ?)',
            [$ownerCode, $excludeSubtreeCode],
        );
    }

    /**
     * The node itself plus every descendant, as `{code, storage_path}`.
     *
     * @return list<object{code: string, storage_path: ?string}>
     */
    public function subtree(string $rootCode): array
    {
        /** @var list<object{code: string, storage_path: ?string}> */
        return DB::select(
            'SELECT code::text AS code, storage_path FROM drive_file_subtree(?)',
            [$rootCode],
        );
    }

    /**
     * True when `$fileCode` was shared with `$viewerCode`, directly or through
     * an ancestor folder. A live node can never sit under a trashed one, so
     * reachability alone is the whole answer.
     */
    public function isSharedWith(string $fileCode, string $viewerCode): bool
    {
        $rows = DB::select(
            'SELECT drive_file_shared_with(?, ?) AS shared',
            [$fileCode, $viewerCode],
        );

        return ($rows[0]->shared ?? false) === true;
    }

    /**
     * True when `$candidateCode` is `$rootCode` itself or lives inside its
     * subtree — the guard against moving a folder into its own branch.
     */
    public function contains(string $rootCode, string $candidateCode): bool
    {
        $rows = DB::select(
            'SELECT drive_file_contains(?, ?) AS contained',
            [$rootCode, $candidateCode],
        );

        return ($rows[0]->contained ?? false) === true;
    }
}
