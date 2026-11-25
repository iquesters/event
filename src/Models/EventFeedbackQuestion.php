<?php

namespace Iquesters\Event\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A question of an event's feedback form. */
class EventFeedbackQuestion extends Model
{
    public const TYPE_RATING = 'rating';

    public const TYPE_YES_NO = 'yes_no';

    public const TYPE_TEXT = 'text';

    public const TYPES = [self::TYPE_RATING, self::TYPE_YES_NO, self::TYPE_TEXT];

    protected $table = 'event_feedback_questions';

    protected $fillable = ['event_id', 'type', 'title', 'description', 'required', 'sort'];

    protected $casts = ['required' => 'boolean'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(EventFeedbackAnswer::class, 'event_feedback_question_id');
    }
}
