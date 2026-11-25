@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Feedback questions', $event->title]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('The questions attendees answer'))

@section('content')
<div class="d-grid gap-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <a href="{{ route('events.manage.edit', $event->uid) }}" class="small text-decoration-none"><i class="fas fa-fw fa-arrow-left"></i> {{ $event->title }}</a>
        <div class="d-flex gap-2">
            <a href="{{ route('events.manage.feedback.results', $event->uid) }}" class="btn btn-sm btn-outline-secondary">Results</a>
            <a href="{{ route('events.manage.feedback.poster', $event->uid) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">QR poster</a>
        </div>
    </div>

    @if ($responses > 0)
        <div class="alert alert-warning small mb-0">
            {{ $responses }} {{ \Illuminate\Support\Str::plural('person has', $responses) }} answered already. Removing a question also removes its answers.
        </div>
    @endif

    {{-- The questions (form schema event-feedback-questions, a repeater). --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            @include('userinterface::components.form', [
                'id' => \Iquesters\Event\Support\EventForms::QUESTIONS,
                'entity_uid' => $event->uid,
                'formData' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            ])
        </div>
    </div>

    {{-- Start again from the event type's set (form schema event-feedback-preset). --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <p class="small text-muted mb-2">Replace the questions with the set for {{ strtolower($event->type ?: 'general') }} events.</p>
            @include('userinterface::components.form', [
                'id' => \Iquesters\Event\Support\EventForms::QUESTIONS_PRESET,
                'entity_uid' => $event->uid,
            ])
        </div>
    </div>
</div>
@endsection
