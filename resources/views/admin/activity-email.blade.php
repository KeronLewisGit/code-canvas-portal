<x-layout title="Email clients">
    <x-page title="Email clients" :eyebrow="$recipients->count().' '.($recipients->count() === 1 ? 'recipient' : 'recipients')">
        <x-slot:actions><a href="{{ route('admin.activity') }}" class="btn btn-light">Back to activity</a></x-slot:actions>
    </x-page>
    <form method="post" action="{{ route('admin.activity.email.send') }}" class="grid gap-6 lg:grid-cols-3" x-data="{ kind: @js($kind) }">
        @csrf
        @foreach ($recipients as $r)<input type="hidden" name="users[]" value="{{ $r->id }}">@endforeach
        <section class="card space-y-5 lg:col-span-2">
            <fieldset>
                <legend class="label">What to send</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-edge p-3 has-checked:border-brand-600 has-checked:bg-brand-50"><input type="radio" name="kind" value="message" x-model="kind" class="mt-1 size-4"><span><span class="block font-semibold">A message</span><span class="text-sm text-muted">Write your own subject and message.</span></span></label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-edge p-3 has-checked:border-brand-600 has-checked:bg-brand-50"><input type="radio" name="kind" value="welcome" x-model="kind" class="mt-1 size-4"><span><span class="block font-semibold">The welcome email again</span><span class="text-sm text-muted">Explains the portal, with a new 7-day link to choose a password. Best for people who haven't signed in.</span></span></label>
                </div>
            </fieldset>
            <div x-show="kind === 'message'" class="space-y-5">
                <x-field label="Subject" name="subject" required><input id="subject" name="subject" value="{{ old('subject') }}" maxlength="150" class="input" :required="kind === 'message'"></x-field>
                <x-field label="Message" name="body" required hint="Each email starts with “Hello [their name],” and ends with your company details. Leave a blank line between paragraphs.">
                    <textarea id="body" name="body" rows="10" class="input" :required="kind === 'message'">{{ old('body') }}</textarea>
                </x-field>
                <label class="flex items-center gap-2 text-sm font-semibold"><input type="hidden" name="button" value="0"><input type="checkbox" name="button" value="1" @checked(old('button', true)) class="size-4 rounded border-line"> Add an “Open the portal” button</label>
            </div>
            <div class="flex flex-wrap gap-2 border-t border-edge pt-5">
                <button class="btn btn-primary" data-confirm="Send this email to {{ $recipients->count() }} {{ $recipients->count() === 1 ? 'person' : 'people' }}?"><x-icon name="mail" class="size-4" /> Send to {{ $recipients->count() }} {{ $recipients->count() === 1 ? 'person' : 'people' }}</button>
                <a href="{{ route('admin.activity') }}" class="btn btn-light">Cancel</a>
            </div>
        </section>
        <aside class="card self-start">
            <x-card-header title="Recipients" />
            <ul class="mt-3 divide-y divide-edge text-sm">
                @foreach ($recipients as $r)
                    <li class="py-2"><span class="font-semibold">{{ $r->name }}</span> <span class="text-muted">· {{ $r->client->company }}</span><span class="block break-all text-xs text-muted">{{ $r->email }} · {{ $r->last_login_at ? 'signed in '.$r->last_login_at->diffForHumans() : 'never signed in' }}</span></li>
                @endforeach
            </ul>
            <p class="hint mt-3">Logins of inactive clients are left out.</p>
        </aside>
    </form>
</x-layout>
