<?php

namespace App\Http\Controllers;

use App\Actions\SaveStudentAttention;
use App\Http\Requests\StudentAttentionRequest;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentAttention;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\StudentAttentions\StudentAttentionType;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class StudentAttentionController extends Controller
{
    private const int PAGE_SIZE = 20;

    public function index(Request $request, BranchContext $branchContext): Response|RedirectResponse
    {
        $branch = $this->currentBranch($request, $branchContext);

        if (! $branch) {
            return to_route('branches.index');
        }

        $timezone = (string) config('aeduca.business_timezone', 'America/Lima');
        $month = $this->month($request, $timezone);
        $monthStart = CarbonImmutable::parse($month.'-01', $timezone)->startOfMonth();
        $attentions = DB::table('student_attentions as attention')
            ->join('students as student', 'student.code', '=', 'attention.student_code')
            ->join('users as author', 'author.code', '=', 'attention.created_by_user_code')
            ->where('attention.branch_code', $branch->code)
            ->where('attention.occurred_at', '>=', $monthStart->utc())
            ->where('attention.occurred_at', '<', $monthStart->addMonth()->utc())
            ->select([
                'attention.code',
                'attention.type',
                'attention.reason',
                'attention.occurred_at',
                'attention.drive_file_code',
                'student.code as student_code',
                'student.dni as student_dni',
                'student.first_name as student_first_name',
                'student.last_name as student_last_name',
                'author.first_name as author_first_name',
                'author.last_name as author_last_name',
            ])
            ->orderByDesc('attention.occurred_at')
            ->orderByDesc('attention.code')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        return Inertia::render('StudentAttentions/Index', [
            'branch' => ['name' => $branch->name],
            'attentions' => [
                'data' => collect($attentions->items())
                    ->map(fn (object $row): array => $this->indexRow($row))
                    ->all(),
                'current_page' => $attentions->currentPage(),
                'last_page' => $attentions->lastPage(),
                'total' => $attentions->total(),
            ],
            'filters' => ['month' => $month],
            'business_timezone' => $timezone,
            'can_manage' => Gate::check('attentions.manage'),
        ]);
    }

    public function students(Request $request, BranchContext $branchContext): JsonResponse
    {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $search = mb_substr(trim(preg_replace(
            '/\s+/',
            ' ',
            (string) $request->query('q', ''),
        ) ?? ''), 0, 100);
        $limit = min(max($request->integer('limit', 10), 1), 20);

        if (mb_strlen($search) < 2) {
            return response()->json(['items' => []]);
        }

        $normalized = mb_strtolower($search);
        $like = '%'.str_replace(['%', '_'], '', $normalized).'%';
        $students = Student::query()
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
            ->map(fn (Student $student): array => $this->studentOption($student))
            ->all();

        return response()->json(['items' => $students]);
    }

    public function create(Request $request, BranchContext $branchContext): Response|RedirectResponse
    {
        $branch = $this->currentBranch($request, $branchContext);

        if (! $branch) {
            return to_route('branches.index');
        }

        $requestedStudent = $request->query('student');
        $student = is_string($requestedStudent) && Str::isUuid($requestedStudent)
            ? Student::query()->find($requestedStudent)
            : null;

        if ($student && ! $this->studentBelongsToBranch($student, $branch)) {
            $student = null;
        }

        $timezone = (string) config('aeduca.business_timezone', 'America/Lima');

        return Inertia::render('StudentAttentions/Form', [
            'branch' => ['name' => $branch->name],
            'attention' => null,
            'selected_student' => $student ? $this->studentOption($student) : null,
            'type_options' => StudentAttentionType::options(),
            'business_timezone' => $timezone,
            'return_month' => $this->month($request, $timezone),
            'can_use_drive' => Gate::check('drive.manage'),
        ]);
    }

    public function store(
        StudentAttentionRequest $request,
        BranchContext $branchContext,
        SaveStudentAttention $saveAttention,
    ): RedirectResponse {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $attributes = $request->validated();

        if ($attributes['drive_file_code']) {
            Gate::authorize('drive.manage');
        }

        $attention = $saveAttention->handle(
            null,
            $branch,
            $this->actor($request),
            $attributes,
        );

        Inertia::flash('success', 'Atención registrada');

        return to_route('student-attentions.index', [
            'month' => $attention->occurred_at
                ->setTimezone((string) config('aeduca.business_timezone', 'America/Lima'))
                ->format('Y-m'),
        ]);
    }

    public function edit(
        Request $request,
        StudentAttention $attention,
        BranchContext $branchContext,
    ): Response|RedirectResponse {
        $branch = $this->currentBranch($request, $branchContext);

        if (! $branch) {
            return to_route('branches.index');
        }

        $attention = $this->attention($attention, $branch)->load([
            'student:code,dni,first_name,last_name',
            'driveFile:code,name,size,deleted_at',
        ]);

        return Inertia::render('StudentAttentions/Form', [
            'branch' => ['name' => $branch->name],
            'attention' => [
                'code' => $attention->code,
                'student_code' => $attention->student_code,
                'type' => $attention->type->value,
                'reason' => $attention->reason,
                'development' => $attention->development,
                'conclusion' => $attention->conclusion,
                'occurred_at_local' => $attention->occurred_at
                    ->setTimezone((string) config('aeduca.business_timezone', 'America/Lima'))
                    ->format('Y-m-d\TH:i'),
                'attachment' => $attention->driveFile
                    ? $this->attachmentData($attention)
                    : null,
            ],
            'selected_student' => $this->studentOption($attention->student),
            'type_options' => StudentAttentionType::options(),
            'business_timezone' => (string) config('aeduca.business_timezone', 'America/Lima'),
            'return_month' => $attention->occurred_at
                ->setTimezone((string) config('aeduca.business_timezone', 'America/Lima'))
                ->format('Y-m'),
            'can_use_drive' => Gate::check('drive.manage'),
        ]);
    }

    public function update(
        StudentAttentionRequest $request,
        StudentAttention $attention,
        BranchContext $branchContext,
        SaveStudentAttention $saveAttention,
    ): RedirectResponse {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $attention = $this->attention($attention, $branch);
        $attributes = $request->validated();

        if (
            $attributes['drive_file_code']
            && $attributes['drive_file_code'] !== $attention->drive_file_code
        ) {
            Gate::authorize('drive.manage');
        }

        $attention = $saveAttention->handle(
            $attention,
            $branch,
            $this->actor($request),
            $attributes,
        );

        Inertia::flash('success', 'Atención actualizada');

        return to_route('student-attentions.index', [
            'month' => $attention->occurred_at
                ->setTimezone((string) config('aeduca.business_timezone', 'America/Lima'))
                ->format('Y-m'),
        ]);
    }

    public function destroy(
        Request $request,
        StudentAttention $attention,
        BranchContext $branchContext,
    ): RedirectResponse {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $this->attention($attention, $branch)->delete();

        Inertia::flash('success', 'Atención eliminada');

        return to_route('student-attentions.index', [
            'month' => $this->month(
                $request,
                (string) config('aeduca.business_timezone', 'America/Lima'),
            ),
        ]);
    }

    public function certificate(
        Request $request,
        StudentAttention $attention,
        BranchContext $branchContext,
    ): JsonResponse {
        $branch = $this->requireCurrentBranch($request, $branchContext);
        $attention = $this->attention($attention, $branch)->load([
            'student:code,dni,first_name,last_name',
            'branch:code,name',
            'createdBy:code,employee_role_code,first_name,last_name',
            'createdBy.employeeRole:code,name',
        ]);

        return response()->json([
            'attention' => [
                'code' => $attention->code,
                'type_label' => $attention->type->label(),
                'reason' => $attention->reason,
                'development' => $attention->development,
                'conclusion' => $attention->conclusion,
                'occurred_at' => $attention->occurred_at->toIso8601String(),
                'has_attachment' => $attention->drive_file_code !== null,
            ],
            'student' => $this->studentOption($attention->student),
            'branch' => ['name' => $attention->branch->name],
            'author' => [
                'full_name' => trim(
                    $attention->createdBy->first_name.' '.$attention->createdBy->last_name,
                ),
                'role_name' => $attention->createdBy->employeeRole?->name,
            ],
            'business_timezone' => (string) config('aeduca.business_timezone', 'America/Lima'),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function attention(StudentAttention $attention, Branch $branch): StudentAttention
    {
        abort_unless($attention->branch_code === $branch->code, 404);

        return $attention;
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
        return DB::table('enrollments as enrollment')
            ->join('academic_cycles as cycle', 'cycle.code', '=', 'enrollment.cycle_code')
            ->where('enrollment.student_code', $student->code)
            ->where('cycle.branch_code', $branch->code)
            ->exists();
    }

    private function month(Request $request, string $timezone): string
    {
        $month = $request->query('month');

        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? $month
            : CarbonImmutable::now($timezone)->format('Y-m');
    }

    /**
     * @return array{code: string, full_name: string, dni: string}
     */
    private function studentOption(Student $student): array
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
    private function indexRow(object $row): array
    {
        return [
            'code' => $row->code,
            'student_code' => $row->student_code,
            'student_dni' => $row->student_dni,
            'student_first_name' => $row->student_first_name,
            'student_last_name' => $row->student_last_name,
            'type_label' => StudentAttentionType::from($row->type)->label(),
            'reason' => $row->reason,
            'occurred_at' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
            'author_name' => trim($row->author_first_name.' '.$row->author_last_name),
            'has_attachment' => $row->drive_file_code !== null,
        ];
    }

    /**
     * @return array{code: string, name: string, size: int, deleted_at: ?string, serve_url: string}
     */
    private function attachmentData(StudentAttention $attention): array
    {
        return [
            'code' => $attention->driveFile->code,
            'name' => $attention->driveFile->name,
            'size' => $attention->driveFile->size,
            'deleted_at' => $attention->driveFile->deleted_at?->toIso8601String(),
            'serve_url' => route('student-attentions.attachment.show', $attention),
        ];
    }
}
