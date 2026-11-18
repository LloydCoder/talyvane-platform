<?php

namespace App\Http\Controllers\Admin;

use App\Models\BrandSetting;
use App\Services\BrandSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BrandingController
{
    public function edit(BrandSettings $settings): View
    {
        return view('admin.branding.edit', [
            'brand' => $settings->adminBrand(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'brand_name' => ['required', 'string', 'min:2', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'contact_email' => ['nullable', 'email:rfc', 'max:254'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_website' => ['nullable', 'url', 'max:255'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated as $key => $value) {
                BrandSetting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => $value,
                        'group' => 'branding',
                        'is_public' => true,
                        'updated_by' => auth()->id(),
                    ],
                );
            }
        });

        return redirect()
            ->route('admin.branding.edit')
            ->with('status', 'Brand settings saved.');
    }
}
