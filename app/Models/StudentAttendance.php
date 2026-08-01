<?php

namespace App\Models;

use App\Support\Attendance\AttendanceMethod;
use App\Support\Attendance\AttendanceState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enrollment_code',
    'cycle_shift_code',
    'attendance_date',
    'state',
    'arrival_at',
    'recording_method',
    'created_by_user_code',
    'reason',
    'corrected_by_user_code',
    'corrected_at',
])]
class StudentAttendance extends Model
{
    use HasUuids;

    protected $primaryKey = 'code';

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_code', 'code');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CycleShift::class, 'cycle_shift_code', 'code');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_code', 'code');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_user_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date',
            'arrival_at' => 'immutable_datetime',
            'corrected_at' => 'immutable_datetime',
            'state' => AttendanceState::class,
            'recording_method' => AttendanceMethod::class,
        ];
    }
}
