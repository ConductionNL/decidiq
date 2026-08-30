---
status: draft
---

# Decidesk Schema Notifications

## Purpose

Adds `x-openregister-notifications` annotations to `lib/Settings/decidesk_register.json` in the verified OpenRegister notification-engine dialect, covering meeting scheduled + reminder, action item assigned + overdue, motion submitted, decision recorded, and participation deadlines. Configuration-only; no data-model or API change.

## ADDED Requirements

### Requirement: Governance schemas MUST declare notifications in the verified engine dialect

`Meeting`, `ActionItem`, `Motion`, `Decision`, `PublicConsultation`, and `BudgetProposal` MUST declare `x-openregister-notifications` using only verified keys: `trigger.type`, `channels[]`, `recipients[]`, and inline `subject{nl,en}`.

#### Scenario: Meeting scheduled and reminder rules

- **GIVEN** the `Meeting` schema
- **WHEN** notifications are declared
- **THEN** a `created`-trigger rule notifies on a newly scheduled meeting
- **AND** a `scheduled`-trigger rule (intervalSec >= 60, filter on lifecycle) sends a reminder

### Requirement: Recipients for non-uid person fields MUST use object-acl/groups, not field

Because `ActionItem.assignee`, `Motion.proposer`, and `Participant` hold participant names / email strings rather than Nextcloud user IDs, rules for these MUST NOT use `kind:field` on those properties; they MUST route to `kind:object-acl` and `kind:groups`.

#### Scenario: Action item assigned routes to object-acl and a group

- **GIVEN** `ActionItem.assignee` is a participant name, not a uid
- **WHEN** the actionAssigned rule is declared
- **THEN** its `recipients` use `kind:object-acl` (permission manage) and `kind:groups`
- **AND** no `kind:field` recipient references `assignee`

### Requirement: Status-change rules MUST be approximated and deferrals documented

With no named lifecycle transition actions on the schemas, status-change rules MUST be expressed via `created` (object first appears in target state) or `scheduled` (filtered on lifecycle/status), and the precise lifecycle-entered form MUST be documented as deferred to `notification-updated-field-change-condition`.

#### Scenario: Motion submitted approximated by created

- **GIVEN** motions enter at lifecycle `submitted` and no `submit` transition action is defined
- **WHEN** the motionSubmitted rule is declared
- **THEN** it uses `trigger.type: "created"`
- **AND** the proposal's Caveats note the lifecycle-entered form is deferred
