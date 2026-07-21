<?php

namespace App\Models;

use App\Support\Academic\AcademicLevel;
use App\Support\Academic\CycleModality;
use Database\Factories\AcademicCycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Aggregate owner of cycle degrees (→ academic groups) and cycle shifts.
 */
#[Fillable(['branch_code', 'name', 'level', 'modality', 'start_date', 'end_date', 'is_active'])]
class AcademicCycle extends Model
{
    /** @use HasFactory<AcademicCycleFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'code';

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_code', 'code');
    }

    public function degrees(): HasMany
    {
        return $this->hasMany(CycleDegree::class, 'cycle_code', 'code');
    }

    public function groups(): HasManyThrough
    {
        return $this->hasManyThrough(
            AcademicGroup::class,
            CycleDegree::class,
            'cycle_code',
            'cycle_degree_code',
            'code',
            'code',
        );
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(CycleShift::class, 'cycle_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'level' => AcademicLevel::class,
            'modality' => CycleModality::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
