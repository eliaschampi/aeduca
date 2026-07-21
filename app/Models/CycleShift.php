<?php

namespace App\Models;

use Database\Factories\CycleShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entry-time and tolerance configuration enabled by a cycle.
 * A cycle owns one or two active shifts (enforced by the aggregate write).
 */
#[Fillable(['cycle_code', 'name', 'entry_time', 'tolerance_minutes', 'sort_order', 'is_active'])]
class CycleShift extends Model
{
    /** @use HasFactory<CycleShiftFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'code';

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AcademicCycle::class, 'cycle_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'tolerance_minutes' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
