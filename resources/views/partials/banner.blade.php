{{-- The event's banner: the image for the screen (desktop ≥ 992px, tablet ≥ 576px, else mobile); a missing size uses the next one. --}}
@php
    $banners = $event->bannerUrls();
    $desktop = $banners['desktop'] ?? $banners['tablet'] ?? $banners['mobile'] ?? null;
    $tablet = $banners['tablet'] ?? $desktop;
    $mobile = $banners['mobile'] ?? $tablet;
@endphp
@if ($desktop)
    <picture>
        <source media="(min-width: 992px)" srcset="{{ $desktop }}">
        <source media="(min-width: 576px)" srcset="{{ $tablet }}">
        <img src="{{ $mobile }}" alt="{{ $event->title }}" class="w-100 d-block" style="max-height: 420px; object-fit: cover;">
    </picture>
@endif
