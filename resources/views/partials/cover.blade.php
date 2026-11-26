{{--
    The event's cover: its image, else one drawn in the theme's colours with the
    title and the date. $ratio: CSS aspect ratio (default square), $class: extra classes.
--}}
@php
    [, $from, $to] = $event->themeColors();
    $ratio = $ratio ?? '1 / 1';
    $cover = $event->coverUrl();
@endphp
<div class="event-cover position-relative overflow-hidden {{ $class ?? '' }}" style="aspect-ratio: {{ $ratio }}; background: linear-gradient(135deg, {{ $from }}, {{ $to }});">
    @if ($cover)
        <img src="{{ $cover }}" alt="{{ $event->title }}" class="position-absolute top-0 start-0 w-100 h-100" style="object-fit: cover;">
    @else
        {{-- Soft shapes and the title, like a poster. --}}
        <div class="position-absolute rounded-circle" style="width: 70%; aspect-ratio: 1; right: -18%; top: -22%; background: rgba(255,255,255,.14);"></div>
        <div class="position-absolute rounded-circle" style="width: 45%; aspect-ratio: 1; left: -12%; bottom: -14%; background: rgba(255,255,255,.10);"></div>
        <div class="position-absolute rounded-circle" style="width: 18%; aspect-ratio: 1; left: 18%; top: 14%; background: rgba(255,255,255,.12);"></div>
        <div class="position-absolute bottom-0 start-0 w-100 p-4 text-white">
            <div class="text-uppercase fw-semibold" style="letter-spacing: .12em; font-size: .75rem; opacity: .85;">{{ $event->starts_at->format('D, j M Y') }}</div>
            <div class="fw-bold lh-sm mt-1" style="font-size: clamp(1.4rem, 4.5vw, 2.4rem); text-wrap: balance;">{{ $event->title }}</div>
            @if ($event->organisation?->name)<div class="mt-2" style="opacity: .85;">{{ $event->organisation->name }}</div>@endif
        </div>
    @endif
</div>
