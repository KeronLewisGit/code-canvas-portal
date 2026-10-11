<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Notifier;
use App\Services\PortalAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $clients = Client::withCount('contracts')->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('company', 'like', '%'.$request->q.'%')->orWhere('contact_name', 'like', '%'.$request->q.'%')->orWhere('email', 'like', '%'.$request->q.'%')))->orderBy('company')->paginate(25)->withQueryString();

        return view('admin.clients.index', compact('clients'));
    }

    public function create(): View
    {
        return view('admin.clients.form', ['client' => new Client(['active' => true, 'type' => 'company'])]);
    }

    /** Adding a client can also open their portal login and email the welcome, so onboarding is one step. */
    public function store(Request $request, PortalAccess $access): RedirectResponse
    {
        $data = $this->validated($request);
        $withLogin = $request->boolean('create_login') && $data['active']; // an inactive client cannot sign in anyway
        if ($withLogin) {
            $request->validate(['email' => [Rule::unique('users', 'email')]], ['email.unique' => 'A portal login already uses this email. Use a different email, or untick "Create a portal login" and add the login later.']);
        }

        // The client and its login are saved together; the welcome email goes out only once both exist.
        $login = null;
        $client = DB::transaction(function () use ($data, $withLogin, $access, &$login) {
            $client = Client::create($data);
            $login = $withLogin ? $access->createLogin($client, $client->contact_name, $client->email, sendEmail: false) : null;

            return $client;
        });

        $redirect = redirect()->route('admin.clients.show', $client);
        if (! $login) {
            return $redirect->with('saved', 'Client added. Create their portal login below when you are ready.');
        }
        $login['emailed'] = app(Notifier::class)->loginDetails($login['user'], $login['password'], isNew: true);

        return $redirect->with('saved', $login['emailed'] ? 'Client added and welcome email sent to '.$client->email.'.' : 'Client added with a portal login. The welcome email was not sent, so pass the details on yourself.')
            ->with('credentials', PortalAccess::banner($login));
    }

    public function show(Client $client): View
    {
        $client->load(['users', 'contracts', 'quotations', 'invoices', 'payments.contract', 'payments.invoice']);

        return view('admin.clients.show', compact('client'));
    }

    public function edit(Client $client): View
    {
        return view('admin.clients.form', compact('client'));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($this->validated($request));

        return redirect()->route('admin.clients.show', $client)->with('saved', 'Client updated.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', array_keys(Client::TYPES))],
            'company' => ['required_if:type,company', 'nullable', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['nullable', 'boolean'],
            'disclosure_consent' => ['nullable', 'boolean'],
        ], ['company.required_if' => 'Enter the company or trading name.', 'contact_name.required' => $request->input('type') === 'individual' ? 'Enter the client\'s full name.' : 'Enter the contact person\'s name.']);
        $data['type'] ??= 'company';
        // An individual is their own contact, and their name is the client's display name.
        if ($data['type'] === 'individual') {
            $data['company'] = $data['contact_name'];
        }
        $data['active'] = $request->boolean('active');
        $data['disclosure_consent_at'] = $request->boolean('disclosure_consent') ? ($request->route('client')?->disclosure_consent_at ?? now()) : null;
        unset($data['disclosure_consent']);

        return $data;
    }
}
