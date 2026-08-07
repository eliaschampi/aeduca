<?php

namespace App\Models;

use App\Support\StudentAttentions\StudentAttentionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_code', 'code');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_code', 'code');
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(
            DriveFile::class,
            'student_attention_files',
            'student_attention_code',
            'drive_file_code',
            'code',
            'code',
        )->withPivot('created_at');
    }

    protected function casts(): array
    {
        return [
            'type' => StudentAttentionType::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
