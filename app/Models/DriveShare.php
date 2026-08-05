<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit read access granted by a Drive file owner to another employee.
 */
#[Fillable([
    'file_code',
    'shared_with_user_code',
])]
class DriveShare extends Model
{
    use HasUuids;

    protected $primaryKey = 'code';

    public $timestamps = false;

    public function file(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class, 'file_code', 'code');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with_user_code', 'code');
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
