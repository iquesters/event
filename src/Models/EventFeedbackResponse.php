<?php

namespace Iquesters\Event\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Iquesters\Foundation\Support\UserModel;

/** One attendee's feedback on an event (one per person). */
class EventFeedbackResponse extends Model
{
    protected $table = 'event_feedback_responses';

    protected $fillable = ['uid', 'event_id', 'user_id', 'anonymous'];

    protected $casts = ['anonymous' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (self $response) {
            $response->uid ??= (string) Str::ulid();
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

    public function answers(): HasMany
    {
        return $this->hasMany(EventFeedbackAnswer::class, 'event_feedback_response_id');
    }
}
