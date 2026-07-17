<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class BranchController extends Controller
{
    public function store(BranchRequest $request, SaveBranch $saveBranch): RedirectResponse
    {
        Gate::authorize('branches.manage');

        $saveBranch->handle(
            null,
            [
                'name' => $request->string('name')->toString(),
                'is_active' => $request->boolean('is_active'),
            ],
            $request->collect('user_codes')->all(),
        );

        return to_route('branches.index');
    }

    public function update(BranchRequest $request, Branch $branch, SaveBranch $saveBranch): RedirectResponse
    {
        Gate::authorize('branches.manage');

        $saveBranch->handle(
            $branch,
            [
                'name' => $request->string('name')->toString(),
                'is_active' => $request->boolean('is_active'),
            ],
            $request->collect('user_codes')->all(),
        );

        return to_route('branches.index');
    }
}
