<?php

namespace App\Actions;

use App\Models\AuthAccount;
use App\Support\Branches\BranchContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Session\Store;

final class SelectBranch
{
    public function __construct(private readonly BranchContext $branchContext) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(AuthAccount $account, string $branchCode, Store $session): void
    {
        $branch = $this->branchContext
            ->authorizedBranches($account)
            ->firstWhere('code', $branchCode);

        if (! $branch) {
            throw new AuthorizationException('No tienes acceso a esta sede.');
        }

        $session->put('current_branch_code', $branch->code);
    }
}
