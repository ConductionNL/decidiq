# Delegated authority check

## Why

Decision 182 (procedures are configuration) and hydra ADR-118 give the mandate registry and matrix to decidiq (capability C17). dossiq keeps 13 `Mandaat*` classes that hold its own matrix and call an external mandaatregister over HTTP. Once decidiq can answer "may this account perform this act", dossiq can drop its matrix and keep one generic transition guard that asks.

## What changes

- `bevoegdheidstoedeling` gains three optional properties: `delegateUser` (a Nextcloud account), `delegateGroup` (a Nextcloud group) and `acts` (the act keys the authority covers). Schema version 0.1.0 to 0.2.0, register version 0.14.0 to 0.15.0.
- New `Service/DelegatedAuthorityCheck::check(actor, act, amount, at)` answers with `authorised`, a `reason` and the `allocation` that covers the act.
- Every uncertain answer is "no": an empty `acts` list covers no act, an unreadable date covers nothing, an ondermandaat whose parent is not in force covers nothing, an unreadable register answers `register-unreadable`.

## Not in this change

- The CSV import of a mandate matrix through OpenRegister's import preview (C23). Follow-up change.
- REQ-DMR-006 is untouched: decidiq still gates none of its own Decision transitions. The asking app enforces.

## Pair

dossiq change `delegated-authority-guard` replaces `MandaatGuard` and `MandaatValidationService` with a guard that calls this check.
