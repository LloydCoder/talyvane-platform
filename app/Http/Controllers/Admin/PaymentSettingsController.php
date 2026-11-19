<?php

namespace App\Http\Controllers\Admin;

use App\Models\PaymentSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentSettingsController
{
    public function edit(): View
    {
        return view('admin.payments.settings', ['settings' => PaymentSetting::current()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payments_enabled' => ['sometimes', 'boolean'],
            'crypto_enabled' => ['sometimes', 'boolean'],
            'cards_enabled' => ['sometimes', 'boolean'],
            'manual_enabled' => ['sometimes', 'boolean'],
            'booking_payments_enabled' => ['sometimes', 'boolean'],
            'membership_payments_enabled' => ['sometimes', 'boolean'],
        ]);

        PaymentSetting::current()->update([
            'payments_enabled' => $request->boolean('payments_enabled'),
            'crypto_enabled' => $request->boolean('crypto_enabled'),
            'cards_enabled' => $request->boolean('cards_enabled'),
            'manual_enabled' => $request->boolean('manual_enabled'),
            'booking_payments_enabled' => $request->boolean('booking_payments_enabled'),
            'membership_payments_enabled' => $request->boolean('membership_payments_enabled'),
            'updated_by' => auth()->id(),
        ]);

        return redirect()->route('admin.payment-settings')->with('status', 'Payment configuration saved. No checkout or settlement adapter is enabled by this configuration alone.');
    }
}
