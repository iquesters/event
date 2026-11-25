<?php

namespace Iquesters\Event\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The answer to one question: a rating (1-5), yes / no, or text. */
class EventFeedbackAnswer extends Model
{
    public $timestamps = false;

    protected $table = 'event_feedback_answers';

    protected $fillable = ['event_feedback_response_id', 'event_feedback_question_id', 'rating', 'yes', 'text'];

    protected $casts = ['rating' => 'integer', 'yes' => 'boolean'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(EventFeedbackQuestion::class, 'event_feedback_question_id');
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(EventFeedbackResponse::class, 'event_feedback_response_id');
    }
}
