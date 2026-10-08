<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /** Admins see everything; a client sees their own invoices once sent. */
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->isAdmin() || ($user->isClient() && $user->client_id === $invoice->client_id && $invoice->status !== 'draft');
    }
}
