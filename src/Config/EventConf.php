<?php

namespace Iquesters\Event\Config;

use Iquesters\Event\Enums\Module;
use Iquesters\Foundation\Support\BaseConf;

/** Event settings, stored in the DB conf (see docs/developer/configuration.md). */
class EventConf extends BaseConf
{
    protected ?string $identifier = Module::EVENT;

    /** Event types to choose from (comma-separated). */
    protected string $event_types;

    /** Whether events can be public (a page anyone can open). */
    protected bool $public_events;

    /** What people get for an event, as the pages and emails call it (e.g. "pass"): one, and several. */
    protected string $ticket_word;

    protected string $ticket_word_plural;

    protected function prepareDefault(BaseConf $default_values)
    {
        $default_values->event_types = 'Meet-up,Reunion,Seminar,Workshop,Sports,Cultural,Fundraiser,Other';
        $default_values->public_events = true;
        $default_values->ticket_word = 'ticket';
        $default_values->ticket_word_plural = 'tickets';
    }

    protected function rules(): array
    {
        return [
            'event_types' => 'string|max:1000',
            'public_events' => 'boolean',
            'ticket_word' => 'string|min:2|max:30',
            'ticket_word_plural' => 'string|min:2|max:30',
        ];
    }

    /** @return string[] */
    public function types(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->event_types))));
    }
}
