# Design: Migrate email linking to the Email integration leaf

## Context

Two in-app email mechanisms exist:

- `EmailLinkService` stores `EmailLink` objects mapping Nextcloud Mail emails to governance objects (Decision, AgendaItem), with reverse lookup and decision-reference extraction (auto-suggest linking from subject/body).
- `MailReplyHandler` is a background job that polls voting-notification email threads and casts votes from the reply body.

ADR-022 explicitly names "parallel link tables — an app creating its own `{app}_email_links` when OR's integration registry provides the equivalent via `openregister_*_links`" as a review-blocking anti-pattern, and ADR-019 exposes an **email** leaf (emails bound to an OR object, tab + widget). So `EmailLinkService` clearly migrates. `MailReplyHandler` is different: it is a vote-casting path coupled to statutory voting.

## Goals / Non-goals

- **Goal:** email-to-dossier linking is the email leaf bound via the registry; the `EmailLink` table is gone.
- **Goal:** vote-by-email keeps working without resurrecting an app-local link store.
- **Non-goal:** moving vote casting itself out of the statutory-voting path (ADR-022 exception).

## Decisions

### D1 — Email leaf is the linking surface; registry provides reverse lookup

The email leaf, bound to the decision-dossier (and agenda-item) OR object via ADR-019, is the surface for linking emails. Reverse lookup ("which dossier is this email linked to?") is served by the registry's object-link index, not an `EmailLink` object. No `{app}_email_links` table.

### D2 — `MailReplyHandler` vote-by-email stays in the statutory path

Vote-by-email is part of statutory voting (the reply casts a real vote subject to quorum/eligibility). Under ADR-022's statutory-voting exception, the vote-casting logic stays in-app in the voting path; `MailReplyHandler` is retained for that purpose. What moves is only the *visibility* of the email thread — it surfaces through the email leaf on the relevant object — not the casting decision. The handler does not create `EmailLink` objects; thread association uses the registry link.

### D3 — Decision-reference extraction: keep only if the leaf can't suggest

`EmailLinkService`'s subject/body extraction for auto-suggest is a convenience. At apply time, check whether the email leaf already offers a "link this email to object X" suggestion. If it does, drop the in-app extraction entirely. If it does not, retain a thin extraction helper that *feeds a suggestion to the leaf* — never a link store. This follows ADR-022's "use the abstraction if it exists" rule.

### D4 — Migration: relink then archive

For each `EmailLink` object, create the equivalent registry email-object link binding the email to the dossier/agenda-item, then archive the legacy `EmailLink` object via OR's archival workflow (no hard delete). Idempotent and resume-safe.

## ADR-022 exceptions (kept in-app — NOT migrated)

- **Statutory voting incl. vote-by-email** — `VotingService` / `QuorumService` / `LiveDecisionService` and `MailReplyHandler`'s vote-casting logic (secret ballots, quorum, proxy/weighted votes, email-cast votes). The polls leaf is for informal straw polls only; statutory voting never moves to a leaf.
- **ORI / Popolo publication** — ADR-001 / ADR-003 stays in-app.

## Risks

- **Mail not installed.** Registry hides the email tab gracefully; dossier remains usable.
- **Vote-by-email coupling.** Care needed so retiring `EmailLinkService` does not break `MailReplyHandler`'s thread association — D2 requires the handler to use the registry link, not `EmailLink`, before the schema is retired.
