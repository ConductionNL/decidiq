## Why

Decidesk governance bodies — municipalities, water boards, associations, and corporate boards — require a structured mechanism to submit, debate, amend, and vote on motions. Without this, the app cannot support the core governance cycle: a proposal is put on the agenda, council members submit motions and amendments, votes are cast (in-person, proxy, or remote), results are tallied, and outcomes are published via ORI API. Budget amendment motions alone represent a demand score of 2025, and proxy voting reaches 1242 — together the single highest-demand cluster for p2.

This spec introduces the Motion entity workflow, the VotingRound session, Vote capture, and the Amendment submission flow. It enables quorum checking before votes open, proxy delegation, real-time tally display, and publication of voting results as open data.

## What Changes

- **New**: Motion index and detail views — list, create, edit, delete, lifecycle badge (submitted → debating → voting → adopted/rejected/withdrawn)
- **New**: VotingRound management — open/close a vote, select voting method (for-against-abstain, ranked-choice, weighted, show-of-hands), quorum verification
- **New**: Vote casting interface — real-time per-participant vote capture with running tally (for / against / abstain)
- **New**: Amendment submission workflow — create amendment linked to a motion, detect conflicting amendments on the same passage, voting on amendments
- **New**: Proxy voting — delegate voting rights from one Participant to another; proxy flag on Vote object
- **New**: Digital co-signatory collection — raadslid can request co-signatures from other council members
- **New**: Voting result publication — publish adopted/rejected results to ORI API endpoint; link via `_files` metadata
- **New**: Automatic dossier integration — decision dossier folder created via `_files` metadata on vote close
- **New**: Calendar deadline notifications — voting deadlines and amendment submission deadlines synced via `_calendar` metadata
- **New**: Audit trail hook — every lifecycle transition logged via Activity stream

## Capabilities

### New Capabilities

- `motion-management`: Full lifecycle CRUD for Motion objects — list, create, edit, delete, lifecycle state machine (submitted → debating → voting → adopted/rejected/withdrawn), digital co-signatory collection
- `voting-round-management`: Open and close VotingRound sessions on a motion or amendment; select voting method; enforce quorum check before opening
- `vote-casting`: Per-participant vote capture (for/against/abstain or ranked); proxy vote flag; running tally widget; email-reply voting via webhook
- `amendment-workflow`: Submit Amendment linked to a Motion; detect conflicting amendments on same text passage; vote on amendments
- `proxy-voting`: Delegate voting rights from one Participant to another for a specific VotingRound; proxy flag on cast Vote
- `quorum-checking`: Verify that the number of present participants meets the GovernanceBody `quorumRule` before a VotingRound can be opened
- `voting-result-publication`: Display per-resolution tally (for/against/abstain/not-voted); majority threshold indicator; publish result to ORI API; generate result PDF via `_files`

### Modified Capabilities

- `meeting-crud` (p1-crud-operations): Meeting detail page gains a Motions section listing related motions via AgendaItem relations
- `agenda-item-crud` (p1-crud-operations): AgendaItem detail page gains a Motions section showing linked motions

## Impact

- **New files**: `src/views/Motions.vue`, `src/views/MotionDetail.vue`, `src/views/VotingRounds.vue`, `src/views/VotingRoundDetail.vue`, `src/views/Amendments.vue`, `src/views/AmendmentDetail.vue`
- **New stores**: `src/store/modules/motionStore.js`, `votingRoundStore.js`, `voteStore.js`, `amendmentStore.js` (all via `createObjectStore`)
- **Router**: add named routes `Motions`, `MotionDetail`, `VotingRoundDetail`, `Amendments`, `AmendmentDetail`
- **No new backend controllers** — all lifecycle and vote data via OpenRegister ObjectService
- **Workflow triggers**: lifecycle transitions invoke `WorkflowEngineController` rules configured via GovernanceBody `workflowTemplate`
- **Notifications**: `NotificationService` dispatch on motion state change, vote open, vote close
- **Dependencies**: `@conduction/nextcloud-vue` (CnTimelineStages, CnStatsBlock, CnChartWidget), OpenRegister WorkflowEngineController, ActivityService, CalendarEventService
- **Breaking changes**: none — builds on p1 register; new schemas are additive
