@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Attendees', $event->title]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Who registered'))

@php
    $R = \Iquesters\Event\Models\EventRegistration::class;
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
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
            <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted">Meals (confirmed)</div><div class="fw-semibold">{{ $totals['veg'] }} veg · {{ $totals['non_veg'] }} non-veg</div><div class="text-muted">{{ $totals['adults'] }} adults · {{ $totals['children'] }} children</div>@if (isset($totals['in_person_people']))<div class="text-muted">{{ $totals['in_person_people'] }} in person · {{ $totals['online_people'] }} online</div>@endif</div></div></div>
            <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted">Paid</div><div class="fs-5 fw-semibold">{{ $money($totals['paid']) }}</div></div></div></div>
        @endif
    </div>

    @if ($addFormId)
        {{-- Form schema event-add-attendee-{uid}: crew and invited guests (free), or people paying at the desk. --}}
        <div class="collapse" id="add-attendee">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fs-6 mb-1">Add attendee</h6>
                    <p class="small text-muted mb-3">For crew and invited guests pick their admin-only {{ \Iquesters\Event\Support\EventWords::t('ticket') }} (e.g. Crew ₹0). Otherwise an order is made: record the cash in Payments → Record a payment (its reference is shown after adding), or send them the pay link from their registration.</p>
                    @include('userinterface::components.form', ['id' => $addFormId, 'entity_uid' => $event->uid, 'formData' => json_encode(['guests' => []])])
                </div>
            </div>
        </div>
    @endif

    {{-- The list: the generic table "event-attendees" with this event's rows. Each row is a card
         (record component event.attendee) with a coloured status badge; the side panel shows the
         registration and, while it can be, the Cancel form (form schema event-registration-admin-cancel). --}}
    @if ($table)
        <div class="d-flex flex-column" style="height: 70vh; min-height: 420px;">
            @include('userinterface::components.table', ['table_schema' => $table, 'dataUrl' => $dataUrl, 'create' => null])
        </div>
    @else
        <div class="alert alert-warning small mb-0">The list needs the user-interface module.</div>
    @endif
</div>
@endsection
