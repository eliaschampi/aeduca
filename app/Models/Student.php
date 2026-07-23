<?php

namespace App\Models;

use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'dni',
    'first_name',
    'last_name',
    'birth_date',
    'phone',
    'address',
    'observation',
])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'code';

    public function contacts(): HasMany
    {
        return $this->hasMany(StudentContact::class, 'student_code', 'code')
            ->orderBy('position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }
}
