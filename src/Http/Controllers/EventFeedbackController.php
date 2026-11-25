<?php

namespace Iquesters\Event\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Support\EventAccess;
use Iquesters\Event\Support\EventFeedback;
use Iquesters\Event\Support\EventForms;

/** The attendee's side: the feedback form of an event (also opened from the QR poster). */
class EventFeedbackController extends Controller
{
    public function __construct(private EventFeedback $feedback) {}

    public function show(Request $request, string $uid): View
    {
        $event = $this->find($request, $uid);

        $closed = $this->feedback->closedReason($request->user(), $event);
        if (! $closed) {
            EventForms::syncFeedback($event); // the form follows the current questions
        }

        return view('event::feedback.form', [
            'event' => $event,
            'formId' => EventForms::feedbackSlug($event),
            'closedReason' => $closed,
        ]);
    }

    /** The event's feedback form (form schema event-feedback-{uid}) posts here: q{question id} => answer. */
    public function store(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        $answers = [];
        foreach ($event->feedbackQuestions()->pluck('id') as $id) {
            $answers[$id] = $request->input('q'.$id);
        }

        try {
            $this->feedback->submit($request->user(), $event, $answers, $request->boolean('anonymous'));
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            // Errors under the form's fields: answers.{id} is q{id} there.
            $errors = collect($e->errors())->mapWithKeys(fn ($m, $k) => [preg_replace('/^answers\.(\d+)$/', 'q$1', $k) => $m])->all();

            return response()->json(['success' => false, 'message' => 'Please check the highlighted questions.', 'errors' => $errors], 422);
        }

        session()->flash('success', 'Thank you for your feedback.');

        return response()->json(['success' => true, 'message' => 'Thank you for your feedback.', 'redirect_url' => route('events.show', $event->uid)]);
    }

    private function find(Request $request, string $uid): Event
    {
        $event = Event::query()->with('organisation')->where('uid', $uid)->firstOrFail();
        abort_unless(EventAccess::canSee($request->user(), $event), 404);

        return $event;
    }
}
