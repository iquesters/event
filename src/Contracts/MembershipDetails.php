<?php

namespace Iquesters\Event\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Iquesters\Event\Models\Event;

/**
 * What a membership module asks of a person registering for an event who is
 * (or becomes) a member of the event's organisation: e.g. community's alumni
 * ask what was completed at the school and when. Bound in the container by
 * that module; without one the event asks nothing extra.
 */
interface MembershipDetails
{
    /** What a member is called (e.g. "Alumnus"). */
    public function memberLabel(Event $event): string;

    /**
     * Fields asked of a member (form-schema fields; ids unique, e.g.
     * school_level, school_year, class_left). The event shows them only when
     * the person is ticked as a member.
     */
    public function fields(Event $event): array;

    /**
     * Check one person's values.
     *
     * @return array{0: array, 1: array<string, string>} the details to keep (passed on with their membership), and errors by field id
     */
    public function validate(Event $event, array $values): array;

    /**
     * The registrant's values from their account (e.g. their school records),
     * with "member" => true when they're already a member.
     */
    public function prefill(Event $event, Authenticatable $user): array;
}
