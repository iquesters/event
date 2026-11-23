# iquesters/event

Events for organisations built on the iquesters modules: admins publish events, members register, admins see the attendees. Public events get their own page.

## Status

| Step | What | State |
|---|---|---|
| 1 | Events, members-only or public, registration with places and a deadline, attendees and CSV | **This release** |
| 2 | Feedback and ratings (question sets, a QR poster for the venue, results) | Planned |
| 3 | Paid tickets (prices and coupons from `iquesters/product`) | Planned |
| 4 | Check-in at the door, reminders by email | Planned |

## Install

```bash
composer require iquesters/event
php artisan migrate
php artisan event:seed
```

Requires `iquesters/foundation`, `iquesters/user-interface` and `iquesters/organisation` (2.x).

## Docs

- [Events](docs/user/events.md) (admins and members)
- [Settings](docs/developer/configuration.md)
- [CHANGELOG](CHANGELOG.md)
