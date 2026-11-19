@extends('admin.layout')
@section('title', 'Site pages')
@section('section', 'CONTENT')
@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">CONTENT MANAGEMENT</p><h1>Site<br><em>pages.</em></h1><p>Create and publish deployment-specific content. Body content is plain text and safely escaped on the public site.</p></div>
    <div class="admin-list-toolbar"><span>{{ $pages->total() }} pages</span><a class="button button-dark" href="{{ route('admin.pages.create') }}">New page ↗</a></div>
    <div class="admin-page-list">
        @forelse ($pages as $page)
            <article class="admin-page-row"><div><span class="eyebrow">{{ $page->is_published ? 'PUBLISHED' : 'DRAFT' }}</span><h2>{{ $page->title }}</h2><p>/pages/{{ $page->slug }}</p></div><a class="text-link" href="{{ route('admin.pages.edit', $page) }}">Edit ↗</a></article>
        @empty
            <p class="empty-table">No custom pages created.</p>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $pages->links() }}</div>
@endsection
