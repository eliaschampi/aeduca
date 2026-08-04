<?php

namespace App\Http\Middleware;

use App\Models\AuthAccount;
use App\Support\Authorization\PermissionResolver;
use App\Support\Branches\BranchContext;
use App\Support\PrivateProfilePhoto;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly PermissionResolver $permissionResolver,
    ) {}

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn () => $this->authenticationData($request),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function authenticationData(Request $request): ?array
    {
        $account = $request->user();

        if (! $account instanceof AuthAccount) {
            return null;
        }

        $account->loadMissing(['user.employeeRole', 'student']);

        if ($account->student) {
            return [
                'actor' => 'student',
                'student' => [
                    'code' => $account->student->code,
                    'first_name' => $account->student->first_name,
                    'last_name' => $account->student->last_name,
                ],
                'branches' => [],
                'current_branch' => null,
                'permissions' => [],
            ];
        }

        $employee = $account->user;
        if (! $employee) {
            return null;
        }
        $branches = $this->branchContext->authorizedBranches($account);
        $currentBranch = $this->branchContext->currentBranch($account);

        return [
            'actor' => 'employee',
            'employee' => [
                'code' => $employee->code,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'role_name' => $employee->employeeRole->name,
                'photo_url' => PrivateProfilePhoto::versionedUrl(
                    $employee->photo_path,
                    'admin.employees.photo',
                    ['employee' => $employee],
                ),
            ],
            'branches' => $branches
                ->map(fn ($branch) => [
                    'code' => $branch->code,
                    'name' => $branch->name,
                ])
                ->values()
                ->all(),
            'current_branch' => $currentBranch
                ? [
                    'code' => $currentBranch->code,
                    'name' => $currentBranch->name,
                ]
                : null,
            'permissions' => $this->permissionResolver->effectiveNames($account),
        ];
    }
}
