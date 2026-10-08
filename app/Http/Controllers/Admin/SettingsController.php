<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Standard terms for contracts, invoices and quotations, plus the deposit and late-fee rates. */
class SettingsController extends Controller
{
    public const GROUPS = ['contract_terms' => 'Contract terms', 'invoice_terms' => 'Invoice terms', 'quotation_terms' => 'Quotation terms'];

    public function terms(): View
    {
        return view('admin.settings.terms', [
            'groups' => collect(self::GROUPS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'clauses' => collect(Setting::get($key, []))->map(fn ($text, $heading) => ['heading' => $heading, 'text' => $text])->values()->all(), 'updated' => Setting::find($key)?->updated_at]),
            'deposit' => Setting::get('deposit_percent'),
            'lateFee' => Setting::get('late_fee_monthly_percent'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group' => ['required', 'in:'.implode(',', array_keys(self::GROUPS))],
            'clauses' => ['required', 'array', 'min:1'],
            'clauses.*.heading' => ['required', 'string', 'max:120'],
            'clauses.*.text' => ['required', 'string', 'max:3000'],
        ]);
        $terms = [];
        foreach ($data['clauses'] as $clause) {
            $terms[trim($clause['heading'])] = trim($clause['text']);
        }
        $before = Setting::get($data['group'], []);
        Setting::put($data['group'], $terms, $request->user()->id);
        Audit::log($request->user(), 'terms_updated', ['group' => $data['group'], 'clauses_before' => count($before), 'clauses_after' => count($terms)]);

        return back()->with('saved', self::GROUPS[$data['group']].' saved. Signed contracts keep the terms they were signed with.');
    }

    public function rates(Request $request): RedirectResponse
    {
        $data = $request->validate(['deposit_percent' => ['required', 'numeric', 'min:0', 'max:100'], 'late_fee_monthly_percent' => ['required', 'numeric', 'min:0', 'max:20']]);
        Setting::put('deposit_percent', (float) $data['deposit_percent'], $request->user()->id);
        Setting::put('late_fee_monthly_percent', (float) $data['late_fee_monthly_percent'], $request->user()->id);
        Audit::log($request->user(), 'rates_updated', $data);

        return back()->with('saved', 'Deposit and late-fee rates saved.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $group = $request->validate(['group' => ['required', 'in:'.implode(',', array_keys(self::GROUPS))]])['group'];
        Setting::where('key', $group)->delete();
        Setting::forget();

        return back()->with('saved', self::GROUPS[$group].' reset to the built-in defaults.');
    }
}
