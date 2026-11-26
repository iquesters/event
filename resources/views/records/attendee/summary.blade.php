{{--
    Record component "event.attendee": one row of an event's Attendees list. The name,
    a coloured status badge, contact, the order and its total, people and meals.
    Plain HTML with __TOKENS__ filled in the browser from the row
    (see user-interface docs/record-components.md).
--}}
<div class="d-grid gap-1 small">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <span class="fw-semibold text-truncate">__NAME__</span>
        <span class="d-flex flex-wrap gap-1 justify-content-end">__ADMIN__ __STATUS__</span>
    </div>
    __CONTACT__
    <div class="d-flex justify-content-between gap-2 text-muted">
        <span>__PEOPLE__ __MEALS__</span>
        <span class="text-nowrap">__ORDER__</span>
    </div>
    <div class="d-flex justify-content-between gap-2 text-muted">
        <span>__DONATION__</span>
        <span class="text-nowrap">__WHEN__</span>
    </div>
</div>
<script type="application/json" data-component-bindings>
{
    "__NAME__": {"type": "text", "paths": ["name"], "fallback": "—"},
    "__PEOPLE__": {"type": "text", "paths": ["people_label"], "fallback": ""},
    "__WHEN__": {"type": "text", "paths": ["registered_at"], "fallback": ""},
    "__CONTACT__": {
        "type": "template",
        "fields": {"value": {"paths": ["contact"]}},
        "condition": "value",
        "template": "<div class=\"text-muted text-truncate\">__VALUE__</div>",
        "fallback_template": ""
    },
    "__MEALS__": {
        "type": "template",
        "fields": {"value": {"paths": ["meals"]}},
        "condition": "value",
        "template": "· __VALUE__",
        "fallback_template": ""
    },
    "__ORDER__": {
        "type": "template",
        "fields": {"ref": {"paths": ["reference"]}, "total": {"paths": ["total_label"]}},
        "condition": "ref",
        "template": "<span class=\"font-monospace\">__REF__</span> · __TOTAL__",
        "fallback_template": ""
    },
    "__DONATION__": {
        "type": "template",
        "fields": {"value": {"paths": ["donation_label"]}},
        "condition": "value",
        "template": "__VALUE__",
        "fallback_template": ""
    },
    "__STATUS__": {
        "type": "template",
        "fields": {"label": {"paths": ["status_label"]}, "tone": {"paths": ["status_tone"], "fallback": "secondary"}},
        "condition": "label",
        "template": "<span class=\"badge text-bg-__TONE__\">__LABEL__</span>",
        "fallback_template": ""
    },
    "__ADMIN__": {
        "type": "template",
        "fields": {"value": {"paths": ["added_by_admin"]}},
        "condition": "value",
        "template": "<span class=\"badge text-bg-light border\">__VALUE__</span>",
        "fallback_template": ""
    }
}
</script>
