@extends('layouts.app')

@section('title', 'Private casting review')

@section('content')
    <main class="container">
        <a href="{{ route('client.model-castings.index') }}">Back to invitations</a>
        <section class="page-heading">
            <p class="eyebrow">{{ $call->reference }} · PRIVATE CASTING</p>
            <h1>{{ $call->title }}</h1>
            <p>{{ $call->description }}</p>
            <p>{{ $call->requirements }}</p>
            <p>{{ $call->country_code }} · {{ $call->project_purpose }} · {{ $call->shoot_starts_at->format('d M Y') }}–{{ $call->shoot_ends_at->format('d M Y') }}</p>
        </section>

        <section class="content-grid">
            @forelse($submissions as $submission)
                <article class="content-card">
                    <p class="eyebrow">{{ $submission->reference }} · PRIVATE PREVIEW</p>
                    <img
                        src="{{ route('client.model-castings.media', $submission) }}"
                        alt="Confidential model portfolio preview"
                        loading="lazy"
                        draggable="false"
                        class="private-casting-preview"
                    >
                    <p>{{ $submission->application->experience_summary }}</p>
                    @php($review = $submission->clientReviews->first())
                    @if($review)
                        <p class="field-help">Your current advisory decision: {{ strtoupper($review->decision) }}</p>
                        <p>{{ $review->note }}</p>
                    @endif
                    <form method="POST" action="{{ route('client.model-castings.review', $submission) }}" class="stacked-form">
                        @csrf
                        <label>Advisory decision
                            <select name="decision" required>
                                <option value="shortlisted" @selected($review?->decision === 'shortlisted')>Shortlist for agency consideration</option>
                                <option value="declined" @selected($review?->decision === 'declined')>Do not progress</option>
                            </select>
                        </label>
                        <label>Feedback for the agency<textarea name="note" minlength="20" maxlength="1500" required>{{ $review?->note }}</textarea></label>
                        <button type="submit">Send advisory feedback</button>
                    </form>
                </article>
            @empty
                <p>No model submissions are currently licensed for this client review.</p>
            @endforelse
        </section>

        <p class="field-help">These previews are confidential and are provided only for this named casting review. Do not copy, publish, distribute or contact models directly. Your feedback is advisory; final model selection remains with the agency. Browser previews cannot prevent screenshots or external capture.</p>
    </main>
@endsection
