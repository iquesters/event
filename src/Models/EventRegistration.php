<?php

namespace Iquesters\Event\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Iquesters\Foundation\Support\UserModel;

/** A person's registration for an event (one per person per event). */
class EventRegistration extends Model
{
    public const STATUS_REGISTERED = 'registered';

    public const STATUS_CANCELLED = 'cancelled';

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
    ];

    protected $casts = [
        'guests' => 'integer',
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
}
