@extends('admin.layout')

@section('title', 'Add destination')
@section('section', 'DISCOVERY / NEW')

@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">DISCOVERY CONFIGURATION</p><h1>Add a<br><em>destination.</em></h1><p>Define a city, its market defaults and whether it is visible to the public.</p></div>
    @include('admin.destinations.form', ['action' => route('admin.destinations.store'), 'method' => 'POST', 'destination' => null])
@endsection
