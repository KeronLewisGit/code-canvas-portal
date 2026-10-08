<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /** Policy 'view' check with the portal's convention: clients get 404 so record existence is not revealed. */
    protected function authorizeView(object $record): void
    {
        $user = request()->user();
        abort_unless($user?->can('view', $record), $user?->isClient() ? 404 : 403);
    }
}
