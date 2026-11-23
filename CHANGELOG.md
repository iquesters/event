# Changelog

All notable changes to `iquesters/event` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added (step 1: events and registration)
- **Events** of an organisation (`events`, model `Event`): title, type, description, start and end, venue, address, online link.
  - **Status:** draft (admins only), published, cancelled; "Past" is worked out from the dates.
  - **Visibility:** members of the organisation, or public.
  - **Registration:** open or closed, places (empty: no limit), a deadline.
- **Manage events** (`/events/manage`, `ensure.event.manager`): super-admins and organisation admins, each limited to their organisations (organisation module's `OrganisationAccess`). Create, edit, publish or cancel; **attendees** list and **CSV** download. Another organisation's event answers 404.
- **Events for members** (`/events`, navigation tabs Upcoming / Past): events of their organisations, or of the one they work in (organisation context). The event page shows the details, places left, and **Register** (with guests and a note) or **Cancel**.
- **Registrations** (`event_registrations`, model `EventRegistration`): one per person per event; guests count against the places, which are checked under a row lock; registering after cancelling reuses the registration.
- **Public page** (`/e/{slug}`) for public events: the details and a Register button (sign-in needed). Anyone signed in may register for a public event; members-only events are for members of the organisation.
- **Access:** module for `super-admin`, `admin`, `user`, `member`; "Events" needs `use-events`, "Manage events" `manage-events` (given to `admin`; super-admin has all).
- **Settings** in the DB conf: `event_types`, `public_events`.
