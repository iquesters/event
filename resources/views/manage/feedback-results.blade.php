@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Feedback', $event->title]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('What attendees said'))

@use('Iquesters\Event\Models\EventFeedbackQuestion')

@section('content')
<div class="d-grid gap-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <a href="{{ route('events.manage.edit', $event->uid) }}" class="small text-decoration-none"><i class="fas fa-fw fa-arrow-left"></i> {{ $event->title }}</a>
        <div class="d-flex gap-2">
            <a href="{{ route('events.manage.feedback.questions', $event->uid) }}" class="btn btn-sm btn-outline-secondary">Questions</a>
            <a href="{{ route('events.manage.feedback.poster', $event->uid) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">QR poster</a>
            @if ($results['responses'] > 0)
                <a href="{{ route('events.manage.feedback.export', $event->uid) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-download"></i> CSV</a>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Overall</div>
                <div class="fs-4 fw-semibold">
                    @if ($results['overall'] !== null)<i class="fas fa-star text-warning fs-5"></i> {{ $results['overall'] }} <span class="fs-6 text-muted">/ 5</span>@else — @endif
                </div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Responses</div>
                <div class="fs-4 fw-semibold">{{ $results['responses'] }}</div>
            </div></div>
        </div>
    </div>

    @if ($results['responses'] === 0)
        <div class="card border-0 shadow-sm"><div class="card-body text-muted small">No feedback yet. Print the QR poster for the venue, or share the event page.</div></div>
    @else
        @foreach ($results['questions'] as $row)
            @php($question = $row['question'])
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2 mb-2">
                        <h6 class="fs-6 mb-0">{{ $question->title }}</h6>
                        <span class="small text-muted text-nowrap">{{ $row['count'] }} {{ \Illuminate\Support\Str::plural('answer', $row['count']) }}</span>
                    </div>

                    @if ($question->type === EventFeedbackQuestion::TYPE_RATING)
                        <div class="small mb-2"><i class="fas fa-star text-warning"></i> <span class="fw-semibold">{{ $row['average'] ?? '—' }}</span> / 5</div>
                        @foreach ($row['spread'] as $stars => $count)
                            @php($share = $row['count'] ? round($count / $row['count'] * 100) : 0)
                            <div class="d-flex align-items-center gap-2 small">
                                <span class="text-muted" style="width: 2.5rem;">{{ $stars }} <i class="fas fa-star"></i></span>
                                <div class="progress flex-grow-1" style="height: .5rem;" role="progressbar" aria-label="{{ $stars }} stars" aria-valuenow="{{ $share }}" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar bg-warning" style="width: {{ $share }}%"></div>
                                </div>
                                <span class="text-muted text-end" style="width: 2rem;">{{ $count }}</span>
                            </div>
                        @endforeach
                    @elseif ($question->type === EventFeedbackQuestion::TYPE_YES_NO)
                        <div class="small"><span class="text-success fw-semibold">Yes {{ $row['yes'] }}</span> · <span class="text-danger fw-semibold">No {{ $row['no'] }}</span></div>
                    @else
                        @forelse ($row['comments'] as $comment)
                            <div class="small py-2 border-bottom">
                                <div style="white-space: pre-line;">{{ $comment['text'] }}</div>
                                <div class="text-muted">{{ $comment['by'] }}</div>
                            </div>
                        @empty
                            <p class="small text-muted mb-0">No comments.</p>
                        @endforelse
                    @endif
                </div>
            </div>
        @endforeach
    @endif
</div>
@endsection
