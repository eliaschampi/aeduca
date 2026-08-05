<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One private Drive node. `user_code` is the single owner; there is no global
 * space. Access for anyone else exists only through an explicit `DriveShare`.
 *
 * `deleted_at` is the product trash, not Eloquent soft deletes: the trash view
 * must be able to list and restore those rows.
 */
#[Fillable([
    'parent_code',
    'name',
    'type',
    'size',
    'storage_path',
    'mime_type',
])]
class DriveFile extends Model
{
    use HasUuids;

    protected $primaryKey = 'code';

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_code', 'code');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_code', 'code');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(DriveShare::class, 'file_code', 'code');
    }

    public function isDirectory(): bool
    {
        return $this->type === 'dir';
    }

    public function isTrashed(): bool
    {
        return $this->deleted_at !== null;
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
