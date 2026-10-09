<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ValidatesLineItems
{
    /** Validation rules shared by quotations and invoices. The contract, if any, must belong to the chosen client. */
    protected function documentRules(Request $request, array $extra = []): array
    {
        return $extra + [
            'client_id' => ['required', 'exists:clients,id'],
            'contract_id' => ['nullable', Rule::exists('contracts', 'id')->where('client_id', $request->input('client_id'))],
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

    protected function documentMessages(): array
    {
        return ['contract_id.exists' => 'That contract belongs to a different client.'];
    }

    /** Validated document fields with the tax rate coerced to a number. */
    protected function documentData(array $data): array
    {
        $data['tax_rate'] = (float) ($data['tax_rate'] ?? 0);

        return collect($data)->except('items')->all();
    }

    /** Line items with a description. Blank rows are dropped; a form with only blank rows is a validation error, not a 0.00 document. */
    protected function itemsFrom(Request $request): array
    {
        $items = array_values(array_filter($request->input('items', []), fn ($row) => trim((string) ($row['description'] ?? '')) !== ''));
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line item with a description.']);
        }

        return $items;
    }
}
