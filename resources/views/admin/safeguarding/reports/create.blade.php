@extends('admin.layout')

@section('title', 'Report safeguarding concern')
@section('section', 'SAFEGUARDING REPORT')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">CONFIDENTIAL SAFEGUARDING</p>
            <h1>Submit a restricted report</h1>
            <p class="field-help">Report a consent, coercion, exploitation, trafficking, minor-safety, non-consensual-content, threat or privacy concern. Do not include identity-document numbers, passwords, payment secrets or unnecessary personal data. Only the assigned safeguarding specialist can read the report details.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="admin-alert error">{{ $errors->first() }}</div>
    @endif

    <section class="admin-panel">
        @if($specialists->isEmpty())
            <p class="field-help">No active safeguarding specialist with MFA enrollment is available. The report has not been accepted. Escalate through the deployment's approved emergency safeguarding contact.</p>
        @else
            <form method="POST" action="{{ route('admin.safeguarding.reports.store') }}" class="admin-form-grid">
                @csrf
                <label>Case category
                    <select name="case_type" required>
                        <option value="consent_concern">Consent concern</option>
                        <option value="coercion_or_exploitation">Coercion or exploitation</option>
                        <option value="trafficking_concern">Trafficking concern</option>
                        <option value="minor_safety_concern">Minor-safety concern</option>
                        <option value="nonconsensual_content">Non-consensual content</option>
                        <option value="threat_or_harassment">Threat or harassment</option>
                        <option value="privacy_or_data_risk">Privacy or data risk</option>
                        <option value="other">Other safeguarding concern</option>
                    </select>
                </label>
                <label>Severity
                    <select name="severity" required>
                        <option value="critical">Critical — immediate risk</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                </label>
                <label>Related record type
                    <select name="subject_type">
                        <option value="">Not linked / unknown</option>
                        <option value="model_application">Model application</option>
                        <option value="companion_profile">Companion profile</option>
                        <option value="booking">Booking</option>
                        <option value="client">Client</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>Related record reference<input name="subject_reference" maxlength="160" autocomplete="off"></label>
                <label class="full-width">Safeguarding specialist
                    <select name="assigned_to" required>
                        <option value="">Select an MFA-enrolled specialist</option>
                        @foreach($specialists as $specialist)
                            <option value="{{ $specialist->id }}">{{ $specialist->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="full-width">Short summary<input name="summary" minlength="20" maxlength="300" required></label>
                <label class="full-width">Confidential report details<textarea name="details" minlength="30" maxlength="5000" rows="8" required></textarea></label>
                <div class="full-width"><button class="button button-dark" type="submit">Submit confidential report</button></div>
            </form>
        @endif
    </section>
@endsection
