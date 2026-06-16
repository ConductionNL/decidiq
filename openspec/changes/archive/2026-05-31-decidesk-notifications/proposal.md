---
kind: config
depends_on: [notification-updated-field-change-condition]
---

## Why

Decidesk (meeting/decision governance for councils, boards, NGOs, citizen participation) currently declares no `x-openregister-notifications` on its schemas, so the OpenRegister notification engine (change `notification-schema-rules-and-userconfig-prefs`, archived 2026-05-26) emits nothing for decidesk objects. Per the fleet notification plan, decidesk should notify about: meeting scheduled + reminder; action item assigned / overdue; motion submitted; decision recorded; participation deadlines.

This change adds schema-declared notification rules in the verified dialect to `lib/Settings/decidesk_register.json`. The design is constrained by two realities discovered while verifying recipient fields:

1. **`Participant`, `Motion.proposer`, and `ActionItem.assignee` do not hold Nextcloud user IDs.** `Participant` holds `displayName` + `email` (an email string, not a uid); `Motion.proposer` is a *name* ("Name of proposer"); `ActionItem.assignee` is "Assigned participant" (a participant reference / name, not a resolvable uid). The verified `kind:field` recipient resolves **uids only**, so assignee/proposer-based delivery is unreliable. Per the plan caveat, decidesk routes these to `kind:groups` (governance/secretariat staff) and `kind:object-acl` (whoever has read/manage on the object) instead of `kind:field`. The assignee-direct rule is declared but flagged: it only fires correctly once `assignee` carries a uid (a separate data-model change).

2. **No named lifecycle transition actions are defined** on Meeting/Motion/Decision (the schemas carry a `lifecycle` enum but no transition-action map). The verified `transition` trigger needs a named `action`. So status-change rules (meeting → scheduled, motion → submitted, decision recorded) are expressed today as `created` (notify when the row first appears in the target state) or `scheduled` reminders, and the precise "lifecycle entered X" form is deferred — see Caveats. `depends_on: notification-updated-field-change-condition` is declared because the most-wanted form ("notify when `lifecycle`/`taskStatus` changed to Y") needs the field-change condition on the `updated` trigger from that engine change.

## What Changes

Add `x-openregister-notifications` to these schemas in `lib/Settings/decidesk_register.json`.

### Meeting — scheduled + reminder

```jsonc
"x-openregister-notifications": {
  "meetingScheduled": {
    "trigger": {"type": "created"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "object-acl", "permission": "read"}, {"kind": "groups", "groups": ["decidesk-members"]}],
    "subject": {"nl": "Nieuwe vergadering ingepland: {{title}}", "en": "New meeting scheduled: {{title}}"}
  },
  "meetingReminder": {
    "trigger": {"type": "scheduled", "intervalSec": 86400, "filter": {"lifecycle": "scheduled"}}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "object-acl", "permission": "read"}],
    "subject": {"nl": "Herinnering: vergadering '{{title}}' komt eraan", "en": "Reminder: meeting '{{title}}' is coming up"}
  }
}
```

Recipients use `object-acl:read` (everyone who can see the meeting) plus a `decidesk-members` group, because `Meeting` has no attendee-uid field.

### ActionItem — assigned + overdue

```jsonc
"x-openregister-notifications": {
  "actionAssigned": {
    "trigger": {"type": "created"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "object-acl", "permission": "manage"}, {"kind": "groups", "groups": ["decidesk-members"]}],
    "subject": {"nl": "Nieuwe actie toegewezen: {{title}}", "en": "New action item assigned: {{title}}"}
  },
  "actionOverdue": {
    "trigger": {"type": "scheduled", "intervalSec": 86400, "filter": {"taskStatus": "overdue"}}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "object-acl", "permission": "manage"}, {"kind": "groups", "groups": ["decidesk-members"]}],
    "subject": {"nl": "Actie over tijd: {{title}}", "en": "Action item overdue: {{title}}"}
  }
}
```

`assignee` is **not** used as a `kind:field` recipient (it is a participant name, not a uid). Once `assignee` carries a uid, add `{"kind": "field", "field": "assignee"}` — see Caveats.

### Motion — submitted

```jsonc
"x-openregister-notifications": {
  "motionSubmitted": {
    "trigger": {"type": "created"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "object-acl", "permission": "read"}, {"kind": "groups", "groups": ["decidesk-members"]}],
    "subject": {"nl": "Nieuwe motie ingediend: {{title}}", "en": "New motion submitted: {{title}}"}
  }
}
```

`proposer` is a name, not a uid — not used as a recipient. Motions enter at lifecycle `submitted`, so `created` ≈ "submitted".

### Decision — recorded

```jsonc
"x-openregister-notifications": {
  "decisionRecorded": {
    "trigger": {"type": "created"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "object-acl", "permission": "read"}, {"kind": "groups", "groups": ["decidesk-members"]}],
    "subject": {"nl": "Besluit vastgelegd: {{title}}", "en": "Decision recorded: {{title}}"}
  }
}
```

### PublicConsultation / BudgetProposal — participation deadlines

```jsonc
// PublicConsultation
"x-openregister-notifications": {
  "consultationDeadline": {
    "trigger": {"type": "scheduled", "intervalSec": 86400, "filter": {"status": "open"}}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "object-acl", "permission": "read"}, {"kind": "groups", "groups": ["decidesk-members"]}],
    "subject": {"nl": "Inspraaktermijn '{{title}}' verloopt binnenkort", "en": "Consultation '{{title}}' deadline is approaching"}
  }
}
```

`BudgetProposal` follows the same `scheduled`+`filter:{status:"voting"}` shape for its voting-window deadline. The deadline date (`submissionDeadline` / proposal voting window) is the field the engine filters/evaluates against per scheduled run.

## Capabilities

No new product capability. This adds schema-declared notification configuration consumed by the existing OpenRegister notification engine.

## Impact

- **Affected file:** `lib/Settings/decidesk_register.json` only (additive `x-openregister-notifications` blocks).
- No data migration, no API change, no Vue change.
- Rules go live only when `notification-schema-rules-and-userconfig-prefs` engine is present.
- Recipient delivery for assignee/proposer is intentionally routed to groups/object-acl, not the (non-uid) person fields — see Caveats.

## Caveats

- **Participant / proposer / assignee hold emails and display names, not Nextcloud uids.** `kind:field` resolves uids only, so per-person delivery to the assignee/proposer/participant is **not used**. Rules route to `kind:object-acl` (read/manage on the object) and `kind:groups` (`decidesk-members`). To deliver to the actual assignee, a data-model change is required to store a uid on `ActionItem.assignee` (and similarly for proposer); only then add `{"kind":"field","field":"assignee"}`. Flagged from the fleet plan's "participant uid caveat".
- **No named lifecycle transition actions exist** on Meeting/Motion/Decision schemas. Status-change rules are approximated by `created` (object first appears in target state) and `scheduled` filtered on `lifecycle`/`status`/`taskStatus`. The precise "lifecycle entered X" / "status changed to Y" form is deferred to `notification-updated-field-change-condition` (declared in depends_on) or to adding named transition actions to the schemas.
- **External-recipient email** (participant emails, citizen submitters) is not deliverable — the engine `field`/`groups`/`object-acl` recipients resolve to internal Nextcloud users only. Citizen-facing participation notifications are out of scope until an external-email channel exists.
- **`decidesk-members` group** is assumed to exist in the deployment; confirm group provisioning, or swap to the deployment's actual governance/secretariat group name.
- **`scheduled` deadline rules** assume the engine evaluates the deadline field (`submissionDeadline`, meeting `scheduledDate`) per run; the per-day reminder fires for matching rows each interval rather than once at an exact horizon.
