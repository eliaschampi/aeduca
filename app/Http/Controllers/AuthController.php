<?php

namespace App\Http\Controllers;

use App\Actions\AuthenticateEmployee;
use App\Actions\LogoutEmployee;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(
        LoginRequest $request,
        AuthenticateEmployee $authenticateEmployee,
    ): RedirectResponse {
        $authenticateEmployee->handle(
            $request,
            $request->string('login')->toString(),
            $request->string('password')->toString(),
        );

        return to_route('branches.index');
    }

    public function destroy(Request $request, LogoutEmployee $logoutEmployee): RedirectResponse
    {
        $logoutEmployee->handle($request);

        return to_route('login');
    }
}
