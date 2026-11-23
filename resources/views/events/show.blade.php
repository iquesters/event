@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make([$event->title]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description(\Illuminate\Support\Str::limit((string) $event->description, 150) ?: $event->title))

@section('content')
<div class="d-grid gap-3">
    @if (session('error'))
        <div class="alert alert-danger py-2 small mb-0">{{ session('error') }}</div>
    @endif
    @if (session('success'))
        <div class="alert alert-success py-2 small mb-0">{{ session('success') }}</div>
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

                    @if ($registration)
                        <div class="alert alert-success small py-2 mb-0">
                            <i class="fas fa-fw fa-circle-check"></i> You're registered
                            @if ($registration->guests) with {{ $registration->guests }} {{ \Illuminate\Support\Str::plural('guest', $registration->guests) }}@endif.
                        </div>
                        @if (! $event->isPast() && $event->status !== \Iquesters\Event\Models\Event::STATUS_CANCELLED)
                            <form method="POST" action="{{ route('events.cancel', $event->uid) }}" onsubmit="return confirm('Cancel your registration?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger w-100">Cancel my registration</button>
                            </form>
                        @endif
                    @elseif ($closedReason)
                        <div class="text-muted small">{{ $closedReason }}</div>
                    @elseif (! $mayRegister)
                        <div class="text-muted small">Only members of {{ $event->organisation?->name }} can register.</div>
                    @else
                        <form method="POST" action="{{ route('events.register', $event->uid) }}" class="d-grid gap-2">
                            @csrf
                            <div>
                                <label for="guests" class="form-label small mb-1">Guests coming with you</label>
                                <input id="guests" type="number" name="guests" min="0" max="{{ \Iquesters\Event\Models\EventRegistration::MAX_GUESTS }}" value="{{ old('guests', 0) }}" class="form-control form-control-sm">
                            </div>
                            <div>
                                <label for="note" class="form-label small mb-1">Note <span class="text-muted">(optional)</span></label>
                                <input id="note" type="text" name="note" maxlength="500" value="{{ old('note') }}" class="form-control form-control-sm" placeholder="e.g. dietary needs">
                            </div>
                            <button class="btn btn-sm btn-primary">Register</button>
                        </form>
                    @endif

                    @if ($canManage)
                        <hr class="my-2">
                        <div class="d-flex gap-2">
                            <a href="{{ route('events.manage.edit', $event->uid) }}" class="btn btn-sm btn-outline-secondary flex-fill">Edit</a>
                            <a href="{{ route('events.manage.attendees', $event->uid) }}" class="btn btn-sm btn-outline-secondary flex-fill">Attendees</a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
