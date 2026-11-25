<?php

namespace Iquesters\Event\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventFeedbackQuestion;
use Iquesters\Event\Support\EventAccess;
use Iquesters\Event\Support\EventFeedback;
use Iquesters\Event\Support\EventForms;
use Iquesters\Event\Support\FeedbackPresets;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The admin's side of feedback: the questions, the results (and CSV), and the QR poster. */
class EventFeedbackManageController extends Controller
{
    public function __construct(private EventFeedback $feedback) {}

    public function questions(Request $request, string $uid): View
    {
        $event = $this->find($request, $uid);

        return view('event::manage.feedback-questions', [
            'event' => $event,
            'responses' => $event->feedbackResponses()->count(),
            // The repeater's rows (each keeps its id).
            'formData' => ['questions' => $event->feedbackQuestions()->get()
                ->map(fn ($q) => ['id' => $q->id, 'title' => $q->title, 'type' => $q->type, 'required' => $q->required])->all()],
        ]);
    }

    /** The event type's question set again (replaces the questions). */
    public function preset(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        $event->feedbackQuestions()->delete();
        FeedbackPresets::apply($event);
        EventForms::syncFeedback($event);
        session()->flash('success', 'Questions set from the '.($event->type ?: 'general').' set.');

        return response()->json(['success' => true, 'message' => 'Questions replaced.', 'redirect_url' => route('events.manage.feedback.questions', $event->uid)]);
    }

    /**
     * The questions form (repeater) posts here: rows with an id are updated, new
     * rows added, rows no longer sent deleted (with their answers).
     */
    public function saveQuestions(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);

        $validated = $request->validate([
            'questions' => ['array', 'max:30'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.title' => ['required', 'string', 'max:255'],
            'questions.*.type' => ['required', Rule::in(EventFeedbackQuestion::TYPES)],
            'questions.*.required' => ['nullable', 'boolean'],
        ], ['questions.*.title.required' => 'Write the question.']);

        $existing = $event->feedbackQuestions()->get()->keyBy('id');
        $kept = [];
        $sort = 0;
        foreach ($validated['questions'] ?? [] as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $values = [
                'title' => $title,
                'type' => $row['type'] ?? EventFeedbackQuestion::TYPE_RATING,
                'required' => ! empty($row['required']),
                'sort' => $sort += 10,
            ];
            $question = isset($row['id']) && $existing->has((int) $row['id'])
                ? tap($existing[(int) $row['id']])->update($values)
                : $event->feedbackQuestions()->create($values);
            $kept[] = $question->id;
        }
        $event->feedbackQuestions()->whereNotIn('id', $kept)->delete();
        EventForms::syncFeedback($event);

        return response()->json(['success' => true, 'message' => 'Questions saved.']);
    }

    public function results(Request $request, string $uid): View
    {
        $event = $this->find($request, $uid);

        return view('event::manage.feedback-results', ['event' => $event, 'results' => $this->feedback->results($event)]);
    }

    /** One row per response, one column per question. */
    public function export(Request $request, string $uid): StreamedResponse
    {
        $event = $this->find($request, $uid);
        $questions = $event->feedbackQuestions()->get();
        $responses = $event->feedbackResponses()->with(['answers', 'user:id,name,email'])->orderBy('created_at')->get();

        return response()->streamDownload(function () use ($questions, $responses) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Email', 'When', ...$questions->pluck('title')->all()], ',', '"', '');
            foreach ($responses as $response) {
                $answers = $response->answers->keyBy('event_feedback_question_id');
                fputcsv($out, [
                    $response->anonymous ? 'Anonymous' : $response->user?->name,
                    $response->anonymous ? '' : $response->user?->email,
                    $response->created_at?->format('Y-m-d H:i'),
                    ...$questions->map(function ($q) use ($answers) {
                        $a = $answers->get($q->id);

                        return match (true) {
                            $a === null => '',
                            $q->type === EventFeedbackQuestion::TYPE_RATING => $a->rating,
                            $q->type === EventFeedbackQuestion::TYPE_YES_NO => $a->yes ? 'Yes' : 'No',
                            default => $a->text,
                        };
                    })->all(),
                ], ',', '"', '');
            }
            fclose($out);
        }, $event->slug.'-feedback.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A printable poster with a QR code to the feedback form (print, or save as PDF). */
    public function poster(Request $request, string $uid): View
    {
        $event = $this->find($request, $uid);

        return view('event::manage.feedback-poster', ['event' => $event, 'url' => route('events.feedback.show', $event->uid)]);
    }

    private function find(Request $request, string $uid): Event
    {
        $event = Event::query()->with('organisation')->where('uid', $uid)->firstOrFail();
        abort_unless(EventAccess::canManageEvent($request->user(), $event), 404);

        return $event;
    }
}
