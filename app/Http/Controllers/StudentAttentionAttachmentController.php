<?php

namespace App\Http\Controllers;

use App\Actions\StoreStudentAttentionAttachment;
use App\Http\Requests\StudentAttentionUploadRequest;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\DriveFile;
use App\Models\StudentAttention;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\Drive\DriveStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class StudentAttentionAttachmentController extends Controller
{
    public function __construct(private readonly DriveStorage $storage) {}

    public function store(
        StudentAttentionUploadRequest $request,
        BranchContext $branchContext,
        StoreStudentAttentionAttachment $storeAttachment,
    ): JsonResponse {
        $this->requireCurrentBranch($request, $branchContext);
        $upload = $request->file('file');
        abort_unless($upload, 422);

        $file = $storeAttachment->handle(
            $this->actor($request),
            $upload,
            $request->string('name')->toString(),
        );

        return response()->json([
            'attachment' => $this->attachmentData($file),
        ], 201);
    }

    public function show(
        Request $request,
        StudentAttention $attention,
        BranchContext $branchContext,
    ): BinaryFileResponse {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        abort_unless($attention->branch_code === $branch->code, 404);

        $file = $attention->driveFile;
        abort_unless(
            $file && ! $file->isDirectory() && $this->storage->exists($file->storage_path),
            404,
        );

        $download = $request->boolean('download') || $file->mime_type === 'image/svg+xml';
        $response = response()->file(
            $this->storage->absolutePath((string) $file->storage_path),
            [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
        $response->setContentDisposition(
            $download
                ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
                : ResponseHeaderBag::DISPOSITION_INLINE,
            $file->name,
        );

        return $response->setPrivate()->setMaxAge(300);
    }

    private function requireCurrentBranch(Request $request, BranchContext $context): Branch
    {
        /** @var AuthAccount $account */
        $account = $request->user();
        $branch = $context->currentBranch($account);
        abort_unless($branch, 409, 'Selecciona una sede para continuar.');

        return $branch;
    }

    private function actor(Request $request): User
    {
        /** @var AuthAccount $account */
        $account = $request->user();
        abort_unless($account->user, 403);

        return $account->user;
    }

    /**
     * @return array{code: string, name: string, size: int, deleted_at: null, serve_url: string}
     */
    private function attachmentData(DriveFile $file): array
    {
        return [
            'code' => $file->code,
            'name' => $file->name,
            'size' => $file->size,
            'deleted_at' => null,
            'serve_url' => route('drive.files.serve', $file),
        ];
    }
}
