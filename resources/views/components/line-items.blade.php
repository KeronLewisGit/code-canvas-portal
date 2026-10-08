@props(['items', 'taxRate', 'currency'])
{{-- Alpine line item editor; posts items[n][description|quantity|unit_price]. --}}
<div x-data="lineItems(@js(collect($items)->map(fn ($i) => ['description' => $i['description'] ?? '', 'quantity' => (float) ($i['quantity'] ?? 1), 'unit_price' => (float) ($i['unit_price'] ?? 0)])->values()), {{ (float) $taxRate }})">
    <table class="table"><thead><tr><th class="w-1/2">Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Line</th><th></th></tr></thead>
    <tbody>
        <template x-for="(item, i) in items" :key="i">
            <tr>
                <td><textarea :name="`items[${i}][description]`" x-model="item.description" rows="2" placeholder="Item name on the first line, details on the lines below" class="input min-h-0"></textarea></td>
                <td><input :name="`items[${i}][quantity]`" x-model="item.quantity" type="number" inputmode="decimal" step="0.01" min="0" class="input text-right"></td>
                <td><input :name="`items[${i}][unit_price]`" x-model="item.unit_price" type="number" inputmode="decimal" step="0.01" min="0" class="input text-right"></td>
                <td class="num font-semibold" x-text="fmt(line(item))"></td>
                <td class="num"><button type="button" @click="remove(i)" class="btn-link-danger" :aria-label="`Remove line ${i + 1}`">Remove</button></td>
            </tr>
        </template>
    </tbody></table>
    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <button type="button" @click="add()" class="btn btn-light btn-sm">Add line</button>
        <dl class="w-64 space-y-1 text-sm">
            <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd class="tabular-nums" x-text="fmt(subtotal)"></dd></div>
            <div class="flex items-center justify-between gap-2"><dt class="text-muted">Tax %</dt><dd><input name="tax_rate" x-model="tax" type="number" inputmode="decimal" step="0.01" min="0" max="100" class="input w-24 py-1 text-right"></dd></div>
            <div class="flex justify-between"><dt class="text-muted">Tax</dt><dd class="tabular-nums" x-text="fmt(taxAmount)"></dd></div>
            <div class="flex justify-between border-t border-line pt-1 text-base font-bold"><dt>Total {{ $currency }}</dt><dd class="tabular-nums" x-text="fmt(total)"></dd></div>
        </dl>
    </div>
</div>
