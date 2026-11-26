{{-- Record section (Manage events table): the event's other pages. --}}
<div class="d-flex flex-wrap gap-2">
    <a href="{{ route('events.manage.attendees', $event->uid) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-users"></i> Attendees</a>
    @if ($event->status !== 'draft')
        <a href="{{ route('events.show', $event->uid) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-eye"></i> Event page</a>
    @endif
    @if ($event->isPublic())
        <a href="{{ route('events.public', $event->uid) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-globe"></i> Public page</a>
    @endif
    @if ($event->feedback_enabled)
        <a href="{{ route('events.manage.feedback.questions', $event->uid) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-list-check"></i> Feedback questions</a>
        <a href="{{ route('events.manage.feedback.results', $event->uid) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-star"></i> Feedback results</a>
        <a href="{{ route('events.manage.feedback.poster', $event->uid) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-qrcode"></i> QR poster</a>
    @endif
</div>
