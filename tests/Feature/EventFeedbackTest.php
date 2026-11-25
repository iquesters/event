<?php

namespace Iquesters\Event\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventFeedbackQuestion;
use Iquesters\Event\Models\EventFeedbackResponse;
use Iquesters\Event\Support\EventFeedback;
use Iquesters\Event\Support\EventRegistrar;
use Iquesters\Event\Support\FeedbackPresets;
use Iquesters\Event\Tests\TestCase;
use Iquesters\Organisation\Models\Organisation;

class EventFeedbackTest extends TestCase
{
    /** A reunion that started an hour ago, with feedback on and its preset questions. */
    private function startedEvent(Organisation $organisation, array $attributes = []): Event
    {
        $event = $this->makeEvent($organisation, $attributes + [
            'type' => 'Reunion',
            'starts_at' => now()->subHour(),
            'feedback_enabled' => true,
            'feedback_anonymous' => true,
        ]);
        FeedbackPresets::apply($event);

        return $event;
    }

    /** The feedback form's fields (q{question id}) for the preset: ratings, yes / no, text. */
    private function formAnswers(Event $event, int $rating = 4): array
    {
        return collect($this->answers($event, $rating))->mapWithKeys(fn ($v, $id) => ['q'.$id => $v])->all();
    }

    /** Answers to the preset: ratings, yes / no, text. */
    private function answers(Event $event, int $rating = 4): array
    {
        return $event->feedbackQuestions()->get()->mapWithKeys(fn (EventFeedbackQuestion $q) => [$q->id => match ($q->type) {
            EventFeedbackQuestion::TYPE_RATING => $rating,
            EventFeedbackQuestion::TYPE_YES_NO => '1',
            default => 'Loved it',
        }])->all();
    }

    private function attendee(Organisation $organisation, Event $event)
    {
        $user = $this->memberOf($organisation);
        // Registered before the event started.
        $event->registrations()->create(['user_id' => $user->id, 'status' => 'registered']);

        return $user;
    }

    public function test_the_preset_follows_the_event_type(): void
    {
        $event = $this->startedEvent($this->makeOrganisation());

        $titles = $event->feedbackQuestions()->pluck('title')->all();
        $this->assertContains('Chances to meet old friends', $titles);
        $this->assertSame(['rating', 'rating', 'rating', 'rating', 'yes_no', 'text'], $event->feedbackQuestions()->pluck('type')->all());

        // Applying again keeps edited questions.
        $event->feedbackQuestions()->first()->update(['title' => 'Changed']);
        FeedbackPresets::apply($event);
        $this->assertSame('Changed', $event->feedbackQuestions()->first()->title);
    }

    public function test_attendees_give_feedback_once(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->startedEvent($organisation);
        $user = $this->attendee($organisation, $event);
        $this->actingAs($user);

        $this->get("/events/{$event->uid}")->assertSee('Give feedback');
        // The page holds the event's feedback form (form schema built from its questions).
        $this->get("/events/{$event->uid}/feedback")->assertOk()->assertSee('id="event-feedback-'.strtolower($event->uid).'"', false);
        $schema = json_decode(DB::table('form_schemas')->where('slug', 'event-feedback-'.strtolower($event->uid))->value('schema'), true);
        $this->assertSame('How was the event overall?', $schema['fields'][0]['label']);

        $this->postJson("/events/{$event->uid}/feedback", $this->formAnswers($event) + ['anonymous' => '1'])
            ->assertOk()->assertJsonPath('redirect_url', route('events.show', $event->uid));

        $response = EventFeedbackResponse::firstOrFail();
        $this->assertTrue($response->anonymous);
        $this->assertSame(6, $response->answers()->count());

        $this->postJson("/events/{$event->uid}/feedback", $this->formAnswers($event))
            ->assertStatus(422)->assertJsonPath('message', 'Thank you, you have given your feedback.');
    }

    public function test_required_answers_and_ratings_are_checked(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->startedEvent($organisation);
        $this->actingAs($this->attendee($organisation, $event));
        $first = $event->feedbackQuestions()->first();

        // Errors come back under the form's fields (q{id}).
        $this->postJson("/events/{$event->uid}/feedback", ['q'.$first->id => 9])
            ->assertStatus(422)->assertJsonValidationErrors('q'.$first->id);
        $this->assertSame(0, EventFeedbackResponse::count());
    }

    public function test_only_attendees_after_the_start_may_answer(): void
    {
        $organisation = $this->makeOrganisation();
        $feedback = app(EventFeedback::class);

        $started = $this->startedEvent($organisation);
        $this->assertSame('Only people registered for this event can give feedback.', $feedback->closedReason($this->memberOf($organisation), $started));

        $later = $this->startedEvent($organisation, ['title' => 'Later', 'starts_at' => now()->addDay()]);
        $user = $this->memberOf($organisation);
        app(EventRegistrar::class)->register($user, $later);
        $this->assertSame('You can give feedback once the event has started.', $feedback->closedReason($user, $later));

        $off = $this->startedEvent($organisation, ['title' => 'Off', 'feedback_enabled' => false]);
        $this->assertSame('Feedback is not open for this event.', $feedback->closedReason($this->attendee($organisation, $off), $off));
    }

    public function test_results_poster_and_csv_for_admins(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->startedEvent($organisation);
        $feedback = app(EventFeedback::class);
        $feedback->submit($this->attendee($organisation, $event), $event, $this->answers($event, 5));
        $ann = $this->attendee($organisation, $event);
        $feedback->submit($ann, $event, $this->answers($event, 3));

        $results = $feedback->results($event);
        $this->assertSame(2, $results['responses']);
        $this->assertSame(4.0, $results['overall']);
        $this->assertSame([5 => 1, 4 => 0, 3 => 1, 2 => 0, 1 => 0], $results['questions'][0]['spread']);
        $this->assertSame(2, $results['questions'][4]['yes']);

        $this->actingAs($this->memberOf($organisation, 'admin'));
        $this->get("/events/manage/{$event->uid}/feedback")->assertOk()->assertSee('Loved it')->assertSee($ann->name);
        $this->get("/events/manage/{$event->uid}/feedback/poster")->assertOk()->assertSee(route('events.feedback.show', $event->uid), false);
        $this->assertStringContainsString('Loved it', $this->get("/events/manage/{$event->uid}/feedback.csv")->streamedContent());

        // Members can't see the results.
        $this->actingAs($ann)->get("/events/manage/{$event->uid}/feedback")->assertForbidden();
    }

    public function test_admins_edit_the_questions(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->startedEvent($organisation);
        $first = $event->feedbackQuestions()->first();
        $this->actingAs($this->memberOf($organisation, 'admin'));

        // The questions form (repeater) posts its rows; rows keep their id.
        $this->putJson("/events/manage/{$event->uid}/feedback/questions", ['questions' => [
            ['id' => $first->id, 'title' => 'Overall?', 'type' => 'rating', 'required' => true],
            ['title' => 'Bring a friend next time?', 'type' => 'yes_no'],
        ]])->assertOk();

        $this->assertSame(['Overall?', 'Bring a friend next time?'], $event->feedbackQuestions()->pluck('title')->all());
        $this->assertSame($first->id, $event->feedbackQuestions()->first()->id);

        $this->putJson("/events/manage/{$event->uid}/feedback/preset")->assertOk();
        $this->assertSame(6, $event->feedbackQuestions()->count());
    }

    public function test_the_event_page_shows_the_rating_once_it_is_over(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->startedEvent($organisation, ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $user = $this->attendee($organisation, $event);
        app(EventFeedback::class)->submit($user, $event, $this->answers($event, 4));

        $this->actingAs($user)->get("/events/{$event->uid}")->assertOk()->assertSee('4')->assertSee('from 1 response');
    }
}
