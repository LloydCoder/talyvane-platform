<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Enquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class EnquiryController extends Controller
{
    public function create(): View
    {
        return view('contact', [
            'destinations' => Destination::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Quietly accept honeypot submissions to avoid giving bots a validation oracle.
        if (filled($request->input('website'))) {
            return redirect()->route('contact')->with('enquiry_received', true);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'destination_id' => ['nullable', 'integer', Rule::exists('destinations', 'id')->where('is_active', true)],
            'privacy_acknowledged' => ['required', 'accepted'],
            'website' => ['nullable', 'max:0'],
        ]);

        unset($validated['privacy_acknowledged'], $validated['website']);
        $validated['privacy_acknowledged_at'] = now();
        $validated['status'] = 'new';

        Enquiry::query()->create($validated);

        return redirect()->route('contact')->with('enquiry_received', true);
    }
}
