<?php

namespace App\Models;

use Database\Factories\CycleDegreeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One fixed grade number offered inside a specific cycle.
 */
#[Fillable(['cycle_code', 'number'])]
class CycleDegree extends Model
{
    /** @use HasFactory<CycleDegreeFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'code';

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AcademicCycle::class, 'cycle_code', 'code');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(AcademicGroup::class, 'cycle_degree_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
        ];
    }
}
