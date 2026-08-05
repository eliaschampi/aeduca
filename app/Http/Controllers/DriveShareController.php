<?php

namespace App\Http\Controllers;

use App\Http\Requests\DriveShareRequest;
use App\Models\AuthAccount;
use App\Models\DriveFile;
use App\Models\DriveShare;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The only way a Drive node leaves its owner's space: an explicit, read-only
 * grant to one other employee. Sharing a folder reaches its whole subtree, so
 * one grant replaces one row per contained file.
 */
class DriveShareController extends Controller
{
    public function index(Request $request, DriveFile $file): JsonResponse
    {
        $this->assertShareable($file, $this->owner($request));

        return response()->json(['shares' => $this->shares($file)]);
    }

    public function store(DriveShareRequest $request, DriveFile $file): JsonResponse
    {
        $owner = $this->owner($request);
        $this->assertShareable($file, $owner);

        $recipientCode = (string) $request->input('user_code');

        if ($recipientCode === $owner->code) {
            throw ValidationException::withMessages([
                'user_code' => 'El archivo ya es tuyo.',
            ]);
        }

        $created = DriveShare::query()->firstOrCreate([
            'file_code' => $file->code,
            'shared_with_user_code' => $recipientCode,
        ])->wasRecentlyCreated;

        return response()->json([
            'created' => $created,
            'shares' => $this->shares($file),
        ], $created ? 201 : 200);
    }

    public function destroy(Request $request, DriveFile $file, DriveShare $share): JsonResponse
    {
        $this->assertShareable($file, $this->owner($request));
        abort_unless($share->file_code === $file->code, 404);

        $share->delete();

        return response()->json(['shares' => $this->shares($file)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shares(DriveFile $file): array
    {
        return $file->shares()
            ->with('recipient:code,first_name,last_name')
            ->get()
            ->sortBy(fn (DriveShare $share): string => mb_strtolower(
                trim(($share->recipient?->first_name ?? '').' '.($share->recipient?->last_name ?? '')),
            ))
            ->map(fn (DriveShare $share): array => [
                'code' => $share->code,
                'user_code' => $share->shared_with_user_code,
                'full_name' => trim(
                    ($share->recipient?->first_name ?? '').' '.($share->recipient?->last_name ?? ''),
                ),
                'created_at' => $share->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function assertShareable(DriveFile $file, User $owner): void
    {
        abort_unless($file->user_code === $owner->code, 404);

        if ($file->isTrashed()) {
            throw ValidationException::withMessages([
                'user_code' => 'No se puede compartir un archivo en la papelera.',
            ]);
        }
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
