<?php

namespace Iquesters\Event\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Iquesters\Organisation\Models\Organisation;

/**
 * An event of an organisation.
 *
 * @property string $uid
 * @property string $title
 * @property string $slug
 * @property string $status
 * @property string $visibility
 * @property Carbon $starts_at
 * @property ?Carbon $ends_at
 * @property ?Carbon $registration_closes_at
 * @property ?int $capacity
 * @property bool $registration_open
 */
class Event extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_CANCELLED];

    public const VISIBILITY_MEMBERS = 'members';

    public const VISIBILITY_PUBLIC = 'public';

    /** Where it happens: in person (venue, address) or online (a link). */
    public const LOCATION_IN_PERSON = 'in_person';

    public const LOCATION_ONLINE = 'online';

    public const LOCATIONS = [self::LOCATION_IN_PERSON, self::LOCATION_ONLINE];

    protected $table = 'events';

    protected $fillable = [
        'uid',
        'organisation_id',
        'title',
        'slug',
        'type',
        'description',
        'location_type',
        'cover_image',
        'theme',
        'maps_url',
        'starts_at',
        'ends_at',
        'venue',
        'address',
        'online_url',
        'visibility',
        'status',
        'registration_open',
        'capacity',
        'registration_closes_at',
        'feedback_enabled',
        'feedback_anonymous',
        'is_paid',
        'paid_settings',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'registration_closes_at' => 'datetime',
        'registration_open' => 'boolean',
        'feedback_enabled' => 'boolean',
        'feedback_anonymous' => 'boolean',
        'capacity' => 'integer',
        'is_paid' => 'boolean',
        'paid_settings' => 'array',
    ];

    /** The page's colour when none is chosen. */
    public const DEFAULT_COLOR = '#4f46e5';

    /** Older events kept a theme name: its colour. */
    private const THEME_NAMES = [
        'indigo' => '#4f46e5', 'rose' => '#e11d48', 'emerald' => '#059669', 'sky' => '#0284c7',
        'amber' => '#d97706', 'violet' => '#7c3aed', 'slate' => '#334155',
    ];

    /** The chosen colour (#rrggbb). */
    public function color(): string
    {
        $theme = strtolower(trim((string) $this->theme));
        if (preg_match('/^#[0-9a-f]{6}$/', $theme)) {
            return $theme;
        }

        return self::THEME_NAMES[$theme] ?? self::DEFAULT_COLOR;
    }

    /**
     * The page's two colours: the chosen one, and a deeper neighbour (hue
     * turned a little, darker) for gradients.
     *
     * @return array{0: string, 1: string, 2: string} label, from, to
     */
    public function themeColors(): array
    {
        $from = $this->color();
        [$r, $g, $b] = array_map(fn ($i) => hexdec(substr($from, $i, 2)) / 255, [1, 3, 5]);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $h = 0.0;
        $s = 0.0;
        if ($d > 0) {
            $s = $d / (1 - abs(2 * $l - 1));
            $h = match ($max) {
                $r => fmod((($g - $b) / $d), 6),
                $g => (($b - $r) / $d) + 2,
                default => (($r - $g) / $d) + 4,
            } * 60;
        }
        $to = self::hsl(fmod($h + 28 + 360, 360), $s, max(0.12, $l - 0.12));

        return [$from, $from, $to];
    }

    private static function hsl(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
        };

        return sprintf('#%02x%02x%02x', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }

    /**
     * The map to show on the public page, from the Google Maps link: its
     * coordinates or place when the link has them, else the venue and address.
     * Null without a link.
     */
    public function mapEmbedUrl(): ?string
    {
        $link = trim((string) $this->maps_url);
        if ($link === '' || $this->isOnline()) {
            return null;
        }
        $query = null;
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $link, $m)) {
            $query = $m[1].','.$m[2];
        } else {
            parse_str((string) parse_url($link, PHP_URL_QUERY), $params);
            $query = $params['q'] ?? $params['query'] ?? null;
            if (! $query && preg_match('#/place/([^/@]+)#', $link, $m)) {
                $query = urldecode(str_replace('+', ' ', $m[1]));
            }
        }
        $query = $query ?: trim($this->venue.' '.$this->address);

        return $query === '' ? null : 'https://maps.google.com/maps?q='.urlencode($query).'&z=15&output=embed';
    }

    /** The cover image's URL, or null (the page then draws one in the theme's colours). */
    public function coverUrl(): ?string
    {
        return $this->cover_image ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->cover_image) : null;
    }

    /**
     * Paid-registration settings (paid_settings): tickets (made as commerce
     * products, see EventProducts).
     */
    public const PAID_DEFAULTS = [
        // Children up to and including this age take the tickets "for children".
        'child_age' => 5,
        // Ask people registering for an optional donation (the organisation's donation product).
        'ask_donation' => false,
        // Registration is also joining the organisation: the ticket includes the membership.
        'with_membership' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->uid ??= (string) Str::ulid();
            $event->slug ??= self::uniqueSlug($event->title);
        });
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($title) ?: 'event', 100, '');
        $slug = $base;
        for ($i = 2; self::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function feedbackQuestions(): HasMany
    {
        return $this->hasMany(EventFeedbackQuestion::class)->orderBy('sort')->orderBy('id');
    }

    public function feedbackResponses(): HasMany
    {
        return $this->hasMany(EventFeedbackResponse::class);
    }

    public function isOnline(): bool
    {
        return $this->location_type === self::LOCATION_ONLINE;
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }

    /** Published, not yet over. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(fn ($q) => $q->where('ends_at', '>=', now())->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>=', now()->startOfDay())));
    }

    public function isPast(): bool
    {
        return ($this->ends_at ?? $this->starts_at->copy()->endOfDay())->isPast();
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC && $this->status !== self::STATUS_DRAFT;
    }

    /** People coming: registrations (confirmed or waiting for payment) plus their guests. */
    public function seatsTaken(): int
    {
        return (int) $this->registrations()->whereIn('status', EventRegistration::ACTIVE_STATUSES)->sum(DB::raw('1 + guests'));
    }

    /** A paid-registration setting (paid_settings), with its default. */
    public function paid(string $key, mixed $default = null): mixed
    {
        return ((array) $this->paid_settings)[$key] ?? self::PAID_DEFAULTS[$key] ?? $default;
    }

    public function seatsLeft(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->seatsTaken());
    }

    /** Why registration is closed now, or null when people can register. */
    public function registrationClosedReason(): ?string
    {
        return match (true) {
            $this->status === self::STATUS_CANCELLED => 'This event was cancelled.',
            $this->status !== self::STATUS_PUBLISHED => 'This event is not published yet.',
            $this->isPast() => 'This event is over.',
            ! $this->registration_open => 'Registration is closed.',
            $this->registration_closes_at !== null && $this->registration_closes_at->isPast() => 'Registration closed on '.$this->registration_closes_at->format('j M Y').'.',
            $this->seatsLeft() === 0 => 'This event is full.',
            default => null,
        };
    }

    public function statusLabel(): string
    {
        if ($this->status === self::STATUS_PUBLISHED && $this->isPast()) {
            return 'Past';
        }

        return Str::headline($this->status);
    }
}
