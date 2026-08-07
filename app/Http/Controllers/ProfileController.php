<?php

namespace App\Http\Controllers;

use App\Models\AuthAccount;
use App\Support\Branches\BranchContext;
use App\Support\Employees\LoadEmployeeProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Self profile — same page and payload shape as admin employee profile. */
class ProfileController extends Controller
{
    public function show(
        Request $request,
        BranchContext $branchContext,
        LoadEmployeeProfile $loadProfile,
    ): Response {
        /** @var AuthAccount $account */
        $account = $request->user();
        $employee = $account->user;
        abort_unless($employee, 403);

        return Inertia::render(
            'Employees/Profile',
            $loadProfile->handle(
                $request,
                $employee,
                $account,
                $branchContext,
                isSelf: true,
            ),
        );
    }
}
