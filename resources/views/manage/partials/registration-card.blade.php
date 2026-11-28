{{-- A registration: who, the order, each person with their ticket and meal, the pay link. The registration page and the Attendees side panel. --}}
@php
    $order = $registration->order;
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp
    <div class="card border-0 shadow-sm">
        <div class="card-body small">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                <div>
                    <h5 class="fs-6 mb-1">{{ $registration->displayName() }}</h5>
                    <div class="text-muted">{{ $registration->email ?: $registration->user?->email }}@if ($registration->phone) · {{ $registration->phone }}@endif</div>
                    <div class="text-muted">Registered {{ $registration->created_at?->format('j M Y, g:i A') }}@if ($registration->source === 'admin') by an admin @endif</div>
                </div>
                <div class="text-end">
                    <div class="fw-semibold">{{ $registration->statusLabel() }}</div>
                    @if ($registration->attendLabel())<div><span class="badge text-bg-light border">{{ $registration->attendLabel() }}</span></div>@endif
                    @if ($order)
                        <div class="font-monospace">{{ $order->reference }}</div>
                        <div>{{ $money($order->total) }} · {{ $order->statusLabel() }}</div>
                    @endif
                </div>
            </div>

            @if ($registration->people)
                <div class="table-responsive">
                    <table class="table table-sm mb-2">
                        <thead><tr class="text-muted"><th>Person</th><th>{{ \Iquesters\Event\Support\EventWords::t('Ticket') }}</th><th>Gender</th><th>Age</th><th>Meal</th><th class="d-none d-md-table-cell">Contact</th></tr></thead>
                        <tbody>
                            @foreach ($registration->people as $person)
                                <tr>
                                    <td>{{ $person['name'] ?? '' }} @if (($person['role'] ?? '') === 'registrant')<span class="badge text-bg-light">registrant</span>@elseif (! empty($person['relation']))<span class="text-muted small">({{ \Iquesters\Event\Models\EventRegistration::RELATIONS[$person['relation']] ?? $person['relation'] }})</span>@endif @if ($event->isHybrid()) <span class="badge text-bg-light border">{{ \Iquesters\Event\Models\EventRegistration::ATTEND_MODES[$registration->attendModeOf((array) $person)] }}</span>@endif
                                        {{-- Joining as a member: what they gave (e.g. their batch, "ISC 2005"). --}}
                                        @if (! empty($person['member_details']['summary']))<div class="text-muted">{{ $person['member_details']['summary'] }}</div>@endif</td>
                                    <td>{{ $person['ticket'] ?? '' }}</td>
                                    <td>{{ \Iquesters\Event\Models\EventRegistration::GENDERS[$person['gender'] ?? ''] ?? '' }}</td>
                                    <td>{{ ($person['age_group'] ?? 'adult') === 'child' ? 'Child' : 'Adult' }}</td>
                                    <td>{{ \Iquesters\Event\Models\EventRegistration::MEALS[$person['meal'] ?? ''] ?? '' }}</td>
                                    <td class="d-none d-md-table-cell">{{ $person['email'] ?? '' }}@if (! empty($person['phone'])) · {{ $person['phone'] }}@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mb-2">{{ 1 + $registration->guests }} {{ \Illuminate\Support\Str::plural('person', 1 + $registration->guests) }}</p>
            @endif
            @if ((float) $registration->donation > 0)<p class="mb-2">Donation: {{ $money($registration->donation) }}</p>@endif
            {{-- Corrections: who changed what, when. --}}
            @if (! empty($registration->edit_history))
                <div class="mb-2">
                    <div class="text-muted mb-1">Changes</div>
                    <ul class="list-unstyled mb-0">
                        @foreach (array_reverse($registration->edit_history) as $edit)
                            <li class="mb-1">
                                <span class="text-muted">{{ \Illuminate\Support\Carbon::parse($edit['at'])->format('j M, g:i A') }} · {{ $edit['by_name'] ?? 'Someone' }}{{ ! empty($edit['admin']) ? ' (admin)' : '' }}:</span>
                                {{ implode('; ', (array) ($edit['changes'] ?? [])) }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if (\Iquesters\Event\Support\EventRegistrationEditor::mayEdit(auth()->user(), $registration, true) && ! request()->routeIs('events.manage.registrations.show'))
                <a href="{{ route('events.manage.registrations.show', $registration->uid) }}#edit" class="btn btn-sm btn-outline-secondary mb-2"><i class="fas fa-fw fa-pen"></i> Correct the details</a>
            @endif
            @if ($registration->note)<p class="mb-2 text-muted">Note: {{ $registration->note }}</p>@endif

            <div class="d-flex flex-wrap gap-2 align-items-center">
                @if ($payUrl)
                    <a href="{{ $payUrl }}" class="btn btn-sm btn-outline-primary">Pay page</a>
                    <span class="text-muted">Pay link to send: <span class="font-monospace text-break">{{ $payUrl }}</span></span>
                @endif
            </div>
        </div>
    </div>

