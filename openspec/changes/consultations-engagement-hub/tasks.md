# Tasks: Consultations & engagement hub

> Decisions resolved (proposal.md §Decisions, 2026-06-23): name = **Consultations**; tenders in
> scope for publish/manage/award only; participatory-budget is a `consultationType`; hub-wide
> Moderation queue retained.

## 1. Schema (lib/Settings/decidesk_register.json)
- [ ] 1.1 Add `consultationType` enum to `PublicConsultation` (default `citizen-participation`;
      values: citizen-participation | market-consultation | tender | idea-box | participatory-budget).
- [ ] 1.2 Add additive optional type fields: tender (`referenceNumber`, `estimatedValue`,
      `awardCriteria`, `questionDeadline`, `awardedTo`), market (`marketScope`), idea-box
      (`votingEnabled`, `ideaCount`), participatory-budget (`budgetCeiling`, `currency`,
      `votingMethod`, `proposalDeadline`, `votingDeadline`).
- [ ] 1.3 Add optional `voteCount` + budget-proposal shape (title, `amount`) to `ConsultationReaction`
      (idea-box + participatory-budget voting).
- [ ] 1.4 Extend `x-openregister-lifecycle` per type (citizen unchanged; market/tender/idea-box/
      participatory-budget states per spec).
- [ ] 1.5 Migrate/backfill: existing rows → `citizen-participation`; legacy `BudgetProposal`/
      `ParticipatoryBudget` rows → `participatory-budget` consultations + budget-proposal reactions
      (archive legacy, no hard delete); add per-type seed examples.

## 2. IA / manifest (src/manifest.d/citizen-participation.json)
- [ ] 2.1 Collapse the participation leaves into one `Consultations` entry; retire top-level
      Participation + Participatory budgets leaves; **keep** the top-level `Moderation queue` leaf.
- [ ] 2.2 Add `quickFilters` to the `Consultations` index: All · Citizen participation · Market
      consultations · Tenders · Idea box · Participatory budgets (merge `consultationType` filter).
- [ ] 2.3 Re-point `ParticipationPage` as the citizen-facing surface of an open consultation.
- [ ] 2.4 Add the Reactions tab to `ConsultationDetail` (`sidebarTabs`/detail config).
- [ ] 2.5 Tender: link-out to the procest authoring process + surface pipelinq responses (read-only);
      no authoring/bidding UI in decidesk.

## 3. Components
- [ ] 3.1 Extract `ConsultationReactionsTab.vue` from `ModerationQueuePage.vue` (props:
      `consultationId?`); inline approve/reject reusing `ReactionApproveModal`/`ReactionRejectModal`
      + `participationApi`.
- [ ] 3.2 Wire `ConsultationReactionsTab` into `ConsultationDetail` (scoped to the consultation).
- [ ] 3.3 Keep the hub-wide `Moderation queue` page mounting the same component with no
      `consultationId` (cross-consultation pending list).
- [ ] 3.4 Type-aware labels/columns on the Consultations index (vocabulary per `consultationType`,
      incl. budget ceiling/currency for participatory-budget).

## 4. Verify
- [ ] 4.1 `openspec validate consultations-engagement-hub --strict` passes.
- [ ] 4.2 Build green; live: one Consultations entry + retained Moderation queue, type tabs in the
      bar (incl. Participatory budgets), reaction moderation in the detail AND hub-wide, legacy
      consultations default to citizen-participation, legacy budgets migrated.
- [ ] 4.3 Hydra gates (schema-declarative / no imperative dispatch / route-auth on any new endpoint).
