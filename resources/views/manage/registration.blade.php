@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Registration', $registration->displayName() ?? '', $event->title]))

@php
    $order = $registration->order;
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $age = $event->paid('free_child_age');
@endphp

@section('content')
<div class="d-grid gap-3">
    <a href="{{ route('events.manage.attendees', $event->uid) }}" class="small text-decoration-none"><i class="fas fa-fw fa-arrow-left"></i> Attendees of {{ $event->title }}</a>

    <div class="card border-0 shadow-sm">
        <div class="card-body small">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                <div>
                    <h5 class="fs-6 mb-1">{{ $registration->displayName() }}</h5>
                    <div class="text-muted">{{ $registration->email ?: $registration->user?->email }}@if ($registration->phone) · {{ $registration->phone }}@endif</div>
                    <div class="text-muted">Registered {{ $registration->created_at?->format('j M Y, g:i A') }}@if ($registration->source === 'admin') by an admin @endif</div>
                </div>
                <div class="text-end">
                    <div class="fw-semibold">{{ $registration->statusLabel() }}</div>
                    @if ($order)
                        <div class="font-monospace">{{ $order->reference }}</div>
                        <div>{{ $money($order->total) }} · {{ $order->statusLabel() }}</div>
                    @endif
                </div>
            </div>

            @if ($registration->people)
                <div class="table-responsive">
                    <table class="table table-sm mb-2">
                        <thead><tr class="text-muted"><th>Person</th><th>Gender</th><th>Age</th><th>Meal</th><th>Alumnus</th><th class="d-none d-md-table-cell">Contact</th></tr></thead>
                        <tbody>
                            @foreach ($registration->people as $person)
                                <tr>
                                    <td>{{ $person['name'] ?? '' }} @if (($person['role'] ?? '') === 'registrant')<span class="badge text-bg-light">registrant</span>@endif</td>
                                    <td>{{ \Iquesters\Event\Models\EventRegistration::GENDERS[$person['gender'] ?? ''] ?? '' }}</td>
                                    <td>{{ ($person['age_group'] ?? 'adult') === 'child' ? $age.' or under' : 'Adult' }}</td>
                                    <td>{{ \Iquesters\Event\Models\EventRegistration::MEALS[$person['meal'] ?? ''] ?? '' }}</td>
                                    <td>{{ ! empty($person['alumnus']) ? 'Yes' : '' }}</td>
                                    <td class="d-none d-md-table-cell">{{ $person['email'] ?? '' }}@if (! empty($person['phone'])) · {{ $person['phone'] }}@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mb-2">{{ 1 + $registration->guests }} {{ \Illuminate\Support\Str::plural('person', 1 + $registration->guests) }}</p>
            @endif
            @if ((float) $registration->donation > 0)<p class="mb-2">Donation: {{ $money($registration->donation) }}</p>@endif
            @if ($registration->note)<p class="mb-2 text-muted">Note: {{ $registration->note }}</p>@endif

            <div class="d-flex flex-wrap gap-2 align-items-center">
                @if ($payUrl)
                    <a href="{{ $payUrl }}" class="btn btn-sm btn-outline-primary">Pay page</a>
                    <span class="text-muted">Pay link to send: <span class="font-monospace text-break">{{ $payUrl }}</span></span>
                @endif
            </div>
        </div>
    </div>

    @if ($canCancel)
        <div class="card border-0 shadow-sm">
            <div class="card-body small">
                <h6 class="fs-6 mb-1">Cancel this registration</h6>
                <p class="text-muted mb-2">Frees its places{{ $order ? ' and cancels order '.$order->reference : '' }}. Paid registrations can't be cancelled here.</p>
                {{-- Form schema event-registration-admin-cancel; {uid} = the registration. --}}
                @include('userinterface::components.form', ['id' => \Iquesters\Event\Support\EventForms::ADMIN_CANCEL_REGISTRATION, 'entity_uid' => $registration->uid, 'formData' => ''])
            </div>
        </div>
    @endif
</div>
@endsection
