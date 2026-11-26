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


## Paid events (tickets, guests, meals)

Needs the commerce and payment modules.

**Set up** (Manage events → the event → Edit → *Paid registration*):

1. Tick **Registration is paid**.
2. **Pass**: the price per person over the free age (e.g. ₹600).
3. **Pass with membership**: what an alumnus pays, the pass plus the membership (e.g. ₹600), with the **membership period** (e.g. 1 Jan – 31 Dec 2027) and the **membership's own price** (e.g. ₹300, used after the event or when bought alone).
4. **Children free up to** (e.g. 5), the **order reference prefix** (e.g. TTV-MEET), **donation** on or off, and whether paying **approves the alumni's memberships** or sends them to Applications.
5. Save. The products appear in **Commerce → Products** (pass, child, crew, membership, pass with membership, donation). Change prices there or here; orders already made keep their prices.
6. Set **Payment settings** (your UPI ID) in the payment module, and optionally the event's **Banners** (desktop, tablet, mobile) in its record.

**People register** on the event page: their details, gender and meal, then each guest (over the free age or not, meal, "also an alumnus" with their email). **Continue to payment** shows the total and the UPI QR. They pay, send the UTR and a screenshot, and the registration shows *Payment under review* until you verify it in **Payments**. Then it is *Confirmed*.

**Attendees** shows the counts for the caterer (confirmed people, veg / non-veg, adults / children) and each registration. **Add attendee**: tick *free* for crew and invited guests; otherwise record their cash in Payments → Record a payment with the order reference. **Download CSV** lists every person.
