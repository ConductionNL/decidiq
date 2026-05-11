# ActionItem Analytics — Declarative Migration

## Purpose

Define the capability boundary for migrating the per-Meeting action-item
completion rate from `ActionItemAnalyticsService::getCompletionRates()`
to schema-declarative `x-openregister-aggregations` +
`x-openregister-calculations` on the Meeting schema, per ADR-031.
`getSummary()` and `getMyItems()` are explicitly retained as imperative
(see proposal/design rationale).

## MODIFIED Requirements

### REQ-AIADM-1: Meeting schema declares cross-schema action-item aggregations

Meeting's schema in `lib/Settings/decidesk_register.json` MUST declare
two `x-openregister-aggregations` entries that count related ActionItem
objects filtered on the back-relation `meeting == @self.id`:

- `totalActionItemCount` — count of all ActionItems linked to the Meeting.
- `completedActionItemCount` — count of linked ActionItems whose
  `taskStatus == "completed"`.

#### Scenario: Aggregations exist on Meeting schema
- **GIVEN** the imported Meeting schema in `decidesk_register.json`
- **WHEN** inspecting `Meeting.configuration.x-openregister-aggregations`
- **THEN** keys `totalActionItemCount` and `completedActionItemCount` MUST be present
- **AND** both MUST declare `metric: "count"`, `schema: "ActionItem"`, and a `filter` referencing `@self.id` on the `meeting` field

### REQ-AIADM-2: Meeting schema declares the action-item completion-rate calculation

Meeting's schema MUST declare an `x-openregister-calculations` entry
`actionItemCompletionRate` of type `number` that evaluates to
`completedActionItemCount / totalActionItemCount × 100` when
`totalActionItemCount > 0`, and `0` otherwise. The calculation MUST be
readable as a derived field on every Meeting object without a service
round-trip.

#### Scenario: Calculation present and well-typed
- **GIVEN** the imported Meeting schema
- **WHEN** inspecting `Meeting.configuration.x-openregister-calculations.actionItemCompletionRate`
- **THEN** it MUST declare `type: "number"`
- **AND** its `expression` MUST guard on `totalActionItemCount > 0` and otherwise emit `0`

#### Scenario: Zero-division yields zero
- **GIVEN** a Meeting object with `totalActionItemCount == 0`
- **WHEN** the engine materialises `actionItemCompletionRate`
- **THEN** the field MUST be `0` (not null, not NaN, not an error)

#### Scenario: Mixed-status Meeting reports correct rate
- **GIVEN** a Meeting linked to four ActionItems with `taskStatus` values `completed`, `completed`, `open`, `open`
- **WHEN** the engine materialises `actionItemCompletionRate`
- **THEN** the field MUST equal `50`

### REQ-AIADM-3: `getCompletionRates()` is removed from the service

`lib/Service/ActionItemAnalyticsService.php` MUST NOT define a public
or private `getCompletionRates()` method after this change. The
remaining public surface of the class MUST consist only of
`getSummary()` and `getMyItems()`, both unchanged in body from the
pre-change implementation.

#### Scenario: Method is gone
- **GIVEN** the post-change `ActionItemAnalyticsService` class
- **WHEN** reflecting over its public methods
- **THEN** `getCompletionRates` MUST NOT appear in the method list
- **AND** `getSummary` and `getMyItems` MUST both still appear

#### Scenario: No remaining callers
- **WHEN** running `grep -rn "getCompletionRates" lib/ src/ tests/`
- **THEN** the command MUST return zero matches

### REQ-AIADM-4: AnalyticsController reads completion rates from Meeting objects

`lib/Controller/AnalyticsController.php` MUST source the dashboard's
completion-rate response from Meeting objects' materialised
`actionItemCompletionRate` field, fetched via the standard
`ObjectService` query path (ordered by `scheduledDate:DESC` with the
caller-supplied limit). It MUST NOT call any
`ActionItemAnalyticsService::getCompletionRates*` method.

#### Scenario: Controller no longer depends on the deleted method
- **GIVEN** the post-change `AnalyticsController`
- **WHEN** inspecting its source for `getCompletionRates`
- **THEN** no reference MUST exist

### REQ-AIADM-5: Frontend wire shape is preserved

The JSON response shape returned by the analytics completion-rate
endpoint MUST keep the keys consumed by the existing dashboard widget
(`meetingTitle`, `completionRate`, `total`) so the frontend requires
no synchronous change. Additional fields (e.g. Meeting `id` for
deep-linking) MAY be added but MUST NOT replace or rename existing keys.

#### Scenario: Existing keys remain
- **GIVEN** a response from the analytics completion-rate endpoint after the migration
- **WHEN** decoding the JSON
- **THEN** each row MUST contain `meetingTitle`, `completionRate`, and `total`
- **AND** the values MUST equal `meeting.title`, `meeting.actionItemCompletionRate`, and `meeting.totalActionItemCount` respectively

### REQ-AIADM-6: Retain-imperative methods remain in place

`getSummary()` MUST remain a thin wrapper over `AggregationRunner`
(four-line dispatch with `round()` and error suppression) and
`getMyItems()` MUST remain a user-scoped, bucketed query producing
overdue/thisWeek/later groupings. Neither method may be migrated to
a schema declaration in this change.

#### Scenario: Both methods still callable with unchanged signature
- **GIVEN** the post-change `ActionItemAnalyticsService` class
- **WHEN** reflecting over `getSummary` and `getMyItems`
- **THEN** their parameter lists and return types MUST be unchanged from the pre-change implementation
