<?php

namespace App\Http\Controllers;

use App\Actions\ManageStudentAttentionFile;
use App\Actions\SaveStudentAttention;
use App\Http\Requests\StudentAttentionFileRequest;
use App\Http\Requests\StudentAttentionRequest;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\DriveFile;
use App\Models\Student;
use App\Models\StudentAttention;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\Drive\DriveStorage;
use App\Support\StudentAttentions\StudentAttentionType;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class StudentAttentionController extends Controller
{
    private const int PAGE_SIZE = 20;

    public function __construct(private readonly DriveStorage $storage) {}

    public function branchIndex(
        Request $request,
        BranchContext $branchContext,
    ): Response|RedirectResponse {
        $branch = $this->currentBranch($request, $branchContext);
        if (! $branch) {
            return to_route('branches.index');
        }

        $timezone = (string) config('aeduca.business_timezone', 'America/Lima');
        $requestedMonth = $request->query('month');
        $month = is_string($requestedMonth)
            && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth) === 1
                ? $requestedMonth
                : CarbonImmutable::now($timezone)->format('Y-m');
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month, $timezone);
        abort_unless($monthStart, 422);

        $requestedType = $request->query('type');
        $type = is_string($requestedType)
            ? StudentAttentionType::tryFrom(trim($requestedType))
            : null;
        $requestedSearch = $request->query('q');
        $search = mb_substr(trim(preg_replace(
            '/\s+/',
            ' ',
            is_string($requestedSearch) ? $requestedSearch : '',
        ) ?? ''), 0, 100);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)).'%';

        $attentions = StudentAttention::query()
            ->join('students as s', 's.code', '=', 'student_attentions.student_code')
            ->where('student_attentions.branch_code', $branch->code)
            ->where('student_attentions.occurred_at', '>=', $monthStart->utc())
            ->where('student_attentions.occurred_at', '<', $monthStart->addMonth()->utc())
            ->when($type, fn ($query) => $query->where('student_attentions.type', $type->value))
            ->when($search !== '', function ($query) use ($search, $like): void {
                $query->where(function ($filter) use ($search, $like): void {
                    $filter
                        ->where('s.dni', $search)
                        ->orWhereRaw("lower(s.first_name || ' ' || s.last_name) LIKE ?", [$like])
                        ->orWhereRaw('lower(student_attentions.reason) LIKE ?', [$like]);
                });
            })
            ->select([
                'student_attentions.*',
                's.dni as student_dni',
                's.first_name as student_first_name',
                's.last_name as student_last_name',
            ])
            ->with('createdBy:code,first_name,last_name')
            ->withCount('files')
            ->orderByDesc('student_attentions.occurred_at')
            ->orderByDesc('student_attentions.code')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        return Inertia::render('Students/Attentions/BranchIndex', [
            'branch' => ['name' => $branch->name],
            'attentions' => [
                'data' => collect($attentions->items())
                    ->map(fn (StudentAttention $attention): array => $this->branchRow($attention))
                    ->all(),
                'current_page' => $attentions->currentPage(),
                'last_page' => $attentions->lastPage(),
                'total' => $attentions->total(),
            ],
            'filters' => [
                'month' => $month,
                'type' => $type?->value ?? '',
                'q' => $search,
            ],
            'type_options' => StudentAttentionType::options(),
            'business_timezone' => $timezone,
            'can_manage' => Gate::check('student_attentions.manage'),
        ]);
    }

    public function students(Request $request, BranchContext $branchContext): JsonResponse
    {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $requestedSearch = $request->query('q');
        $search = mb_substr(trim(preg_replace(
            '/\s+/',
            ' ',
            is_string($requestedSearch) ? $requestedSearch : '',
        ) ?? ''), 0, 100);
        $limit = min(max($request->integer('limit', 10), 1), 20);

        if (mb_strlen($search) < 2) {
            return response()->json(['items' => []]);
        }

        $normalized = mb_strtolower($search);
        $like = '%'.str_replace(['%', '_'], '', $normalized).'%';
        $items = Student::query()
            ->whereHas('enrollments.cycle', fn ($query) => $query
                ->where('branch_code', $branch->code))
            ->where(function ($query) use ($search, $normalized, $like): void {
                $query
                    ->where('dni', $search)
                    ->orWhereRaw("lower(first_name || ' ' || last_name) % ?", [$normalized])
                    ->orWhereRaw("lower(first_name || ' ' || last_name) LIKE ?", [$like]);
            })
            ->orderByRaw('CASE WHEN dni = ? THEN 0 ELSE 1 END', [$search])
            ->orderByRaw(
                "similarity(lower(first_name || ' ' || last_name), ?) DESC",
                [$normalized],
            )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit($limit)
            ->get(['code', 'dni', 'first_name', 'last_name'])
            ->map(fn (Student $student): array => [
                'code' => $student->code,
                'full_name' => trim($student->first_name.' '.$student->last_name),
                'dni' => $student->dni,
            ])
            ->all();

        return response()->json(['items' => $items]);
    }

    public function index(
        Request $request,
        Student $student,
        BranchContext $branchContext,
    ): Response|RedirectResponse {
        $branch = $this->currentBranch($request, $branchContext);
        if (! $branch) {
            return to_route('branches.index');
        }

        $requestedType = $request->query('type');
        $type = is_string($requestedType)
            ? StudentAttentionType::tryFrom(trim($requestedType))
            : null;
        $attentions = StudentAttention::query()
            ->where('student_code', $student->code)
            ->where('branch_code', $branch->code)
            ->when($type, fn ($query) => $query->where('type', $type->value))
            ->with('createdBy:code,first_name,last_name')
            ->withCount('files')
            ->orderByDesc('occurred_at')
            ->orderByDesc('code')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        return Inertia::render('Students/Attentions/Index', [
            'student' => $this->studentData($student),
            'branch' => ['name' => $branch->name],
            'attentions' => [
                'data' => collect($attentions->items())
                    ->map(fn (StudentAttention $attention): array => $this->indexRow($attention))
                    ->all(),
                'current_page' => $attentions->currentPage(),
                'last_page' => $attentions->lastPage(),
                'total' => $attentions->total(),
            ],
            'filters' => ['type' => $type?->value ?? ''],
            'type_options' => StudentAttentionType::options(),
            'business_timezone' => (string) config('aeduca.business_timezone', 'America/Lima'),
            'can_manage' => Gate::check('student_attentions.manage')
                && $this->studentBelongsToBranch($student, $branch),
        ]);
    }

    public function create(
        Request $request,
        Student $student,
        BranchContext $branchContext,
    ): Response|RedirectResponse {
        $branch = $this->currentBranch($request, $branchContext);
        if (! $branch) {
            return to_route('branches.index');
        }

        if (! $this->studentBelongsToBranch($student, $branch)) {
            Inertia::flash('error', 'El alumno no tiene una matrícula registrada en la sede actual.');

            return to_route('students.attentions.index', $student);
        }

        return Inertia::render('Students/Attentions/Form', [
            'student' => $this->studentData($student),
            'branch' => ['name' => $branch->name],
            'attention' => null,
            'type_options' => StudentAttentionType::options(),
            'business_timezone' => (string) config('aeduca.business_timezone', 'America/Lima'),
            'can_use_drive' => Gate::check('drive.manage'),
        ]);
    }

    public function store(
        StudentAttentionRequest $request,
        Student $student,
        BranchContext $branchContext,
        SaveStudentAttention $saveAttention,
    ): RedirectResponse {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $attention = $saveAttention->handle(
            null,
            $student,
            $branch,
            $this->actor($request),
            $request->validated(),
        );

        Inertia::flash([
            'success' => 'Atención registrada',
            ...($request->boolean('attach') ? ['open_attachments' => true] : []),
        ]);

        return to_route('students.attentions.show', [$student, $attention]);
    }

    public function show(
        Request $request,
        Student $student,
        string $attention,
        BranchContext $branchContext,
    ): Response|RedirectResponse {
        $branch = $this->currentBranch($request, $branchContext);
        if (! $branch) {
            return to_route('branches.index');
        }

        $record = $this->attention($student, $attention, $branch)
            ->load([
                'createdBy:code,first_name,last_name',
                'updatedBy:code,first_name,last_name',
            ]);
        $files = $record->files()
            ->orderByPivot('created_at', 'desc')
            ->get();

        return Inertia::render('Students/Attentions/Show', [
            'student' => $this->studentData($student),
            'branch' => ['name' => $branch->name],
            'attention' => $this->attentionData($record),
            'files' => $files->map(fn (DriveFile $file): array => $this->fileData(
                $student,
                $record,
                $file,
            ))->all(),
            'business_timezone' => (string) config('aeduca.business_timezone', 'America/Lima'),
            'can_manage' => Gate::check('student_attentions.manage'),
            'can_use_drive' => Gate::check('drive.manage'),
        ]);
    }

    public function edit(
        Request $request,
        Student $student,
        string $attention,
        BranchContext $branchContext,
    ): Response|RedirectResponse {
        $branch = $this->currentBranch($request, $branchContext);
        if (! $branch) {
            return to_route('branches.index');
        }

        $record = $this->attention($student, $attention, $branch);

        return Inertia::render('Students/Attentions/Form', [
            'student' => $this->studentData($student),
            'branch' => ['name' => $branch->name],
            'attention' => [
                'code' => $record->code,
                'type' => $record->type->value,
                'reason' => $record->reason,
                'development' => $record->development,
                'conclusion' => $record->conclusion,
                'occurred_at_local' => $record->occurred_at
                    ->setTimezone((string) config('aeduca.business_timezone', 'America/Lima'))
                    ->format('Y-m-d\TH:i'),
            ],
            'type_options' => StudentAttentionType::options(),
            'business_timezone' => (string) config('aeduca.business_timezone', 'America/Lima'),
            'can_use_drive' => Gate::check('drive.manage'),
        ]);
    }

    public function update(
        StudentAttentionRequest $request,
        Student $student,
        string $attention,
        BranchContext $branchContext,
        SaveStudentAttention $saveAttention,
    ): RedirectResponse {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $record = $this->attention($student, $attention, $branch);
        $saveAttention->handle(
            $record,
            $student,
            $branch,
            $this->actor($request),
            $request->validated(),
        );

        Inertia::flash([
            'success' => 'Atención actualizada',
            ...($request->boolean('attach') ? ['open_attachments' => true] : []),
        ]);

        return to_route('students.attentions.show', [$student, $record]);
    }

    public function attachFile(
        StudentAttentionFileRequest $request,
        Student $student,
        string $attention,
        BranchContext $branchContext,
        ManageStudentAttentionFile $manageFile,
    ): JsonResponse {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $record = $this->attention($student, $attention, $branch);
        $file = DriveFile::query()->findOrFail((string) $request->validated('file_code'));

        $manageFile->attach($record, $file, $branch, $this->actor($request));

        return response()->json([
            'file' => $this->fileData($student, $record, $file->refresh()),
        ], 201);
    }

    public function detachFile(
        Request $request,
        Student $student,
        string $attention,
        DriveFile $file,
        BranchContext $branchContext,
        ManageStudentAttentionFile $manageFile,
    ): JsonResponse {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $record = $this->attention($student, $attention, $branch);
        $manageFile->detach($record, $file, $branch);

        return response()->json(['detached' => true]);
    }

    public function serveFile(
        Request $request,
        Student $student,
        string $attention,
        DriveFile $file,
        BranchContext $branchContext,
    ): BinaryFileResponse {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $record = $this->attention($student, $attention, $branch);

        abort_if($file->isDirectory(), 404);
        abort_unless(
            DB::table('student_attention_files')
                ->where('student_attention_code', $record->code)
                ->where('drive_file_code', $file->code)
                ->exists(),
            404,
        );
        abort_unless($this->storage->exists($file->storage_path), 404);

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

    private function attention(Student $student, string $code, Branch $branch): StudentAttention
    {
        return StudentAttention::query()
            ->where('code', $code)
            ->where('student_code', $student->code)
            ->where('branch_code', $branch->code)
            ->firstOrFail();
    }

    private function currentBranch(Request $request, BranchContext $context): ?Branch
    {
        /** @var AuthAccount $account */
        $account = $request->user();

        return $context->currentBranch($account);
    }

    private function requireCurrentBranch(Request $request, BranchContext $context): Branch
    {
        $branch = $this->currentBranch($request, $context);
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

    private function studentBelongsToBranch(Student $student, Branch $branch): bool
    {
        return DB::table('enrollments as e')
            ->join('academic_cycles as c', 'c.code', '=', 'e.cycle_code')
            ->where('e.student_code', $student->code)
            ->where('c.branch_code', $branch->code)
            ->exists();
    }

    /**
     * @return array{code: string, full_name: string, dni: string}
     */
    private function studentData(Student $student): array
    {
        return [
            'code' => $student->code,
            'full_name' => trim($student->first_name.' '.$student->last_name),
            'dni' => $student->dni,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function indexRow(StudentAttention $attention): array
    {
        return [
            'code' => $attention->code,
            'type_label' => $attention->type->label(),
            'reason' => $attention->reason,
            'occurred_at' => $attention->occurred_at->toIso8601String(),
            'author_name' => trim(
                ($attention->createdBy?->first_name ?? '').' '.
                ($attention->createdBy?->last_name ?? ''),
            ),
            'files_count' => (int) $attention->files_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchRow(StudentAttention $attention): array
    {
        return [
            ...$this->indexRow($attention),
            'student_code' => $attention->student_code,
            'student_dni' => $attention->getAttribute('student_dni'),
            'student_first_name' => $attention->getAttribute('student_first_name'),
            'student_last_name' => $attention->getAttribute('student_last_name'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attentionData(StudentAttention $attention): array
    {
        return [
            'code' => $attention->code,
            'type_label' => $attention->type->label(),
            'reason' => $attention->reason,
            'development' => $attention->development,
            'conclusion' => $attention->conclusion,
            'occurred_at' => $attention->occurred_at->toIso8601String(),
            'created_at' => $attention->created_at->toIso8601String(),
            'updated_at' => $attention->updated_at->toIso8601String(),
            'author_name' => trim(
                ($attention->createdBy?->first_name ?? '').' '.
                ($attention->createdBy?->last_name ?? ''),
            ),
            'updated_by_name' => $attention->updatedBy
                ? trim($attention->updatedBy->first_name.' '.$attention->updatedBy->last_name)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileData(
        Student $student,
        StudentAttention $attention,
        DriveFile $file,
    ): array {
        return [
            'code' => $file->code,
            'name' => $file->name,
            'size' => $file->size,
            'deleted_at' => $file->deleted_at?->toIso8601String(),
            'serve_url' => route('students.attentions.files.serve', [
                $student,
                $attention,
                $file,
            ]),
        ];
    }
}
