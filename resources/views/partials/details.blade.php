{{-- An event's details (event page and public page). --}}
<div class="card border-0 shadow-sm overflow-hidden">
    @include('event::partials.cover', ['event' => $event, 'ratio' => '3 / 1'])
    <div class="card-body">
        <div class="d-flex flex-wrap gap-1 mb-2">
            @if ($event->type)<span class="badge text-bg-light">{{ $event->type }}</span>@endif
            @if ($event->status === \Iquesters\Event\Models\Event::STATUS_CANCELLED)
                <span class="badge text-bg-danger">Cancelled</span>
            @elseif ($event->isPast())
                <span class="badge text-bg-secondary">Past</span>
            @endif
        </div>
        <h1 class="fs-4 mb-1">{{ $event->title }}</h1>
        <div class="text-muted small mb-3">{{ $event->organisation?->name }}</div>

        <dl class="row small mb-3">
            <dt class="col-sm-3 text-muted fw-normal"><i class="fas fa-fw fa-calendar"></i> When</dt>
            <dd class="col-sm-9">
                {{ $event->starts_at->format('D, j M Y · g:i A') }}
                @if ($event->ends_at)
                    – {{ $event->ends_at->isSameDay($event->starts_at) ? $event->ends_at->format('g:i A') : $event->ends_at->format('D, j M Y · g:i A') }}
                @endif
            </dd>
            @if ($event->venue || $event->address)
                <dt class="col-sm-3 text-muted fw-normal"><i class="fas fa-fw fa-location-dot"></i> Where</dt>
                <dd class="col-sm-9" style="white-space: pre-line;">{{ $event->venue }}@if ($event->venue && $event->address){{ "\n" }}@endif{{ $event->address }}@if ($event->maps_url && ! $event->isOnline()) <a href="{{ $event->maps_url }}" target="_blank" rel="noopener nofollow" class="d-block small mt-1"><i class="fas fa-map"></i> Open in Maps</a>@endif</dd>
            @endif
            @if ($event->online_url)
                <dt class="col-sm-3 text-muted fw-normal"><i class="fas fa-fw fa-video"></i> Online</dt>
                <dd class="col-sm-9"><a href="{{ $event->online_url }}" target="_blank" rel="noopener nofollow">{{ $event->online_url }}</a></dd>
            @endif
            @if ($event->registration_closes_at)
                <dt class="col-sm-3 text-muted fw-normal"><i class="fas fa-fw fa-hourglass-end"></i> Register by</dt>
                <dd class="col-sm-9">{{ $event->registration_closes_at->format('j M Y, g:i A') }}</dd>
            @endif
        </dl>

        @if ($event->description)
            <div class="small" style="white-space: pre-line;">{{ $event->description }}</div>
        @endif
    </div>
</div>
