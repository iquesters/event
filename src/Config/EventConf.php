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

    protected function prepareDefault(BaseConf $default_values)
    {
        $default_values->event_types = 'Meet-up,Reunion,Seminar,Workshop,Sports,Cultural,Fundraiser,Other';
        $default_values->public_events = true;
    }

    protected function rules(): array
    {
        return [
            'event_types' => 'string|max:1000',
            'public_events' => 'boolean',
        ];
    }

    /** @return string[] */
    public function types(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->event_types))));
    }
}
