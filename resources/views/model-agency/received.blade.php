@extends('portal.layout')
@section('title', 'Application received')
@section('content')
    <p class="eyebrow">APPLICATION RECEIVED</p>
    <h1>Thank you for<br><em>your interest.</em></h1>
    <p class="admin-muted">Your model-agency application has been received. The agency will review the information submitted. Submission is not acceptance, does not create a representation agreement and does not guarantee work.</p>
    <p class="admin-muted">Please do not send identity documents, sensitive personal data or additional photographs by unencrypted email. If further information is required, the agency will provide an approved secure channel.</p>
    <a class="button button-dark" href="{{ route('home') }}">Return to home ↗</a>
@endsection
