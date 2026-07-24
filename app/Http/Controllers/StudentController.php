<?php

namespace App\Http\Controllers;

use App\Actions\SaveStudentProfile;
use App\Actions\UpdateStudentPhoto;
use App\Http\Requests\StudentPhotoRequest;
use App\Http\Requests\StudentRequest;
use App\Models\AuthAccount;
use App\Models\Student;
use App\Support\Academic\DegreeNumber;
use App\Support\Branches\BranchContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentController extends Controller
{
    public function search(Request $request): Response
    {
        $query = trim(preg_replace('/\s+/', ' ', (string) $request->query('q', '')) ?? '');
        $students = $this->directoryQuery($query)
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Students/Search', [
            'students' => [
                'data' => collect($students->items())
                    ->map(fn (object $row): array => $this->directoryRow($row))
                    ->all(),
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'total' => $students->total(),
            ],
            'filters' => ['q' => $query],
            'can_manage' => Gate::check('students.manage'),
            'can_view_enrollments' => Gate::check('enrollments.view'),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $query = trim(preg_replace('/\s+/', ' ', (string) $request->query('q', '')) ?? '');
        $limit = min(max($request->integer('limit', 10), 1), 20);

        if (mb_strlen($query) < 2) {
            return response()->json(['items' => []]);
        }

        $items = $this->directoryQuery(mb_substr($query, 0, 100))
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'code' => $row->student_code,
                'full_name' => trim($row->first_name.' '.$row->last_name),
                'dni' => $row->dni,
                'roll_code' => $row->enrollment_status === 'active'
                    ? $row->roll_code
                    : null,
            ])
            ->all();

        return response()->json(['items' => $items]);
    }

    public function create(): Response
    {
        return Inertia::render('Students/Form', [
            'student' => null,
        ]);
    }

    public function store(StudentRequest $request, SaveStudentProfile $saveStudent): RedirectResponse
    {
        $student = $saveStudent->handle(
            null,
            $this->attributes($request),
        );

        Inertia::flash('success', 'Alumno registrado');

        return to_route('students.show', $student);
    }

    public function show(
        Request $request,
        Student $student,
        BranchContext $branchContext,
    ): Response {
        /** @var AuthAccount $account */
        $account = $request->user();
        $isSelf = $account->student_code === $student->code;

        if (! $isSelf) {
            Gate::authorize('students.view');
        }

        $enrollmentsQuery = DB::table('student_enrollment_overview')
            ->where('student_code', $student->code);

        if (! $isSelf) {
            $branchCodes = $branchContext->authorizedBranches($account)->pluck('code');
            $enrollmentsQuery->whereIn('branch_code', $branchCodes);
        }

        $enrollmentCount = (clone $enrollmentsQuery)->count();
        $enrollments = $enrollmentsQuery
            ->orderByRaw(<<<'SQL'
                CASE enrollment_status
                    WHEN 'active' THEN 0
                    WHEN 'inactive' THEN 1
                    ELSE 2
                END
                SQL)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => $this->enrollmentRow($row))
            ->all();

        $student->loadMissing('authAccount:code,student_code,login,is_active,last_login_at');

        return Inertia::render('Students/Show', [
            'student' => $this->studentData($student),
            'access' => $student->authAccount
                ? [
                    'login' => $student->authAccount->login,
                    'is_active' => $student->authAccount->is_active,
                    'last_login_at' => $student->authAccount->last_login_at?->toIso8601String(),
                ]
                : null,
            'contacts' => $isSelf
                ? []
                : $student->contacts()
                    ->latest()
                    ->get(['code', 'name', 'phone', 'note'])
                    ->map(fn ($contact): array => [
                        'code' => $contact->code,
                        'name' => $contact->name,
                        'phone' => $contact->phone,
                        'note' => $contact->note,
                    ])
                    ->all(),
            'enrollments' => $enrollments,
            'enrollment_count' => $enrollmentCount,
            'is_self' => $isSelf,
            'can_manage' => ! $isSelf && Gate::check('students.manage'),
            'can_manage_enrollments' => ! $isSelf && Gate::check('enrollments.manage'),
        ]);
    }

    public function edit(Student $student): Response
    {
        return Inertia::render('Students/Form', [
            'student' => $this->studentData($student),
        ]);
    }

    public function update(
        StudentRequest $request,
        Student $student,
        SaveStudentProfile $saveStudent,
    ): RedirectResponse {
        $saveStudent->handle(
            $student,
            $this->attributes($request),
        );

        Inertia::flash('success', 'Alumno actualizado');

        return to_route('students.show', $student);
    }

    public function updatePhoto(
        StudentPhotoRequest $request,
        Student $student,
        UpdateStudentPhoto $updatePhoto,
    ): RedirectResponse {
        $photo = $request->file('photo');
        abort_unless($photo, 422);

        $updatePhoto->handle($student, $photo);

        Inertia::flash('success', 'Foto del alumno actualizada');

        return to_route('students.show', $student);
    }

    public function photo(
        Request $request,
        Student $student,
        BranchContext $branchContext,
    ): BinaryFileResponse {
        /** @var AuthAccount $account */
        $account = $request->user();

        if (
            $account->student_code !== $student->code
            && ! Gate::check('students.view')
        ) {
            $branch = $account->user_code
                ? $branchContext->currentBranch($account)
                : null;

            abort_unless(
                $branch
                && Gate::check('enrollments.view')
                && DB::table('student_enrollment_overview')
                    ->where('student_code', $student->code)
                    ->where('branch_code', $branch->code)
                    ->exists(),
                403,
            );
        }

        abort_unless(
            $student->photo_path && Storage::disk('local')->exists($student->photo_path),
            404,
        );

        return response()
            ->file(Storage::disk('local')->path($student->photo_path))
            ->setPrivate()
            ->setMaxAge(300);
    }

    private function directoryQuery(string $search): Builder
    {
        $query = DB::table('student_directory')->select([
            'student_code',
            'dni',
            'first_name',
            'last_name',
            'photo_path',
            'student_is_active',
            'roll_code',
            'enrollment_is_active',
            'enrollment_status',
            'group_name',
            'degree_number',
            'cycle_name',
            'branch_name',
        ]);

        if ($search === '') {
            return $query->orderByDesc('student_created_at');
        }

        $normalized = mb_strtolower($search);
        $like = '%'.str_replace(['%', '_'], '', $normalized).'%';

        return $query
            ->where(function (Builder $builder) use ($search, $normalized, $like): void {
                $builder
                    ->where('dni', $search)
                    ->orWhere(function (Builder $roll) use ($search): void {
                        $roll
                            ->where('enrollment_status', 'active')
                            ->where('roll_code', $search);
                    })
                    ->orWhereRaw('lower(full_name) % ?', [$normalized])
                    ->orWhereRaw('lower(full_name) LIKE ?', [$like]);
            })
            ->orderByRaw('CASE WHEN dni = ? THEN 0 ELSE 1 END', [$search])
            ->orderByRaw(
                "CASE WHEN enrollment_status = 'active' AND roll_code = ? THEN 0 ELSE 1 END",
                [$search],
            )
            ->orderByRaw('similarity(lower(full_name), ?) DESC', [$normalized])
            ->orderBy('full_name');
    }

    /**
     * @return array<string, mixed>
     */
    private function directoryRow(object $row): array
    {
        return [
            'code' => $row->student_code,
            'dni' => $row->dni,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'photo_url' => $row->photo_path
                ? route('students.photo', $row->student_code)
                : null,
            'is_active' => (bool) $row->student_is_active,
            'enrollment' => $row->roll_code
                ? [
                    'roll_code' => $row->roll_code,
                    'is_active' => (bool) $row->enrollment_is_active,
                    'status' => $row->enrollment_status,
                    'status_label' => $this->enrollmentStatusLabel($row->enrollment_status),
                    'cycle_name' => $row->cycle_name,
                    'degree_label' => DegreeNumber::label((int) $row->degree_number),
                    'group_name' => $row->group_name,
                    'branch_name' => $row->branch_name,
                ]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function studentData(Student $student): array
    {
        return [
            'code' => $student->code,
            'dni' => $student->dni,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'birth_date' => $student->birth_date?->toDateString(),
            'phone' => $student->phone,
            'address' => $student->address,
            'observation' => $student->observation,
            'photo_url' => $student->photo_path
                ? route('students.photo', $student)
                : null,
            'is_active' => $student->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentRow(object $row): array
    {
        return [
            'code' => $row->enrollment_code,
            'roll_code' => $row->roll_code,
            'is_active' => (bool) $row->is_active,
            'status' => $row->enrollment_status,
            'status_label' => $this->enrollmentStatusLabel($row->enrollment_status),
            'observation' => $row->observation,
            'academic_group_code' => $row->academic_group_code,
            'group_name' => $row->group_name,
            'degree_label' => DegreeNumber::label((int) $row->degree_number),
            'cycle_name' => $row->cycle_name,
            'branch_name' => $row->branch_name,
            'shift_names' => $row->shift_names,
            'created_at' => $row->created_at,
        ];
    }

    private function enrollmentStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Activa',
            'finalized' => 'Ciclo finalizado',
            default => 'Inactiva',
        };
    }

    /**
     * @return array{
     *     dni: string,
     *     first_name: string,
     *     last_name: string,
     *     birth_date: ?string,
     *     phone: ?string,
     *     address: ?string,
     *     observation: ?string,
     *     is_active: bool
     * }
     */
    private function attributes(StudentRequest $request): array
    {
        return [
            'dni' => $request->string('dni')->toString(),
            'first_name' => $request->string('first_name')->toString(),
            'last_name' => $request->string('last_name')->toString(),
            'birth_date' => $request->input('birth_date'),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'observation' => $request->input('observation'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
