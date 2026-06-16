# Design: Decidesk web-push notification rules

## Approach

The whole change is declarative data in `lib/Settings/decidesk_register.json`. There is no decidesk
backend involvement: OpenRegister reads the `x-openregister-notifications` block per schema, evaluates
triggers, resolves recipients, renders the title (`subject`) + body (`message`) in the recipient's
language, and delivers over each declared channel. Decidesk's only job is to author the rules correctly
within the canonical dialect (ADR-031, hydra gate-18) and not to clobber the rules it already has.

## Dialect mapping (title vs body)

| Field        | Role in this change                                                    |
|--------------|------------------------------------------------------------------------|
| `subject`    | i18n `{nl,en}` → notification **TITLE**                                 |
| `message`    | i18n `{nl,en}` → notification **BODY** (from `openregister-notification-body`) |
| `channels`   | `["nc-notification", "web-push"]`                                       |
| `originApp`  | `"decidesk"`                                                            |
| `actions`    | one entry `{label:{nl,en}, primary:true, target:{kind:"object-detail"}}` |
| placeholders | `{{title}}` (safe, matches decidesk's existing rules)                   |

`{kind:object-detail}` with no `object` deeplinks to the **triggering** object. Decidesk has registered
deeplinks for `meeting`, `action-item`, and `decision`, so all three "Open X" actions resolve.

## Per-rule decisions

### Meeting `meetingStartingSoon`
- Trigger `scheduled`, `intervalSec: 900` (≥ 60 as required), `filter: {lifecycle: "scheduled"}`.
  Mirrors the existing `meetingReminder` rule, so the clean scheduled filter is expressible and no
  `transition` fallback is needed.
- Recipients `object-acl read`: the Meeting schema has no participant array field, and `chair` is a
  single Participant UUID string, not a recipient list — `object-acl` is the schema-supported choice
  and matches the existing `meetingReminder` recipient.

### ActionItem `actionItemAssignedToYou`
- Trigger `created` (an action item is "assigned to you" the moment it is created with an assignee).
- Recipients `{kind:field, field:"assignee"}` per the brief. **Caveat:** `assignee` is currently a
  free-text label, not a user id, so it will not resolve to a deliverable recipient until the field is
  migrated to a user/Participant reference; the existing `actionAssigned` rule keeps `object-acl manage`
  as the working fallback. This is recorded rather than worked around so the brief's recipient shape is
  honoured verbatim.

### Decision `voteRequested`
- Trigger `updated` + `condition {field:"lifecycle", operator:"equals", value:"voting"}`. The decidesk
  register never uses a `transition` trigger — every lifecycle rule (e.g. `decisionProposed`,
  `outcomeEmitted`) is an `updated` trigger with a `condition`. Using the same shape keeps the rule
  consistent and valid against the dialect. `voting` is the lifecycle state in which a vote is
  requested.
- Recipients `object-acl read`: the Decision schema has no user-id decision-maker field
  (`proposer`/`coSigners` are free-text names; actual voters live in the Vote / VotingRound schemas),
  so `object-acl` is the schema-supported recipient choice and matches the existing Decision rules.

## Non-clobber guarantee

Each rule is inserted as a new key inside the schema's existing `x-openregister-notifications` map.
Verified post-edit: Meeting now holds `meetingScheduled, meetingReminder, meetingStartingSoon`;
ActionItem holds `actionAssigned, actionOverdue, actionItemAssignedToYou`; Decision holds
`decisionProposed, decisionRecorded, decisionSuperseded, decisionRepealed, outcomeEmitted,
voteRequested`. No pre-existing rule is altered or removed.

## Graceful degradation

On an OpenRegister build that predates `openregister-notification-body`, the unknown `message`,
`web-push`, and `actions` keys are ignored; each rule still delivers as an `nc-notification` with its
title. The change is therefore safe to ship ahead of, or together with, the OpenRegister dependency.
