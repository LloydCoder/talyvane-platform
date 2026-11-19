@extends('portal.layout')
@section('title', 'Request a booking')
@section('content')
    <p class="eyebrow">BOOKING REQUEST / {{ $profile->destination->name }}</p><h1>Request a<br><em>booking.</em></h1><p class="admin-muted">Your request will be reviewed. Automatic confirmation is only possible when every configured eligibility, consent, availability and payment condition is met.</p>
    @if ($errors->any())<div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="portal-placeholder"><strong>{{ $profile->display_name }}</strong><p>{{ $profile->summary }}</p><small>{{ $profile->destination->name }} · {{ $profile->destination->timezone }}</small></div>
    <form method="POST" action="{{ route('bookings.store') }}">
        @csrf
        <input type="hidden" name="profile_id" value="{{ $profile->id }}">
        <label for="start_at">Requested local date and time ({{ $profile->destination->timezone }})</label><input id="start_at" name="start_at" type="datetime-local" required value="{{ old('start_at') }}">
        <label for="duration_minutes">Duration</label><select id="duration_minutes" name="duration_minutes" required>@foreach ([30,60,90,120,180,240] as $duration)<option value="{{ $duration }}" @selected(old('duration_minutes', 60) == $duration)>{{ $duration }} minutes</option>@endforeach</select>
        <label for="client_note">Additional details (optional)</label><textarea id="client_note" name="client_note" rows="4" maxlength="2000">{{ old('client_note') }}</textarea>
        <p class="field-help">Do not include private addresses, identity documents or payment credentials. This is a request, not a confirmed booking.</p>
        <button class="button button-dark admin-submit" type="submit">Submit booking request ↗</button>
    </form>
@endsection
