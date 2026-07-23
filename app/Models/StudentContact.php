<?php

namespace App\Models;

use Database\Factories\StudentContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['student_code', 'position', 'name', 'phone', 'note'])]
class StudentContact extends Model
{
    /** @use HasFactory<StudentContactFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'code';

    public $timestamps = false;

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
