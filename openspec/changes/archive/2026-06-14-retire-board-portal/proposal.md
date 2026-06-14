# Proposal: retire-board-portal

## Why

The "board portal" (Phase 8, archived change `2026-06-12-board-meeting-resolutions`)
added a **parallel** corporate entity set (`Board`, `BoardMember`, `BoardMeeting`,
`BoardVote`, `BoardMinutes`, `BoardMaterial`, `BoardAuditLogEntry`) on top of the
universal entities, plus its own Vue views and nav. This violates ADR-006
(mode adaptation over parallel entities): there must be exactly **one schema per
concept**, with audience differences expressed by label adaptation, type
discriminators, and progressive disclosure — never a duplicated schema set.

The corporate data already lives on the universal entities after the earlier
cycles of this branch: resolutions are `decision` objects with
`decisionType=resolution` (C1 `unify-decision-supertype`); corporate board
members are `Person` + `Membership` objects (C2 `popolo-decision-makers`).

## What Changes

- **Delete** the 7 parallel `board-*` schemas (and their seeds) from
  `lib/Settings/decidesk_register.json`.
- **Extend** `GovernanceBody.bodyType` with `supervisory-board` and
  `executive-board` (keep existing values).
- **Re-seed** the corporate demo on the universal entities (1 `GovernanceBody`,
  1 `Meeting`, 1 `Minutes`, `mode=corp`).
- **Delete** the board-portal manifest fragment, the six `Board*`/`Resolution*`
  Vue views, the two board modals, and their `registry.js` registrations.
- **Delete** the board-CRUD controllers/services/lifecycle guard and the
  `BoardMeetingCalDavBridge` listener (served now by the universal manifest
  pages over governance-body/meeting/decision/minutes; meeting CalDAV sync stays
  on the universal `meeting` path per ADR-002).
- **Retarget** the board-coupled governance services + controllers (eIDAS,
  conflict-of-interest, proxy-vote, audit-log, governance-report,
  regulator-export, multilingual-reconciliation, QesGuard) onto the unified
  entities (`meeting`/`decision`/`minutes`/`vote`/`membership`/`audit-trail`) —
  no feature loss. eIDAS becomes a proper decision *method* in Cycle 2.
- **Remove** the `resolution` entry from `DecideskSearchProvider` (decisions
  remain searchable).

## Impact

- Affected specs: `governance-bodies`, `meeting-management`, `resolution-minutes`.
- Affected code: register JSON, manifest fragment, 6 views + 2 modals, registry,
  routes, DI registrations + CalDAV listener, board controllers/services,
  retargeted governance services/controllers, search provider.
- No migration class; no object data transformed (thin client, ADR-022).
