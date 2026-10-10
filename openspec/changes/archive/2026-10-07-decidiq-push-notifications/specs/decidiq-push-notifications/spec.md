# decidiq-push-notifications Specification

**Status:** proposed
**Scope:** decidiq
**Tier:** config / declarative notifications
**Depends on:** openregister `openregister-notification-body` (the `message` body field + `web-push`
channel + `actions` deeplink targets in the `x-openregister-notifications` dialect); decidiq
`decidesk-notifications` (the existing in-app `nc-notification` rules this change extends); hydra
ADR-031 (schema-declarative-business-logic) and the canonical `x-openregister-notifications` dialect
(hydra gate-18). OpenRegister owns dispatch, recipient resolution, scheduling, and web-push delivery;
decidiq only declares the rules in `lib/Settings/decidesk_register.json`.

## Purpose

Add **web-push** notification rules to decidiq using the title-vs-body content model so that the three
most time-sensitive decidiq events reach a participant's device even when no Nextcloud tab is open:
a meeting is about to start, an action item is assigned, and a vote is requested. Each rule is declared
purely as data in the existing per-schema `x-openregister-notifications` blocks — no PHP, no imperative
dispatch — and adds the `web-push` channel alongside the existing `nc-notification` channel. The
`subject` i18n map is the notification **title**; the new `message` i18n map is the notification
**body**; a single primary `action` carries an `object-detail` deeplink so the recipient can open the
triggering object directly. All existing decidiq notification rules are preserved unchanged.

## Content model — title vs body

Per `openregister-notification-body`:

- `subject` (i18n map `{nl, en}`) → notification **TITLE**.
- `message` (i18n map `{nl, en}`) → notification **BODY** (new).
- `actions` (array, max 2; each `{label:{nl,en}, primary, target}`) → tappable buttons. `target.kind`
  of `object-detail` (with no `object`) deeplinks to the triggering object; decidiq has registered
  deeplinks for `meeting`, `action-item`, and `decision`, so "Open X" resolves.
- `channels` includes both `nc-notification` and `web-push`; `originApp` is `decidiq`.
- Placeholders use the safe `{{<field>}}` form already used by decidiq's existing rules (`{{title}}`).

## ADDED Requirements

### Requirement: REQ-DPN-001 — Meeting starting-soon web-push reminder

The Meeting schema's `x-openregister-notifications` block SHALL declare a `meetingStartingSoon` rule
that delivers over both `nc-notification` and `web-push` with `originApp` `decidiq`. The rule SHALL use
a `scheduled` trigger with an `intervalSec` of at least 60 (declared as 900) and a `filter` that matches
meetings whose `lifecycle` is `scheduled`, so only upcoming, not-yet-opened meetings fire it. Recipients
SHALL be resolved by `object-acl` `read` permission, because the Meeting schema exposes no participant
array field and its `chair` field is a Participant UUID string rather than a recipient list. The rule's
`subject` SHALL be `{nl:"Vergadering begint binnenkort: {{title}}", en:"Meeting starts soon: {{title}}"}`
and its `message` SHALL be `{nl:"{{title}} begint in Decidiq. Openen?", en:"{{title}} is starting in
Decidiq. Open it?"}`. The rule SHALL carry exactly one primary `action` labelled
`{nl:"Vergadering openen", en:"Open meeting"}` whose `target` is `{kind:object-detail}` resolving to the
triggering meeting. The change SHALL NOT alter or remove the existing `meetingScheduled` or
`meetingReminder` rules.

#### Scenario: A scheduled meeting nears its start time

- GIVEN a Meeting object whose `lifecycle` is `scheduled`
- WHEN OpenRegister evaluates the `scheduled` notification sweep and the `meetingStartingSoon` filter
  matches
- THEN a notification with title "Meeting starts soon: <title>" and body "<title> is starting in
  Decidiq. Open it?" is delivered over `nc-notification` and `web-push`, carrying a primary "Open
  meeting" action that deeplinks to that meeting's detail page

#### Scenario: A draft or closed meeting does not fire the reminder

- GIVEN a Meeting object whose `lifecycle` is `draft`, `opened`, or `closed`
- WHEN the `scheduled` sweep evaluates the `meetingStartingSoon` filter
- THEN the rule does not match and no starting-soon notification is sent for that meeting

#### Scenario: Existing meeting rules are preserved

- GIVEN the Meeting schema already declares `meetingScheduled` and `meetingReminder`
- WHEN `meetingStartingSoon` is added
- THEN all three rules coexist in the Meeting `x-openregister-notifications` block and the two
  pre-existing rules are unchanged

---

### Requirement: REQ-DPN-002 — Action item assigned-to-you web-push

The ActionItem schema's `x-openregister-notifications` block SHALL declare an `actionItemAssignedToYou`
rule that delivers over both `nc-notification` and `web-push` with `originApp` `decidiq`. The rule SHALL
fire on the `created` trigger and SHALL resolve recipients via `{kind:field, field:"assignee"}` so the
assignee is notified. The rule's `subject` SHALL be `{nl:"Aan jou toegewezen: {{title}}",
en:"Assigned to you: {{title}}"}` and its `message` SHALL be `{nl:"Dit actiepunt staat in Decidiq.
Openen?", en:"This action item is in Decidiq. Open it?"}`. The rule SHALL carry exactly one primary
`action` labelled `{nl:"Actiepunt openen", en:"Open action item"}` whose `target` is
`{kind:object-detail}` resolving to the triggering action item. The change SHALL NOT alter or remove the
existing `actionAssigned` or `actionOverdue` rules.

#### Scenario: A new action item is assigned

- GIVEN a new ActionItem object is created with an `assignee`
- WHEN OpenRegister evaluates the `created` trigger
- THEN a notification with title "Assigned to you: <title>" and body "This action item is in Decidiq.
  Open it?" is delivered to the resolved assignee over `nc-notification` and `web-push`, carrying a
  primary "Open action item" action that deeplinks to that action item's detail page

#### Scenario: Existing action-item rules are preserved

- GIVEN the ActionItem schema already declares `actionAssigned` and `actionOverdue`
- WHEN `actionItemAssignedToYou` is added
- THEN all three rules coexist in the ActionItem `x-openregister-notifications` block and the two
  pre-existing rules are unchanged

---

### Requirement: REQ-DPN-003 — Decision vote-requested web-push

The Decision schema's `x-openregister-notifications` block SHALL declare a `voteRequested` rule that
delivers over both `nc-notification` and `web-push` with `originApp` `decidiq`. Because the canonical
dialect in this register expresses lifecycle transitions as an `updated` trigger with a `condition`
(no `transition` trigger is used in decidiq), the rule SHALL fire on `updated` with a `condition`
matching `lifecycle` equal to `voting` — the state in which a decision's vote is requested. Recipients
SHALL be resolved by `object-acl` `read` permission, because the Decision schema exposes no user-id
decision-maker field (`proposer`/`coSigners` are free-text names and votes live in the separate Vote /
VotingRound schemas). The rule's `subject` SHALL be `{nl:"Jouw stem is gevraagd: {{title}}",
en:"Your vote is requested: {{title}}"}` and its `message` SHALL be `{nl:"Dit besluit staat in Decidiq.
Openen?", en:"This decision is in Decidiq. Open it?"}`. The rule SHALL carry exactly one primary
`action` labelled `{nl:"Besluit openen", en:"Open decision"}` whose `target` is `{kind:object-detail}`
resolving to the triggering decision. The change SHALL NOT alter or remove any of the existing Decision
notification rules.

#### Scenario: A decision enters the voting state

- GIVEN a Decision object whose `lifecycle` changes to `voting`
- WHEN OpenRegister evaluates the `updated` trigger and the `voteRequested` condition matches
- THEN a notification with title "Your vote is requested: <title>" and body "This decision is in
  Decidiq. Open it?" is delivered over `nc-notification` and `web-push`, carrying a primary "Open
  decision" action that deeplinks to that decision's detail page

#### Scenario: A non-voting lifecycle change does not request a vote

- GIVEN a Decision object whose `lifecycle` changes to `deliberating` or `decided`
- WHEN the `updated` trigger evaluates the `voteRequested` condition
- THEN the rule does not match and no vote-requested notification is sent

#### Scenario: Existing decision rules are preserved

- GIVEN the Decision schema already declares `decisionProposed`, `decisionRecorded`,
  `decisionSuperseded`, `decisionRepealed`, and `outcomeEmitted`
- WHEN `voteRequested` is added
- THEN all six rules coexist in the Decision `x-openregister-notifications` block and the five
  pre-existing rules are unchanged
