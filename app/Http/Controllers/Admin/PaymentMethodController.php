<?php

namespace App\Http\Controllers\Admin;

use App\Models\PaymentMethod;
use App\Models\PaymentSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentMethodController
{
    public function index(): View
    {
        return view('admin.payments.methods', [
            'methods' => PaymentMethod::query()->with('verifier')->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'asset' => ['required', Rule::in(['BTC', 'USDT', 'USDC'])],
            'network' => ['required', 'string', 'min:2', 'max:40'],
            'address' => ['required', 'string', 'min:16', 'max:255'],
            'token_contract' => ['required_if:asset,USDT,USDC', 'nullable', 'string', 'max:255'],
            'confirmations_required' => ['required', 'integer', 'min:1', 'max:100'],
            'purpose_booking' => ['sometimes', 'boolean'],
            'purpose_membership' => ['sometimes', 'boolean'],
        ]);

        PaymentMethod::query()->create([
            'asset' => $validated['asset'],
            'network' => trim($validated['network']),
            'address' => trim($validated['address']),
            'token_contract' => isset($validated['token_contract']) ? trim($validated['token_contract']) : null,
            'confirmations_required' => $validated['confirmations_required'],
            'purpose_booking' => $request->boolean('purpose_booking'),
            'purpose_membership' => $request->boolean('purpose_membership'),
            'enabled' => false,
            'verification_status' => 'pending',
        ]);

        return redirect()->route('admin.payment-methods')->with('status', 'Receiving method saved disabled. Review the network, address and token contract before it can be enabled.');
    }

    public function review(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $validated = $request->validate([
            'verification_reference' => ['required', 'string', 'min:8', 'max:255'],
            'reviewed' => ['required', 'accepted'],
        ]);

        $paymentMethod->forceFill([
            'verification_status' => 'reviewed',
            'verification_reference_hash' => hash_hmac('sha256', $validated['verification_reference'], (string) config('app.key')),
            'verified_by' => auth()->id(),
            'address_reviewed_at' => now(),
        ])->save();

        return redirect()->route('admin.payment-methods')->with('status', 'Configuration review recorded. This does not verify incoming transactions or enable blockchain settlement.');
    }

    public function toggle(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        if ($validated['enabled']) {
            $settings = PaymentSetting::current();

            if ($paymentMethod->verification_status !== 'reviewed' || ! $paymentMethod->address_reviewed_at) {
                throw ValidationException::withMessages(['enabled' => 'The receiving address must be reviewed before it can be enabled.']);
            }

            if (! $settings->payments_enabled || ! $settings->crypto_enabled) {
                throw ValidationException::withMessages(['enabled' => 'Global payments and crypto must both be enabled first.']);
            }

            $bookingAllowed = $paymentMethod->purpose_booking && $settings->booking_payments_enabled;
            $membershipAllowed = $paymentMethod->purpose_membership && $settings->membership_payments_enabled;

            if (! $bookingAllowed && ! $membershipAllowed) {
                throw ValidationException::withMessages(['enabled' => 'At least one matching payment purpose must be enabled globally and on this receiving method.']);
            }
        }

        $paymentMethod->update(['enabled' => $validated['enabled']]);

        return redirect()->route('admin.payment-methods')->with('status', 'Receiving method state updated.');
    }
}
