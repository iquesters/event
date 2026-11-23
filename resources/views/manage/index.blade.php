@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Manage events']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Create and manage events'))

@section('content')
<div class="d-grid gap-3">
    @if (session('success'))
        <div class="alert alert-success py-2 small mb-0">{{ session('success') }}</div>
    @endif

    <div class="d-flex justify-content-between align-items-center">
        <form method="GET" class="d-flex gap-2">
            <select name="status" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Status">
                <option value="">All statuses</option>
                @foreach (\Iquesters\Event\Models\Event::STATUSES as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ \Illuminate\Support\Str::headline($status) }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('events.manage.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-fw fa-plus"></i> New event</a>
    </div>

    <div class="card border-0 shadow-sm">
        @if ($events->isEmpty())
            <div class="card-body text-muted small">No events yet. <a href="{{ route('events.manage.create') }}">Create the first one</a>.</div>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Event</th>
                            <th>When</th>
                            <th class="d-none d-md-table-cell">Organisation</th>
                            <th>Status</th>
                            <th class="text-end">Registered</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td><a href="{{ route('events.manage.edit', $event->uid) }}" class="text-decoration-none">{{ $event->title }}</a>
                                    @if ($event->visibility === 'public')<span class="badge text-bg-light ms-1">Public</span>@endif</td>
                                <td class="text-nowrap">{{ $event->starts_at->format('j M Y, g:i A') }}</td>
                                <td class="d-none d-md-table-cell">{{ $event->organisation?->name }}</td>
                                <td>
                                    @php($label = $event->statusLabel())
                                    <span class="badge {{ ['Draft' => 'text-bg-secondary', 'Published' => 'text-bg-success', 'Cancelled' => 'text-bg-danger', 'Past' => 'text-bg-light'][$label] ?? 'text-bg-light' }}">{{ $label }}</span>
                                </td>
                                <td class="text-end">{{ $event->registered_count }}@if ($event->capacity) / {{ $event->capacity }}@endif</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('events.manage.attendees', $event->uid) }}" class="btn btn-sm btn-outline-secondary" title="Attendees"><i class="fas fa-users"></i></a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{ $events->links() }}
</div>
@endsection
