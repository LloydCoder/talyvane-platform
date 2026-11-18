<?php

namespace App\Http\Controllers\Admin;

use App\Models\BrandSetting;
use App\Services\BrandSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

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
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000'],
        ]);

        $logo = $validated['logo'] ?? null;
        unset($validated['logo']);

        $oldLogo = BrandSetting::query()->where('key', 'logo_path')->value('value');
        $newLogo = $logo?->storePublicly('brand-assets', 'public');

        try {
            DB::transaction(function () use ($validated, $newLogo): void {
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

                if ($newLogo !== null) {
                    BrandSetting::query()->updateOrCreate(
                        ['key' => 'logo_path'],
                        [
                            'value' => $newLogo,
                            'group' => 'branding',
                            'is_public' => true,
                            'updated_by' => auth()->id(),
                        ],
                    );
                }
            });
        } catch (Throwable $exception) {
            if ($newLogo !== null) {
                Storage::disk('public')->delete($newLogo);
            }

            throw $exception;
        }

        if ($newLogo !== null && $oldLogo !== null && $oldLogo !== $newLogo) {
            Storage::disk('public')->delete($oldLogo);
        }

        return redirect()
            ->route('admin.branding.edit')
            ->with('status', 'Brand settings saved.');
    }
}
