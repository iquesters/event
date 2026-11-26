# Changelog

All notable changes to `iquesters/event` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added (step 3: paid registration; needs iquesters/commerce 2.x, and iquesters/payment for the pay page)
- **Paid events** (event form → *Paid registration*): the pass price, the alumni price (pass with membership), the membership's own price and period, free children up to an age (5), the order reference prefix (e.g. `TTV-MEET`), an optional donation, and whether paying approves alumni's memberships (`together`) or sends them to Applications (`separate`).
- **Saving a paid event makes its products in commerce** (`EventProducts`, linked to the event): *Pass*, *Child 5 or under* (₹0), *Crew / invited guest* (private, ₹0), the organisation's *membership* for the period (shared by its events in that period), *Pass with membership* (a bundle: the pass at its price, the membership for the rest), *Donation*. Sold until registration closes (the deadline, else the start); saving again updates them; orders keep their prices.
- **The registration form** of a paid event (form schema `event-paid-registration-{uid}`): name, mobile, email, gender, meal, "I am an alumnus"; **guests** (name, gender, over 5 / 5 or under, meal, also an alumnus + their email and mobile); donation; the organisation's Terms and Privacy policy (required). **Continue to payment** makes one commerce order (`{prefix}-0001`) with a line per person: alumni the pass with membership (unless they already paid that membership: the pass), other guests the pass, children free; then the pay page (`/pay/{order}`, payment module) and back to the event.
- **Registration statuses** follow the order: *Awaiting payment* → *Payment under review* → *Confirmed* (paid), back to awaiting payment when a payment is rejected, *Cancelled* with the order. Places count every registration that isn't cancelled. Cancelling an unpaid registration cancels its order.
- **Attendees** (paid events): confirmed people, adults / children, veg / non-veg, the amount paid; each registration's people, order and pay link (`/events/manage/registrations/{uid}`), and Cancel for unpaid ones (form `event-registration-admin-cancel`). **Add attendee** (form `event-add-attendee-{uid}`): crew and invited guests free, or someone paying at the desk (record the cash in Payments). **CSV**: one row per person (reference, status, gender, age, meal, alumnus, contact).
- **Banners per device** (form `event-banners` in the event's record): desktop, tablet, mobile; shown at the top of the event pages, the right one for the screen.
- **After registration closes**, a paid event's pages offer *Become a member* (the membership page with the organisation chosen).

### Fixed
- **The attendee feedback page uses the full content width** like every other page.

### Changed (every form is a form schema)
- **Manage events is the generic table** (`event-manage-table`, table and split modes): rows from `GET /events/manage/data` (limited to the organisations managed), **New event** and the record use the **`event-details`** form schema (JSON to `POST /events/manage`, `PUT /events/manage/{uid}`), plus a links section (attendees, feedback, pages). `/events/manage` and `/events/manage/{uid}/edit` redirect to the table.
- **Register / cancel** use the `event-registration` and `event-registration-cancel` form schemas (JSON).
- **Feedback questions** use the `event-feedback-questions` form schema (a repeater; rows keep their id) and `event-feedback-preset`.
- **The attendee feedback form** is a form schema per event (`event-feedback-{uid}`), rebuilt from its questions (ratings as 1–5 star choices); answers post JSON, errors show under each question.
- `event:seed` creates the table and the forms. The old hand-written pages are gone.

### Added (step 2: feedback and ratings)
- **Feedback per event** (switch "Ask attendees for feedback" on the event; optional "may stay anonymous"): it opens when the event starts, for people registered for it, one response each.
- **Questions** (`event_feedback_questions`): rating (1–5 stars), yes / no, or text; required or not. Switching feedback on starts from the event type's set (`FeedbackPresets`: reunion / meet-up, seminar / workshop, sports / cultural, general); admins edit, reorder and remove them, or reload the set.
- **Answers** (`event_feedback_responses`, `event_feedback_answers`); `EventFeedback` checks who may answer and the answers (required, ratings 1–5), saves them, and builds the results.
- **Results** for the event's admins: overall rating, per question the average and the spread of 1–5 stars, yes / no counts, comments (names hidden for anonymous answers); **CSV** download (one row per response).
- **QR poster:** a printable A4 page (print or save as PDF) whose QR code opens the feedback form; drawn in the browser with qrcodejs, no server package.
- **Event page:** "Give feedback" for attendees once it has started; the average rating and how many responded once it is over.

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
