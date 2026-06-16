---
kind: config
---

# Proposal: Decidesk web-push notification rules (title-vs-body model)

## Problem

Decidesk's existing notification rules (`decidesk-notifications`) only deliver over the in-app
`nc-notification` channel and only carry a `subject`. The three most time-sensitive decidesk events —
a meeting about to start, an action item assigned to you, a vote being requested — are exactly the
moments where a participant is *not* looking at a Nextcloud tab. Without `web-push` and an actionable
deeplink, the reminder lands in a bell icon nobody sees in time, and the recipient still has to
navigate to find the object.

OpenRegister's `x-openregister-notifications` dialect has gained the pieces needed to fix this
(`openregister-notification-body`): a `message` body field (notification body, distinct from the
`subject` title), a `web-push` channel, an `originApp`, and `actions` carrying `object-detail`
deeplinks. Decidesk already registers deeplinks for `meeting`, `action-item`, and `decision`, so an
"Open X" action resolves. Nothing consumes these features yet in decidesk.

## Proposed Change

Add three declarative web-push rules — one per schema — into the **existing**
`x-openregister-notifications` blocks in `lib/Settings/decidesk_register.json`, using the title-vs-body
content model. Pure config: no PHP, no imperative dispatch (ADR-031, hydra gate-18). OpenRegister owns
dispatch, recipient resolution, scheduling, and web-push delivery. Every rule sets `channels` to
`["nc-notification", "web-push"]`, `originApp` to `decidesk`, a `subject` (title) + `message` (body)
i18n map in nl + en, and a single primary `action` deeplinking to the triggering object via
`{kind:object-detail}`. All pre-existing rules are preserved.

### 1. Meeting — `meetingStartingSoon`

A `scheduled` trigger (`intervalSec: 900`, ≥ 60) filtered to `lifecycle == scheduled` fires a
starting-soon reminder. Recipients: `object-acl` `read` (the Meeting schema has no participant array
field; `chair` is a Participant UUID string, not a recipient list). Title "Meeting starts soon:
{{title}}", body "{{title}} is starting in Decidesk. Open it?", primary action "Open meeting".

### 2. ActionItem — `actionItemAssignedToYou`

A `created` trigger fires when an action item is created. Recipients: `{kind:field, field:"assignee"}`
per the brief. Title "Assigned to you: {{title}}", body "This action item is in Decidesk. Open it?",
primary action "Open action item".

### 3. Decision — `voteRequested`

An `updated` trigger with a `condition` matching `lifecycle == voting` fires when a decision's vote is
requested. Recipients: `object-acl` `read` (the Decision schema has no user-id decision-maker field).
Title "Your vote is requested: {{title}}", body "This decision is in Decidesk. Open it?", primary
action "Open decision".

## Trigger-choice compromises

- **Meeting** uses the requested `scheduled` + `lifecycle == scheduled` filter (cleanly expressible —
  matches the existing `meetingReminder` pattern), so no `transition` fallback was needed.
- **ActionItem** recipient `{kind:field, field:"assignee"}` is declared as requested, but `assignee`
  is currently a **free-text label** (e.g. "Wethouder Duurzaamheid"), not a Nextcloud user id, so it
  will not resolve to a deliverable recipient until `assignee` is migrated to a user/Participant
  reference. The existing `actionAssigned` rule already targets `object-acl manage` as the working
  fallback recipient and is left in place.
- **Decision** uses `updated` + `condition (lifecycle == voting)` rather than a `transition` trigger,
  because the entire decidesk register expresses lifecycle changes through `updated`/`condition`
  (no `transition` trigger appears anywhere in it); `voting` is the canonical "your vote is requested"
  state in the Decision lifecycle. Recipients fall back to `object-acl read` because no user-id
  decision-maker field exists (`proposer`/`coSigners` are free-text; votes live in Vote/VotingRound).

## Impact

- Changed file: `lib/Settings/decidesk_register.json` (three rules added; no rule removed or altered).
- No code, routes, controllers, or migrations.
- Depends on OpenRegister having shipped `openregister-notification-body` so `message`, `web-push`, and
  `actions` are honoured; on an older OpenRegister the rules degrade to `nc-notification` with a title
  only (the `message`/`actions` fields are ignored, not fatal).
