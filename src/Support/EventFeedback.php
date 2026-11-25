<?php

namespace Iquesters\Event\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventFeedbackAnswer;
use Iquesters\Event\Models\EventFeedbackQuestion;
use Iquesters\Event\Models\EventFeedbackResponse;
use Iquesters\Event\Models\EventRegistration;

/**
 * Feedback and ratings: who may answer (people registered for the event, once
 * it has started, once each), saving the answers, and the results.
 */
class EventFeedback
{
    /** Why the user can't give feedback now, or null when they can. */
    public function closedReason(?Authenticatable $user, Event $event): ?string
    {
        return match (true) {
            ! $event->feedback_enabled || $event->status !== Event::STATUS_PUBLISHED => 'Feedback is not open for this event.',
            ! $event->hasStarted() => 'You can give feedback once the event has started.',
            $user === null => 'Sign in to give feedback.',
            ! $this->attended($user, $event) => 'Only people registered for this event can give feedback.',
            $this->responseOf($user, $event) !== null => 'Thank you, you have given your feedback.',
            ! $event->feedbackQuestions()->exists() => 'Feedback is not open for this event.',
            default => null,
        };
    }

    public function responseOf(Authenticatable $user, Event $event): ?EventFeedbackResponse
    {
        return EventFeedbackResponse::query()->where('event_id', $event->id)->where('user_id', $user->getAuthIdentifier())->first();
    }

    /**
     * @param  array<int|string, mixed>  $answers  question id => rating (1-5) | "1"/"0" | text
     *
     * @throws \DomainException when feedback is closed for the user
     * @throws ValidationException
     */
    public function submit(Authenticatable $user, Event $event, array $answers, bool $anonymous = false): EventFeedbackResponse
    {
        if ($reason = $this->closedReason($user, $event)) {
            throw new \DomainException($reason);
        }

        $questions = $event->feedbackQuestions()->get();
        $rules = [];
        $attributes = [];
        foreach ($questions as $question) {
            $key = "answers.{$question->id}";
            $attributes[$key] = $question->title;
            $rules[$key] = [$question->required ? 'required' : 'nullable', ...match ($question->type) {
                EventFeedbackQuestion::TYPE_RATING => ['integer', 'between:1,5'],
                EventFeedbackQuestion::TYPE_YES_NO => ['boolean'],
                default => ['string', 'max:2000'],
            }];
        }
        Validator::make(['answers' => $answers], $rules, ['answers.*.required' => 'Please answer this question.'], $attributes)->validate();

        return DB::transaction(function () use ($user, $event, $questions, $answers, $anonymous) {
            $response = EventFeedbackResponse::create([
                'event_id' => $event->id,
                'user_id' => $user->getAuthIdentifier(),
                'anonymous' => $anonymous && $event->feedback_anonymous,
            ]);

            foreach ($questions as $question) {
                $value = $answers[$question->id] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                EventFeedbackAnswer::create([
                    'event_feedback_response_id' => $response->id,
                    'event_feedback_question_id' => $question->id,
                    'rating' => $question->type === EventFeedbackQuestion::TYPE_RATING ? (int) $value : null,
                    'yes' => $question->type === EventFeedbackQuestion::TYPE_YES_NO ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : null,
                    'text' => $question->type === EventFeedbackQuestion::TYPE_TEXT ? mb_substr(trim((string) $value), 0, 2000) : null,
                ]);
            }

            return $response;
        });
    }

    /**
     * Results: the overall rating (average of all rating answers), and per question
     * the average, spread of 1-5, yes / no counts, or the comments.
     */
    public function results(Event $event): array
    {
        $questions = $event->feedbackQuestions()->with(['answers.response.user:id,name'])->get();
        $ratings = $questions->where('type', EventFeedbackQuestion::TYPE_RATING)->flatMap->answers->pluck('rating')->filter();

        return [
            'responses' => $event->feedbackResponses()->count(),
            'overall' => $ratings->isEmpty() ? null : round($ratings->avg(), 1),
            'questions' => $questions->map(function (EventFeedbackQuestion $question) {
                $answers = $question->answers;

                return [
                    'question' => $question,
                    'count' => $answers->count(),
                    'average' => $question->type === EventFeedbackQuestion::TYPE_RATING && $answers->isNotEmpty() ? round($answers->avg('rating'), 1) : null,
                    'spread' => $question->type === EventFeedbackQuestion::TYPE_RATING
                        ? collect(range(5, 1))->mapWithKeys(fn ($n) => [$n => $answers->where('rating', $n)->count()])->all()
                        : null,
                    'yes' => $question->type === EventFeedbackQuestion::TYPE_YES_NO ? $answers->where('yes', true)->count() : null,
                    'no' => $question->type === EventFeedbackQuestion::TYPE_YES_NO ? $answers->where('yes', false)->count() : null,
                    'comments' => $question->type === EventFeedbackQuestion::TYPE_TEXT
                        ? $answers->map(fn ($a) => ['text' => $a->text, 'by' => $a->response->anonymous ? 'Anonymous' : ($a->response->user?->name ?? '—')])->values()->all()
                        : [],
                ];
            })->all(),
        ];
    }

    /** The overall rating and how many responses, for the event page (null: nothing yet). */
    public function summary(Event $event): ?array
    {
        $average = EventFeedbackAnswer::query()
            ->join('event_feedback_questions as q', 'q.id', '=', 'event_feedback_answers.event_feedback_question_id')
            ->where('q.event_id', $event->id)
            ->whereNotNull('event_feedback_answers.rating')
            ->avg('event_feedback_answers.rating');

        return $average === null ? null : ['average' => round((float) $average, 1), 'responses' => $event->feedbackResponses()->count()];
    }

    private function attended(Authenticatable $user, Event $event): bool
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', EventRegistration::STATUS_REGISTERED)
            ->exists();
    }
}
