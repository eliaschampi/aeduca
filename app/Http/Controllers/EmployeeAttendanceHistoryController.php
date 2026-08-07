<?php

namespace App\Http\Controllers;

use App\Models\AuthAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Deep link into the unified employee profile attendance tab.
 * Avoids a second history surface (Coedula: profile owns attendance + schedules).
 */
final class EmployeeAttendanceHistoryController extends Controller
{
    public function __invoke(Request $request, User $employee): RedirectResponse
    {
        /** @var AuthAccount $account */
        $account = $request->user();
        $actor = $account->user;
        abort_unless($actor, 403);

        $query = array_filter([
            'tab' => 'attendance',
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if ($actor->code === $employee->code) {
            return redirect()->route('profile.show', $query);
        }

        return redirect()->route('admin.employees.show', [
            'employee' => $employee,
            ...$query,
        ]);
    }
}
