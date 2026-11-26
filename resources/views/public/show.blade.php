<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->title }} · {{ $event->organisation?->name }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit((string) $event->description, 160) ?: $event->title }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.0/css/bootstrap.min.css" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
</head>
<body class="bg-body-tertiary">
<main class="container py-4 d-grid gap-3" style="max-width: 860px;">
    @include('event::partials.details', ['event' => $event])

    <div class="card border-0 shadow-sm">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2 small">
            @if ($closedReason)
                <span class="text-muted">{{ $closedReason }}</span>
                @if ($event->is_paid && \Illuminate\Support\Facades\Route::has('community.membership') && ! $event->isPast())
                    <a href="{{ route('community.membership', ['organisation' => $event->organisation?->uid]) }}" class="btn btn-sm btn-outline-primary">Become a member</a>
                @endif
            @else
                <span>Want to come? Sign in to register.@if ($event->is_paid) <span class="text-muted">Tickets are paid by UPI after registering.</span>@endif</span>
                <a href="{{ route('events.show', $event->uid) }}" class="btn btn-sm btn-primary">Register</a>
            @endif
        </div>
    </div>
</main>
</body>
</html>
