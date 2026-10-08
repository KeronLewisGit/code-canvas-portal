<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return view('admin.clients.form', ['client' => new Client(['active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = Client::create($this->validated($request));

        return redirect()->route('admin.clients.show', $client)->with('saved', 'Client added. Next, create their portal login below.');
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
            'company' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['nullable', 'boolean'],
            'disclosure_consent' => ['nullable', 'boolean'],
        ]);
        $data['active'] = $request->boolean('active');
        $data['disclosure_consent_at'] = $request->boolean('disclosure_consent') ? ($request->route('client')?->disclosure_consent_at ?? now()) : null;
        unset($data['disclosure_consent']);

        return $data;
    }
}
