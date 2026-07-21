<?php

namespace App\Models;

use Database\Factories\AcademicGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Concrete section inside a cycle degree. UI label: «Sección».
 * Future enrollment references academic_group_code.
 */
#[Fillable(['cycle_degree_code', 'name', 'sort_order', 'is_active'])]
class AcademicGroup extends Model
{
    /** @use HasFactory<AcademicGroupFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'code';

    public function cycleDegree(): BelongsTo
    {
        return $this->belongsTo(CycleDegree::class, 'cycle_degree_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
