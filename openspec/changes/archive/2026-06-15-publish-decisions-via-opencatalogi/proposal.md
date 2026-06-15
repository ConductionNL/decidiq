# Proposal: Public publication of decisions, agendas, and minutes via OpenCatalogi

## Why

Decidesk's own docs promise to "take and **publish** the minutes", FEATURES.md lists "Public decision register (citizen-facing portal)" (#11) and "Agenda publication (public/member-only)" (#36), and the WOO (Wet open overheid) actively obliges Dutch public bodies to publish decisions, agendas, and minutes — yet **no spec mentions public or anonymous visibility of any governance output** (FEATURE-REEVALUATION-2026-06-11, MISSING, med). The `Decision` schema already carries dormant `isPublished`/`publishedAt` fields and `Meeting` carries `isPublic`, but nothing specifies what drives them or what publication means.

In the raadsinformatie market decidesk targets (Notubiz, iBabs, GO Raadsinformatie), public publication is not an add-on — it is the product. 265 of 345 Dutch municipalities consume OpenRaadsinformatie-format data; a decision suite whose decisions can never leave the authenticated app is not competitive and not WOO-compliant.

The fresh `citizen-participation` change already established the route for decidesk's public surface: **OpenCatalogi / the OpenRegister published-predicate, never app-local public pages**. This change applies the same route to the core governance outputs.

## What Changes

- **Create the new `public-publication` capability**: staff-driven, per-object publication of adopted decisions, finalized meeting agendas, and approved minutes through `@self.published` on OpenRegister plus routing into a configured OpenCatalogi catalog per governance body. No app-local anonymous pages or read endpoints — anonymous read access happens exclusively through OpenCatalogi's / OR's existing publication surface (identical posture to `citizen-participation`).
- **Eligibility gates**: only `decided`/`enacted` decisions, agendas of meetings flagged `isPublic` whose convocation has been sent, and minutes in `approved` lifecycle are publishable. Drafts, deliberation material, and the entire board-governance family (`BoardMeeting`, `BoardMinutes`, `BoardMaterial`, `Resolution` with confidential classification, conflict declarations, audit logs) are **never** publishable — the board-meeting-resolutions confidentiality model wins.
- **Publication payload builder**: published objects are derived summary payloads — vote totals, never individual voter identities; attendance as counts/roles per the body's policy; confidential agenda items and their attachments stripped from published agendas.
- **OpenRaadsinformatie alignment**: publication payloads carry the ORI mappings the specs already cite (`Besluit`, `Vergadering`, `AgendaPunt`, `Verslag`) as structured fields, so OpenCatalogi/OR public API consumers receive ORI-compatible data. A full ORI/OAI-PMH harvester endpoint is out of scope (follow-up via OpenConnector).
- **Withdraw and rectify**: publication can be withdrawn (un-publish with an audit-trailed reason) and rectified (re-publish a corrected payload that references the rectified version) — the WOO correction duty.
- **Drive the dormant fields**: `Decision.isPublished`/`publishedAt` and `Meeting.isPublic` become spec-governed; `isPublished`/`publishedAt` are writable only via the publication flow (delta on `decision-management`).
- **Admin configuration**: target OpenCatalogi catalog per governance body and per-type publication policy (manual-only vs. prompt-on-transition) in the existing admin settings surface; graceful degradation with a staff-visible warning when OpenCatalogi is absent.

## Capabilities

### New Capabilities

- `public-publication`: eligibility-gated, staff-driven publication of decisions, agendas, and minutes via the OR published-predicate and OpenCatalogi catalogs, with ORI-aligned payloads, confidentiality guards, withdraw/rectify, and zero app-local public surface.

### Modified Capabilities

- `decision-management`: ADDED requirement — the `isPublished`/`publishedAt` fields are owned by the publication flow (never directly client-written), the decision detail view exposes the publish/withdraw actions to authorized staff, and publication events are recorded in the immutable decision audit trail.

## Impact

- **Schemas**: no new entity schemas; `PublicationRecord` is a small bookkeeping schema (published object reference, payload version, catalog reference, publishedBy, publishedAt, withdrawnAt, rectifiesVersion) in `decidesk_register.json`. Existing `Decision.isPublished`/`publishedAt` and `Meeting.isPublic` get spec-governed semantics.
- **Storage / RBAC / notifications**: all from OpenRegister — publication records in the decidesk register, publish/withdraw authority via OR RBAC (governance-body staff roles), an ADR-031 `x-openregister-notifications` rule notifying body members on publish/withdraw. No app-local notification engine.
- **Backend**: thin publish/withdraw/rectify endpoints plus a `PublicationPayloadService` (eligibility check, payload derivation, PII strip, ORI mapping, `@self.published`, catalog routing). Plain CRUD stays on `useObjectStore` → OR object API per ADR-022.
- **Frontend**: publish/withdraw actions on decision, meeting, and minutes detail views; a "Published" overview list per body; admin settings for catalog targets and policies. No public Vue pages.
- **Dependency**: OpenCatalogi (publication surface), OpenRegister published-predicate. Same known constraint as citizen-participation: verify `@self.published` is settable via the OR object API for decidesk objects on the deployed OR version before building (magic-mapper gap).
- **Alignment**: shares the publication route, degradation behaviour, and negative no-app-local-surface posture with the `citizen-participation` change — one consistent public story for the whole app.
- **Out of scope**: ORI/OAI-PMH harvester endpoint (OpenConnector follow-up), publication of citizen-participation results (owned by `citizen-participation`), board-governance outputs (explicitly excluded), automatic publication without staff action.
