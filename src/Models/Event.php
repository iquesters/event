<?php

namespace Iquesters\Event\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    protected $table = 'events';

    protected $fillable = [
        'uid',
        'organisation_id',
        'title',
        'slug',
        'type',
        'description',
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
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'registration_closes_at' => 'datetime',
        'registration_open' => 'boolean',
        'capacity' => 'integer',
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

    /** People coming: registrations plus their guests. */
    public function seatsTaken(): int
    {
        return (int) $this->registrations()->where('status', EventRegistration::STATUS_REGISTERED)->sum(DB::raw('1 + guests'));
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
