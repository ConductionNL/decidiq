# Migration: decision-detail-fullpicture

## Current State

`lib/Settings/decidesk_register.json` — Decision schema (version 0.4.0):
- `x-openregister-relations`: `amends` (many-to-one → Decision, amendment→parent-motion), `offer`/`order`/`product` (contract attachments), `route` (one-to-many → DecisionStage, C4).
- `x-openregister-calculations`: `routeComplete`, `currentStage` (C4 declarative route progress). No `effectiveStatus`.
- `x-openregister-notifications`: `decisionProposed`, `decisionRecorded`. No supersession/repeal rule.
- `x-openregister-seeds`: 19 decisions including `besluit-begroting-2026`; none carry decision-to-decision modification relations.
- No `supersedes`/`repeals`/`implements`/`refersTo` relations.

`src/manifest.json` — `DecisionDetail.config.sidebarTabs`: overview, lifecycle, actionItems, voting, audit. Decisions index (`/decisions`) has no in-force filter.

`src/registry.js` — no `DecisionRouteTab` / `RelatedDecisionsTab`.

## Target State

Decision schema (version bumped, e.g. 0.5.0):
- Relations add `supersedes`, `repeals`, `implements`, `refersTo` (one-to-many → Decision); existing `amends` description widened to "this decision modifies that decision" (NO second `amends` — see design D1).
- Calculations add `effectiveStatus` (materialised, derived from inbound `supersedes`/`repeals` of decided/enacted sources; precedence repealed > superseded > lifecycle) IF OR supports inverse-relation calcs; otherwise the field is omitted and the detail view derives it client-side (design D2).
- Notifications add a rule notifying the governance body when a decision becomes superseded/repealed (ADR-031 dialect).
- Seeds add `besluit-begroting-2027` carrying `supersedes` → `besluit-begroting-2026`.

`src/manifest.json` — two new sidebarTabs (`route` → DecisionRouteTab, `related` → RelatedDecisionsTab); Decisions index gains an in-force filter (quickFilters or filter, design D3).

`src/registry.js` — `DecisionRouteTab` + `RelatedDecisionsTab` registered as `kind: "page"`.

## Migration Class

No Nextcloud `lib/Migration/VersionXXXX.php` class is required. Decidesk owns no database tables (thin client over OpenRegister); schema, calculations, notifications, and seeds are applied declaratively when the register definition is imported/synced via `SettingsService` (the register JSON is the source of truth). This is a declarative register update, not a DB migration.

```
Version: n/a (declarative register sync)
File: lib/Settings/decidesk_register.json (Decision schema version bump)
Key operations:
- Add 4 relation types + widen amends description on Decision
- Add effectiveStatus calculation (conditional on OR capability)
- Add supersession/repeal notification rule
- Add besluit-begroting-2027 seed + its supersedes relation
```

## Migration Steps

1. Bump the Decision schema `version` in `decidesk_register.json` (additive change).
2. Add `supersedes`, `repeals`, `implements`, `refersTo` to `Decision.x-openregister-relations`; widen the `amends` description.
3. Add the `effectiveStatus` entry to `Decision.x-openregister-calculations` (or omit per design D2 if OR cannot express the inverse-relation lookup).
4. Add the supersession/repeal rule to `Decision.x-openregister-notifications`.
5. Add the `besluit-begroting-2027` seed carrying `supersedes` → `besluit-begroting-2026`.
6. Re-import/sync the register via the existing settings flow (`occ` or the admin Settings action) so OR picks up the new schema version, calculation, notification, and seed.

## Data Impact

Additive only. No records are deleted or transformed. Existing decisions gain a *derived* `effectiveStatus` (read-time computation, no stored lifecycle change) — none of their lifecycle states or audit trails change. Exactly one new seed object is created (`besluit-begroting-2027`) plus one relation on it. Safe to run on live data; idempotent on re-sync (seed keyed by slug).

## Rollback Procedure

Revert `decidesk_register.json` to the prior Decision schema version (remove the 4 relations, the `effectiveStatus` calculation, the notification rule, and the 2027 seed; restore the original `amends` description) and re-sync the register. Remove the two sidebarTabs + in-force filter from `src/manifest.json` and the two tab registrations from `src/registry.js`. No stored data to unwind — relations are OR object relations and effectiveStatus is derived.

## Validation

- `python3 -c "import json; json.load(open('lib/Settings/decidesk_register.json'))"` parses; `python3 -c "import json; json.load(open('src/manifest.json'))"` parses.
- After re-sync: opening `besluit-begroting-2026` shows effectiveStatus `superseded` + the "Superseded by Programmabegroting 2027" banner; opening `besluit-begroting-2027` shows the outgoing `supersedes` in its RelatedDecisionsTab.
- The in-force filter on `/decisions` excludes `besluit-begroting-2026` when set to "in force".
- The route tab renders without error for both routed and stageless decisions.
- `hydra-gate-notification-dialect` passes (canonical ADR-031 dialect on the new rule).
