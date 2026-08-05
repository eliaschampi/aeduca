<?php

namespace App\Http\Controllers;

use App\Actions\DeleteDriveFiles;
use App\Actions\StoreDriveFile;
use App\Actions\TrashDriveFile;
use App\Http\Requests\DriveFileUpdateRequest;
use App\Http\Requests\DriveFolderRequest;
use App\Http\Requests\DriveUploadRequest;
use App\Models\AuthAccount;
use App\Models\DriveFile;
use App\Models\DriveShare;
use App\Models\User;
use App\Support\Drive\DriveStorage;
use App\Support\Drive\DriveTree;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Drive is a private space: every row has one owner and nobody else sees it
 * unless the owner shares that exact file (see {@see DriveShareController}).
 * Authorization is therefore ownership, not a permission.
 */
class DriveController extends Controller
{
    /** Bounded views never page; they answer "what is recent/biggest". */
    private const SHORTLIST_LIMIT = 50;

    private const SEARCH_LIMIT = 100;

    public function __construct(
        private readonly DriveStorage $storage,
        private readonly DriveTree $tree,
    ) {}

    public function index(Request $request): Response
    {
        $owner = $this->owner($request);

        return Inertia::render('Drive/Index', [
            'storage' => $this->storage->usage($owner),
            'recipients' => User::query()
                ->where('is_active', true)
                ->whereKeyNot($owner->code)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['code', 'first_name', 'last_name'])
                ->map(fn (User $user): array => [
                    'code' => $user->code,
                    'full_name' => trim($user->first_name.' '.$user->last_name),
                ])
                ->all(),
        ]);
    }

    /**
     * One list endpoint per view; `path` carries the breadcrumb trail so a
     * folder reload rebuilds the same crumbs a click-through produced.
     */
    public function files(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $view = (string) $request->query('view', 'folder');
        $search = trim((string) $request->query('search', ''));

        if ($view === 'shared_by_me') {
            return $this->flatListing($this->sharedByMe($owner));
        }

        if ($view === 'shared_with_me') {
            return $this->flatListing($this->sharedWithMe($owner), owned: false);
        }

        if ($view === 'trash') {
            return $this->flatListing($this->trash($owner));
        }

        if ($search !== '') {
            return $this->flatListing($this->search($owner, $search));
        }

        if ($view === 'recent' || $view === 'heavy') {
            return $this->flatListing($this->shortlist($owner, $view));
        }

        $parent = $this->resolveReadableFolder($request->query('parent'), $owner);
        $isOwnSpace = $parent === null || $parent->user_code === $owner->code;

        // A readable folder makes its children readable, so once the parent is
        // authorized the listing needs no owner filter.
        $files = DriveFile::query()
            ->whereNull('deleted_at')
            ->when(
                $parent,
                fn (Builder $query): Builder => $query->where('parent_code', $parent->code),
                fn (Builder $query): Builder => $query
                    ->where('user_code', $owner->code)
                    ->whereNull('parent_code'),
            )
            ->orderByRaw("CASE WHEN type = 'dir' THEN 0 ELSE 1 END")
            ->orderByRaw('lower(name)')
            ->get();

        return response()->json([
            'files' => $this->rows($files, $owner),
            'path' => $parent ? $this->readablePath($parent, $owner) : [],
            'owned' => $isOwnSpace,
        ]);
    }

    /**
     * Destination folders for the move dialog, labelled with their full path.
     */
    public function folders(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $exclude = $request->query('exclude');

        return response()->json([
            'folders' => $this->tree->folderPaths(
                $owner->code,
                is_string($exclude) && $exclude !== '' ? $exclude : null,
            ),
        ]);
    }

    public function storeFolder(DriveFolderRequest $request): JsonResponse
    {
        $owner = $this->owner($request);
        $parent = $this->resolveFolder($request->input('parent_code'), $owner);

        $folder = $this->rejectingNameConflict(function () use ($owner, $parent, $request): DriveFile {
            $folder = new DriveFile([
                'parent_code' => $parent?->code,
                'name' => (string) $request->input('name'),
                'type' => 'dir',
                'size' => 0,
            ]);
            $folder->user_code = $owner->code;
            $folder->save();

            return $folder;
        });

        return response()->json(['file' => $this->row($folder, $owner)], 201);
    }

    public function store(DriveUploadRequest $request, StoreDriveFile $storeFile): JsonResponse
    {
        $owner = $this->owner($request);
        $parent = $this->resolveFolder($request->input('parent_code'), $owner);
        $upload = $request->file('file');
        abort_unless($upload, 422);

        $file = $this->rejectingNameConflict(fn (): DriveFile => $storeFile->handle(
            $owner,
            $upload,
            $parent,
            (string) $request->input('name'),
        ));

        return response()->json([
            'file' => $this->row($file, $owner),
            'storage' => $this->storage->usage($owner),
        ], 201);
    }

    public function update(
        DriveFileUpdateRequest $request,
        DriveFile $file,
        TrashDriveFile $trashFile,
    ): JsonResponse {
        $owner = $this->owner($request);
        $this->assertOwned($file, $owner);

        if (! $request->hasAny(['name', 'parent_code', 'trashed'])) {
            throw ValidationException::withMessages(['name' => 'No hay cambios que aplicar.']);
        }

        $this->rejectingNameConflict(function () use ($request, $file, $owner, $trashFile): void {
            if ($request->has('name')) {
                $file->name = (string) $request->input('name');
            }

            if ($request->has('parent_code')) {
                $file->parent_code = $this->resolveMoveTarget($request, $file, $owner);
            }

            $file->save();

            if ($request->has('trashed')) {
                $request->boolean('trashed')
                    ? $trashFile->trash($file)
                    : $trashFile->restore($file);
            }
        });

        return response()->json(['file' => $this->row($file->refresh(), $owner)]);
    }

    public function destroy(
        Request $request,
        DriveFile $file,
        DeleteDriveFiles $deleteFiles,
    ): JsonResponse {
        $owner = $this->owner($request);
        $this->assertOwned($file, $owner);

        abort_unless($file->isTrashed(), 422);

        $deleted = $deleteFiles->permanentlyDelete($file);

        return response()->json([
            'deleted' => $deleted,
            'storage' => $this->storage->usage($owner),
        ]);
    }

    public function emptyTrash(Request $request, DeleteDriveFiles $deleteFiles): JsonResponse
    {
        $owner = $this->owner($request);

        return response()->json([
            'deleted' => $deleteFiles->emptyTrash($owner),
            'storage' => $this->storage->usage($owner),
        ]);
    }

    /**
     * Streams a blob to its owner or to someone it was explicitly shared with.
     */
    public function serve(Request $request, DriveFile $file): BinaryFileResponse
    {
        $owner = $this->owner($request);

        abort_if($file->isDirectory() || $file->isTrashed(), 404);
        abort_unless(
            $file->user_code === $owner->code
                || $this->tree->isSharedWith($file->code, $owner->code),
            404,
        );
        abort_unless($this->storage->exists($file->storage_path), 404);

        // SVG renders as an active document, so it is never served inline.
        $download = $request->boolean('download') || $file->mime_type === 'image/svg+xml';

        $response = response()
            ->file($this->storage->absolutePath((string) $file->storage_path), [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]);

        // Symfony builds the RFC 5987 header and an ASCII fallback, so accented
        // names survive the download.
        $response->setContentDisposition(
            $download
                ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
                : ResponseHeaderBag::DISPOSITION_INLINE,
            $file->name,
        );

        return $response->setPrivate()->setMaxAge(300);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sharedByMe(User $owner): array
    {
        $files = $this->ownQuery($owner)
            ->whereHas('shares')
            ->withCount('shares')
            ->orderByRaw('lower(name)')
            ->get();

        return $files
            ->map(fn (DriveFile $file): array => [
                ...$this->row($file, $owner),
                'shared_count' => (int) $file->shares_count,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sharedWithMe(User $viewer): array
    {
        $files = DriveFile::query()
            ->whereNull('deleted_at')
            ->whereHas(
                'shares',
                fn (Builder $query): Builder => $query->where('shared_with_user_code', $viewer->code),
            )
            ->with('owner:code,first_name,last_name')
            ->orderByRaw('lower(name)')
            ->get();

        return $files
            ->map(fn (DriveFile $file): array => [
                ...$this->row($file, $viewer),
                'owner_name' => trim(
                    ($file->owner?->first_name ?? '').' '.($file->owner?->last_name ?? ''),
                ),
            ])
            ->all();
    }

    /**
     * Only the nodes the owner actually deleted: a trashed child of a trashed
     * folder is restored with its parent, so listing it separately is noise.
     *
     * @return list<array<string, mixed>>
     */
    private function trash(User $owner): array
    {
        $files = DriveFile::query()
            ->where('user_code', $owner->code)
            ->whereNotNull('deleted_at')
            ->whereDoesntHave(
                'parent',
                fn (Builder $query): Builder => $query->whereNotNull('deleted_at'),
            )
            ->orderByDesc('deleted_at')
            ->get();

        return $this->rows($files, $owner);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(User $owner, string $term): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_substr($term, 0, 100)).'%';

        $files = $this->ownQuery($owner)
            ->whereRaw('name ILIKE ?', [$like])
            ->orderByRaw("CASE WHEN type = 'dir' THEN 0 ELSE 1 END")
            ->orderByRaw('lower(name)')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return $this->rows($files, $owner);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shortlist(User $owner, string $view): array
    {
        $files = $this->ownQuery($owner)
            ->where('type', '!=', 'dir')
            ->when(
                $view === 'recent',
                fn (Builder $query): Builder => $query->orderByDesc('updated_at'),
                fn (Builder $query): Builder => $query->orderByDesc('size'),
            )
            ->limit(self::SHORTLIST_LIMIT)
            ->get();

        return $this->rows($files, $owner);
    }

    /**
     * @return Builder<DriveFile>
     */
    private function ownQuery(User $owner): Builder
    {
        return DriveFile::query()
            ->where('user_code', $owner->code)
            ->whereNull('deleted_at');
    }

    private function resolveMoveTarget(
        DriveFileUpdateRequest $request,
        DriveFile $file,
        User $owner,
    ): ?string {
        $target = $this->resolveFolder($request->input('parent_code'), $owner);

        if ($target && $file->isDirectory() && $this->tree->contains($file->code, $target->code)) {
            throw ValidationException::withMessages([
                'parent_code' => 'No se puede mover una carpeta dentro de sí misma.',
            ]);
        }

        return $target?->code;
    }

    /**
     * A parent is only usable as a write target when the actor owns it, it is a
     * live folder, and it is not in the trash.
     */
    private function resolveFolder(mixed $code, User $owner): ?DriveFile
    {
        if (! is_string($code) || $code === '' || $code === 'root') {
            return null;
        }

        $folder = DriveFile::query()
            ->where('code', $code)
            ->where('user_code', $owner->code)
            ->where('type', 'dir')
            ->whereNull('deleted_at')
            ->first();

        abort_unless($folder, 404);

        return $folder;
    }

    /**
     * Browsing also accepts a folder shared with the actor, directly or through
     * one of its ancestors.
     */
    private function resolveReadableFolder(mixed $code, User $viewer): ?DriveFile
    {
        if (! is_string($code) || $code === '' || $code === 'root') {
            return null;
        }

        $folder = DriveFile::query()
            ->where('code', $code)
            ->where('type', 'dir')
            ->whereNull('deleted_at')
            ->first();

        abort_unless(
            $folder && (
                $folder->user_code === $viewer->code
                || $this->tree->isSharedWith($folder->code, $viewer->code)
            ),
            404,
        );

        return $folder;
    }

    /**
     * Inside a received folder the trail starts at the shared root: the owner's
     * folders above it were never shared and must not be named.
     *
     * @return list<object{code: string, name: string}>
     */
    private function readablePath(DriveFile $folder, User $viewer): array
    {
        $path = $this->tree->ancestors($folder->code);

        if ($folder->user_code === $viewer->code) {
            return $path;
        }

        $sharedCodes = DriveShare::query()
            ->where('shared_with_user_code', $viewer->code)
            ->pluck('file_code')
            ->all();

        $start = 0;
        foreach ($path as $index => $node) {
            if (in_array($node->code, $sharedCodes, true)) {
                $start = $index;
            }
        }

        return array_slice($path, $start);
    }

    /**
     * @param  list<array<string, mixed>>  $files
     */
    private function flatListing(array $files, bool $owned = true): JsonResponse
    {
        return response()->json(['files' => $files, 'path' => [], 'owned' => $owned]);
    }

    private function assertOwned(DriveFile $file, User $owner): void
    {
        abort_unless($file->user_code === $owner->code, 404);
    }

    /**
     * The partial unique indexes own sibling-name uniqueness; this turns the
     * race-free database answer into a field error the dialog can show.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $write
     * @return TValue
     */
    private function rejectingNameConflict(Closure $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'name' => 'Ya existe un elemento con ese nombre en esa carpeta.',
            ]);
        }
    }

    /**
     * @param  iterable<DriveFile>  $files
     * @return list<array<string, mixed>>
     */
    private function rows(iterable $files, User $viewer): array
    {
        $rows = [];

        foreach ($files as $file) {
            $rows[] = $this->row($file, $viewer);
        }

        return $rows;
    }

    /**
     * `scope` is not stored: Lumi's `DriveFileItem` requires the field, and the
     * only distinction this Drive makes is own versus received.
     *
     * @return array<string, mixed>
     */
    private function row(DriveFile $file, User $viewer): array
    {
        return [
            'code' => $file->code,
            'scope' => $file->user_code === $viewer->code ? 'user_private' : 'shared',
            'name' => $file->name,
            'type' => $file->type,
            'size' => $file->size,
            'mime_type' => $file->mime_type,
            'parent_code' => $file->parent_code,
            'user_code' => $file->user_code,
            'deleted_at' => $file->deleted_at?->toIso8601String(),
            'created_at' => $file->created_at->toIso8601String(),
            'updated_at' => $file->updated_at->toIso8601String(),
        ];
    }

    private function owner(Request $request): User
    {
        /** @var AuthAccount $account */
        $account = $request->user();
        $owner = $account->user;
        abort_unless($owner, 403);

        return $owner;
    }
}
