# Design: Migrate in-app comments to the Talk integration leaf

status: pr-created

## Context

`CommentService` was built under the archived `p4-collaboration` change. It stores `Comment` objects in OpenRegister with a polymorphic target (`{register}:{schema}:{uuid}`) and threading via a `parentComment` reference. It is the in-app discussion surface on agenda items, motions, amendments, and decisions.

ADR-019 (integration registry) and ADR-022 (apps consume OR abstractions) together mandate that "discussion threads on an object" come from the **talk** leaf — a Nextcloud Talk conversation bound to the OR object — not a per-app comment store. Decidesk already consumes the registry via `src/views/MeetingIntegrations.vue` (xWiki leaf live), so the consumption pattern is proven in this repo.

## Goals / Non-goals

- **Goal:** discussion on meeting/motion detail pages is a Talk conversation surfaced through the registry tab+widget shell.
- **Goal:** existing `Comment` objects are migrated into Talk messages without losing author/timestamp, and the legacy objects are archived (not purged) for audit.
- **Non-goal:** changing voting, minutes, or any statutory record. Discussion is informal context, never the decision record.
- **Non-goal:** building a new Talk binding mechanism — the registry already binds a conversation to an object.

## Decisions

### D1 — Talk leaf is the discussion surface, bound per artifact

Each meeting and motion detail page renders the registry's **talk** tab. The registry creates/binds one Talk conversation per OR object (the governance artifact) using the existing ADR-019 object-link mechanism. No app-local conversation table.

### D2 — Migration: archive, don't delete

Legacy `Comment` objects are read once, replayed into the bound Talk conversation as messages (author + original timestamp carried in the message metadata where Talk allows; otherwise prefixed in the message body), then each `Comment` is set to an archived state via OR's archival workflow. Hard deletion is forbidden — the audit trail of who-said-what is retained per ADR-022's immutable-audit principle.

### D3 — Threading degrades gracefully

The in-app model supported `parentComment` threading. Talk conversations are flat with reply-quotes. Migration flattens threads chronologically and renders a quote of the parent's first line when Talk's reply API is available; this is acceptable because discussion is informal context, not a structured record.

## ADR-022 exceptions (kept in-app — NOT migrated by this change)

This change migrates discussion only. The following decidesk capabilities are explicit ADR-022 exceptions documented here for cross-change clarity and are **out of scope** for this migration:

- **Statutory voting** — `VotingService` / `QuorumService` / `LiveDecisionService` (secret ballots, quorum, proxy/weighted votes) stay in-app. The polls leaf is only for *informal straw polls*; statutory voting never moves to a leaf.
- **ORI / Popolo publication** — ADR-001 / ADR-003 publication stays in-app.

These exceptions are not affected by retiring `CommentService`.

## Risks

- **Talk not installed.** The registry already handles a missing leaf app by hiding the tab; the meeting/motion page must degrade to "discussion unavailable" rather than erroring. Verified against the xWiki-leaf fallback already in `MeetingIntegrations.vue`.
- **Migration ordering.** Comment import must run after the Talk conversation is bound; the one-shot must be idempotent (re-run safe) so a partial run can resume.
