@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make([$event->title]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description(\Illuminate\Support\Str::limit((string) $event->description, 150) ?: $event->title))

@section('content')
@php
    $R = \Iquesters\Event\Models\EventRegistration::class;
    $money = fn ($v) => '₹'.rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $paidOpen = $event->is_paid && ! $registration && ! $closedReason && $mayRegister;
@endphp
<div class="d-grid gap-3">
    @if (session('success'))
        <div class="alert alert-success small mb-0">{{ session('success') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            @include('event::partials.details', ['event' => $event, 'showOnlineLink' => $showOnlineLink ?? true])
        </div>

        {{-- Registration --}}
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm" id="registration">
                <div class="card-body d-grid gap-2">
                    <h6 class="fs-6 mb-0">Registration</h6>
                    @if ($event->capacity !== null)
                        <div class="small text-muted">{{ $event->seatsLeft() }} of {{ $event->capacity }} places left</div>
                    @endif

                    @if ($event->is_paid)
                        {{-- What it costs --}}
                        <ul class="list-unstyled small mb-0">
                            {{-- The tickets people pick (made on the event, priced in commerce). --}}
                            @foreach ($prices as $ticket)
                                <li class="py-1">
                                    <div class="d-flex justify-content-between gap-2"><span>{{ $ticket['name'] }}</span><span class="fw-semibold text-nowrap">{{ $ticket['price'] > 0 ? $money($ticket['price']) : 'Free' }}</span></div>
                                    @if ($ticket['left'] !== null)<div class="fw-semibold {{ $ticket['left'] === 0 ? 'text-danger' : 'text-muted' }}">{{ $ticket['left'] === 0 ? 'Sold out' : $ticket['left'].' left' }}</div>@endif
                                    @if ($ticket['includes'])<div class="text-muted">Includes {{ $ticket['includes'] }}</div>@endif
                                    @include('event::partials.ticket-membership', ['ticket' => $ticket, 'event' => $event, 'money' => $money])
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($registration)
                        @php($order = $registration->order)
                        <div class="alert {{ $registration->status === $R::STATUS_REGISTERED ? 'alert-success' : 'alert-warning' }} small py-2 mb-0">
                            @if ($registration->status === $R::STATUS_REGISTERED)
                                <i class="fas fa-fw fa-circle-check"></i> You're registered
                            @elseif ($registration->status === $R::STATUS_UNDER_REVIEW)
                                <i class="fas fa-fw fa-hourglass-half"></i> Payment sent: the organisers are checking it
                            @else
                                <i class="fas fa-fw fa-indian-rupee-sign"></i> Registered: waiting for your payment
                            @endif
                            @if ($registration->guests) · {{ 1 + $registration->guests }} people @endif
                            @if ($registration->attendLabel()) · {{ $registration->attendLabel() }} @endif
                            {{-- Joining online and confirmed: their link. --}}
                            @if ($registration->status === $R::STATUS_REGISTERED && $event->isHybrid() && $registration->anyOnline() && $event->online_url)
                                <div class="mt-1"><i class="fas fa-fw fa-video"></i> <a href="{{ $event->online_url }}" target="_blank" rel="noopener nofollow">Join online</a></div>
                            @endif
                            @if ($order)<div class="mt-1">Order <span class="font-monospace">{{ $order->reference }}</span> · {{ $money($order->total) }}</div>@endif
                        </div>
                        @if ($payUrl)
                            <a href="{{ $payUrl }}" class="btn btn-sm btn-primary">{{ $registration->status === $R::STATUS_UNDER_REVIEW ? 'See payment' : 'Pay now' }}</a>
                        @endif
                        @if ($editFormId)
                            {{-- Correct names, mobiles, meals, a member's details: until registration closes. --}}
                            <a href="#edit-registration" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="edit-registration"><i class="fas fa-fw fa-pen"></i> Edit details</a>
                        @endif
                        @if (! $event->isPast() && $event->status !== \Iquesters\Event\Models\Event::STATUS_CANCELLED && ! ($order && $order->isPaid() && (float) $order->total > 0))
                            {{-- Form schema event-registration-cancel (a paid registration only before it's paid). --}}
                            @include('userinterface::components.form', ['id' => \Iquesters\Event\Support\EventForms::CANCEL_REGISTRATION, 'entity_uid' => $event->uid, 'formData' => ''])
                        @endif
                    @elseif ($closedReason)
                        <div class="text-muted small">{{ $closedReason }}</div>
                        @if ($event->is_paid && $membershipUrl)
                            <div class="small">Not a member yet? <a href="{{ $membershipUrl }}">Become a member of {{ $event->organisation?->name }}</a>.</div>
                        @endif
                    @elseif (! $mayRegister)
                        <div class="text-muted small">Only members of {{ $event->organisation?->name }} can register.</div>
                    @elseif ($event->is_paid)
                        <div class="small text-muted">Register with the form below.</div>
                    @else
                        {{-- Form schema event-registration (guests, note). --}}
                        @include('userinterface::components.form', ['id' => \Iquesters\Event\Support\EventForms::REGISTRATION, 'entity_uid' => $event->uid, 'formData' => json_encode(['guests' => 0])])
                    @endif

                    {{-- Feedback: attendees once it has started; the rating once it is over. --}}
                    @if ($rating)
                        <div class="small"><i class="fas fa-star text-warning"></i> <span class="fw-semibold">{{ $rating['average'] }}</span> / 5
                            <span class="text-muted">from {{ $rating['responses'] }} {{ \Illuminate\Support\Str::plural('response', $rating['responses']) }}</span></div>
                    @endif
                    @if (! $feedbackClosed)
                        <a href="{{ route('events.feedback.show', $event->uid) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-fw fa-star"></i> Give feedback</a>
                    @endif

                    @if ($canManage)
                        <hr class="my-2">
                        <div class="d-flex gap-2">
                            <a href="{{ route('events.manage.edit', $event->uid) }}" class="btn btn-sm btn-outline-secondary flex-fill">Edit</a>
                            <a href="{{ route('events.manage.attendees', $event->uid) }}" class="btn btn-sm btn-outline-secondary flex-fill">Attendees</a>
                            @if ($event->feedback_enabled)
                                <a href="{{ route('events.manage.feedback.results', $event->uid) }}" class="btn btn-sm btn-outline-secondary flex-fill">Feedback</a>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($editFormId)
        {{-- Form schema event-registration-edit-{uid}: the same people, their details corrected. --}}
        <div class="collapse {{ request()->boolean('edit') ? 'show' : '' }}" id="edit-registration">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="fs-6 mb-1">Edit your details</h5>
                    <p class="small text-muted mb-3">You can correct these until registration closes. Your {{ \Iquesters\Event\Support\EventWords::t('ticket') }}, who is an adult or a child, membership and the number of guests stay as they are.</p>
                    @include('userinterface::components.form', ['id' => $editFormId, 'entity_uid' => $event->uid, 'formData' => json_encode($editFormData, JSON_UNESCAPED_UNICODE)])
                </div>
            </div>
        </div>
    @endif

    @if ($paidOpen)
        {{-- The paid registration form (form schema event-paid-registration-{uid}): you, your guests, donation, terms → the pay page. --}}
        <div class="card border-0 shadow-sm" id="register">
            <div class="card-body">
                <h5 class="fs-6 mb-1">Register</h5>
                <p class="small text-muted mb-3">Add everyone coming with you. You pay for everyone at once on the next page (UPI); your registration is confirmed when the organisers verify the payment.</p>
                @include('userinterface::components.form', ['id' => $paidFormId, 'entity_uid' => $event->uid, 'formData' => json_encode($paidFormData, JSON_UNESCAPED_UNICODE)])
            </div>
        </div>
    @endif
</div>
@if ($paidOpen)
    {{-- The total, above the terms: worked out by the server (the same ticket rules as the order) as the form is filled in. --}}
    <script>
        // The form draws itself after the page loads: start once its Total section is there.
        window.addEventListener('load', () => {
            const card = document.getElementById('register');
            if (!card) return;
            const ready = () => card.querySelector('[data-section-id="summary"] .lab-form-section-body');
            if (ready()) return startTotal(card);
            const watch = new MutationObserver(() => { if (ready()) { watch.disconnect(); startTotal(card); } });
            watch.observe(card, { childList: true, subtree: true });
        });
        function startTotal(card) {
            const form = card.querySelector('form');
            const section = card.querySelector('[data-section-id="summary"] .lab-form-section-body');
            if (!form || !section || section.querySelector('.lab-order-summary')) return;
            const money = (v) => '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 2 });
            const box = document.createElement('div');
            box.className = 'col-12';
            box.innerHTML = '<div class="lab-order-summary p-3"><div class="d-grid gap-1 small" data-lines></div>'
                + '<div class="d-flex justify-content-between align-items-baseline border-top mt-2 pt-2"><span class="fw-semibold">Total</span><span class="lab-order-total" data-total>…</span></div></div>';
            section.appendChild(box);
            const linesEl = box.querySelector('[data-lines]');
            const totalEl = box.querySelector('[data-total]');
            const row = (left, right, muted) => {
                const div = document.createElement('div');
                div.className = 'd-flex justify-content-between gap-2' + (muted ? ' text-muted' : '');
                const a = document.createElement('span'); a.textContent = left;
                const b = document.createElement('span'); b.className = 'text-nowrap'; b.textContent = right;
                div.append(a, b);
                return div;
            };
            let timer = null, seq = 0;
            const refresh = async () => {
                const mine = ++seq;
                const payload = typeof serializeDynamicFormPayload === 'function' && form.__formMeta
                    ? serializeDynamicFormPayload(form, form.__formMeta)
                    : Object.fromEntries(new FormData(form));
                try {
                    const res = await fetch(@json(route('events.pay-quote', $event->uid)), {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                        body: JSON.stringify(payload instanceof FormData ? Object.fromEntries(payload) : payload),
                    });
                    const json = await res.json();
                    if (mine !== seq || !json?.data) return;
                    const q = json.data;
                    linesEl.replaceChildren(...q.lines.map(l => row(l.who + (l.ticket ? ' · ' + l.ticket : ''), l.price === null ? @json(\Iquesters\Event\Support\EventWords::t('Choose a ticket')) : (l.price > 0 ? money(l.price) : 'Free'), l.price === null)));
                    if (q.donation > 0) linesEl.appendChild(row('Donation', money(q.donation)));
                    totalEl.textContent = money(q.total);
                } catch (e) { /* the total stays as it was */ }
            };
            const later = () => { clearTimeout(timer); timer = setTimeout(refresh, 350); };
            form.addEventListener('input', later);
            form.addEventListener('change', later);
            form.addEventListener('click', (e) => { if (e.target.closest('button')) later(); });
            setTimeout(refresh, 300);
        }
    </script>
@endif
{{-- Arrived from the public page's "Get tickets" (?register=1), after signing in or up: straight to the form. --}}
@if (request()->boolean('register'))
    <script>
        window.addEventListener('load', () => {
            const target = document.getElementById('register') || document.getElementById('registration');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                setTimeout(() => target.querySelector('input:not([type=hidden]), select, textarea')?.focus({ preventScroll: true }), 600);
            }
        });
    </script>
@endif
@endsection
