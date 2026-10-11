<x-layout :title="$client->exists ? 'Edit client' : 'Add client'">
    <x-page :title="$client->exists ? $client->company : 'Add a client'" eyebrow="Client" :back="[route('admin.clients.index'), 'Clients']" />
    <form method="post" action="{{ $client->exists ? route('admin.clients.update', $client) : route('admin.clients.store') }}" class="card grid gap-5 sm:grid-cols-2">
        @csrf @if ($client->exists) @method('patch') @endif
        <x-field label="Company or trading name" name="company" required class="sm:col-span-2"><input id="company" name="company" value="{{ old('company', $client->company) }}" required class="input"></x-field>
        <x-field label="Contact person" name="contact_name" required><input id="contact_name" name="contact_name" value="{{ old('contact_name', $client->contact_name) }}" required class="input"></x-field>
        <x-field label="Email" name="email" required><input id="email" name="email" type="email" value="{{ old('email', $client->email) }}" required class="input"></x-field>
        <x-field label="Phone" name="phone"><input id="phone" name="phone" type="tel" autocomplete="tel" value="{{ old('phone', $client->phone) }}" class="input"></x-field>
        <x-field label="Website" name="website"><input id="website" name="website" type="url" value="{{ old('website', $client->website) }}" placeholder="https://" class="input"></x-field>
        <x-field label="Address" name="address" class="sm:col-span-2"><input id="address" name="address" value="{{ old('address', $client->address) }}" class="input"></x-field>
        <x-field label="Private notes" name="notes" class="sm:col-span-2" hint="Only you see these."><textarea id="notes" name="notes" rows="4" class="input">{{ old('notes', $client->notes) }}</textarea></x-field>
        <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-2"><input type="checkbox" name="active" value="1" @checked(old('active', $client->active)) class="size-4 rounded border-line"> Active client</label>
        <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="checkbox" name="disclosure_consent" value="1" @checked(old('disclosure_consent', $client->hasDisclosureConsent())) class="mt-1 size-4 rounded border-line"><span><span class="font-semibold">Client has consented to disclosure</span><span class="block text-muted">Tick only once the client has agreed in writing that their contracts and payment history may appear in bank or lender reports.{{ $client->disclosure_consent_at ? ' Recorded '.$client->disclosure_consent_at->format('j M Y').'.' : '' }}</span></span></label>
        @unless ($client->exists)
            <label class="flex items-start gap-2 rounded-xl bg-brand-50 p-4 text-sm ring-1 ring-brand-100 sm:col-span-2"><input type="checkbox" name="create_login" value="1" @checked($errors->any() ? old('create_login') : true) class="mt-1 size-4 rounded border-line"><span><span class="font-semibold">Create a portal login and email the welcome</span><span class="block text-muted">Sends the contact a welcome email with their sign-in email, a generated password and a link to the portal. Untick to add the login later from the client page.</span></span></label>
        @endunless
        <div class="flex gap-2 sm:col-span-2"><button class="btn btn-primary">{{ $client->exists ? 'Save changes' : 'Add client' }}</button><a href="{{ $client->exists ? route('admin.clients.show', $client) : route('admin.clients.index') }}" class="btn btn-light">Cancel</a></div>
    </form>
</x-layout>
