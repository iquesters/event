# Settings

Event settings live in the DB conf (`EventConf`, identifier `iquesters/event`):

```php
use Iquesters\Event\Enums\Module;
use Iquesters\Foundation\Support\ConfProvider;

ConfProvider::from(Module::EVENT)->types();
```

| Setting | Default | Meaning |
|---|---|---|
| `event_types` | `Meet-up,Reunion,Seminar,Workshop,Sports,Cultural,Fundraiser,Other` | Types to choose from (comma-separated) |
| `public_events` | `true` | Whether an event can be public (a page anyone can open, `/e/{slug}`) |

## Access

- **Manage events:** super-admin, and organisation admins for their organisations (`Iquesters\Organisation\Support\OrganisationAccess`).
- **See and register:** members of the organisation (published and cancelled events); for public events, anyone signed in.
- `EventAccess` holds these rules; `EventRegistrar` registers and cancels (capacity checked under a lock).
