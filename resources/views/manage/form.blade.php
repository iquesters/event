@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make([$event->exists ? 'Edit event' : 'New event']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Event details'))

@php
    $dt = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d\TH:i') : '';
    $v = fn (string $key) => old($key, $event->{$key});
@endphp

@section('content')
<form method="POST" action="{{ $event->exists ? route('events.manage.update', $event->uid) : route('events.manage.store') }}" class="d-grid gap-3">
    @csrf
    @if ($event->exists) @method('PUT') @endif

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <a href="{{ route('events.manage.index') }}" class="small text-decoration-none"><i class="fas fa-fw fa-arrow-left"></i> Events</a>
        <div class="d-flex gap-2">
            @if ($event->exists)
                @if ($event->status !== 'draft')<a href="{{ route('events.show', $event->uid) }}" class="btn btn-sm btn-outline-secondary">View</a>@endif
                @if ($event->isPublic())<a href="{{ route('events.public', $event->slug) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">Public page</a>@endif
                <a href="{{ route('events.manage.attendees', $event->uid) }}" class="btn btn-sm btn-outline-secondary">Attendees</a>
            @endif
            <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success py-2 small mb-0">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger py-2 small mb-0">Please check the highlighted fields.</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body row g-3">
            <div class="col-12 col-md-8">
                <label for="title" class="form-label small">Title <span class="text-danger">*</span></label>
                <input id="title" name="title" value="{{ $v('title') }}" required maxlength="255" class="form-control form-control-sm @error('title') is-invalid @enderror">
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-4">
                <label for="type" class="form-label small">Type</label>
                <select id="type" name="type" class="form-select form-select-sm">
                    <option value="">—</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected($v('type') === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label for="organisation_id" class="form-label small">Organisation <span class="text-danger">*</span></label>
                <select id="organisation_id" name="organisation_id" required class="form-select form-select-sm @error('organisation_id') is-invalid @enderror">
                    @if ($organisations->count() > 1)<option value="">Choose…</option>@endif
                    @foreach ($organisations as $organisation)
                        <option value="{{ $organisation->id }}" @selected((int) $v('organisation_id') === $organisation->id)>{{ $organisation->name }}</option>
                    @endforeach
                </select>
                @error('organisation_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-3">
                <label for="status" class="form-label small">Status</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    @foreach (['draft' => 'Draft (only admins see it)', 'published' => 'Published', 'cancelled' => 'Cancelled'] as $value => $label)
                        <option value="{{ $value }}" @selected($v('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="visibility" class="form-label small">Who can see it</label>
                <select id="visibility" name="visibility" class="form-select form-select-sm @error('visibility') is-invalid @enderror">
                    <option value="members" @selected($v('visibility') === 'members')>Members</option>
                    @if ($publicAllowed)<option value="public" @selected($v('visibility') === 'public')>Everyone (public page)</option>@endif
                </select>
            </div>
            <div class="col-12">
                <label for="description" class="form-label small">Description</label>
                <textarea id="description" name="description" rows="5" maxlength="10000" class="form-control form-control-sm">{{ $v('description') }}</textarea>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body row g-3">
            <h6 class="fs-6 mb-0">When and where</h6>
            <div class="col-12 col-md-6">
                <label for="starts_at" class="form-label small">Starts <span class="text-danger">*</span></label>
                <input id="starts_at" type="datetime-local" name="starts_at" value="{{ $dt($v('starts_at')) }}" required class="form-control form-control-sm @error('starts_at') is-invalid @enderror">
                @error('starts_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6">
                <label for="ends_at" class="form-label small">Ends</label>
                <input id="ends_at" type="datetime-local" name="ends_at" value="{{ $dt($v('ends_at')) }}" class="form-control form-control-sm @error('ends_at') is-invalid @enderror">
                @error('ends_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6">
                <label for="venue" class="form-label small">Venue</label>
                <input id="venue" name="venue" value="{{ $v('venue') }}" maxlength="255" class="form-control form-control-sm" placeholder="e.g. School auditorium">
            </div>
            <div class="col-12 col-md-6">
                <label for="online_url" class="form-label small">Online link</label>
                <input id="online_url" type="url" name="online_url" value="{{ $v('online_url') }}" placeholder="https://" class="form-control form-control-sm @error('online_url') is-invalid @enderror">
                @error('online_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label for="address" class="form-label small">Address</label>
                <textarea id="address" name="address" rows="2" maxlength="1000" class="form-control form-control-sm">{{ $v('address') }}</textarea>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body row g-3">
            <h6 class="fs-6 mb-0">Registration</h6>
            <div class="col-12 col-md-4">
                <div class="form-check form-switch mt-md-4">
                    <input type="hidden" name="registration_open" value="0">
                    <input id="registration_open" type="checkbox" role="switch" name="registration_open" value="1" class="form-check-input" @checked($v('registration_open'))>
                    <label for="registration_open" class="form-check-label small">Registration open</label>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <label for="capacity" class="form-label small">Places <span class="text-muted">(empty: no limit)</span></label>
                <input id="capacity" type="number" name="capacity" min="1" value="{{ $v('capacity') }}" class="form-control form-control-sm @error('capacity') is-invalid @enderror">
            </div>
            <div class="col-6 col-md-4">
                <label for="registration_closes_at" class="form-label small">Register by</label>
                <input id="registration_closes_at" type="datetime-local" name="registration_closes_at" value="{{ $dt($v('registration_closes_at')) }}" class="form-control form-control-sm @error('registration_closes_at') is-invalid @enderror">
                @error('registration_closes_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>
</form>
@endsection
