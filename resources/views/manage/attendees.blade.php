@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Attendees', $event->title]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Who registered'))

@php
    $R = \Iquesters\Event\Models\EventRegistration::class;
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $badge = fn ($status) => match ($status) {
        $R::STATUS_REGISTERED => 'text-bg-success',
        $R::STATUS_UNDER_REVIEW => 'text-bg-info',
        $R::STATUS_AWAITING_PAYMENT => 'text-bg-warning',
        default => 'text-bg-secondary',
    };
@endphp

@section('content')
<div class="d-grid gap-3">
    @if (session('success'))<div class="alert alert-success small mb-0">{{ session('success') }}</div>@endif

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <a href="{{ route('events.manage.edit', $event->uid) }}" class="small text-decoration-none"><i class="fas fa-fw fa-arrow-left"></i> {{ $event->title }}</a>
        <div class="d-flex gap-2">
            @if ($addFormId)
                <a href="#add-attendee" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="add-attendee"><i class="fas fa-fw fa-user-plus"></i> Add attendee</a>
            @endif
            <a href="{{ route('events.manage.export', $event->uid) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-download"></i> Download CSV</a>
        </div>
    </div>

    {{-- Head counts (paid: confirmed only, for the caterer). --}}
    <div class="row g-2 small">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted">Registrations</div><div class="fs-5 fw-semibold">{{ $totals['registrations'] }}</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted">People @if ($event->is_paid)(confirmed)@endif</div><div class="fs-5 fw-semibold">{{ $event->is_paid ? $totals['confirmed_people'].' / '.$totals['people'] : $totals['people'] }}</div>@if ($event->capacity)<div class="text-muted">{{ $event->capacity }} places</div>@endif</div></div></div>
        @if ($event->is_paid)
            <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted">Meals (confirmed)</div><div class="fw-semibold">{{ $totals['veg'] }} veg · {{ $totals['non_veg'] }} non-veg</div><div class="text-muted">{{ $totals['adults'] }} adults · {{ $totals['children'] }} children</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted">Paid</div><div class="fs-5 fw-semibold">{{ $money($totals['paid']) }}</div></div></div></div>
        @endif
    </div>

    @if ($addFormId)
        {{-- Form schema event-add-attendee-{uid}: crew and invited guests (free), or people paying at the desk. --}}
        <div class="collapse" id="add-attendee">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fs-6 mb-1">Add attendee</h6>
                    <p class="small text-muted mb-3">For crew and invited guests tick "free". Otherwise an order is made: record the cash in Payments → Record a payment (its reference is shown after adding), or send them the pay link from their registration.</p>
                    @include('userinterface::components.form', ['id' => $addFormId, 'entity_uid' => $event->uid, 'formData' => json_encode(['alumnus' => false, 'guests' => []])])
                </div>
            </div>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        @if ($registrations->isEmpty())
            <div class="card-body text-muted small">Nobody has registered yet.</div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            @if ($event->is_paid)<th>Order</th>@endif
                            <th class="text-end">People</th>
                            @if ($event->is_paid)<th class="d-none d-md-table-cell">Meals</th><th class="text-end d-none d-md-table-cell">Total</th>@endif
                            <th>Status</th>
                            <th class="d-none d-md-table-cell">When</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($registrations as $registration)
                            <tr @if (! $registration->isActive()) class="text-muted" @endif>
                                <td>
                                    {{ $registration->displayName() ?? '—' }}
                                    <div class="text-muted d-none d-md-block">{{ $registration->email ?: $registration->user?->email }}@if ($registration->phone) · {{ $registration->phone }}@endif</div>
                                    @if ($registration->source === 'admin')<span class="badge text-bg-light">added by admin</span>@endif
                                </td>
                                @if ($event->is_paid)<td class="font-monospace">{{ $registration->order?->reference }}</td>@endif
                                <td class="text-end">{{ 1 + $registration->guests }}@if ($registration->children) <span class="text-muted">({{ $registration->children }} child)</span>@endif</td>
                                @if ($event->is_paid)
                                    <td class="d-none d-md-table-cell">{{ $registration->veg }} veg · {{ $registration->non_veg }} non-veg</td>
                                    <td class="text-end d-none d-md-table-cell">{{ $registration->order ? $money($registration->order->total) : '' }}</td>
                                @endif
                                <td><span class="badge {{ $badge($registration->status) }}">{{ $registration->statusLabel() }}</span></td>
                                <td class="d-none d-md-table-cell text-nowrap">{{ $registration->created_at?->format('j M, g:i A') }}</td>
                                <td class="text-end"><a href="{{ route('events.manage.registrations.show', $registration->uid) }}" class="text-decoration-none">Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
