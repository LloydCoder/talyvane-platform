@extends('portal.layout')
@section('title', 'Application received')
@section('content')
    <p class="eyebrow">APPLICATION RECEIVED</p>
    <h1>Thank you for<br><em>your interest.</em></h1>
    @if (session('application_reference'))
        <section class="portal-form-section" aria-labelledby="application-reference-heading">
            <h2 id="application-reference-heading">Your application reference</h2>
            <p><strong>{{ session('application_reference') }}</strong></p>
            <p class="admin-muted">Keep this reference. You will need it together with the email address submitted on your application to access the private applicant privacy-support workflow.</p>
        </section>
    @endif
    <p class="admin-muted">Your model-agency application has been received. The agency will review the information submitted. Submission is not acceptance, does not create a representation agreement and does not guarantee work.</p>
    <p class="admin-muted">Please do not send identity documents, sensitive personal data or additional photographs by unencrypted email. If further information is required, the agency will provide an approved secure channel.</p>
    <p class="admin-muted">To access, correct, withdraw or request erasure of this application, use the <a href="{{ route('model-agency.application-support') }}">secure applicant privacy support page</a>.</p>
    <a class="button button-dark" href="{{ route('home') }}">Return to home ↗</a>
@endsection
