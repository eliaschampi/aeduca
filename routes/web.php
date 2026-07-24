<?php

use App\Http\Controllers\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Admin\CycleController as AdminCycleController;
use App\Http\Controllers\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\StudentAccessController;
use App\Http\Controllers\StudentContactController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->delete('/logout', [AuthController::class, 'destroy'])
    ->name('logout');

Route::middleware(['auth', 'account.active'])->group(function () {
    Route::get('/students/{student}/photo', [StudentController::class, 'photo'])
        ->whereUuid('student')
        ->name('students.photo');
    Route::get('/students/{student}', [StudentController::class, 'show'])
        ->whereUuid('student')
        ->name('students.show');
});

Route::middleware(['auth', 'account.active', 'employee.actor'])->group(function () {
    Route::get('/', HomeController::class)
        ->middleware('can:dashboard.view')
        ->name('home');
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::put('/current-branch', [BranchController::class, 'update'])
        ->name('current-branch.update');

    Route::get('/students/search', [StudentController::class, 'search'])
        ->middleware('can:students.view')
        ->name('students.search');
    Route::get('/students/lookup', [StudentController::class, 'lookup'])
        ->middleware('can:students.view')
        ->name('students.lookup');
    Route::get('/students/create', [StudentController::class, 'create'])
        ->middleware('can:students.manage')
        ->name('students.create');
    Route::post('/students', [StudentController::class, 'store'])
        ->middleware('can:students.manage')
        ->name('students.store');
    Route::get('/students/{student}/edit', [StudentController::class, 'edit'])
        ->middleware('can:students.manage')
        ->name('students.edit');
    Route::put('/students/{student}', [StudentController::class, 'update'])
        ->middleware('can:students.manage')
        ->name('students.update');
    Route::put('/students/{student}/photo', [StudentController::class, 'updatePhoto'])
        ->middleware('can:students.manage')
        ->name('students.photo.update');
    Route::post('/students/{student}/contacts', [StudentContactController::class, 'store'])
        ->middleware('can:students.manage')
        ->name('students.contacts.store');
    Route::put('/students/{student}/contacts/{contact}', [StudentContactController::class, 'update'])
        ->middleware('can:students.manage')
        ->name('students.contacts.update');
    Route::delete('/students/{student}/contacts/{contact}', [StudentContactController::class, 'destroy'])
        ->middleware('can:students.manage')
        ->name('students.contacts.destroy');
    Route::post('/students/{student}/access', [StudentAccessController::class, 'update'])
        ->middleware('can:students.manage')
        ->name('students.access.update');

    Route::get('/students', [EnrollmentController::class, 'index'])
        ->middleware('can:enrollments.view')
        ->name('enrollments.index');
    Route::get('/students/{student}/enrollments/create', [EnrollmentController::class, 'create'])
        ->middleware('can:enrollments.manage')
        ->name('enrollments.create');
    Route::post('/students/{student}/enrollments', [EnrollmentController::class, 'store'])
        ->middleware('can:enrollments.manage')
        ->name('enrollments.store');
    Route::get('/enrollments/{enrollment}/edit', [EnrollmentController::class, 'edit'])
        ->middleware('can:enrollments.manage')
        ->name('enrollments.edit');
    Route::put('/enrollments/{enrollment}', [EnrollmentController::class, 'update'])
        ->middleware('can:enrollments.manage')
        ->name('enrollments.update');
    Route::patch('/enrollments/{enrollment}/state', [EnrollmentController::class, 'updateState'])
        ->middleware('can:enrollments.manage')
        ->name('enrollments.state');
    Route::prefix('admin')->name('admin.')->group(function () {
        // Branch catalog lives on /branches; these routes only write catalog attributes.
        Route::post('/branches', [AdminBranchController::class, 'store'])
            ->middleware('can:branches.manage')
            ->name('branches.store');
        Route::put('/branches/{branch}', [AdminBranchController::class, 'update'])
            ->middleware('can:branches.manage')
            ->name('branches.update');

        Route::get('/employees', [AdminEmployeeController::class, 'index'])
            ->middleware('can:employees.view')
            ->name('employees.index');
        Route::get('/employees/create', [AdminEmployeeController::class, 'create'])
            ->middleware('can:employees.manage')
            ->name('employees.create');
        Route::post('/employees', [AdminEmployeeController::class, 'store'])
            ->middleware('can:employees.manage')
            ->name('employees.store');
        Route::get('/employees/{employee}', [AdminEmployeeController::class, 'show'])
            ->middleware('can:employees.view')
            ->name('employees.show');
        Route::put('/employees/{employee}', [AdminEmployeeController::class, 'update'])
            ->middleware('can:employees.manage')
            ->name('employees.update');
        Route::put('/employees/{employee}/password', [AdminEmployeeController::class, 'changePassword'])
            ->middleware('can:employees.manage')
            ->name('employees.password');
        Route::put('/employees/{employee}/access', [AdminEmployeeController::class, 'updateAccess'])
            ->middleware('can:employees.manage')
            ->name('employees.access');
        Route::put('/employees/{employee}/permissions', [AdminEmployeeController::class, 'syncPermissions'])
            ->middleware('can:employees.manage')
            ->name('employees.permissions');

        Route::get('/roles', [AdminRoleController::class, 'index'])
            ->middleware('can:roles.view')
            ->name('roles.index');
        Route::get('/roles/create', [AdminRoleController::class, 'create'])
            ->middleware('can:roles.manage')
            ->name('roles.create');
        Route::post('/roles', [AdminRoleController::class, 'store'])
            ->middleware('can:roles.manage')
            ->name('roles.store');
        Route::get('/roles/{role}', [AdminRoleController::class, 'show'])
            ->middleware('can:roles.view')
            ->name('roles.show');
        Route::put('/roles/{role}', [AdminRoleController::class, 'update'])
            ->middleware('can:roles.manage')
            ->name('roles.update');

        Route::get('/cycles', [AdminCycleController::class, 'index'])
            ->middleware('can:cycles.view')
            ->name('cycles.index');
        Route::get('/cycles/create', [AdminCycleController::class, 'create'])
            ->middleware('can:cycles.manage')
            ->name('cycles.create');
        Route::post('/cycles', [AdminCycleController::class, 'store'])
            ->middleware('can:cycles.manage')
            ->name('cycles.store');
        Route::get('/cycles/{cycle}', [AdminCycleController::class, 'show'])
            ->middleware('can:cycles.view')
            ->name('cycles.show');
        Route::put('/cycles/{cycle}', [AdminCycleController::class, 'update'])
            ->middleware('can:cycles.manage')
            ->name('cycles.update');
    });
});
