# Changelog

All notable changes to Decidesk are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [0.1.7] - 2026-06-01

### Changed

- **Action items consolidated on the canonical OpenRegister object.** The
  parallel in-app `Task` / `Delegation` object stores (p4-collaboration) are
  retired; the OpenRegister `action-item` object is now the single source of
  truth for follow-up content and status, in line with ADR-022.
- **Deck integration leaf consumed declaratively.** The Meeting and Decision
  schemas now whitelist the `deck` integration in `configuration.linkedTypes`
  (ADR-019), and the meeting/decision detail pages gained an "Action board"
  sidebar tab that renders the registry deck board as a projection over the
  action items. The board lights up automatically once the Deck app + deck
  leaf provider are installed; with Deck absent the tab simply does not render
  and action items remain reachable via the ActionItems pages.
- **Delegation / reclaim mapped onto the canonical record.**
  `ActionItemDelegationService` reassigns an action item to a substitute
  (`assignee` change, IDOR-safe) and lets the original delegator reclaim it
  (`assignee` reverts to `delegator`, `reclaimedAt` stamped); the reclaim fact
  is preserved by OpenRegister's immutable audit trail (ADR-005 / D2). Exposed
  via `POST /api/action-items/{id}/reassign` and `.../reclaim`.

### Added

- `lib/Repair/MigrateTasksToActionItems` — idempotent, resume-safe migration
  that projects each legacy `Task` onto an `action-item` (marker
  `migratedFromTaskUuid`), replays active `Delegation` semantics onto the
  action item, then archives the legacy objects via OpenRegister's soft-delete
  workflow (no hard delete; objects stay queryable for audit).

### Removed

- `TaskService`, `DelegationService`, `TaskController`, `DelegationController`,
  their routes and DI registrations, and the `Tasks` / `TaskDetail` manifest
  pages + `task` / `delegation` register-mapping entries.

### Notes

- This environment ships no Deck app and no CalDAV VTODO layer, so the live
  deck-card rendering / VTODO sync the spec anticipates is implemented
  declaratively and the runtime-only remainder is deferred until the deck leaf
  ships — see `openspec/changes/migrate-action-items-to-deck-leaf/tasks.md`.
