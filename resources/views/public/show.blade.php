<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->title }} · {{ $event->organisation?->name }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit((string) $event->description, 160) ?: $event->title }}">
    {{-- Shared links show the cover and the title. --}}
    <meta property="og:title" content="{{ $event->title }}">
    <meta property="og:description" content="{{ $event->starts_at->format('D, j M Y') }}{{ $event->organisation?->name ? ' · '.$event->organisation->name : '' }}">
    @if ($event->coverUrl())<meta property="og:image" content="{{ url($event->coverUrl()) }}">@endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.0/css/bootstrap.min.css" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    @php
        [, $from, $to] = $event->themeColors();
        $money = fn ($v) => '₹'.rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $ends = $event->ends_at;
        $sameDay = ! $ends || $ends->isSameDay($event->starts_at);
        // The map and "Open in Maps" only when the organisers gave the place's Google Maps link.
        $mapsUrl = ! $event->isOnline() && $event->maps_url ? $event->maps_url : null;
        $mapEmbed = $event->mapEmbedUrl();
        $cancelled = $event->status === \Iquesters\Event\Models\Event::STATUS_CANCELLED;
    @endphp
    <style>
        :root { --theme-from: {{ $from }}; --theme-to: {{ $to }}; }
        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            color: #1f2330;
            background:
                radial-gradient(60rem 40rem at 10% -10%, color-mix(in srgb, var(--theme-from) 22%, transparent), transparent 70%),
                radial-gradient(50rem 36rem at 110% 10%, color-mix(in srgb, var(--theme-to) 18%, transparent), transparent 70%),
                #f6f6f9;
            min-height: 100vh;
        }
        .glass { background: rgba(255, 255, 255, .78); backdrop-filter: blur(14px); border: 1px solid rgba(255, 255, 255, .9); border-radius: 1rem; box-shadow: 0 10px 30px rgba(20, 20, 40, .06); }
        .event-cover { border-radius: 1.25rem; box-shadow: 0 20px 45px rgba(20, 20, 40, .18); }
        .tile { width: 3rem; height: 3rem; border-radius: .75rem; border: 1px solid rgba(0, 0, 0, .08); background: #fff; flex-shrink: 0; overflow: hidden; }
        .tile-month { font-size: .6rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #fff; background: linear-gradient(135deg, var(--theme-from), var(--theme-to)); line-height: 1.1rem; }
        .tile-day { font-size: 1.15rem; font-weight: 700; line-height: 1.85rem; }
        .btn-theme { background: linear-gradient(135deg, var(--theme-from), var(--theme-to)); color: #fff; border: 0; border-radius: .75rem; padding: .7rem 1rem; font-weight: 600; }
        .btn-theme:hover { color: #fff; filter: brightness(1.08); }
        .section-label { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: #6b7083; }
        .avatar { width: 2rem; height: 2rem; border-radius: 50%; object-fit: cover; background: linear-gradient(135deg, var(--theme-from), var(--theme-to)); color: #fff; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }
        h1.event-title { font-weight: 800; letter-spacing: -.02em; line-height: 1.1; font-size: clamp(2rem, 5vw, 3rem); }
        @media (min-width: 992px) { .sticky-lg { position: sticky; top: 1.5rem; } }
    </style>
</head>
<body>
<header class="container py-3 d-flex justify-content-between align-items-center" style="max-width: 1080px;">
    <a href="{{ $organisationUrl ?? url('/') }}" class="d-flex align-items-center gap-2 text-decoration-none text-reset fw-semibold small">
        @if ($logoUrl)<img src="{{ $logoUrl }}" alt="" class="avatar">@else<span class="avatar small">{{ mb_substr((string) $event->organisation?->name, 0, 1) }}</span>@endif
        {{ $event->organisation?->name }}
    </a>
    @guest
        <a href="{{ route('events.show', ['uid' => $event->uid, 'register' => 1]) }}" class="small text-decoration-none fw-semibold" style="color: var(--theme-from);">Sign in</a>
    @endguest
</header>

<main class="container pb-5" style="max-width: 1080px;">
    <div class="row g-4 g-lg-5">
        {{-- The cover and who organises it --}}
        <div class="col-12 col-lg-5">
            <div class="sticky-lg d-grid gap-3">
                @include('event::partials.cover', ['event' => $event, 'ratio' => '1 / 1'])
                <div class="glass p-3">
                    <div class="section-label mb-2">Hosted by</div>
                    <a href="{{ $organisationUrl ?? '#' }}" class="d-flex align-items-center gap-2 text-decoration-none text-reset {{ $organisationUrl ? '' : 'pe-none' }}">
                        @if ($logoUrl)<img src="{{ $logoUrl }}" alt="" class="avatar">@else<span class="avatar small">{{ mb_substr((string) $event->organisation?->name, 0, 1) }}</span>@endif
                        <span class="fw-semibold">{{ $event->organisation?->name }}</span>
                    </a>
                    @if ($going > 0)
                        <div class="small text-muted mt-2"><i class="fas fa-user-group me-1"></i>{{ $going }} going</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- The details --}}
        <div class="col-12 col-lg-7">
            <div class="d-flex flex-wrap gap-2 mb-2">
                @if ($event->type)<span class="badge rounded-pill text-bg-light border">{{ $event->type }}</span>@endif
                @if ($cancelled)<span class="badge rounded-pill text-bg-danger">Cancelled</span>@elseif ($event->isPast())<span class="badge rounded-pill text-bg-secondary">Past event</span>@endif
            </div>
            <h1 class="event-title mb-4">{{ $event->title }}</h1>

            {{-- When --}}
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="tile text-center">
                    <div class="tile-month">{{ $event->starts_at->format('M') }}</div>
                    <div class="tile-day">{{ $event->starts_at->format('j') }}</div>
                </div>
                <div>
                    <div class="fw-semibold">{{ $event->starts_at->format('l, j F') }}{{ $sameDay ? '' : ' – '.$ends->format('l, j F') }}</div>
                    <div class="text-muted small">{{ $event->starts_at->format('g:i A') }}@if ($ends) – {{ $ends->format($sameDay ? 'g:i A' : 'j M, g:i A') }}@endif</div>
                </div>
            </div>

            {{-- Where --}}
            <div class="d-flex align-items-center gap-3 mb-4">
                <div class="tile d-flex align-items-center justify-content-center text-muted"><i class="fas {{ $event->isOnline() ? 'fa-video' : 'fa-location-dot' }}"></i></div>
                <div class="min-w-0">
                    @if ($event->isOnline())
                        <div class="fw-semibold">Online</div>
                        <div class="text-muted small">The link is shared with people who register.</div>
                    @else
                        <div class="fw-semibold">{{ $event->venue ?: 'To be announced' }}</div>
                        @if ($event->address)<div class="text-muted small text-truncate">{{ $event->address }}</div>@endif
                    @endif
                </div>
            </div>

            {{-- Registration --}}
            <div class="glass p-3 p-md-4 mb-4">
                <div class="section-label mb-3">Registration</div>
                @if ($tickets !== [])
                    <ul class="list-unstyled mb-3">
                        @foreach ($tickets as $ticket)
                            <li class="d-flex justify-content-between gap-3 py-2 {{ $loop->first ? '' : 'border-top' }}">
                                <div>
                                    <div class="fw-semibold">{{ $ticket['name'] }}</div>
                                    @if ($ticket['includes'])<div class="small text-muted">Includes {{ $ticket['includes'] }}</div>@endif
                                    <div class="small">@include('event::partials.ticket-membership', ['ticket' => $ticket, 'event' => $event, 'money' => $money])</div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-nowrap">{{ $ticket['price'] > 0 ? $money($ticket['price']) : 'Free' }}</div>
                                    {{-- Limited tickets: how many are left. --}}
                                    @if ($ticket['left'] !== null)
                                        <div class="small fw-semibold {{ $ticket['left'] === 0 ? 'text-danger' : ($ticket['left'] <= 10 ? 'text-warning-emphasis' : 'text-muted') }}">{{ $ticket['left'] === 0 ? 'Sold out' : $ticket['left'].' left' }}</div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($closedReason)
                    <div class="d-flex align-items-center gap-2 text-muted"><i class="fas fa-circle-info"></i><span>{{ $closedReason }}</span></div>
                    @if ($event->is_paid && $membershipUrl && ! $event->isPast() && ! $cancelled)
                        <a href="{{ $membershipUrl }}" class="btn btn-outline-secondary w-100 mt-3 rounded-3">Become a member of {{ $event->organisation?->name }}</a>
                    @endif
                @else
                    <p class="small text-muted mb-3">
                        @if ($event->capacity !== null){{ $event->seatsLeft() }} of {{ $event->capacity }} places left. @endif
                        {{ $event->is_paid ? 'Sign in, choose a ticket for you and each guest, then pay by UPI.' : 'Sign in and register; bring guests if you like.' }}
                    </p>
                    <a href="{{ route('events.show', ['uid' => $event->uid, 'register' => 1]) }}" class="btn btn-theme w-100">{{ $event->is_paid ? 'Get tickets' : 'Register' }}</a>
                    @if ($event->registration_closes_at)
                        <div class="small text-muted text-center mt-2">Registration closes {{ $event->registration_closes_at->format('j M Y, g:i A') }}</div>
                    @endif
                @endif
            </div>

            {{-- About --}}
            @if ($event->description)
                <div class="mb-4">
                    <div class="section-label mb-2">About</div>
                    <div style="white-space: pre-line;">{{ $event->description }}</div>
                </div>
            @endif

            {{-- Location --}}
            @if (! $event->isOnline() && ($event->venue || $event->address))
                <div>
                    <div class="section-label mb-2">Location</div>
                    <div class="fw-semibold">{{ $event->venue }}</div>
                    @if ($event->address)<div class="text-muted" style="white-space: pre-line;">{{ $event->address }}</div>@endif
                    @if ($mapEmbed)
                        <div class="ratio ratio-16x9 rounded-4 overflow-hidden shadow-sm mt-3">
                            <iframe src="{{ $mapEmbed }}" title="Map: {{ $event->venue }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" style="border: 0;"></iframe>
                        </div>
                    @endif
                    @if ($mapsUrl)<a href="{{ $mapsUrl }}" target="_blank" rel="noopener nofollow" class="small fw-semibold text-decoration-none d-inline-block mt-2" style="color: var(--theme-from);"><i class="fas fa-map me-1"></i>Open in Maps</a>@endif
                </div>
            @endif
        </div>
    </div>
</main>
</body>
</html>
