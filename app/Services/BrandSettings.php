<?php

namespace App\Services;

use App\Models\BrandSetting;

class BrandSettings
{
    public function publicBrand(): array
    {
        $defaults = config('talyvane.brand', []);

        $stored = BrandSetting::query()
            ->where('group', 'branding')
            ->where('is_public', true)
            ->pluck('value', 'key')
            ->all();

        return array_merge($defaults, $stored);
    }

    public function adminBrand(): array
    {
        $defaults = config('talyvane.brand', []);

        $stored = BrandSetting::query()
            ->where('group', 'branding')
            ->pluck('value', 'key')
            ->all();

        return array_merge($defaults, $stored);
    }
}
