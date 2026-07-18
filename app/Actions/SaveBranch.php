<?php

namespace App\Actions;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Create or update branch attributes only.
 * Membership (user_branches) is owned exclusively by employee administration.
 */
final class SaveBranch
{
    /**
     * @param  array{name: string, is_active: bool}  $attributes
     */
    public function handle(?Branch $branch, array $attributes): Branch
    {
        return DB::transaction(function () use ($branch, $attributes): Branch {
            if ($branch === null) {
                return Branch::query()->create($attributes);
            }

            $branch->update($attributes);

            return $branch->refresh();
        });
    }
}
