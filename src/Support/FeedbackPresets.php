<?php

namespace Iquesters\Event\Support;

use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventFeedbackQuestion;

/**
 * Starting questions by event type (admins change them afterwards).
 * Each: [type, title].
 */
class FeedbackPresets
{
    private const COMMON_END = [
        [EventFeedbackQuestion::TYPE_YES_NO, 'Would you come to the next one?'],
        [EventFeedbackQuestion::TYPE_TEXT, 'Anything we should do better?'],
    ];

    public static function for(?string $type): array
    {
        $specific = match (strtolower((string) $type)) {
            'reunion', 'meet-up' => [
                [EventFeedbackQuestion::TYPE_RATING, 'How was the event overall?'],
                [EventFeedbackQuestion::TYPE_RATING, 'Venue and arrangements'],
                [EventFeedbackQuestion::TYPE_RATING, 'Food and refreshments'],
                [EventFeedbackQuestion::TYPE_RATING, 'Chances to meet old friends'],
            ],
            'seminar', 'workshop' => [
                [EventFeedbackQuestion::TYPE_RATING, 'How was the session overall?'],
                [EventFeedbackQuestion::TYPE_RATING, 'Speakers'],
                [EventFeedbackQuestion::TYPE_RATING, 'How useful was the content?'],
                [EventFeedbackQuestion::TYPE_RATING, 'Venue and arrangements'],
            ],
            'sports', 'cultural' => [
                [EventFeedbackQuestion::TYPE_RATING, 'How was the event overall?'],
                [EventFeedbackQuestion::TYPE_RATING, 'Organisation and timing'],
                [EventFeedbackQuestion::TYPE_RATING, 'Venue and arrangements'],
            ],
            default => [
                [EventFeedbackQuestion::TYPE_RATING, 'How was the event overall?'],
                [EventFeedbackQuestion::TYPE_RATING, 'Venue and arrangements'],
                [EventFeedbackQuestion::TYPE_RATING, 'Organisation'],
            ],
        };

        return [...$specific, ...self::COMMON_END];
    }

    /** Give the event its type's questions (only when it has none). */
    public static function apply(Event $event): void
    {
        if ($event->feedbackQuestions()->exists()) {
            return;
        }

        foreach (self::for($event->type) as $i => [$type, $title]) {
            $event->feedbackQuestions()->create([
                'type' => $type,
                'title' => $title,
                // Ratings and yes / no are quick: required; text stays optional.
                'required' => $type !== EventFeedbackQuestion::TYPE_TEXT,
                'sort' => ($i + 1) * 10,
            ]);
        }
    }
}
