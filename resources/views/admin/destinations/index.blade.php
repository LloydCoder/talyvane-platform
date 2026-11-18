@extends('admin.layout')

@section('title', 'Destinations')
@section('section', 'DISCOVERY')

@section('content')
    <div class="admin-page-heading compact">
        <p class="eyebrow">DISCOVERY CONFIGURATION</p>
        <h1>Market<br><em>destinations.</em></h1>
        <p>Control which cities appear in public discovery. Inactive destinations and their profiles remain unpublished.</p>
    </div>
    <div class="admin-list-toolbar">
        <span>{{ $destinations->total() }} configured destinations</span>
        <a class="button button-dark" href="{{ route('admin.destinations.create') }}">Add destination <span>↗</span></a>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Destination</th><th>Country</th><th>Currency</th><th>Timezone</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($destinations as $destination)
                <tr>
                    <td><strong>{{ $destination->city_name }}</strong><small>{{ $destination->name }}</small></td>
                    <td>{{ $destination->country_name }} <small>{{ $destination->country_code }}</small></td>
                    <td>{{ $destination->currency_code }}</td>
                    <td>{{ $destination->timezone }}</td>
                    <td><span class="status-pill {{ $destination->is_active ? 'status-active' : 'status-inactive' }}">{{ $destination->is_active ? 'Published' : 'Inactive' }}</span></td>
                    <td><a class="table-action" href="{{ route('admin.destinations.edit', $destination) }}">Edit ↗</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty-table">No destinations yet. Add the first operating city.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination-wrap">{{ $destinations->links() }}</div>
@endsection
