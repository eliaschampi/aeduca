<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_code',
    'branch_code',
    'weekday',
    'entry_time',
    'to_time',
    'created_by_user_code',
])]
final class EmployeeSchedule extends Model
{
    use HasUuids;

    protected $primaryKey = 'code';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_code', 'code');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_code', 'code');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class, 'schedule_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }
}
