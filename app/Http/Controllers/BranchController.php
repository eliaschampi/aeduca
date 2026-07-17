<?php

namespace App\Http\Controllers;

use App\Actions\SelectBranch;
use App\Http\Requests\SelectBranchRequest;
use App\Models\AuthAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Branches/Index');
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
