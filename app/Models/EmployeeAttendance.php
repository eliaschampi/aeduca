<?php

namespace App\Models;

use App\Support\EmployeeAttendance\EmployeeAttendanceMethod;
use App\Support\EmployeeAttendance\EmployeeAttendanceState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_code',
    'branch_code',
    'schedule_code',
    'attendance_date',
    'state',
    'entry_time',
    'observation',
    'recording_method',
    'created_by_user_code',
    'updated_by_user_code',
])]
final class EmployeeAttendance extends Model
{
    use HasUuids;

    protected $primaryKey = 'code';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_code', 'code');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(EmployeeSchedule::class, 'schedule_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date',
            'state' => EmployeeAttendanceState::class,
            'recording_method' => EmployeeAttendanceMethod::class,
        ];
    }
}
