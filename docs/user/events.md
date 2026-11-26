# Events

## For admins

Open **Events → Manage events** and press **New event**.

1. Fill in the title, type, date and time, venue or online link, and a description.
2. **Who can see it:** members of your organisation, or everyone (a public page).
3. **Registration:** turn it on or off, set the number of places (leave empty for no limit) and a "register by" date.
4. **Status:**
   - **Draft:** only admins see it, while you prepare it;
   - **Published:** members can see it and register;
   - **Cancelled:** it stays visible, marked cancelled, and nobody can register.

**Attendees** lists who registered, with their guests and notes. **Download CSV** gives the same list for Excel.

You only see and edit the events of the organisations you manage. If you manage several, pick one in the header to see only its events.

## For members

Open **Events**: **Upcoming** shows what's coming; **Past** shows earlier events.

Open an event to see the details and how many places are left. Press **Register**, say how many guests are coming with you, and add a note if needed (e.g. dietary needs). You can **cancel** your registration until the event starts.

## Public events

A public event has its own page at `/e/the-event-name`, which anyone can open, e.g. from your website. To register, people sign in.

## Feedback and ratings

**For admins:** on the event, switch on **Ask attendees for feedback** (and, if you like, **Attendees may stay anonymous**). The questions start from the event type's set.
- **Questions:** change, reorder or remove them; choose rating (1–5 stars), yes / no or text, and whether an answer is required. **Use the … set** puts the type's questions back.
- **QR poster:** print it (or save it as PDF) and put it up at the venue; scanning it opens the feedback form.
- **Results:** the overall rating, each question's average and spread, yes / no counts and comments; **CSV** has every response.

**For attendees:** once the event has started, open the event and press **Give feedback** (or scan the poster). You can answer once.


## Free or paid events

A **free** event needs nothing more: people register with their guests.

A **paid** event (needs the commerce and payment modules) has **its own tickets**. Everything else belongs to commerce:

1. **On the event** (Manage events → the event → Edit), tick **Registration is paid**, then **Tickets → Add ticket**, one per price: name, price (0 for free), *how many can be sold* (shows *No limit* when left empty), *what's included* (e.g. Lunch, T-shirt), *only admins can give it* (crew, invited guests). Save: each ticket becomes a product in Commerce → Products. Change a price here any time; orders already made keep theirs. A ticket you remove goes off sale.
2. **In Commerce → Products** (optional):
   - **Combinations**: a *Bundle* with one of the event's tickets inside (e.g. *Pass with membership* = the event's Pass + a membership product at ₹0). It is offered at registration next to the tickets.
   - **Donation**: a *Donation* product (the giver picks the amount). When the organisation has one, registration asks for an optional donation.
   - **Order numbers**: commerce's setting *order_reference_prefix* (e.g. TTV-MEET → TTV-MEET-0001).
3. Set **Payment settings** (your UPI ID) in the payment module, and the event's **Banners** in its record.

Whether paying also approves a membership is a **community** setting (*approve_paid_memberships*, on by default).

**People register** on the event page: their details, gender, meal and **ticket**, then each guest with their own ticket (a ticket with a membership inside needs that person's email). **Continue to payment** shows the total and the UPI QR. They pay, send the UTR and a screenshot, and the registration shows *Payment under review* until you verify it in **Payments**. Then it is *Confirmed*.

**Attendees** shows the counts for the caterer (confirmed people, veg / non-veg, adults / children) and each registration with everyone's ticket. **Add attendee** offers every ticket including admin-only ones (crew: ₹0, confirmed at once); for others record their cash in Payments → Record a payment with the order reference. **Download CSV** lists every person with their ticket.

## Where and places

**Where**: *In person* shows Venue and Address (the venue is required); *Online* shows the Online link (required). **Places**: tick *Limit the number of places*, then *How many places* (everyone counts: the person registering and each guest).

## The event page

Every event has a public page at **/e/** + its uid (shown under the event's links; old links by name still work). When the event is *Everyone (public page)*, anyone can open it.

- **Page look** (event form): a **Cover image** (square works best) and a **Colour** (any colour, from the colour picker). Without a cover, one is drawn in the colour with the title and the date.
- **Google Maps link** (in person, optional): paste the place's link from Google Maps (Share → Copy link). The page then shows the **map** and **Open in Maps**; without it, neither shows.
- **Tickets**: each with its price and what's included; a ticket with *How many can be sold* shows how many are **left** (*Sold out* at 0).
