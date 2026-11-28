# Changelog

All notable changes to `iquesters/event` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed (mobile numbers)
- **Mobile numbers are checked for their country** (foundation's `MobileNumber`): you, each guest, Add attendee and the edit form. An Indian number needs 10 digits starting 6–9, a UAE one a mobile (not a landline), and so on for every country. Kept tidied: `+91 98000 00001`. Numbers already saved are not changed.
- **Every country's code** in the Country code list (India first, then the usual ones), instead of 24. `EventRegistration::PHONE_CODES` stays for code using it (deprecated).
- **A guest's "Joining as a member?" says who should tick it**: *Tick if this guest is also an alumnus.* (the app's member label).
- Requires foundation `^2.2`.

### Changed (in person and online: each guest chooses)
- **Each guest says how they attend** (*Attending: In person / Online* on their card); the registrant answers at the top. A person's meal is asked only when they come in person, and each gets the passes for their own way (the total follows).
- Places count only those coming in person; meals and the veg / non-veg counts too. New column `online_people` (migration `2026_11_30_000011_add_online_people_to_event_registrations`, a column only); registrations made before count everyone the registrant's way.
- The registrant gets the online link when anyone in the registration joins online (*N joining online*); a registration with people coming in person also gets the usual confirmation.
- The attendees list shows *In person*, *Online* or *2 in person · 1 online*; the card and the CSV show each person's own way.
- The edit form lets the registrant and each guest switch, each when their pass is for both ways; the history says who switched.

### Added (your word for "ticket")
- **Settings `ticket_word` / `ticket_word_plural`** (default *ticket* / *tickets*): what people get for an event, in the app's own word, e.g. *pass* / *passes*. Every text people read uses it: the event and registration forms (labels, help, buttons), the event pages (*Passes available*, *Get passes*, *Only 3 passes left*), messages (*Choose one of this event's passes.*), the attendees CSV column and the registration card. Ids, field names and stored data stay *ticket*. `EventWords::t()` puts the word into a text, keeping its capital.
- The ticket name's example is now *e.g. Standard*.

### Added (in person and online events)
- **Where: In person and online** (`location_type` `hybrid`): the venue, address, map and the online link together.
- **How will you attend? In person / Online** first on the registration form (and the add-attendee form), once per registration: the guests attend the same way. Kept as `attend_mode` (migration `2026_11_30_000010_add_attend_mode_to_event_registrations`, a column only).
- **Meals only for those coming in person**: the meal question (yours and each guest's) shows after *In person*; online people have none, so the veg / non-veg counts stay the caterer's. An online-only event no longer asks for meals.
- **Places are the venue's**: the places limit counts only those coming in person; a full venue still leaves joining online (*No places are left in person (you can still join online)*).
- **Tickets for one way of attending** (optional): each ticket's *For: In person or online / In person / Online* (default both). Same price for everyone: leave it. A different online price: add e.g. an *Online pass* for Online; people get the tickets for how they attend (the bundles follow their tickets), and the total on the form follows.
- **The online link only for those joining online**: once confirmed (paid, or nothing to pay) they get the email *Joining online: the link* (new template `event.online.join`) and see it on the event page; others don't, and the public page never shows it.
- **Switching** in the edit form (registrant until registration closes, admins any time): only when everyone's ticket is for both ways (the price stays the same) and, to come in person, places are left; kept in the edit history; switching to online when confirmed sends the link.
- The attendees list shows *In person* / *Online* per registration and *X in person · Y online*; the CSV has an *Attending* column; the registration card shows it.

### Changed (guests on the registration form)
- **Each guest: name and relation required** (*Relation to you*: spouse, child, parent, brother / sister, other relative, friend, colleague, other). Kept with the person, shown on the registration card, in the attendees CSV (*Relation*) and on the order line; editable with the other details (and in the edit history).
- **A guest's email and mobile are optional**, needed only for a guest joining as a member (the membership is theirs); before, every adult guest needed a mobile. The form shows their star only after *Joining as a member?* is ticked (user-interface `requiredWhen`); the server checks the same.

## [1.2.0] - 2026-11-27

### Added (emails, with iquesters/notifications)
- **The person registering gets an email**: *registration received* with the amount and the pay link when payment is due, or *registration confirmed* when there is nothing to pay. Declared by `EventNotifications`, sent by the notifications module (templates editable per organisation). Without that module nothing is sent (`suggest`).

### Added (correcting a registration)
- **Edit details** on the event page: the person registering corrects names, mobile numbers, gender, meals and members' details (e.g. their batch) of themselves and their guests, **until registration closes** (form schema `event-registration-edit-{event uid}`, `PUT /events/{uid}/registration`).
- **Admins correct any registration at any time** from its page (*Correct the details*, also linked from the Attendees side panel; form schema `event-registration-admin-edit-{event uid}`, `PUT /events/manage/registrations/{uid}`).
- **Locked**: tickets, adult or child, membership, the number of guests and a member's email (the membership is theirs). They show read-only; to change them, cancel and register again (unpaid) or use *Add attendee*.
- **History**: each correction is kept (`edit_history`: when, who, admin or not, what changed, e.g. *Guest 1 meal: Veg → Non-veg*) and shown on the registration. Meal counts follow. An unpaid order's lines follow too, so a membership is made with the corrected name and details.
- Each person keeps what they entered for the membership details (`member_input`), to show it again.
- Migration `2026_11_30_000008_add_edit_history_to_event_registrations` (adds a column).

### Changed (the public event page)
- **No ticket names or prices before signing in**: the Registration card says only *Tickets available*, *Only N tickets left* (when every ticket is limited and 10 or fewer are left) or *Sold out*, and *Sign in to see the tickets and prices…*. The prices show on the event page after signing in.
- **Share instead of Sign in** in the header: the phone's share sheet, else *Link copied* (the button under the details already leads to sign-in and the form).
- **The map pins the place of the Google Maps link**: a short share link (`maps.app.goo.gl/…`) is followed once (remembered) to its place and coordinates. Before, the map searched the typed venue and address, which Google could match to another place.
- **The app's tab icon** on the public page and the QR poster (they had none, so browsers showed a default).

### Fixed
- **Requires `iquesters/user-interface` ^2.2 and `iquesters/organisation` ^2.2**: the attendees page uses user-interface's table partial (`components.table`, new in 2.2) and the public page uses `OrganisationContext::rememberVisit()` (new in organisation 2.2); with ^2.1 older versions could be installed and those pages would fail.

## [1.1.0] – 2026-11-27

_Also covers 1.0.0, which was tagged without its own heading._

### Database
- **One migration for this release** (`2026_11_30_000003_add_paid_registration_to_events`): events get `location_type`, `cover_image`, `theme`, `maps_url`, `is_paid`, `paid_settings`; registrations get the order, the registrant's details, head counts, meals, donation, people, source and terms. Each column is added only when missing, so it is safe on live data. The unused per-device banner and order-prefix columns are no longer made.

### Changed (the registration form)
- **Attendees on the generic table** (table schema `event-attendees`, made and kept up to date by `EventTables::ensureAttendees()`; the page gives it the event's rows with `data-data-url`): each row a card (record component `event.attendee`) with a **coloured status badge** (yellow *Awaiting payment*, blue *Payment under review*, green *Confirmed*, grey *Cancelled*), *Added by admin*, the order and total, people, meals, donation; search; the side panel shows the registration (people, tickets, each member's details, the pay link) and, while it can be cancelled, the **Cancel** form (form schema). The totals, *Add attendee* and *Download CSV* stay above it. Endpoints: `/events/manage/{uid}/attendees/data`, `…/data/{registration}`, `/events/manage/registrations/{uid}/summary`.
- **Each member's details in the registration** (e.g. *ISC 2005*) under their name.
- **Fixed: the Attendees page failed** (*Missing parameter organisationUid*) for an organisation with its own terms page: the event was loaded with its organisation's id and name only, so the form's terms links had no uid. The organisation's uid is loaded now.
- **Fixed: the membership details were dropped** (*Studied up to*, *Year of passing*): the form's rules didn't list the membership module's fields, so validation removed them and registering failed with *Add what you completed at this school…* even when filled in. They're now passed on (registrant and guests). Test with a membership module plugged in.
- **The donation follows the donation product's limits**: at least its minimum (₹1 when none is set) and at most its maximum, in the form (min/max, *₹100 to ₹5,000; leave empty for none*) and on the server (*A donation is ₹100 to ₹5,000*). No negative or zero donations.
- **One price line per ticket**: the ticket list (event page and public page) no longer shows the membership bundle as a second ₹600 line. It shows as a note on its ticket: *Membership 2027 included when you join {organisation}* when it costs the same, else *With Membership 2027: ₹900*. Shown only when the event's registration includes membership.
- **The total before the terms**: a *Total* section above the Agreement lists each person with their ticket and price, the donation and the total. It updates as the form is filled in, from `POST /events/{uid}/pay-quote` (`EventPaidRegistrar::quote`, the same ticket rules as the order).
- **Plain words**: each guest is asked *Joining as a member?* with **Yes / No** buttons (No to start; a note says Yes makes them a member of the organisation), shown for adults only. The question was *{Member label} of {organisation}?*.
- **Guests as cards** (*Guest 1*, *Guest 2*…), each starting as *Adult* with *+91*.
- **An adult guest's mobile is required** (a child's isn't asked).
- **A member is an adult**: after *Joining as a member?* Yes, *Adult or child* no longer offers *Child*; the server also refuses a child member.

### Added (the event page's look)
- **A public event page like an invitation** (`/e/{uid}`): the cover on the left with *Hosted by* (the organisation's logo and name, linked to its public page) and how many are going; on the right the title, a calendar tile with the date and times, the place (or *Online*), a **Registration** card (the tickets with prices and what's included, places left, *Get tickets* / *Register*, when registration closes), *About* and the location. A limited ticket shows how many are left (*Sold out* at 0). The page is tinted with the event's colour; shared links show the cover and the title (Open Graph).
- **Page look** on the event form: **Cover image** (one image, square works best) and **Colour** (a colour picker: any colour; the page derives a deeper shade for its gradients). Without a cover, one is drawn in the colour with the title and the date. *Remove the current cover image* shows only when there is one. Columns `cover_image`, `theme`. The per-device banners are gone.
- **Google Maps link** (in-person events, optional; column `maps_url`): the public page shows the map (centred on the link's place or coordinates, else the venue) and *Open in Maps* only when it is given.
- **From the public page straight to the form**: *Get tickets* / *Register* / *Sign in* lead, after signing in or signing up, to the event page scrolled to its registration form (`?register=1`). The public page remembers the event's organisation, so the membership page then has it chosen.
- **No ticket to pick**: each ticket says *who it's for* (Adults, Children up to the event's age, Everyone) and the event sets the child age (e.g. 5). The registration picks each person's ticket from *adult or child* and *member*: a member (adult) gets a bundle with one of their tickets and a membership (made in commerce) unless they already have that membership; others their only ticket. A *Ticket* field shows only when an event really has a choice (and always for admins).
- **"Registration includes membership of the organisation"** (per event, Tickets section): only then registering is also joining (the ticket includes the membership bundle, the membership details are asked, guests are asked whether they're members). Unticked: a plain ticketed event. The form states the registrant's ticket and price (*Your ticket: … · ₹600*) instead of a Ticket list, which shows only when there's a real choice.
- **The person registering joins**: no "member" question for them; on an event that sells a membership they get it with their ticket and are asked the membership details directly. Guests are asked. (Forms also rebuild when a bundle's items change.)
- **"{Member label} of {organisation}?"** for each guest (e.g. *Alumnus of TTV?*); a member is asked the membership module's details (community: what they completed at the school, the year, the class left after), shown only when ticked and prefilled from the registrant's member record. Plug-in point: `Contracts\MembershipDetails` (bound by community). The details travel with the membership line.
- **Mobile numbers with a country code** (default India +91), for the registrant and guests; kept as `+91 98000 00001`.
- **The registration form follows the organisation's products**: saving a product (e.g. a donation or a bundle made after the event) rebuilds its paid events' forms. *Register below* is gone (the form is right there).
- **"Ask for a donation"** on the event (Tickets section): only then the registration form has an optional donation, paid with the tickets. If the organisation has no donation product, saving makes one (*Donation*, the giver chooses the amount, at least 1; the organisation's, reused by its other events). An event that doesn't ask still offers it when the organisation's donation is set to *Offer with every paid order* (commerce).
- **Public addresses use the uid** (`/e/{uid}`), which never changes; an old address by name (`/e/{slug}`) redirects there (301).

### Added (step 3: paid registration; needs iquesters/commerce 2.x, and iquesters/payment for the pay page)
- **Free or paid**: a free event needs nothing more. *Registration is paid* shows the **Tickets** (only then), the event's own: each with a name, a price (0 for free), how many can be sold (shows *No limit* when empty), what's included (e.g. Lunch, T-shirt) and *only admins can give it* (crew, invited guests).
- **Tickets are commerce products** (`EventProducts`, type `ticket`, linked to the event; admin-only ones private). On sale until registration closes; saving again updates them; a ticket removed from the event goes off sale (orders keep their prices). **Combinations are made in commerce**: a bundle that contains one of the event's tickets (e.g. *Pass with membership* = the event's Pass + a membership) is offered at registration next to the tickets. A **donation** is offered when the organisation has a donation product in commerce. **Order numbers** come from commerce (`order_reference_prefix`). Approving memberships on payment is community's setting. The event makes no membership, bundle or donation products and has no field for them.
- **The registration form** of a paid event (form schema `event-paid-registration-{uid}`): name, mobile, email, gender, meal and **ticket**; **guests**, each with name, gender, adult or child, meal, **ticket**, email and mobile; donation (when offered); the organisation's Terms and Privacy policy (required). A ticket containing a membership needs that person's email and isn't for someone who already paid that membership. **Continue to payment** makes one commerce order, a line per person for their ticket (a bundle expands into its items), then the pay page (payment module) and back to the event.
- **Where**: *In person* (venue and address, the venue required) or *Online* (the link, required); only the fields for the choice show, and the others aren't kept. New column `location_type` (existing events with only a link become online). **Places**: *Limit the number of places* then *How many places* (no more "leave empty for unlimited").
- **Registration statuses** follow the order: *Awaiting payment* → *Payment under review* → *Confirmed* (paid), back to awaiting payment when a payment is rejected, *Cancelled* with the order. Places count every registration that isn't cancelled. Cancelling an unpaid registration cancels its order.
- **Attendees** (paid events): confirmed people, adults / children, veg / non-veg, the amount paid; each registration's people, order and pay link (`/events/manage/registrations/{uid}`), and Cancel for unpaid ones (form `event-registration-admin-cancel`). **Add attendee** (form `event-add-attendee-{uid}`): crew and invited guests free, or someone paying at the desk (record the cash in Payments). **CSV**: one row per person (reference, status, gender, age, meal, alumnus, contact).
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
