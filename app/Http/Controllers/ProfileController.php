<?php

namespace App\Http\Controllers;

use App\Models\AuthAccount;
use App\Models\Branch;
use App\Support\PrivateProfilePhoto;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var AuthAccount $account */
        $account = $request->user();
        $employee = $account->user;
        abort_unless($employee, 403);

        // Do not partially select authAccount/employeeRole columns: the authenticated
        // graph is the same in-memory models in tests and would drop is_active checks.
        $employee->loadMissing(['employeeRole', 'branches']);

        return Inertia::render('Profile/Show', [
            'employee' => [
                'code' => $employee->code,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'role_name' => $employee->employeeRole?->name,
                'login' => $account->login,
                'last_login_at' => $account->last_login_at?->toIso8601String(),
                'branches' => $employee->branches
                    ->map(fn (Branch $branch): array => [
                        'code' => $branch->code,
                        'name' => $branch->name,
                    ])
                    ->values()
                    ->all(),
                'photo_url' => PrivateProfilePhoto::versionedUrl(
                    $employee->photo_path,
                    'admin.employees.photo',
                    ['employee' => $employee],
                ),
            ],
        ]);
    }
}
