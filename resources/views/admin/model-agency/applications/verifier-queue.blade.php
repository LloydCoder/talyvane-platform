@extends('layouts.admin')

@section('content')
    <section class="space-y-6">
        <header>
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Restricted review workspace</p>
            <h1 class="mt-2 text-3xl font-semibold">Adult eligibility verification</h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-600">Review only applications already in the agency review state. Inspect the applicant's identity document through the approved offline procedure; do not upload, photograph, copy or retain document details in this platform.</p>
        </header>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $errors->first() }}</div>
        @endif

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold">Pending eligibility checks</h2>
                <p class="mt-1 text-sm text-slate-500">Applicant email, contact details, portfolio, experience notes and other agency records are intentionally excluded.</p>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($applications as $application)
                    <article class="space-y-4 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold">{{ $application->full_name }}</p>
                                <p class="text-sm text-slate-500">{{ $application->reference }} · Policy {{ $application->adult_eligibility_policy_version }}</p>
                            </div>
                            <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800">Awaiting verification</span>
                        </div>
                        <form method="POST" action="{{ route('admin.adult-eligibility.verify', $application) }}" class="grid gap-3 md:grid-cols-2">
                            @csrf
                            <label class="flex items-start gap-2 text-sm"><input required type="checkbox" name="identity_matches_applicant" value="1"> Identity document matches the applicant</label>
                            <label class="flex items-start gap-2 text-sm"><input required type="checkbox" name="document_is_valid" value="1"> Document appears valid under the approved policy</label>
                            <label class="flex items-start gap-2 text-sm"><input required type="checkbox" name="meets_policy_age_threshold" value="1"> Applicant meets the policy's adult age threshold</label>
                            <input type="hidden" name="verification_method" value="manual_document_inspection">
                            <div class="md:col-span-2">
                                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Record eligibility verification</button>
                            </div>
                        </form>
                    </article>
                @empty
                    <p class="p-6 text-sm text-slate-500">No applications are awaiting adult eligibility verification.</p>
                @endforelse
            </div>
        </div>
        {{ $applications->links() }}
    </section>
@endsection
