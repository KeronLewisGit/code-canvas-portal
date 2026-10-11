<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

/** Tenant-aware access: admins manage everything; a client user sees only their own contracts, once issued to them. */
class ContractPolicy
{
    public function view(User $user, Contract $contract): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isClient() && $user->client_id === $contract->client_id && $contract->isVisibleToClient();
    }

    public function sign(User $user, Contract $contract): bool
    {
        return $this->view($user, $contract) && $user->isClient() && $contract->awaitingSignature();
    }

    public function download(User $user, Contract $contract): bool
    {
        return $this->view($user, $contract);
    }
}
