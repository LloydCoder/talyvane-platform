<?php

namespace App\Http\Controllers\Admin;

use App\Models\BookingSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingSettingsController
{
    public function edit(): View
    {
        return view('admin.bookings.settings', [
            'settings' => BookingSetting::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'booking_mode' => ['required', 'in:manual,automatic'],
            'auto_confirm_enabled' => ['sometimes', 'boolean'],
            'auto_requires_verified_deposit' => ['sometimes', 'boolean'],
            'minimum_notice_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'maximum_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'client_cancellation_enabled' => ['sometimes', 'boolean'],
            'cancellation_cutoff_hours' => ['required', 'integer', 'min:0', 'max:720'],
        ]);

        $settings = BookingSetting::current();
        $settings->update([
            'booking_mode' => $validated['booking_mode'],
            'auto_confirm_enabled' => $request->boolean('auto_confirm_enabled'),
            'auto_requires_verified_deposit' => $request->boolean('auto_requires_verified_deposit'),
            'minimum_notice_hours' => $validated['minimum_notice_hours'],
            'maximum_advance_days' => $validated['maximum_advance_days'],
            'client_cancellation_enabled' => $request->boolean('client_cancellation_enabled'),
            'cancellation_cutoff_hours' => $validated['cancellation_cutoff_hours'],
            'updated_by' => auth()->id(),
        ]);

        return redirect()->route('admin.bookings.settings')->with('status', 'Booking settings saved.');
    }
}
