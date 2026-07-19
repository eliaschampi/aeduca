<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BranchController extends Controller
{
    public function store(BranchRequest $request, SaveBranch $saveBranch): RedirectResponse
    {
        $saveBranch->handle(null, [
            'name' => $request->string('name')->toString(),
            'is_active' => $request->boolean('is_active'),
        ]);

        Inertia::flash('success', 'Sede creada');

        return to_route('branches.index');
    }

    public function update(BranchRequest $request, Branch $branch, SaveBranch $saveBranch): RedirectResponse
    {
        $saveBranch->handle($branch, [
            'name' => $request->string('name')->toString(),
            'is_active' => $request->boolean('is_active'),
        ]);

        Inertia::flash('success', 'Sede actualizada');

        return to_route('branches.index');
    }
}
