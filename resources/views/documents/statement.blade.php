@php($company = config('company'))
@php($reference = 'Statement · '.$client->company.' · '.now()->format('j M Y'))
<x-document-layout :reference="$reference" :title="'Statement of account'" :back="auth()->user()->isAdmin() ? route('admin.clients.show', $client) : route('portal.invoices.index')">
    <article class="doc">
        <header class="flex items-start justify-between gap-6">
            <div>
                <p class="doc-title">STATEMENT</p>
                <p class="mt-2 font-semibold">Statement of account</p>
                <p class="small muted">As at {{ now()->format('j F Y') }}</p>
            </div>
            <div class="text-right">
                <x-logo :full="true" class="ml-auto h-20 w-auto" />
                <p class="mt-4 font-semibold">{{ $company['trading_name'] }}</p>
                <p class="small muted">{{ $company['name'] }}<br>{{ $company['address'] }}<br>{{ $company['email'] }}@if ($company['phone'])<br>{{ $company['phone'] }}@endif</p>
            </div>
        </header>

        <div class="small mt-8">
            <p class="muted">Account of</p>
            <p class="font-semibold">{{ $client->company }}</p>
            <p class="muted">@unless ($client->isIndividual()){{ $client->contact_name }}<br>@endunless @if ($client->address){{ $client->address }}<br>@endif{{ $client->email }}</p>
        </div>

        @forelse ($sections as $currency => $rows)
            <p class="mt-8 font-semibold">{{ $currency }} account</p>
            <table class="doc-table mt-2">
                <thead><tr><th style="width:6.5em">Date</th><th style="width:8em">Reference</th><th>Details</th><th class="num" style="width:7em">Charges</th><th class="num" style="width:7em">Payments</th><th class="num" style="width:7.5em">Balance</th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr><td>{{ $r['date']->format('j M Y') }}</td><td>{{ $r['ref'] }}</td><td>{{ $r['text'] }}</td><td class="num">{{ $r['charge'] > 0 ? number_format($r['charge'], 2) : '' }}</td><td class="num">{{ $r['credit'] > 0 ? number_format($r['credit'], 2) : '' }}</td><td class="num">{{ number_format($r['balance'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            @php($closing = $rows->last()['balance'])
            <div class="totals mt-3 avoid-break">
                <div class="due"><span>{{ $closing < 0 ? 'In credit' : 'Balance due' }}</span><span>{{ money(abs($closing), $currency) }}</span></div>
            </div>
        @empty
            <p class="mt-8 muted">No invoices or payments yet.</p>
        @endforelse

        <p class="small muted mt-8">Charges are invoices we have issued; payments are those we have verified. Payments you have submitted but we have not yet verified are not shown. Questions? Email {{ $company['email'] }}.</p>
    </article>
</x-document-layout>
