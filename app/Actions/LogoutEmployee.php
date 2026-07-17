<?php

namespace App\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LogoutEmployee
{
    public function handle(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
