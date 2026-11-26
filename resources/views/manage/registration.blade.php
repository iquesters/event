@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Registration', $registration->displayName() ?? '', $event->title]))

@php
    $order = $registration->order;
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

@section('content')
<div class="d-grid gap-3">
    <a href="{{ route('events.manage.attendees', $event->uid) }}" class="small text-decoration-none"><i class="fas fa-fw fa-arrow-left"></i> Attendees of {{ $event->title }}</a>

    @include('event::manage.partials.registration-card', ['event' => $event, 'registration' => $registration, 'payUrl' => $payUrl])

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
