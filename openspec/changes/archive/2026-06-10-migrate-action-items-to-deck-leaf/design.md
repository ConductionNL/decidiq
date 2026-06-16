# Design: Migrate action-item board UI to the Deck integration leaf

## Context

Decidesk has **two** overlapping "follow-up task" mechanisms:

1. **ADR-002 ActionItems as CalDAV VTODOs** — the established source of truth. Action items extracted from minutes are written as VTODOs (SUMMARY, DESCRIPTION, DUE, STATUS, COMPLETED, ATTENDEE, plus X-properties `MOTION-UID` / `MEETING-UID`). This is what surfaces them in the Nextcloud Tasks app.
2. **p4-collaboration `TaskService` + `DelegationService`** — a later, parallel in-app `Task` / `Delegation` object store adding delegation-with-substitute and a reclaim lifecycle. Its own docblock even notes Tasks are "distinct from CalDAV ActionItem follow-up tasks (see ADR-002)" — i.e. the duplication was known.

ADR-019's **deck** leaf provides a kanban board (stacks + cards) bound to an OR object. ADR-022 forbids an app-local task-tracking/board UI that duplicates this. The job is to collapse the duplication and put the board UI on the deck leaf.

## Goals / Non-goals

- **Goal:** one source of truth for action-item content; one board UI (the deck leaf).
- **Goal:** no loss of the reclaim/substitute audit semantics that have governance meaning.
- **Non-goal:** changing how action items are *extracted* from minutes (`ActionItemExtractionService` stays — it writes VTODOs).
- **Non-goal:** moving statutory voting or ORI publication (ADR-022 exceptions, below).

## Decisions

### D1 — VTODO is the source of truth; deck is the board projection (the reconciliation)

The CalDAV VTODO ActionItem (ADR-002) is the **canonical record** of an action item's content and status. The deck leaf is the **board UI / projection** over those items — a deck card per VTODO on a board bound to the meeting or decision OR object. We do **not** keep a third copy in a p4 `Task` object.

Rationale: ADR-002 already made VTODO authoritative (Tasks-app visibility, RFC 5545 standard, X-property linkage). Introducing deck as a second store would re-create the exact duplication ADR-022 warns against. Treating deck as a projection keeps one write path for content (VTODO) and one board surface (deck), with the registry binding the board to the object.

Card↔VTODO sync direction and conflict policy (which side wins on a status edit made in Deck vs Tasks) is an implementation detail for apply; the spec requires that the VTODO remains authoritative and the board reflects it.

### D2 — Delegation/substitute/reclaim semantics map onto VTODO + audit, not a separate object

`DelegationService`'s substitute-during-absence and reclaim lifecycle are reduced to:
- **Assignee change** on the VTODO (ATTENDEE), reflected as a card move/reassign on the board.
- **Substitute window** carried as a VTODO X-property (e.g. `X-DECIDESK-SUBSTITUTE` + `X-DECIDESK-SUBSTITUTE-UNTIL`) — but only if no OR-native delegation abstraction exists; checked at apply time per ADR-022 ("use the OR abstraction if one exists").
- **Reclaim** recorded as an OpenRegister audit event on the meeting/decision object, so the formal "delegator reclaimed task X" governance fact is preserved without a bespoke `Delegation` object.

Any delegation metadata that genuinely cannot be expressed by VTODO + deck + OR audit is the only thing that may stay in-app, and would require its own ADR-022 exception entry — apply must justify it explicitly rather than reviving the whole `Delegation` schema by default.

### D3 — Migration: project then archive

For each existing `Task` object: ensure a VTODO ActionItem exists (create from the Task if the Task predates VTODO storage), then ensure a deck card exists on the bound board. For each `Delegation` object: apply D2's assignee/X-property/audit mapping. Then archive the legacy `Task` / `Delegation` objects via OR's archival workflow — never hard-delete (audit). Idempotent and resume-safe.

## ADR-022 exceptions (kept in-app — NOT migrated)

- **Statutory voting** — `VotingService` / `QuorumService` / `LiveDecisionService` (secret ballots, quorum, proxy/weighted votes). The polls leaf covers only informal straw polls; statutory voting never becomes a leaf.
- **ORI / Popolo publication** — ADR-001 / ADR-003 stays in-app.

`ActionItemExtractionService` is **not** an exception to flag — it stays because it is the writer of the VTODO source of truth, not a duplicate of a leaf.

## Risks

- **Deck not installed.** Board tab hides gracefully (registry behaviour), action items remain reachable via the Tasks app over the same VTODOs.
- **Card/VTODO drift.** Two surfaces editing one record; mitigation is the VTODO-authoritative rule (D1) plus a defined conflict policy at apply time.
- **Lossy delegation mapping.** D2 reduces a richer in-app model to VTODO+audit; acceptable because the reclaim *fact* (the governance-relevant part) is preserved in the immutable audit trail.
