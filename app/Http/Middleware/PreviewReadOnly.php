<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\ClientPreviewController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** While an admin previews the portal as a client, nothing can be changed: only viewing, leaving the preview, or signing out. */
class PreviewReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ClientPreviewController::active() && ! $request->isMethodSafe() && ! $request->routeIs('preview.stop', 'logout')) {
            return back()->with('warning', 'This is a read-only preview of the client portal, so nothing was changed. Use "Back to admin" to make changes.');
        }

        return $next($request);
    }
}
