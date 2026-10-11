<x-layout :title="$client->exists ? 'Edit client' : 'Add client'">
    <x-page :title="$client->exists ? $client->company : 'Add a client'" eyebrow="Client" :back="[route('admin.clients.index'), 'Clients']" />
    <form method="post" action="{{ $client->exists ? route('admin.clients.update', $client) : route('admin.clients.store') }}" class="card grid gap-5 sm:grid-cols-2" x-data="{ type: @js(old('type', $client->type ?? 'company')) }">
        @csrf @if ($client->exists) @method('patch') @endif
        <fieldset class="sm:col-span-2">
            <legend class="label">This client is</legend>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach (\App\Models\Client::TYPES as $k => $label)
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-line" :class="type === '{{ $k }}' && 'bg-brand-50 ring-brand-400'">
                        <input type="radio" name="type" value="{{ $k }}" x-model="type" class="mt-1" @checked(old('type', $client->type) === $k)>
                        <span><span class="block text-sm font-semibold">{{ $k === 'company' ? 'A company or organisation' : 'An individual' }}</span><span class="block text-xs text-muted">{{ $k === 'company' ? 'A business, with a contact person you deal with.' : 'A person contracting in their own name.' }}</span></span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <template x-if="type === 'company'">
            <x-field label="Company or trading name" name="company" required class="sm:col-span-2"><input id="company" name="company" value="{{ old('company', $client->isIndividual() ? '' : $client->company) }}" required autocomplete="organization" class="input"></x-field>
        </template>
        <x-field name="contact_name" required label="Name">
            <x-slot:labelText><span x-text="type === 'company' ? 'Contact person' : 'Full name'">Contact person</span></x-slot:labelText>
            <input id="contact_name" name="contact_name" value="{{ old('contact_name', $client->contact_name) }}" required autocomplete="name" class="input">
        </x-field>
        <x-field label="Email" name="email" required><input id="email" name="email" type="email" value="{{ old('email', $client->email) }}" required class="input"></x-field>
        <x-field label="Phone" name="phone"><input id="phone" name="phone" type="tel" autocomplete="tel" placeholder="+1 (868) 555-1234" value="{{ old('phone', $client->phone) }}" class="input"></x-field>
        <x-field label="Website" name="website"><input id="website" name="website" type="url" value="{{ old('website', $client->website) }}" placeholder="https://" class="input"></x-field>
        <x-field label="Address" name="address" class="sm:col-span-2"><input id="address" name="address" value="{{ old('address', $client->address) }}" class="input"></x-field>
        <x-field label="Private notes" name="notes" class="sm:col-span-2" hint="Only you see these."><textarea id="notes" name="notes" rows="4" class="input">{{ old('notes', $client->notes) }}</textarea></x-field>
        <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-2"><input type="checkbox" name="active" value="1" @checked(old('active', $client->active)) class="size-4 rounded border-line"> Active client</label>
        <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="checkbox" name="disclosure_consent" value="1" @checked(old('disclosure_consent', $client->hasDisclosureConsent())) class="mt-1 size-4 rounded border-line"><span><span class="font-semibold">Client has consented to disclosure</span><span class="block text-muted">Tick only once the client has agreed in writing that their contracts and payment history may appear in bank or lender reports.{{ $client->disclosure_consent_at ? ' Recorded '.$client->disclosure_consent_at->format('j M Y').'.' : '' }}</span></span></label>
        @unless ($client->exists)
            <label class="flex items-start gap-2 rounded-xl bg-brand-50 p-4 text-sm ring-1 ring-brand-100 sm:col-span-2"><input type="checkbox" name="create_login" value="1" @checked($errors->any() ? old('create_login') : true) class="mt-1 size-4 rounded border-line"><span><span class="font-semibold">Create a portal login and email the welcome</span><span class="block text-muted">Sends the contact a welcome email with a link to choose their password and sign in. Untick to add the login later from the client page.</span></span></label>
        @endunless
        <div class="flex gap-2 sm:col-span-2"><button class="btn btn-primary">{{ $client->exists ? 'Save changes' : 'Add client' }}</button><a href="{{ $client->exists ? route('admin.clients.show', $client) : route('admin.clients.index') }}" class="btn btn-light">Cancel</a></div>
    </form>
</x-layout>
