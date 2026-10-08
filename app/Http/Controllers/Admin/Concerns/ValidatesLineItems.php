<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\Request;

trait ValidatesLineItems
{
    /** Validation rules shared by quotations and invoices. */
    protected function documentRules(array $extra = []): array
    {
        return $extra + [
            'client_id' => ['required', 'exists:clients,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'title' => ['required', 'string', 'max:160'],
            'issue_date' => ['required', 'date'],
            'currency' => ['required', 'in:'.implode(',', array_keys(config('company.currencies')))],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    /** Validated document fields with the tax rate coerced to a number. */
    protected function documentData(array $data): array
    {
        $data['tax_rate'] = (float) ($data['tax_rate'] ?? 0);

        return collect($data)->except('items')->all();
    }

    protected function itemsFrom(Request $request): array
    {
        return array_values(array_filter($request->input('items', []), fn ($row) => trim((string) ($row['description'] ?? '')) !== ''));
    }
}
