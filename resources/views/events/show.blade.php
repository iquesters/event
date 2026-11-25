@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make([$event->title]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description(\Illuminate\Support\Str::limit((string) $event->description, 150) ?: $event->title))

@section('content')
<div class="d-grid gap-3">

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

                    @if ($registration)
                        <div class="alert alert-success small py-2 mb-0">
                            <i class="fas fa-fw fa-circle-check"></i> You're registered
                            @if ($registration->guests) with {{ $registration->guests }} {{ \Illuminate\Support\Str::plural('guest', $registration->guests) }}@endif.
                        </div>
                        @if (! $event->isPast() && $event->status !== \Iquesters\Event\Models\Event::STATUS_CANCELLED)
                            {{-- Form schema event-registration-cancel. --}}
                            @include('userinterface::components.form', ['id' => \Iquesters\Event\Support\EventForms::CANCEL_REGISTRATION, 'entity_uid' => $event->uid])
                        @endif
                    @elseif ($closedReason)
                        <div class="text-muted small">{{ $closedReason }}</div>
                    @elseif (! $mayRegister)
                        <div class="text-muted small">Only members of {{ $event->organisation?->name }} can register.</div>
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
</div>
@endsection
