<?php

namespace App\Http\Controllers\Admin;

use App\Models\Destination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DestinationController
{
    public function index(): View
    {
        return view('admin.destinations.index', [
            'destinations' => Destination::query()->orderBy('country_name')->orderBy('city_name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.destinations.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $coverImage = $validated['cover_image'] ?? null;
        unset($validated['cover_image'], $validated['remove_cover_image'], $validated['image_rights_confirmed']);
        $validated['image_path'] = $coverImage?->store('destinations', 'public');

        Destination::query()->create($validated);

        return redirect()->route('admin.destinations.index')->with('status', 'Destination created.');
    }

    public function edit(Destination $destination): View
    {
        return view('admin.destinations.edit', compact('destination'));
    }

    public function update(Request $request, Destination $destination): RedirectResponse
    {
        $validated = $this->validated($request, $destination);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $coverImage = $validated['cover_image'] ?? null;
        unset($validated['cover_image']);

        $previousImage = $destination->image_path;
        if ($coverImage) {
            $validated['image_path'] = $coverImage->store('destinations', 'public');
        } elseif ($request->boolean('remove_cover_image')) {
            $validated['image_path'] = null;
        }

        $destination->update($validated);

        if ($previousImage && $previousImage !== $destination->image_path) {
            Storage::disk('public')->delete($previousImage);
        }

        return redirect()->route('admin.destinations.index')->with('status', 'Destination updated.');
    }

    private function validated(Request $request, ?Destination $destination = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:140', Rule::unique('destinations', 'slug')->ignore($destination?->id)],
            'country_code' => ['required', 'alpha', 'size:2'],
            'country_name' => ['required', 'string', 'max:100'],
            'city_name' => ['required', 'string', 'max:100'],
            'currency_code' => ['required', 'alpha', 'size:3'],
            'locale' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'timezone'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cover_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=800,min_height=500'],
            'image_rights_confirmed' => ['required_with:cover_image', 'accepted'],
            'remove_cover_image' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
