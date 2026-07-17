<?php

namespace App\Models;

use Database\Factories\EmployeeRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description', 'is_active'])]
class EmployeeRole extends Model
{
    /** @use HasFactory<EmployeeRoleFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'code';

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permissions',
            'employee_role_code',
            'permission_code',
            'code',
            'code',
        );
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
