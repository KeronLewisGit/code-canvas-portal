<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Who has signed in to the client portal, when, and what each login did last. */
class ActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $logins = User::with('client')->where('role', 'client')->whereNotNull('client_id')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->q.'%')->orWhere('email', 'like', '%'.$request->q.'%')
                ->orWhereHas('client', fn ($c) => $c->where('company', 'like', '%'.$request->q.'%'))))
            ->when($request->query('show') === 'never', fn ($q) => $q->whereNull('last_login_at'))
            ->orderByRaw('last_seen_at is null')->orderByDesc('last_seen_at')->orderBy('name')
            ->paginate(50)->withQueryString();

        $signIns = DB::table('login_events')->join('users', 'users.id', '=', 'login_events.user_id')->leftJoin('clients', 'clients.id', '=', 'users.client_id')
            ->orderByDesc('login_events.created_at')->limit(30)
            ->get(['login_events.created_at', 'login_events.ip', 'login_events.user_agent', 'users.name', 'users.role', 'users.client_id', 'clients.company']);

        return view('admin.activity', ['logins' => $logins, 'signIns' => $signIns, 'neverCount' => User::where('role', 'client')->whereHas('client', fn ($c) => $c->where('active', true))->whereNull('last_login_at')->count()]);
    }

    /** "Chrome on Windows" from a user agent, good enough to recognise a device. */
    public static function device(?string $agent): string
    {
        if (! $agent) {
            return 'Unknown device';
        }
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'unknown system',
        };

        return $browser.' on '.$os;
    }
}
