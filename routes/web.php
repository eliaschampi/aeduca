<?php

use App\Http\Controllers\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->delete('/logout', [AuthController::class, 'destroy'])
    ->name('logout');

Route::middleware(['auth', 'employee.active'])->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::put('/current-branch', [BranchController::class, 'update'])
        ->name('current-branch.update');

    Route::prefix('admin')->name('admin.')->group(function () {
        // Branch catalog lives on /branches; these routes only write membership.
        Route::post('/branches', [AdminBranchController::class, 'store'])
            ->name('branches.store');
        Route::put('/branches/{branch}', [AdminBranchController::class, 'update'])
            ->name('branches.update');

        Route::get('/employees', [AdminEmployeeController::class, 'index'])
            ->name('employees.index');
        Route::get('/employees/create', [AdminEmployeeController::class, 'create'])
            ->name('employees.create');
        Route::post('/employees', [AdminEmployeeController::class, 'store'])
            ->name('employees.store');
        Route::get('/employees/{employee}', [AdminEmployeeController::class, 'show'])
            ->name('employees.show');
        Route::put('/employees/{employee}', [AdminEmployeeController::class, 'update'])
            ->name('employees.update');
        Route::put('/employees/{employee}/password', [AdminEmployeeController::class, 'changePassword'])
            ->name('employees.password');
        Route::put('/employees/{employee}/access', [AdminEmployeeController::class, 'toggleAccess'])
            ->name('employees.access');
        Route::put('/employees/{employee}/permission-overrides', [AdminEmployeeController::class, 'syncPermissionOverrides'])
            ->name('employees.permission-overrides');

        Route::get('/roles', [AdminRoleController::class, 'index'])
            ->name('roles.index');
        Route::get('/roles/create', [AdminRoleController::class, 'create'])
            ->name('roles.create');
        Route::post('/roles', [AdminRoleController::class, 'store'])
            ->name('roles.store');
        Route::get('/roles/{role}', [AdminRoleController::class, 'show'])
            ->name('roles.show');
        Route::put('/roles/{role}', [AdminRoleController::class, 'update'])
            ->name('roles.update');
    });
});
