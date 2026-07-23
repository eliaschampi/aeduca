<?php

namespace App\Models;

use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'student_code',
    'academic_group_code',
    'roll_code',
    'is_active',
    'observation',
])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'code';

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_code', 'code');
    }

    public function academicGroup(): BelongsTo
    {
        return $this->belongsTo(AcademicGroup::class, 'academic_group_code', 'code');
    }

    public function shifts(): BelongsToMany
    {
        return $this->belongsToMany(
            CycleShift::class,
            'enrollment_shifts',
            'enrollment_code',
            'cycle_shift_code',
        );
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(PaymentObligation::class, 'enrollment_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
