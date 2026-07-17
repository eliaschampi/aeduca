<?php

namespace App\Actions;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

final class SaveBranch
{
    /**
     * Create or update a branch and sync assigned workers through user_branches.
     *
     * @param  array{name: string, is_active: bool}  $attributes
     * @param  list<string>  $userCodes
     */
    public function handle(?Branch $branch, array $attributes, array $userCodes): Branch
    {
        return DB::transaction(function () use ($branch, $attributes, $userCodes): Branch {
            if ($branch === null) {
                $branch = Branch::query()->create($attributes);
            } else {
                $branch->update($attributes);
            }

            $branch->users()->sync($userCodes);

            return $branch->refresh();
        });
    }
}
