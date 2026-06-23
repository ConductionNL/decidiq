# p3-citizen-participation — delta: consultations & engagement hub

## ADDED Requirements

### Requirement: Consultation supertype with consultationType
The system SHALL treat `PublicConsultation` as a supertype distinguished by a stored, queryable
`consultationType` enum (`citizen-participation` | `market-consultation` | `tender` | `idea-box`),
so that all consultation kinds reuse one list + detail surface filtered by type. Existing
consultations without a value SHALL be treated as `citizen-participation`. Type-specific fields are
optional and additive; no shared field is removed.

#### Scenario: Default type for legacy consultations
- GIVEN a `PublicConsultation` created before this change with no `consultationType`
- WHEN it is read or listed
- THEN it is treated as `consultationType = citizen-participation` and renders unchanged.

#### Scenario: Filtering by type returns only that type
- GIVEN consultations of several `consultationType` values
- WHEN the Consultations list is filtered to `tender`
- THEN only consultations with `consultationType = tender` are returned (server-side filter on the
  stored field, no client derivation).

### Requirement: Single Consultations hub with in-bar type filters
The system SHALL present engagement under ONE top-level "Consultations" navigation entry whose index
offers in-action-bar quick-filter tabs to switch between `All` and each consultation type (plus a
Participatory budgets view). The separate top-level "Participation", "Participatory budgets", and
"Moderation queue" leaves SHALL NOT appear as top-level peers.

#### Scenario: One engagement entry in the menu
- GIVEN the app navigation
- WHEN it renders
- THEN there is a single "Consultations" entry and no separate top-level "Moderation queue" leaf.

#### Scenario: Type tabs live in the action bar
- GIVEN the Consultations index
- WHEN it renders
- THEN the type quick-filters render inside the action bar (not as a separate row), and selecting a
  tab re-fetches the list with the merged `consultationType` filter.

### Requirement: In-context reaction moderation on the consultation detail
The consultation detail page SHALL include a Reactions tab listing that consultation's
`ConsultationReaction`s with inline approve/reject for staff, using the existing approve/reject
endpoints. A reaction's `moderationStatus` SHALL gate its visibility and its contribution to
`submissionCount` exactly as today.

#### Scenario: Moderate a reaction from the consultation it belongs to
- GIVEN a staff user on a consultation detail with a `pending` reaction
- WHEN they approve it from the Reactions tab
- THEN the reaction's `moderationStatus` becomes `approved` and it counts toward results — without
  leaving the consultation detail.

#### Scenario: Reject requires a reason
- GIVEN a staff user rejecting a reaction from the Reactions tab
- WHEN they confirm
- THEN a rejection reason is required and stored on the reaction, mirroring the prior queue behaviour.

### Requirement: Per-type consultation lifecycle
Each `consultationType` SHALL define its `status` via declarative `x-openregister-lifecycle` with
stored, queryable states: citizen-participation (`draft → open → closed → results-published`),
market-consultation (`… → report-published`), tender (`draft → published → questions → submission →
evaluation → awarded`), idea-box (`draft → open → closed`, reactions vote-countable).

#### Scenario: Tender phase progression
- GIVEN a `tender` consultation in `published`
- WHEN staff advance it
- THEN it moves through `questions → submission → evaluation → awarded`, each a stored status that
  drives the list badge and filters.
