<?php

use App\Http\Controllers\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Admin\CycleController as AdminCycleController;
use App\Http\Controllers\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\DriveController;
use App\Http\Controllers\DriveShareController;
use App\Http\Controllers\EmployeeAttendanceController;
use App\Http\Controllers\EmployeeAttendanceHistoryController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StudentAccessController;
use App\Http\Controllers\StudentAttendanceHistoryController;
use App\Http\Controllers\StudentAttentionAttachmentController;
use App\Http\Controllers\StudentAttentionController;
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
    Route::get('/students/{student}/attendance', StudentAttendanceHistoryController::class)
        ->whereUuid('student')
        ->name('students.attendance');
    Route::get('/students/{student}', [StudentController::class, 'show'])
        ->whereUuid('student')
        ->name('students.show');
});

Route::middleware(['auth', 'account.active', 'employee.actor'])->group(function () {
    Route::get('/', HomeController::class)
        ->middleware('can:dashboard.view')
        ->name('home');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::put('/current-branch', [BranchController::class, 'update'])
        ->name('current-branch.update');

    // Drive is a private space per employee: one permission gates the whole
    // module here, and ownership decides everything inside it.
    Route::prefix('drive')->name('drive.')->middleware('can:drive.manage')->group(function () {
        Route::get('/', [DriveController::class, 'index'])->name('index');
        Route::get('/files', [DriveController::class, 'files'])->name('files');
        Route::post('/files', [DriveController::class, 'store'])->name('files.store');
        Route::get('/folders', [DriveController::class, 'folders'])->name('folders');
        Route::post('/folders', [DriveController::class, 'storeFolder'])->name('folders.store');
        Route::delete('/trash', [DriveController::class, 'emptyTrash'])->name('trash.destroy');

        Route::prefix('files/{file}')->whereUuid('file')->group(function () {
            Route::get('/serve', [DriveController::class, 'serve'])->name('files.serve');
            Route::patch('/', [DriveController::class, 'update'])->name('files.update');
            Route::delete('/', [DriveController::class, 'destroy'])->name('files.destroy');

            Route::get('/shares', [DriveShareController::class, 'index'])->name('shares.index');
            Route::post('/shares', [DriveShareController::class, 'store'])->name('shares.store');
            Route::delete('/shares/{share}', [DriveShareController::class, 'destroy'])
                ->whereUuid('share')
                ->name('shares.destroy');
        });
    });

    Route::get('/attendance', [AttendanceController::class, 'index'])
        ->middleware('can:attendance.view')
        ->name('attendance.index');
    Route::get('/attendance/scan', [AttendanceController::class, 'scan'])
        ->middleware('can:attendance.manage')
        ->name('attendance.scan');
    Route::post('/attendance/scan', [AttendanceController::class, 'storeScan'])
        ->middleware('can:attendance.manage')
        ->name('attendance.scan.store');
    Route::post('/attendance/manual', [AttendanceController::class, 'storeManual'])
        ->middleware('can:attendance.manage')
        ->name('attendance.manual.store');

    Route::prefix('employee-attendance')->name('employee-attendance.')->group(function () {
        Route::get('/', [EmployeeAttendanceController::class, 'index'])
            ->middleware('can:employee_attendance.view')
            ->name('index');
        Route::get('/register', [EmployeeAttendanceController::class, 'register'])
            ->middleware('can:employee_attendance.manage')
            ->name('register');
        Route::post('/register', [EmployeeAttendanceController::class, 'storeScan'])
            ->middleware('can:employee_attendance.manage')
            ->name('register.store');
        Route::post('/manual', [EmployeeAttendanceController::class, 'storeManual'])
            ->middleware('can:employee_attendance.manage')
            ->name('manual.store');
        Route::get('/employees/{employee}/history', EmployeeAttendanceHistoryController::class)
            ->whereUuid('employee')
            ->middleware('can:employee_attendance.view')
            ->name('history');
    });

    Route::get('/students/search', [StudentController::class, 'search'])
        ->middleware('can:students.view')
        ->name('students.search');
    Route::get('/students/lookup', [StudentController::class, 'lookup'])
        ->middleware('can:students.view')
        ->name('students.lookup');
    Route::prefix('student-attentions')->name('student-attentions.')->group(function () {
        Route::get('/', [StudentAttentionController::class, 'index'])
            ->middleware('can:attentions.view')
            ->name('index');
        Route::get('/students', [StudentAttentionController::class, 'students'])
            ->middleware('can:attentions.manage')
            ->name('students');
        Route::get('/create', [StudentAttentionController::class, 'create'])
            ->middleware('can:attentions.manage')
            ->name('create');
        Route::post('/', [StudentAttentionController::class, 'store'])
            ->middleware('can:attentions.manage')
            ->name('store');
        Route::post('/attachment', [StudentAttentionAttachmentController::class, 'store'])
            ->middleware(['can:attentions.manage', 'can:drive.manage'])
            ->name('attachment.store');

        Route::prefix('{attention}')->whereUuid('attention')->group(function () {
            Route::get('/edit', [StudentAttentionController::class, 'edit'])
                ->middleware('can:attentions.manage')
                ->name('edit');
            Route::get('/certificate', [StudentAttentionController::class, 'certificate'])
                ->middleware('can:attentions.view')
                ->name('certificate');
            Route::get('/attachment', [StudentAttentionAttachmentController::class, 'show'])
                ->middleware('can:attentions.view')
                ->name('attachment.show');
            Route::put('/', [StudentAttentionController::class, 'update'])
                ->middleware('can:attentions.manage')
                ->name('update');
            Route::delete('/', [StudentAttentionController::class, 'destroy'])
                ->middleware('can:attentions.manage')
                ->name('destroy');
        });
    });
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
    Route::delete('/students/{student}', [StudentController::class, 'destroy'])
        ->middleware('can:students.delete')
        ->name('students.destroy');
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
    Route::delete('/enrollments/{enrollment}', [EnrollmentController::class, 'destroy'])
        ->middleware('can:enrollments.delete')
        ->name('enrollments.destroy');
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
            ->name('employees.show');
        Route::get('/employees/{employee}/photo', [AdminEmployeeController::class, 'photo'])
            ->whereUuid('employee')
            ->name('employees.photo');
        Route::put('/employees/{employee}/photo', [AdminEmployeeController::class, 'updatePhoto'])
            ->whereUuid('employee')
            ->name('employees.photo.update');
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
        Route::post('/employees/{employee}/schedules', [AdminEmployeeController::class, 'storeSchedule'])
            ->whereUuid('employee')
            ->middleware('can:employee_attendance.manage')
            ->name('employees.schedules.store');
        Route::delete('/employees/{employee}/schedules/{schedule}', [AdminEmployeeController::class, 'destroySchedule'])
            ->whereUuid(['employee', 'schedule'])
            ->middleware('can:employee_attendance.manage')
            ->name('employees.schedules.destroy');

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
