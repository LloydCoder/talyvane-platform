@extends('admin.layout')

@section('title', 'Edit destination')
@section('section', 'DISCOVERY / EDIT')

@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">DISCOVERY CONFIGURATION</p><h1>Edit<br><em>destination.</em></h1><p>Changes affect public destination discovery and new profile assignment.</p></div>
    @include('admin.destinations.form', ['action' => route('admin.destinations.update', $destination), 'method' => 'PUT', 'destination' => $destination])
@endsection
