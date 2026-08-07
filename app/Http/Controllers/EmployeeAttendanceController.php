<?php

namespace App\Http\Controllers;

use App\Actions\SaveEmployeeAttendance;
use App\Http\Requests\EmployeeAttendanceManualRequest;
use App\Http\Requests\EmployeeAttendanceScanRequest;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Support\Branches\BranchContext;
use App\Support\EmployeeAttendance\EmployeeAttendanceQueries;
use App\Support\PrivateProfilePhoto;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class EmployeeAttendanceController extends Controller
{
    public function __construct(
        private readonly EmployeeAttendanceQueries $queries,
    ) {}

    public function index(Request $request, BranchContext $branchContext): Response|RedirectResponse
    {
        $branch = $this->currentBranch($request, $branchContext);
        if (! $branch) {
            return to_route('branches.index');
        }

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $now = $this->now();
        $date = (string) ($validated['date'] ?? $now->toDateString());
        $canViewPhotos = Gate::check('employees.view');
        $rows = collect($this->queries->daily($branch->code, $date, $now))
            ->map(function (object $row) use ($canViewPhotos): array {
                $photoUrl = $canViewPhotos
                    ? PrivateProfilePhoto::versionedUrl(
                        is_string($row->photo_path) ? $row->photo_path : null,
                        'admin.employees.photo',
                        ['employee' => $row->user_code],
                    )
                    : null;

                return $this->queries->mapDailyRow($row, $photoUrl);
            })
            ->all();

        return Inertia::render('EmployeeAttendance/Index', [
            'branch' => ['code' => $branch->code, 'name' => $branch->name],
            'filters' => ['date' => $date],
            'today' => $now->toDateString(),
            'rows' => $rows,
            'business_timezone' => $this->timezone(),
            'can_manage' => Gate::check('employee_attendance.manage'),
            'can_view_profiles' => Gate::check('employees.view'),
        ]);
    }

    public function register(Request $request, BranchContext $branchContext): Response|RedirectResponse
    {
        $branch = $this->currentBranch($request, $branchContext);
        if (! $branch) {
            return to_route('branches.index');
        }

        $now = $this->now();

        return Inertia::render('EmployeeAttendance/Register', [
            'branch' => ['code' => $branch->code, 'name' => $branch->name],
            'business_date' => $now->toDateString(),
            'business_timezone' => $this->timezone(),
        ]);
    }

    public function storeScan(
        EmployeeAttendanceScanRequest $request,
        BranchContext $branchContext,
        SaveEmployeeAttendance $save,
    ): JsonResponse {
        $branch = $this->currentBranch($request, $branchContext);
        abort_unless($branch, 403);

        /** @var AuthAccount $account */
        $account = $request->user();
        $actor = $account->user;
        abort_unless($actor, 403);

        try {
            $result = $save->scan($branch, $actor, $request->string('dni')->toString());
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first()
                    ?? 'No se pudo registrar la lectura.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $photoPath = $result['employee']['photo_path'] ?? null;
        unset($result['employee']['photo_path']);
        // Scan operators need the face on the result card; manage is enough without full employees.view.
        $result['employee']['photo_url'] = (
            Gate::check('employees.view') || Gate::check('employee_attendance.manage')
        )
            ? PrivateProfilePhoto::versionedUrl(
                is_string($photoPath) ? $photoPath : null,
                'admin.employees.photo',
                ['employee' => $result['employee']['code']],
            )
            : null;

        return response()->json(['result' => $result]);
    }

    public function storeManual(
        EmployeeAttendanceManualRequest $request,
        BranchContext $branchContext,
        SaveEmployeeAttendance $save,
    ): RedirectResponse {
        $branch = $this->currentBranch($request, $branchContext);
        abort_unless($branch, 403);

        /** @var AuthAccount $account */
        $account = $request->user();
        $actor = $account->user;
        abort_unless($actor, 403);

        $operation = $request->string('operation')->toString();
        $save->manual($branch, $actor, $request->validated());

        Inertia::flash('success', match ($operation) {
            'delete' => 'Registro eliminado',
            'update' => 'Asistencia actualizada',
            default => 'Asistencia registrada',
        });

        return back();
    }

    private function currentBranch(Request $request, BranchContext $branchContext): ?Branch
    {
        /** @var AuthAccount $account */
        $account = $request->user();

        return $branchContext->currentBranch($account);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function timezone(): string
    {
        return (string) config('aeduca.business_timezone', 'America/Lima');
    }
}
