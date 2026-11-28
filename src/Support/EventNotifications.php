<?php

namespace Iquesters\Event\Support;

use Illuminate\Support\Facades\Route;
use Iquesters\Event\Models\EventRegistration;

/**
 * The messages the event module sends, when iquesters/notifications is
 * installed (without it nothing is sent): a paid registration received
 * (payment due) and a registration confirmed with nothing to pay. Payment
 * confirmations come from the payment module. The wording is the
 * notifications module's template (editable per organisation).
 */
class EventNotifications
{
    private const TYPES = 'Iquesters\Notifications\Support\NotificationTypes';

    private const SENDER = 'Iquesters\Notifications\Notifications';

    public static function available(): bool
    {
        return class_exists(self::TYPES) && class_exists(self::SENDER);
    }

    public static function register(): void
    {
        if (! self::available()) {
            return;
        }
        $variables = [
            'name' => 'Name of the person registering',
            'organisation' => 'Organisation',
            'event' => 'Event',
            'date' => 'Date and time',
            'place' => 'Venue (or "Online")',
            'people' => 'How many people',
            'reference' => 'Order number',
            'amount' => 'Amount',
            'event_url' => 'Event page link',
            'pay_url' => 'Pay page link',
        ];
        $sample = [
            'name' => 'Rahul Sen', 'organisation' => 'TTV Alumni Association', 'event' => '4th Annual Alumni Meet',
            'date' => 'Sat, 3 Jan 2027, 10:00 AM', 'place' => 'School Auditorium, Tribeni', 'people' => '4 people',
            'reference' => 'ORD-0001', 'amount' => '₹1,900', 'event_url' => url('/events/01ABCDEFGHJKMNPQRSTVWXYZ00'), 'pay_url' => url('/pay/01ABCDEFGHJKMNPQRSTVWXYZ00'),
        ];
        $types = app(self::TYPES);

        $types->register('event.registration.received', [
            'label' => 'Registration received (payment due)',
            'description' => 'Someone registered for a paid event; their order waits for payment.',
            'module' => 'event',
            'variables' => $variables,
            'sample' => $sample,
            'defaults' => ['email' => [
                'subject' => 'Registered for {{event}}: please pay {{reference}}',
                'body' => "Dear {{name}},\n\nThank you for registering for **{{event}}** ({{date}}, {{place}}) for {{people}}.\n\nTo confirm your place, please pay **{{amount}}** for order **{{reference}}** by UPI:\n\n[Pay now]({{pay_url}})\n\nAfter paying, send the UTR and a screenshot on the same page; we'll confirm by email.\n\n[See the event]({{event_url}})\n\n{{organisation}}",
            ]],
        ]);
        $types->register('event.registration.confirmed', [
            'label' => 'Registration confirmed (nothing to pay)',
            'description' => 'A registration with nothing to pay (e.g. free tickets, crew added by an admin) is confirmed at once.',
            'module' => 'event',
            'variables' => $variables,
            'sample' => $sample,
            'defaults' => ['email' => [
                'subject' => 'You\'re registered for {{event}}',
                'body' => "Dear {{name}},\n\nYou're registered for **{{event}}** ({{date}}, {{place}}) for {{people}}. See you there!\n\n[See the event]({{event_url}})\n\n{{organisation}}",
            ]],
        ]);
        $types->register('event.online.join', [
            'label' => 'Joining online: the link',
            'description' => 'Someone joining online is confirmed (paid, or nothing to pay): the link to join. Only they get it; it is not on the event page.',
            'module' => 'event',
            'variables' => [
                'name' => 'Name of the person registering',
                'organisation' => 'Organisation',
                'event' => 'Event',
                'date' => 'Date and time',
                'people' => 'How many people',
                'online_url' => 'The link to join',
                'event_url' => 'Event page link',
            ],
            'sample' => ['online_url' => 'https://meet.google.com/abc-defg-hij'] + array_intersect_key($sample, array_flip(['name', 'organisation', 'event', 'date', 'people', 'event_url'])),
            'defaults' => ['email' => [
                'subject' => 'Your link to join {{event}} online',
                'body' => "Dear {{name}},\n\nYou're registered to join **{{event}}** online ({{date}}) for {{people}}.\n\n[Join online]({{online_url}})\n\nThe link: {{online_url}}\n\nPlease keep it to yourself. It also stays on the event page while you're signed in.\n\n[See the event]({{event_url}})\n\n{{organisation}}",
            ]],
        ]);
    }

    /** Someone joining online is confirmed: their link (only theirs; never on the public page). */
    public static function joinOnline(EventRegistration $registration): void
    {
        if (! self::available()) {
            return;
        }
        $registration->loadMissing(['event.organisation', 'user']);
        $event = $registration->event;
        if (! $event || ! $registration->attendsOnline() || trim((string) $event->online_url) === '') {
            return;
        }
        $people = 1 + (int) $registration->guests;

        (self::SENDER)::send('event.online.join', $event->organisation_id, [
            'email' => $registration->email ?: $registration->user?->email,
            'name' => $registration->displayName(),
            'user_id' => $registration->user_id,
        ], [
            'name' => (string) $registration->displayName(),
            'organisation' => $event->organisation?->name ?? '',
            'event' => $event->title,
            'date' => $event->starts_at?->format('D, j M Y, g:i A') ?? '',
            'people' => $people === 1 ? '1 person' : $people.' people',
            'online_url' => (string) $event->online_url,
            'event_url' => Route::has('events.show') ? route('events.show', $event->uid) : '',
        ], $registration);
    }

    /** After a paid registration: payment due, or confirmed when nothing is to pay. */
    public static function registered(EventRegistration $registration): void
    {
        if (! self::available()) {
            return;
        }
        $registration->loadMissing(['event.organisation', 'order', 'user']);
        $event = $registration->event;
        $order = $registration->order;
        if (! $event || ! $order) {
            return;
        }
        // Confirmed at once (nothing to pay) and joining online: the email with their link.
        if ($order->isPaid() && $registration->attendsOnline()) {
            self::joinOnline($registration);

            return;
        }
        $people = 1 + (int) $registration->guests;
        $money = ($order->currency === 'INR' ? '₹' : $order->currency.' ').number_format((float) $order->total, 2);

        (self::SENDER)::send($order->isPaid() ? 'event.registration.confirmed' : 'event.registration.received', $event->organisation_id, [
            'email' => $registration->email ?: $registration->user?->email,
            'name' => $registration->displayName(),
            'user_id' => $registration->user_id,
        ], [
            'name' => (string) $registration->displayName(),
            'organisation' => $event->organisation?->name ?? '',
            'event' => $event->title,
            'date' => $event->starts_at?->format('D, j M Y, g:i A') ?? '',
            'place' => $registration->attendsOnline() ? 'Online' : trim(implode(', ', array_filter([$event->venue, $event->address]))),
            'people' => $people === 1 ? '1 person' : $people.' people',
            'reference' => $order->reference,
            'amount' => $money,
            'event_url' => Route::has('events.show') ? route('events.show', $event->uid) : '',
            'pay_url' => Route::has('payment.pay') ? route('payment.pay', $order->uid) : '',
        ], $registration);
    }
}
