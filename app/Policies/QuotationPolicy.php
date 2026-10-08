<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function view(User $user, Quotation $quotation): bool
    {
        return $user->isAdmin() || ($user->isClient() && $user->client_id === $quotation->client_id && $quotation->status !== 'draft');
    }

    public function answer(User $user, Quotation $quotation): bool
    {
        return $this->view($user, $quotation) && $user->isClient() && $quotation->canBeAnswered();
    }
}
