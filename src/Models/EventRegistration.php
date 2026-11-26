<?php

namespace Iquesters\Event\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Iquesters\Commerce\Models\Order;
use Iquesters\Foundation\Support\UserModel;

/** A person's registration for an event (one per person per event). */
class EventRegistration extends Model
{
    /** Confirmed: free, or the order is paid. */
    public const STATUS_REGISTERED = 'registered';

    /** Paid registration: the order waits for payment (also after a rejected payment). */
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    /** Paid registration: proof sent, an admin checks it. */
    public const STATUS_UNDER_REVIEW = 'payment_under_review';

    public const STATUS_CANCELLED = 'cancelled';

    /** Holding places. */
    public const ACTIVE_STATUSES = [self::STATUS_REGISTERED, self::STATUS_AWAITING_PAYMENT, self::STATUS_UNDER_REVIEW];

    public const LABELS = [
        self::STATUS_REGISTERED => 'Confirmed',
        self::STATUS_AWAITING_PAYMENT => 'Awaiting payment',
        self::STATUS_UNDER_REVIEW => 'Payment under review',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    public const GENDERS = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];

    public const MEALS = ['veg' => 'Veg', 'non_veg' => 'Non-veg'];

    /** Most guests one person may bring. */
    public const MAX_GUESTS = 10;

    protected $table = 'event_registrations';

    protected $fillable = [
        'uid',
        'event_id',
        'user_id',
        'status',
        'guests',
        'note',
        'checked_in_at',
        'order_id',
        'name',
        'email',
        'phone',
        'gender',
        'meal',
        'adults',
        'children',
        'veg',
        'non_veg',
        'donation',
        'people',
        'source',
        'terms_accepted_at',
        'added_by',
    ];

    protected $casts = [
        'guests' => 'integer',
        'adults' => 'integer',
        'children' => 'integer',
        'veg' => 'integer',
        'non_veg' => 'integer',
        'donation' => 'decimal:2',
        'people' => 'array',
        'terms_accepted_at' => 'datetime',
        'checked_in_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $registration) {
            $registration->uid ??= (string) Str::ulid();
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::name());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? Str::headline((string) $this->status);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** The registrant's name: as entered, else the account's. */
    public function displayName(): ?string
    {
        return $this->name ?: $this->user?->name;
    }
}
