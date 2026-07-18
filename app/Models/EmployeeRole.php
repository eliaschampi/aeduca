<?php

namespace App\Models;

use Database\Factories\EmployeeRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'is_active'])]
class EmployeeRole extends Model
{
    /** @use HasFactory<EmployeeRoleFactory> */
    use HasFactory, HasUuids;

    protected $primaryKey = 'code';

    /**
     * Permissions that may be assigned to employees with this role.
     * Not automatic grants.
     */
    public function permissionScopes(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'employee_role_permission_scopes',
            'employee_role_code',
            'permission_code',
            'code',
            'code',
        );
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'employee_role_code', 'code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
