# Design: followup-implementation-progress

Read at decidiq development `4d7430ff` and openregister development `555af72`.

## What exists

| Piece | Where |
|---|---|
| Decision | `lib/Settings/decidesk_register.json` `Decision`: `lifecycle` (draft to enacted, `x-openregister-lifecycle`), `outcome`, `enactedAt`, `decisionType` (motion is a decision type, ADR-005); `authorization.update` admins only; `x-openregister-notifications` on the decision (decisionProposed, decisionRecorded, ...) |
| Motion page | `src/manifest.json` `MotionDetail` (`/motions/:id`, schema `decision`), widgets motion-data, motion-files, motion-amendments, motion-votes, motion-amendment-order, motion-voting-round |
| Decision page | `src/manifest.json:1158` `DecisionDetail` (`/decisions/:id`), including `decision-actions` (custom `ActionItemsSurface`) and `decision-commitments` (object-list on `governance-commitment` with `relatedMotion = @objectId`) |
| Lists | `src/manifest.json:856` `Motions` (schema decision, filter `decisionType: motion`, columns title, motionType, proposer, lifecycle, submittedAt); `Decisions` |
| Transition guard | `lib/Lifecycle/DecisionTransitionGuard.php:133` (enacted) |
| Listener registration for object events | `lib/AppInfo/Registrar/ObjectListenerRegistrar.php` |
| OpenRegister aggregation | `lib/Service/Aggregation/AggregationAnnotationValidator.php:64` metrics count, sum, avg, min, max; no latest value of a related object |

## Approach

### Schema

Fragment `lib/Settings/register.d/92-implementation-progress.json`:

`ImplementationUpdate` (slug `implementation-update`, Schema.org `UpdateAction`):

| Property | Type | Notes |
|---|---|---|
| `decision` | uuid, `$ref` Decision | required |
| `reportedOn` | date | required, defaults to today |
| `status` | enum `not-started`, `on-track`, `delayed`, `completed`, `will-not-be-implemented` | required |
| `percentComplete` | integer 0 to 100 | optional |
| `text` | string | required, what happened |
| `reportedBy` | string | Nextcloud uid, set by the server |

Authorization: read `authenticated`; create `decidiq-secretariat` and `decidiq-administrators`; update and delete `decidiq-administrators` only, because an update is a record, not a draft. (`Decision.proposer` is a display name, not a user id, so the proposer cannot be granted create through a `match` rule.)

Added to `Decision`: `implementationStatus` (same enum, read-only in forms) and `implementationStatusDate` (date, read-only).

`x-openregister-notifications` on `Decision`, rule `implementationSilent`: trigger `scheduled` (intervalSec 86400), filter `outcome = adopted`, `implementationStatus` not in `completed`, `will-not-be-implemented`, `implementationStatusDate` older than 90 days or empty with `decisionDate` older than 90 days; channels `nc-notification`; recipients group `decidiq-secretariat`; subject nl "Geen voortgang gemeld: {{title}}", en "No progress reported: {{title}}".

### Service

`lib/Listener/ImplementationUpdateListener.php` listens to two OpenRegister events for schema `implementation-update`, both already used in `ObjectListenerRegistrar.php:40-41`:

- `ObjectCreatingEvent`: refuses the update (a validation error OpenRegister answers with 422) unless the decision is in `decided` or `enacted` with `outcome` `adopted`, and sets `reportedBy` to the caller.
- `ObjectCreatedEvent`: writes `implementationStatus` and `implementationStatusDate` onto the decision as the system, only when this update is the newest by `reportedOn`.

### Screens

- `DecisionDetail` and `MotionDetail`: widget `decision-implementation`, type `object-list` on `implementation-update` with `filter: { decision: "@objectId" }`, sort `reportedOn desc`, columns reportedOn, status (badge), percentComplete, text, `allowCreate: true`; the data widget includes `implementationStatus`.
- `Motions` and `Decisions` lists: column `implementationStatus`, quick filter on it.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Update record, read rules, lists, widgets | Declarative schema and manifest | Plain data and pages |
| 90-day reminder | Declarative `x-openregister-notifications` scheduled rule | The canonical dialect (ADR-031, gate-18) |
| Current status on the decision | Imperative listener | ADR-031 exception: a derived field from the newest related object; the aggregation dialect offers count, sum, avg, min and max only |
| Only adopted decisions take updates | Imperative, in the same listener | A condition on another object's lifecycle and outcome, which an `authorization` match on the update cannot reach |

## Seed data

Municipality example set: the adopted motion "Motie meer groen in de wijk" gets two updates, 2026-03-01 `on-track` 30% "Plan van aanpak vastgesteld door het college", and 2026-06-15 `delayed` 45% "Aanbesteding uitgesteld tot na de zomer". The association set gets one `completed` update on a board decision about the new membership fee.

## Files

- `lib/Settings/register.d/92-implementation-progress.json`, `lib/Settings/profiles/municipality.json`, `lib/Settings/profiles/association.json`
- `lib/Listener/ImplementationUpdateListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- `src/manifest.json` (`DecisionDetail`, `MotionDetail`, `Motions`, `Decisions`)
- `tests/Unit/Listener/ImplementationUpdateListenerTest.php` (constructs OpenRegister's real object event), `tests/newman/implementation-progress.json`, `tests/e2e/implementation-progress.spec.ts`
