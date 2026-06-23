# Tasks: Consultations & engagement hub

> Gated on the open questions in proposal.md (esp. tender scope + name). Implement after review.

## 1. Schema (lib/Settings/decidesk_register.json)
- [ ] 1.1 Add `consultationType` enum to `PublicConsultation` (default `citizen-participation`).
- [ ] 1.2 Add additive type fields: tender (`referenceNumber`, `estimatedValue`, `awardCriteria`,
      `questionDeadline`, `awardedTo`), market (`marketScope`), idea-box (`votingEnabled`,
      `ideaCount`). All optional.
- [ ] 1.3 Add optional `voteCount` to `ConsultationReaction` (idea-box voting).
- [ ] 1.4 Extend `x-openregister-lifecycle` per type (citizen unchanged; tender/market/idea states).
- [ ] 1.5 Seed/backfill: existing rows → `citizen-participation`; add per-type seed examples.

## 2. IA / manifest (src/manifest.d/citizen-participation.json)
- [ ] 2.1 Collapse the 4 menu leaves into one `Consultations` entry (retire top-level Participation,
      Participatory budgets, Moderation queue leaves).
- [ ] 2.2 Add `quickFilters` to the `Consultations` index: All · Citizen participation · Market
      consultations · Tenders · Idea box · Budgets (Budgets deep-links to the budget view).
- [ ] 2.3 Re-point `ParticipationPage` as the citizen-facing surface of an open consultation.
- [ ] 2.4 Add the Reactions tab to `ConsultationDetail` (`sidebarTabs`/detail config).

## 3. Components
- [ ] 3.1 Extract `ConsultationReactionsTab.vue` from `ModerationQueuePage.vue` (props:
      `consultationId?`); inline approve/reject reusing `ReactionApproveModal`/`ReactionRejectModal`
      + `participationApi`.
- [ ] 3.2 Wire `ConsultationReactionsTab` into `ConsultationDetail` (scoped to the consultation).
- [ ] 3.3 (optional, open Q4) Keep a hub-wide moderation view mounting the same component with no
      `consultationId`.
- [ ] 3.4 Type-aware labels/columns on the Consultations index (vocabulary per `consultationType`).

## 4. Verify
- [ ] 4.1 `openspec validate consultations-engagement-hub --strict` passes.
- [ ] 4.2 Build green; live: one Consultations entry, type tabs in the bar, reaction moderation in
      the detail, legacy consultations default to citizen-participation.
- [ ] 4.3 Hydra gates (schema-declarative / no imperative dispatch / route-auth on any new endpoint).
