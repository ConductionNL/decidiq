# process-configuration Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- urgent-decision-procedure

## Purpose

Delta for the urgent-decision-procedure change: adds a per-template `urgencyPolicy` object to `ProcessTemplate` so each governance body configures whether and how the urgent procedure is available. Follows the established fail-closed posture of process-configuration (absent/malformed template config never fails open).

## ADDED Requirements

### Requirement: Per-template urgency policy

The `ProcessTemplate` schema SHALL gain an optional `urgencyPolicy` object with: `allowedTriggerRoles` (array of role tokens, e.g. `chair`, `secretary`, `board-member`), `minimumNoticeFloorHours` (integer ≥ 0 — the shortest convocation notice an emergency meeting may use), `responseDeadlineHours` (object `{min, max}` bounding the expedited written-round deadline), `ratificationRequired` (boolean, default true), and `ratifyingBody` (reference to the GovernanceBody that ratifies, overridable at trigger time). Server-side template validation SHALL reject an `urgencyPolicy` with an empty `allowedTriggerRoles`, an unrecognised role token, `min > max` deadline bounds, or `ratificationRequired=true` without a resolvable `ratifyingBody` (fail closed, mirroring the existing transition-graph validation). When a body's template has NO `urgencyPolicy`, the urgent procedure MUST be unavailable for that body — never available-with-no-limits. Built-in templates SHALL remain read-only; an administrator enables urgency by duplicating and editing a template, or editing a custom one.

#### Scenario: Administrator configures urgency for a municipal council template

- GIVEN an administrator editing a custom "Municipal council" process template
- WHEN they set `urgencyPolicy` with `allowedTriggerRoles=["chair"]`, `minimumNoticeFloorHours=24`, `responseDeadlineHours={min:12, max:96}`, `ratificationRequired=true`, `ratifyingBody=Gemeenteraad`
- THEN the template saves and bodies assigned this template can run the urgent procedure within those limits

#### Scenario: Malformed urgency policy is rejected

- GIVEN an administrator editing a template's `urgencyPolicy`
- WHEN they set `responseDeadlineHours={min: 96, max: 12}`
- THEN the server refuses to save the template (HTTP 400) naming the inverted bounds

#### Scenario: Absent policy means the procedure is unavailable

- GIVEN a body whose assigned template has no `urgencyPolicy` (including all unmodified built-in templates)
- WHEN any actor attempts to declare a decision of that body urgent
- THEN the trigger is rejected as not configured — the absence of configuration never falls back to permissive defaults

#### Scenario: Ratification requirement needs a ratifying body

- GIVEN an administrator saving an `urgencyPolicy` with `ratificationRequired=true` and no `ratifyingBody`
- WHEN the template is validated
- THEN the save is rejected naming the missing ratifying body
