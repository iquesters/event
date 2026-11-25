@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Feedback', $event->title]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Tell us how '.$event->title.' went'))

@section('content')
{{-- Full width, like every other page in the app layout. --}}
<div class="d-grid gap-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted">{{ $event->organisation?->name }} · {{ $event->starts_at->format('j M Y') }}</div>
                <h5 class="fs-5 mb-0">{{ $event->title }}</h5>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                @if ($closedReason)
                    <p class="small mb-2">{{ $closedReason }}</p>
                    <a href="{{ route('events.show', $event->uid) }}" class="small">Back to the event</a>
                @else
                    {{-- The event's feedback form (form schema event-feedback-{uid}, built from its questions). --}}
                    @include('userinterface::components.form', ['id' => $formId, 'entity_uid' => $event->uid])
                @endif
            </div>
        </div>
</div>
@endsection
