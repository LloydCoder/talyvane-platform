<?php

namespace App\Http\Controllers\Admin;

use App\Models\CountryMarket;
use App\Models\Currency;
use App\Models\GlobalizationSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GlobalizationController
{
    public function edit(): View
    {
        return view('admin.globalization.index', [
            'settings' => GlobalizationSetting::current(),
            'currencies' => Currency::query()->orderBy('code')->get(),
            'markets' => CountryMarket::query()->with('defaultCurrency')->orderBy('name')->paginate(20),
        ]);
    }

    public function updateDefaults(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'base_currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/', Rule::exists('currencies', 'code')->where('is_enabled', true)],
            'default_locale' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
            'default_timezone' => ['required', 'timezone'],
        ]);

        DB::transaction(function () use ($validated, $request): void {
            Currency::query()->update(['is_reporting_currency' => false]);

            if (! empty($validated['base_currency_code'])) {
                Currency::query()->where('code', $validated['base_currency_code'])
                    ->update(['is_reporting_currency' => true]);
            }

            GlobalizationSetting::current()->update([
                ...$validated,
                'updated_by' => $request->user()->id,
            ]);
        });

        return redirect()->route('admin.globalization')->with('status', 'Globalization defaults saved.');
    }

    public function storeCurrency(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/', 'unique:currencies,code'],
            'numeric_code' => ['nullable', 'string', 'size:3', 'regex:/^[0-9]{3}$/', 'unique:currencies,numeric_code'],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:16'],
            'minor_unit' => ['required', 'integer', 'between:0,3'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        Currency::query()->create([
            ...$validated,
            'is_enabled' => $request->boolean('is_enabled'),
            'is_reporting_currency' => false,
        ]);

        return redirect()->route('admin.globalization')->with('status', 'Currency added. Confirm its ISO 4217 metadata before enabling it for transactions.');
    }

    public function storeMarket(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/', 'unique:country_markets,country_code'],
            'alpha3_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/', 'unique:country_markets,alpha3_code'],
            'name' => ['required', 'string', 'max:120'],
            'default_currency_code' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_enabled', true)],
            'default_locale' => ['nullable', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
            'default_timezone' => ['nullable', 'timezone'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        CountryMarket::query()->create([
            ...$validated,
            'is_enabled' => $request->boolean('is_enabled'),
            'compliance_status' => 'pending',
            'compliance_reviewed_at' => null,
            'compliance_reviewed_by' => null,
        ]);

        return redirect()->route('admin.globalization')->with('status', 'Country market added in pending compliance state. It is not cleared for service availability.');
    }
}
