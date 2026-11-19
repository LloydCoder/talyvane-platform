<?php

namespace App\Http\Controllers\Admin;

use App\Models\SitePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SitePageController
{
    public function index(): View
    {
        return view('admin.pages.index', [
            'pages' => SitePage::query()->latest()->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.pages.form', ['page' => new SitePage()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePage($request);
        $validated['slug'] = Str::slug($validated['slug']);
        $validated['is_published'] = $request->boolean('is_published');
        $validated['created_by'] = auth()->id();
        $validated['updated_by'] = auth()->id();
        $validated['published_at'] = $validated['is_published'] ? now() : null;

        SitePage::query()->create($validated);

        return redirect()->route('admin.pages.index')->with('status', 'Page saved.');
    }

    public function edit(SitePage $page): View
    {
        return view('admin.pages.form', compact('page'));
    }

    public function update(Request $request, SitePage $page): RedirectResponse
    {
        $validated = $this->validatePage($request, $page);
        $validated['slug'] = Str::slug($validated['slug']);
        $validated['is_published'] = $request->boolean('is_published');
        $validated['updated_by'] = auth()->id();
        $validated['published_at'] = $validated['is_published'] ? ($page->published_at ?? now()) : null;

        $page->update($validated);

        return redirect()->route('admin.pages.index')->with('status', 'Page updated.');
    }

    private function validatePage(Request $request, ?SitePage $page = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'min:2', 'max:140', Rule::unique('site_pages', 'slug')->ignore($page?->id)],
            'excerpt' => ['nullable', 'string', 'max:320'],
            'body' => ['required', 'string', 'max:50000'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:170'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
    }
}
