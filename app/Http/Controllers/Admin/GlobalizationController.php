<?php

namespace App\Http\Controllers\Admin;

use App\Models\CountryMarket;
use App\Models\Currency;
use App\Models\GlobalizationSetting;
use App\Models\GlobalizationAuditEvent;
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

        $settings = GlobalizationSetting::current();
        $before = [
            ...$settings->only(['base_currency_code', 'default_locale', 'default_timezone']),
            'reporting_currency_code' => $settings->base_currency_code,
        ];

        DB::transaction(function () use ($validated, $request, $settings, $before): void {
            Currency::query()->update(['is_reporting_currency' => false]);

            if (! empty($validated['base_currency_code'])) {
                Currency::query()->where('code', $validated['base_currency_code'])
                    ->update(['is_reporting_currency' => true]);
            }

            $settings->update([
                ...$validated,
                'updated_by' => $request->user()->id,
            ]);

            GlobalizationAuditEvent::query()->create([
                'actor_id' => $request->user()->id,
                'action' => 'deployment_defaults.updated',
                'subject_type' => 'globalization_settings',
                'subject_key' => '1',
                'before_state' => $before,
                'after_state' => [
                    ...$settings->fresh()->only(['base_currency_code', 'default_locale', 'default_timezone']),
                    'reporting_currency_code' => $validated['base_currency_code'] ?? null,
                ],
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

        $currency = Currency::query()->create([
            ...$validated,
            'is_enabled' => $request->boolean('is_enabled'),
            'is_reporting_currency' => false,
        ]);

        $this->recordAudit($request, 'currency.created', 'currency', $currency->code, null, $currency->only([
            'code', 'numeric_code', 'name', 'symbol', 'minor_unit', 'is_enabled',
        ]));

        return redirect()->route('admin.globalization')->with('status', 'Currency added. Confirm its ISO 4217 metadata before enabling it for transactions.');
    }

    public function toggleCurrency(Request $request, Currency $currency): RedirectResponse
    {
        $request->validate(['is_enabled' => ['required', 'boolean']]);
        $enabled = $request->boolean('is_enabled');

        if (! $enabled && GlobalizationSetting::current()->base_currency_code === $currency->code) {
            return redirect()->route('admin.globalization')->withErrors([
                'currency' => 'Choose another reporting currency before disabling this currency.',
            ]);
        }

        $before = ['is_enabled' => $currency->is_enabled];
        $currency->update(['is_enabled' => $enabled]);
        $this->recordAudit($request, 'currency.availability_changed', 'currency', $currency->code, $before, [
            'is_enabled' => $currency->is_enabled,
        ]);

        return redirect()->route('admin.globalization')->with('status', 'Currency availability updated.');
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

        $market = CountryMarket::query()->create([
            ...$validated,
            'is_enabled' => $request->boolean('is_enabled'),
            'compliance_status' => 'pending',
            'compliance_reviewed_at' => null,
            'compliance_reviewed_by' => null,
        ]);

        $this->recordAudit($request, 'country_market.created', 'country_market', $market->country_code, null, $market->only([
            'country_code', 'alpha3_code', 'name', 'default_currency_code', 'default_locale',
            'default_timezone', 'is_enabled', 'compliance_status',
        ]));

        return redirect()->route('admin.globalization')->with('status', 'Country market added in pending compliance state. It is not cleared for service availability.');
    }

    private function recordAudit(
        Request $request,
        string $action,
        string $subjectType,
        string $subjectKey,
        ?array $before,
        array $after,
    ): void {
        GlobalizationAuditEvent::query()->create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_key' => $subjectKey,
            'before_state' => $before,
            'after_state' => $after,
        ]);
    }
}
