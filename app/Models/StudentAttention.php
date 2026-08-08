<?php

namespace App\Models;

use App\Support\StudentAttentions\StudentAttentionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'type',
    'reason',
    'development',
    'conclusion',
    'occurred_at',
])]
class StudentAttention extends Model
{
    use HasUuids;

    protected $primaryKey = 'code';

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_code', 'code');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_code', 'code');
    }

    public function driveFile(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class, 'drive_file_code', 'code');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_code', 'code');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'type' => StudentAttentionType::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
