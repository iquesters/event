@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Attendees', $event->title]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Who registered'))

@php
    $registered = $registrations->where('status', \Iquesters\Event\Models\EventRegistration::STATUS_REGISTERED);
    $people = $registered->count() + $registered->sum('guests');
@endphp

@section('content')
<div class="d-grid gap-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <a href="{{ route('events.manage.edit', $event->uid) }}" class="small text-decoration-none"><i class="fas fa-fw fa-arrow-left"></i> {{ $event->title }}</a>
        <a href="{{ route('events.manage.export', $event->uid) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-download"></i> Download CSV</a>
    </div>

    <p class="small text-muted mb-0">
        {{ $registered->count() }} registered · {{ $people }} people with guests
        @if ($event->capacity) · {{ $event->capacity }} places @endif
    </p>

    <div class="card border-0 shadow-sm">
        @if ($registrations->isEmpty())
            <div class="card-body text-muted small">Nobody has registered yet.</div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Name</th><th class="d-none d-md-table-cell">Email</th><th class="text-end">Guests</th><th class="d-none d-md-table-cell">Note</th><th>Status</th><th class="d-none d-md-table-cell">When</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($registrations as $registration)
                            <tr @if ($registration->status !== 'registered') class="text-muted" @endif>
                                <td>{{ $registration->user?->name ?? '—' }}</td>
                                <td class="d-none d-md-table-cell">{{ $registration->user?->email }}</td>
                                <td class="text-end">{{ $registration->guests }}</td>
                                <td class="d-none d-md-table-cell">{{ $registration->note }}</td>
                                <td>{{ \Illuminate\Support\Str::headline($registration->status) }}</td>
                                <td class="d-none d-md-table-cell text-nowrap">{{ $registration->created_at?->format('j M, g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
