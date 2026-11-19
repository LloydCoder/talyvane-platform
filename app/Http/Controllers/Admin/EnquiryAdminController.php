<?php

namespace App\Http\Controllers\Admin;

use App\Models\Enquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EnquiryAdminController
{
    public function index(): View
    {
        return view('admin.enquiries.index', [
            'enquiries' => Enquiry::query()->with('destination')->latest()->paginate(20),
        ]);
    }

    public function update(Request $request, Enquiry $enquiry): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['new', 'in_progress', 'responded', 'closed', 'spam'])],
        ]);

        $enquiry->status = $validated['status'];

        if ($validated['status'] === 'responded') {
            $enquiry->responded_at ??= now();
        }

        if ($validated['status'] === 'closed') {
            $enquiry->closed_at ??= now();
        } else {
            $enquiry->closed_at = null;
        }

        $enquiry->save();

        return redirect()->route('admin.enquiries.index')->with('status', 'Enquiry status updated.');
    }
}
