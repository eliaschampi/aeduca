<?php

namespace App\Http\Controllers;

use App\Actions\SelectBranch;
use App\Http\Requests\SelectBranchRequest;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        $canViewCatalog = Gate::check('branches.view');
        $canManage = Gate::check('branches.manage');

        $catalog = [];
        $employees = [];

        if ($canViewCatalog) {
            $catalog = Branch::query()
                ->with(['users' => fn ($query) => $query
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->select(['users.code', 'users.first_name', 'users.last_name'])])
                ->orderBy('name')
                ->get(['code', 'name', 'is_active'])
                ->map(fn (Branch $branch): array => [
                    'code' => $branch->code,
                    'name' => $branch->name,
                    'is_active' => $branch->is_active,
                    'members' => $branch->users
                        ->map(fn (User $user): array => [
                            'code' => $user->code,
                            'name' => trim("{$user->first_name} {$user->last_name}"),
                        ])
                        ->values()
                        ->all(),
                ])
                ->all();
        }

        if ($canManage) {
            $employees = User::query()
                ->where('is_active', true)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['code', 'first_name', 'last_name'])
                ->map(fn (User $user): array => [
                    'code' => $user->code,
                    'name' => trim("{$user->first_name} {$user->last_name}"),
                ])
                ->all();
        }

        return Inertia::render('Branches/Index', [
            'catalog' => $catalog,
            'employees' => $employees,
            'can_view_catalog' => $canViewCatalog,
            'can_manage' => $canManage,
        ]);
    }

    public function update(
        SelectBranchRequest $request,
        SelectBranch $selectBranch,
    ): RedirectResponse {
        /** @var AuthAccount $account */
        $account = $request->user();

        $selectBranch->handle(
            $account,
            $request->string('branch_code')->toString(),
            $request->session(),
        );

        return to_route('branches.index');
    }
}
