@extends('discovery.layout')
@section('title', 'Contact')
@section('robots', 'noindex,nofollow')
@section('content')
    <section class="discovery-intro"><p class="eyebrow">A PRIVATE CONVERSATION</p><h1>Make an<br><em>enquiry.</em></h1><p>Send a discreet message to the team. Please share only the information needed for us to respond.</p></section>
    @if (session('enquiry_received'))
        <div class="form-success contact-success" role="status">Thank you. Your enquiry has been received. The team will review it and respond using the contact details you supplied.</div>
    @else
        <form class="contact-form" method="POST" action="{{ route('enquiries.store') }}">
            @csrf
            @if ($errors->any())<div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="honeypot" aria-hidden="true"><label for="website">Leave this field empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
            <label for="name">Your name</label><input id="name" name="name" required maxlength="100" autocomplete="name" value="{{ old('name') }}">
            <label for="email">Email address</label><input id="email" name="email" type="email" required maxlength="254" autocomplete="email" value="{{ old('email') }}">
            <label for="phone">Phone (optional)</label><input id="phone" name="phone" maxlength="40" autocomplete="tel" value="{{ old('phone') }}">
            <label for="destination_id">Destination (optional)</label><select id="destination_id" name="destination_id"><option value="">Not specified</option>@foreach ($destinations as $destination)<option value="{{ $destination->id }}" @selected(old('destination_id') == $destination->id)>{{ $destination->name }}</option>@endforeach</select>
            <label for="subject">Subject</label><input id="subject" name="subject" required maxlength="160" value="{{ old('subject') }}">
            <label for="message">Message</label><textarea id="message" name="message" rows="6" minlength="10" maxlength="5000" required>{{ old('message') }}</textarea>
            <label class="privacy-check"><input type="checkbox" name="privacy_acknowledged" value="1" required @checked(old('privacy_acknowledged'))> I acknowledge the <a href="{{ route('privacy') }}">privacy notice</a> and agree that the team may use these details to respond to this enquiry.</label>
            <p class="field-help">Do not submit identity documents, payment credentials, private addresses or sensitive personal information through this form.</p>
            <button class="button button-dark" type="submit">Send enquiry ↗</button>
        </form>
    @endif
@endsection
