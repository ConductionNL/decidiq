# decidesk-notifications Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- urgent-decision-procedure

## Purpose

Delta for the urgent-decision-procedure change: adds two declarative `x-openregister-notifications` rules on the `Decision` schema for the urgent procedure, in the verified engine dialect (ADR-031) and respecting the existing recipient rules (no `kind:field` on non-uid properties).

## ADDED Requirements

### Requirement: Urgency notification rules in the verified dialect

The `Decision` schema's `x-openregister-notifications` block SHALL gain two rules using only verified keys (`trigger.type`, `channels[]`, `recipients[]`, inline `subject{nl,en}`): (1) `urgentDecisionDeclared` — `trigger.type: "updated"` with condition `isUrgent equals true`, notifying on the urgency declaration; (2) `urgentRatificationDue` — `trigger.type: "scheduled"` (intervalSec ≥ 60) filtered on `awaitingRatification equals true`, reminding while ratification is outstanding. Both rules SHALL route recipients via `kind:object-acl` and `kind:groups` (group `decidesk-members`) — covering members of the deciding and ratifying bodies through object access — and SHALL NOT use `kind:field` on any non-uid property. Subjects SHALL carry `{nl,en}` variants (e.g. nl "Spoedbesluit: {{title}}" / en "Urgent decision: {{title}}", and nl "Bekrachtiging vereist: {{title}}" / en "Ratification due: {{title}}"). A precise per-body-membership recipient form is documented as deferred (same deferral posture as the lifecycle-entered trigger in the base spec).

#### Scenario: Members notified on urgency declaration

- GIVEN the `Decision` schema's notification rules after this change
- WHEN a decision is updated with `isUrgent=true`
- THEN the `urgentDecisionDeclared` rule fires an NC notification to `object-acl` readers and the `decidesk-members` group with the nl/en urgent-decision subject

#### Scenario: Ratification-due reminder while outstanding

- GIVEN an urgent decision with `awaitingRatification=true`
- WHEN the scheduled `urgentRatificationDue` rule interval elapses
- THEN recipients receive a ratification-due NC notification
- AND once the ratifying stage is decided (`awaitingRatification=false`) the rule no longer matches and no further reminders are sent

#### Scenario: No field-recipient on non-uid properties

- GIVEN the two new rules
- WHEN their `recipients` are inspected
- THEN no `kind:field` recipient references `urgencyDeclaredBy` or any participant-name property; only `kind:object-acl` and `kind:groups` are used
