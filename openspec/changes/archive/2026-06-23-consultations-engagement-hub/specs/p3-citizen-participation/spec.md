# p3-citizen-participation — delta: consultations & engagement hub

## ADDED Requirements

### Requirement: Consultation supertype with consultationType
The system SHALL treat `PublicConsultation` as a supertype distinguished by a stored, queryable
`consultationType` enum (`citizen-participation` | `market-consultation` | `tender` | `idea-box` |
`participatory-budget`), so that all consultation kinds reuse one list + detail surface filtered by
type. Existing consultations without a value SHALL be treated as `citizen-participation`.
Type-specific fields are optional and additive; no shared field is removed.

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
offers in-action-bar quick-filter tabs to switch between `All` and each consultation type
(`Citizen participation`, `Market consultations`, `Tenders`, `Idea box`, `Participatory budgets`).
The separate top-level "Participation" and "Participatory budgets" leaves SHALL NOT appear as
top-level peers (Participatory budgets becomes the `participatory-budget` type view inside the hub).
The "Moderation queue" leaf is retained (see the hub-wide moderation requirement).

#### Scenario: One engagement entry in the menu
- GIVEN the app navigation
- WHEN it renders
- THEN there is a single "Consultations" entry, the separate "Participation" and "Participatory
  budgets" top-level leaves are gone, and "Moderation queue" remains as its own top-level leaf.

#### Scenario: Type tabs live in the action bar
- GIVEN the Consultations index
- WHEN it renders
- THEN the type quick-filters (including `Participatory budgets`) render inside the action bar (not
  as a separate row), and selecting a tab re-fetches the list with the merged `consultationType`
  filter.

### Requirement: Tender scope boundary (publish, manage, award only)
For `consultationType = tender`, the system SHALL own only **publishing** the tender, **managing the
responses**, and recording the **award decision** (`awardedTo`). The system SHALL NOT provide tender
*authoring* (owned by procest) nor bidder *response/submission* authoring (owned by pipelinq, the
CRM); a tender consultation MAY link back to the procest process that authored it and reference
pipelinq-sourced responses where present.

#### Scenario: Award is recorded as a decision outcome
- GIVEN a `tender` consultation in `evaluation`
- WHEN staff record the winning party
- THEN `awardedTo` is set and the status advances to `awarded`, treated as a decision outcome.

#### Scenario: Authoring and bidding are out of scope
- GIVEN a tender consultation
- WHEN a user looks for tender-document authoring or bid submission
- THEN decidesk does not provide them; the schema carries a `procestProcessRef` link-back field for
  the procest authoring process (rendering the link-out + surfacing pipelinq responses is a
  documented follow-up).

### Requirement: Hub-wide moderation queue retained
In addition to the per-consultation Reactions tab, the system SHALL retain a hub-wide "Moderation
queue" view listing all `pending` `ConsultationReaction`s across every consultation, using the same
`ConsultationReactionsTab` moderation component and approve/reject endpoints.

#### Scenario: Cross-consultation pending list
- GIVEN pending reactions on several consultations
- WHEN a staff user opens the Moderation queue
- THEN all pending reactions across consultations are listed and can be approved/rejected with the
  same component used on the detail tab.

### Requirement: Participatory budget as a consultation type
The system SHALL model participatory budgets as `consultationType = participatory-budget` on the
Consultation supertype, with optional fields `budgetCeiling`, `currency`, `votingMethod`,
`proposalDeadline`, `votingDeadline`. Budget proposals SHALL be expressible as
`ConsultationReaction`s carrying a proposal shape (`proposalTitle`, `proposalAmount`, `voteCount`).
The legacy `ParticipatoryBudget`/`BudgetProposal` schemas and their `BudgetRounds` page SHALL be
retained as a transitional surface reachable from the hub; folding existing legacy data into the
supertype is a documented follow-up migration (no hard deletion of legacy data in this change).

#### Scenario: Budget round appears in the hub
- GIVEN a participatory-budget consultation
- WHEN the Consultations hub is filtered to `Participatory budgets`
- THEN it appears with its `budgetCeiling`/`currency`, and the filter additionally deep-links to the
  retained BudgetRounds view for the legacy budget flow.

#### Scenario: Legacy budget data stays usable during transition
- GIVEN budget rounds created under the legacy `ParticipatoryBudget`/`BudgetProposal` model
- WHEN a user opens the retained BudgetRounds view from the hub
- THEN the legacy budget data is fully readable/usable and is not hard-deleted; a follow-up migration
  will fold it into `consultationType = participatory-budget` consultations.

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
evaluation → awarded`), idea-box (`draft → open → closed`, reactions vote-countable),
participatory-budget (`draft → proposals-open → voting → closed → results-published`).

#### Scenario: Tender phase progression
- GIVEN a `tender` consultation in `published`
- WHEN staff advance it
- THEN it moves through `questions → submission → evaluation → awarded`, each a stored status that
  drives the list badge and filters.
