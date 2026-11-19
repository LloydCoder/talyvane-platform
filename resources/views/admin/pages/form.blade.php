@extends('admin.layout')
@section('title', $page->exists ? 'Edit page' : 'New page')
@section('section', 'CONTENT')
@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">CONTENT MANAGEMENT</p><h1>{{ $page->exists ? 'Edit' : 'Create' }}<br><em>page.</em></h1><p>Plain-text page content is escaped before rendering. Do not paste HTML or scripts.</p></div>
    <form class="settings-form" method="POST" action="{{ $page->exists ? route('admin.pages.update', $page) : route('admin.pages.store') }}">
        @csrf @if ($page->exists) @method('PUT') @endif
        @if ($errors->any())<div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <label for="title">Page title</label><input id="title" name="title" maxlength="120" required value="{{ old('title', $page->title) }}">
        <label for="slug">URL slug</label><input id="slug" name="slug" maxlength="140" required value="{{ old('slug', $page->slug) }}">
        <label for="excerpt">Short description</label><textarea id="excerpt" name="excerpt" rows="2" maxlength="320">{{ old('excerpt', $page->excerpt) }}</textarea>
        <label for="body">Page content</label><textarea id="body" name="body" rows="16" maxlength="50000" required>{{ old('body', $page->body) }}</textarea>
        <label for="seo_title">SEO title</label><input id="seo_title" name="seo_title" maxlength="70" value="{{ old('seo_title', $page->seo_title) }}">
        <label for="seo_description">SEO description</label><textarea id="seo_description" name="seo_description" rows="2" maxlength="170">{{ old('seo_description', $page->seo_description) }}</textarea>
        <label class="remember-row"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $page->is_published))> Publish this page publicly</label>
        <div class="settings-actions"><a class="text-link" href="{{ route('admin.pages.index') }}">Cancel</a><button class="button button-dark" type="submit">Save page ↗</button></div>
    </form>
@endsection
