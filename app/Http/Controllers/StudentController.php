<?php

namespace App\Http\Controllers;

use App\Actions\SaveStudent;
use App\Http\Requests\StudentRequest;
use App\Models\AuthAccount;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Branches\BranchContext;
use App\Support\Business\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));
        $students = Student::query()
            ->select([
                'code',
                'dni',
                'first_name',
                'last_name',
                'phone',
                'address',
                'created_at',
            ]);

        if ($search === '') {
            $students
                ->orderByDesc('created_at')
                ->orderByDesc('code');
        } else {
            $pattern = '%'.$search.'%';

            $students
                ->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw('dni ILIKE ?', [$pattern])
                        ->orWhereRaw('first_name ILIKE ?', [$pattern])
                        ->orWhereRaw('last_name ILIKE ?', [$pattern])
                        ->orWhereRaw("concat_ws(' ', first_name, last_name) ILIKE ?", [$pattern]);
                })
                ->orderByRaw('CASE WHEN dni = ? THEN 0 ELSE 1 END', [$search])
                ->orderByRaw('lower(last_name)')
                ->orderByRaw('lower(first_name)')
                ->orderBy('code');
        }

        $paginator = $students
            ->paginate(10)
            ->appends($search === '' ? [] : ['q' => $search])
            ->through(fn (Student $student): array => [
                'code' => $student->code,
                'dni' => $student->dni,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'phone' => $student->phone,
                'address' => $student->address,
                'created_at' => $student->created_at->toIso8601String(),
            ]);

        return Inertia::render('Students/Index', [
            'students' => $paginator,
            'q' => $search,
            'can_manage' => Gate::check('students.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Students/Create');
    }

    public function store(StudentRequest $request, SaveStudent $saveStudent): RedirectResponse
    {
        $student = $saveStudent->handle(
            null,
            $this->studentAttributes($request),
        );

        Inertia::flash('success', 'Estudiante creado');

        return to_route('students.show', $student);
    }

    public function show(
        Request $request,
        BranchContext $context,
        Student $student,
    ): Response {
        $student->load('contacts');
        $canViewEnrollments = Gate::check('enrollments.view');
        $canManageEnrollments = Gate::check('enrollments.manage');
        $authorizedBranchCodes = [];

        if ($canManageEnrollments) {
            /** @var AuthAccount $account */
            $account = $request->user();
            $authorizedBranchCodes = $context->authorizedBranches($account)->pluck('code')->all();
        }

        $today = BusinessCalendar::today();
        $enrollments = $canViewEnrollments
            ? $student->enrollments()
                ->with([
                    'academicGroup.cycleDegree.cycle.branch',
                    'shifts' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'),
                ])
                ->withCount('obligations')
                ->withSum('obligations', 'amount')
                ->orderByDesc('is_active')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Enrollment $enrollment): array => $this->enrollmentData(
                    $enrollment,
                    $authorizedBranchCodes,
                    $today,
                ))
                ->all()
            : [];

        return Inertia::render('Students/Show', [
            'student' => [
                ...$this->studentData($student),
                'contacts' => $student->contacts
                    ->map(fn ($contact): array => [
                        'code' => $contact->code,
                        'name' => $contact->name,
                        'phone' => $contact->phone,
                        'note' => $contact->note,
                    ])
                    ->values()
                    ->all(),
                'enrollments' => $enrollments,
            ],
            'can_manage' => Gate::check('students.manage'),
            'can_view_enrollments' => $canViewEnrollments,
            'can_manage_enrollments' => $canManageEnrollments,
        ]);
    }

    public function edit(Student $student): Response
    {
        return Inertia::render('Students/Edit', [
            'student' => $this->studentData($student),
        ]);
    }

    public function update(
        StudentRequest $request,
        Student $student,
        SaveStudent $saveStudent,
    ): RedirectResponse {
        $student = $saveStudent->handle(
            $student,
            $this->studentAttributes($request),
        );

        Inertia::flash('success', 'Estudiante actualizado');

        return to_route('students.show', $student);
    }

    /**
     * @return array{dni: string, first_name: string, last_name: string, birth_date: ?string, phone: ?string, address: ?string, observation: ?string}
     */
    private function studentAttributes(StudentRequest $request): array
    {
        return [
            'dni' => $request->string('dni')->toString(),
            'first_name' => $request->string('first_name')->toString(),
            'last_name' => $request->string('last_name')->toString(),
            'birth_date' => $request->input('birth_date'),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'observation' => $request->input('observation'),
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
        ];
    }

    /**
     * @param  list<string>  $authorizedBranchCodes
     * @return array<string, mixed>
     */
    private function enrollmentData(
        Enrollment $enrollment,
        array $authorizedBranchCodes,
        CarbonImmutable $today,
    ): array {
        $group = $enrollment->academicGroup;
        $degree = $group->cycleDegree;
        $cycle = $degree->cycle;
        $isCompleted = $cycle->end_date->lt($today);

        return [
            'code' => $enrollment->code,
            'roll_code' => $enrollment->roll_code,
            'cycle_name' => $cycle->name,
            'branch_name' => $cycle->branch->name,
            'assignment' => "{$cycle->level->gradeLabel($degree->number)} · Sección {$group->name}",
            'shift_names' => $enrollment->shifts->pluck('name')->all(),
            'obligations_count' => $enrollment->obligations_count,
            'obligations_total' => number_format(
                (float) $enrollment->obligations_sum_amount,
                2,
                '.',
                '',
            ),
            'status' => $enrollment->is_active
                ? 'active'
                : ($isCompleted ? 'completed' : 'inactive'),
            'status_label' => $enrollment->is_active
                ? 'Activa'
                : ($isCompleted ? 'Finalizada' : 'Inactiva'),
            'created_at' => $enrollment->created_at->toIso8601String(),
            'can_edit' => in_array($cycle->branch_code, $authorizedBranchCodes, true),
        ];
    }
}
