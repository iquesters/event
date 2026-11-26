{{-- A ticket's membership (a bundle made in commerce): included at the same price, or its own price. --}}
@if (! empty($ticket['membership']))
    <div class="text-success-emphasis">
        <i class="fas fa-fw fa-id-card"></i>
        @if ($ticket['membership']['price'] === null)
            {{ $ticket['membership']['name'] }} included when you join {{ $event->organisation?->name }}
        @else
            With {{ $ticket['membership']['name'] }}: {{ $money($ticket['membership']['price']) }}
        @endif
    </div>
@endif
