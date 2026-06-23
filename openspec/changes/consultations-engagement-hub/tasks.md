# Tasks: Consultations & engagement hub

> Decisions resolved (proposal.md §Decisions, 2026-06-23): name = **Consultations**; tenders in
> scope for publish/manage/award only; participatory-budget is a `consultationType`; hub-wide
> Moderation queue retained.

## 1. Schema (lib/Settings/decidesk_register.json)
- [x] 1.1 Add `consultationType` enum to `PublicConsultation` (default `citizen-participation`;
      values: citizen-participation | market-consultation | tender | idea-box | participatory-budget).
- [x] 1.2 Add additive optional type fields: tender (`referenceNumber`, `estimatedValue`,
      `awardCriteria`, `questionDeadline`, `awardedTo`, `currency`, `procestProcessRef`), market
      (`marketScope`), idea-box (`votingEnabled`, `ideaCount`), participatory-budget
      (`budgetCeiling`, `currency`, `votingMethod`, `proposalDeadline`, `votingDeadline`).
- [x] 1.3 Add optional `voteCount` + budget-proposal shape (`proposalTitle`, `proposalAmount`) to
      `ConsultationReaction` (idea-box + participatory-budget voting).
- [x] 1.4 Extend `x-openregister-lifecycle` per type (citizen unchanged; market/tender/idea-box/
      participatory-budget states per spec) — added as a shared transition map on the supertype.
- [~] 1.5 Backfill: existing rows default to `citizen-participation` (handled by the schema default
      + ParticipationLifecycleService read-normalisation). **Follow-up:** legacy `BudgetProposal`/
      `ParticipatoryBudget` → `participatory-budget` consultations + budget-proposal reactions
      (archive legacy, no hard delete). Deferred — the `BudgetRounds` page is retained transitional
      so legacy budget data stays fully usable in the meantime. Tracked for a follow-up migration.

## 2. IA / manifest (src/manifest.d/citizen-participation.json)
- [x] 2.1 Collapse the participation leaves into one `Consultations` entry; retire top-level
      Participation + Participatory budgets leaves; **keep** the top-level `Moderation queue` leaf.
- [x] 2.2 Add `quickFilters` to the `Consultations` index: All · Citizen participation · Market
      consultations · Tenders · Idea box · Participatory budgets (merge `consultationType` filter).
- [x] 2.3 Re-point `ParticipationPage` as the citizen-facing surface of an open consultation (page
      retained, removed as a top-level menu peer; reachable from the hub).
- [x] 2.4 Add the Reactions tab to `ConsultationDetail` (`sidebarTabs` → ConsultationReactionsTab).
- [~] 2.5 Tender: schema carries `procestProcessRef` link-out field. **Follow-up:** render the
      procest authoring link + surface pipelinq responses (read-only) on the tender detail. Deferred.

## 3. Components
- [x] 3.1 Extract `ConsultationReactionsTab.vue` (props: `objectId?`/`register`/`schema`); inline
      approve/reject reusing `ReactionApproveModal`/`ReactionRejectModal` + `participationApi`.
- [x] 3.2 Wire `ConsultationReactionsTab` into `ConsultationDetail` (scoped to the consultation via
      the OR `_relations.public-consultation` filter).
- [x] 3.3 Keep the hub-wide `Moderation queue` page mounting the same component with no `objectId`
      (cross-consultation pending list) — ModerationQueuePage refactored to reuse the tab.
- [x] 3.4 Type-aware columns on the Consultations index (Type badge + Status badge per row).

## 4. Verify
- [x] 4.1 `openspec validate consultations-engagement-hub --strict` passes.
- [x] 4.2 Build green; LIVE-VERIFIED on :8080: one Consultations entry + retained Moderation queue,
      type quick-filters in the bar (Tenders → 1 row server-side), reaction moderation in the detail
      (approve removes the reaction, 0 console errors), legacy consultations default to
      citizen-participation. (Legacy-budget migration deferred per 1.5.)
- [x] 4.3 Schema-declarative (consultationType + lifecycle are stored/queryable); no new endpoints
      (reuses existing participation action routes); no imperative dispatch.
