@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make([$past ? 'Past events' : 'Events']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Events of your organisation'))

@php
    // Show each event's organisation only when the list mixes several.
    $manyOrganisations = $events->pluck('organisation_id')->unique()->count() > 1;
@endphp

@section('content')
<div class="d-grid gap-3">
    @if ($events->isEmpty())
        <div class="card border-0 shadow-sm"><div class="card-body text-muted small">
            {{ $past ? 'No past events.' : 'No upcoming events yet.' }}
        </div></div>
    @else
        <div class="row g-3">
            @foreach ($events as $event)
                <div class="col-12 col-md-6 col-xl-4">
                    <a href="{{ route('events.show', $event->uid) }}" class="card border-0 shadow-sm h-100 text-decoration-none text-body">
                        <div class="card-body d-flex gap-3">
                            {{-- Date block --}}
                            <div class="text-center rounded bg-primary-subtle text-primary flex-shrink-0 py-2" style="width: 3.5rem;">
                                <div class="small text-uppercase">{{ $event->starts_at->format('M') }}</div>
                                <div class="fs-4 fw-semibold lh-1">{{ $event->starts_at->format('j') }}</div>
                            </div>
                            <div style="min-width: 0;">
                                <div class="fw-semibold text-truncate">{{ $event->title }}</div>
                                <div class="small text-muted">
                                    {{ $event->starts_at->format('D, j M Y · g:i A') }}
                                    @if ($event->venue) · {{ $event->venue }} @elseif ($event->online_url) · Online @endif
                                </div>
                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    @if ($event->type)<span class="badge text-bg-light">{{ $event->type }}</span>@endif
                                    @if ($event->status === \Iquesters\Event\Models\Event::STATUS_CANCELLED)
                                        <span class="badge text-bg-danger">Cancelled</span>
                                    @elseif (in_array($event->id, $mine, true))
                                        <span class="badge text-bg-success"><i class="fas fa-check"></i> Registered</span>
                                    @endif
                                    @if ($manyOrganisations)
                                        <span class="badge text-bg-light">{{ $event->organisation?->name }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
        {{ $events->links() }}
    @endif
</div>
@endsection
