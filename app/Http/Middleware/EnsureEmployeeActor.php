<?php

namespace App\Http\Middleware;

use App\Models\AuthAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeActor
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        abort_unless($account instanceof AuthAccount && $account->user_code !== null, 403);

        return $next($request);
    }
}
