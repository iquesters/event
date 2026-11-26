@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make([$event->title]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description(\Illuminate\Support\Str::limit((string) $event->description, 150) ?: $event->title))

@section('content')
@php
    $R = \Iquesters\Event\Models\EventRegistration::class;
    $money = fn ($v) => '₹'.rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $paidOpen = $event->is_paid && ! $registration && ! $closedReason && $mayRegister;
@endphp
<div class="d-grid gap-3">
    @if (session('success'))
        <div class="alert alert-success small mb-0">{{ session('success') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            @include('event::partials.details', ['event' => $event])
        </div>

        {{-- Registration --}}
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-grid gap-2">
                    <h6 class="fs-6 mb-0">Registration</h6>
                    @if ($event->capacity !== null)
                        <div class="small text-muted">{{ $event->seatsLeft() }} of {{ $event->capacity }} places left</div>
                    @endif

                    @if ($event->is_paid)
                        {{-- What it costs --}}
                        <ul class="list-unstyled small mb-0">
                            @if ($prices['bundle'] !== null)<li class="d-flex justify-content-between"><span>Alumnus (pass with membership)</span><span class="fw-semibold">{{ $money($prices['bundle']) }}</span></li>@endif
                            @if ($prices['pass'] !== null)<li class="d-flex justify-content-between"><span>Guest over {{ $event->paid('free_child_age') }}</span><span class="fw-semibold">{{ $money($prices['pass']) }}</span></li>@endif
                            <li class="d-flex justify-content-between"><span>Child {{ $event->paid('free_child_age') }} or under</span><span class="fw-semibold">Free</span></li>
                        </ul>
                    @endif

                    @if ($registration)
                        @php($order = $registration->order)
                        <div class="alert {{ $registration->status === $R::STATUS_REGISTERED ? 'alert-success' : 'alert-warning' }} small py-2 mb-0">
                            @if ($registration->status === $R::STATUS_REGISTERED)
                                <i class="fas fa-fw fa-circle-check"></i> You're registered
                            @elseif ($registration->status === $R::STATUS_UNDER_REVIEW)
                                <i class="fas fa-fw fa-hourglass-half"></i> Payment sent: the organisers are checking it
                            @else
                                <i class="fas fa-fw fa-indian-rupee-sign"></i> Registered: waiting for your payment
                            @endif
                            @if ($registration->guests) · {{ 1 + $registration->guests }} people @endif
                            @if ($order)<div class="mt-1">Order <span class="font-monospace">{{ $order->reference }}</span> · {{ $money($order->total) }}</div>@endif
                        </div>
                        @if ($payUrl)
                            <a href="{{ $payUrl }}" class="btn btn-sm btn-primary">{{ $registration->status === $R::STATUS_UNDER_REVIEW ? 'See payment' : 'Pay now' }}</a>
                        @endif
                        @if (! $event->isPast() && $event->status !== \Iquesters\Event\Models\Event::STATUS_CANCELLED && ! ($order && $order->isPaid() && (float) $order->total > 0))
                            {{-- Form schema event-registration-cancel (a paid registration only before it's paid). --}}
                            @include('userinterface::components.form', ['id' => \Iquesters\Event\Support\EventForms::CANCEL_REGISTRATION, 'entity_uid' => $event->uid, 'formData' => ''])
                        @endif
                    @elseif ($closedReason)
                        <div class="text-muted small">{{ $closedReason }}</div>
                        @if ($event->is_paid && $membershipUrl)
                            <div class="small">Not a member yet? <a href="{{ $membershipUrl }}">Become a member of {{ $event->organisation?->name }}</a>.</div>
                        @endif
                    @elseif (! $mayRegister)
                        <div class="text-muted small">Only members of {{ $event->organisation?->name }} can register.</div>
                    @elseif ($event->is_paid)
                        <a href="#register" class="btn btn-sm btn-primary">Register below</a>
                    @else
                        {{-- Form schema event-registration (guests, note). --}}
                        @include('userinterface::components.form', ['id' => \Iquesters\Event\Support\EventForms::REGISTRATION, 'entity_uid' => $event->uid, 'formData' => json_encode(['guests' => 0])])
                    @endif

                    {{-- Feedback: attendees once it has started; the rating once it is over. --}}
                    @if ($rating)
                        <div class="small"><i class="fas fa-star text-warning"></i> <span class="fw-semibold">{{ $rating['average'] }}</span> / 5
                            <span class="text-muted">from {{ $rating['responses'] }} {{ \Illuminate\Support\Str::plural('response', $rating['responses']) }}</span></div>
                    @endif
                    @if (! $feedbackClosed)
                        <a href="{{ route('events.feedback.show', $event->uid) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-fw fa-star"></i> Give feedback</a>
                    @endif

                    @if ($canManage)
                        <hr class="my-2">
                        <div class="d-flex gap-2">
                            <a href="{{ route('events.manage.edit', $event->uid) }}" class="btn btn-sm btn-outline-secondary flex-fill">Edit</a>
                            <a href="{{ route('events.manage.attendees', $event->uid) }}" class="btn btn-sm btn-outline-secondary flex-fill">Attendees</a>
                            @if ($event->feedback_enabled)
                                <a href="{{ route('events.manage.feedback.results', $event->uid) }}" class="btn btn-sm btn-outline-secondary flex-fill">Feedback</a>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($paidOpen)
        {{-- The paid registration form (form schema event-paid-registration-{uid}): you, your guests, donation, terms → the pay page. --}}
        <div class="card border-0 shadow-sm" id="register">
            <div class="card-body">
                <h5 class="fs-6 mb-1">Register</h5>
                <p class="small text-muted mb-3">Add everyone coming with you. You pay for everyone at once on the next page (UPI); your registration is confirmed when the organisers verify the payment.</p>
                @include('userinterface::components.form', ['id' => $paidFormId, 'entity_uid' => $event->uid, 'formData' => json_encode($paidFormData, JSON_UNESCAPED_UNICODE)])
            </div>
        </div>
    @endif
</div>
@endsection
