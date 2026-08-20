# Design: urgent-decision-procedure

## Architecture Overview

Urgency is a thin, additive layer over three proven mechanisms — nothing new is invented:

```
                       urgency trigger (guarded, audited)
                                    │
              Decision ─── isUrgent / urgencyReason / declaredBy/At
                 │                                   │
     route (existing DecisionStage machinery)        │ auto-append
                 │                                   ▼
   [preparatory…decisive stages, unchanged] ── [ratifying stage]
                 │                                   │
   expedited variant A: Meeting                      │ AgendaItem on next
     meetingType=extraordinary +                     │ regular meeting of
     shortenedNotice deviation record                │ the ratifying body
   expedited variant B: VotingRound                  ▼
     votingDeadline = now + N hours       outcome adopted → ratified
     (existing BW 2:40 written path)      outcome rejected → reversal via
                                          repeals/supersedes → effectiveStatus
```

Current state verified against the codebase:
- `Decision` (`lib/Settings/decidesk_register.json`) already carries a declarative `x-openregister-lifecycle` (8 states incl. `withdrawn`), a `route` relation to `DecisionStage`, route-progress calculations, `supersedes`/`repeals` relations with derived `effectiveStatus`, and `x-openregister-notifications`.
- `DecisionStage` already has `stageType=ratifying` in its enum and a declarative status lifecycle (`pending → active → decided/skipped`).
- `Meeting.meetingType` already has `extraordinary` and `regular`; convocation notice arithmetic exists (meeting-management spec, `src/services/noticeRules.js`, default 15 days).
- `VotingRound` already has `votingDeadline` (date-time) + `deadlineReminderSentAt`; the written-resolution path (BW 2:40) carries quorum on the round (`lib/Service/DecisionLifecycleService.php`).
- `ProcessTemplate` (`lib/Settings/register.d/43-process-config-v1.json`) is the established per-body policy home with fail-closed validation.

## Declarative-vs-imperative decision (ADR-031)

**Default is declarative; each imperative seam below is a justified exception.**

Declarative (extends existing `x-openregister-*` declarations, no new Service logic):
1. **Urgency fields** — plain additive schema properties on `Decision`; no new lifecycle states or transitions. The existing `x-openregister-lifecycle` blocks on `Decision` and `DecisionStage` are reused byte-identical: the ratifying stage runs the *existing* stage lifecycle (`pending → active → decided`), and route completion is already derived. The urgency lifecycle therefore *extends existing lifecycle declarations by composition*, not by editing them — there is nothing a new lifecycle dialect would add. (Canonical dialect reminder: any lifecycle edit must use `initial`, never `initialState`/`default` — the drift is silently ignored.)
2. **`awaitingRatification`** — an `x-openregister-calculations` derivation on `Decision` (`isUrgent && ∃ ratifying stage non-terminal`), mirroring the existing `currentStage`/`routeComplete` pattern. List, detail, and dashboard KPI all read the materialised field (no N+1).
3. **Notifications** — two `x-openregister-notifications` rules in the verified dialect (`updated`-trigger on `isUrgent`, `scheduled`-trigger filtered on `awaitingRatification`), recipients via `object-acl` + `groups` per the decidesk-notifications recipient rule.
4. **Expedited written deadline** — reuses `VotingRound.votingDeadline` and its existing declarative 24h reminder; only bound-checking is added (see seam B).

Imperative exceptions (each justified, each fail-closed):
- **A. Urgency-trigger guard** (`UrgencyTriggerGuard`, `lib/Lifecycle/`): who may set `isUrgent` depends on resolving the acting user against the meeting chair or `urgencyPolicy.allowedTriggerRoles` — actor-role resolution is not expressible in the schema dialect. This is precisely the existing, accepted pattern of `DecisionTransitionGuard` (chair-only transitions, fail closed when no chair resolves); we extend that pattern rather than invent one. It also enforces the `isUrgent`/`urgencyReason` field-guard (direct client writes rejected — same posture as the existing `isPublished` guard).
- **B. Ratification orchestration** (`UrgentRatificationService`, `lib/Service/`): appending a `DecisionStage`, resolving the ratifying body's next `regular` meeting, and creating a linked `AgendaItem` is cross-object creation with a retry-when-meeting-appears fallback — multi-object write orchestration the declarative dialect cannot do. Precedent: `DecisionCascadeService` (cascade → ActionItems), the established decidesk pattern for exactly this shape. Also validates `minimumNoticeFloorHours` and `responseDeadlineHours` bounds at the two expedited entry points (config-vs-request comparison at write time).
- **Explicitly rejected imperative options**: a `DecisionUrgencyService` state machine (would duplicate the declarative lifecycle — ADR-031 violation); new Decision lifecycle states like `awaiting-ratification` (urgency is orthogonal, exactly like `isPublished`; a state would break every existing transition consumer); a scheduled background job polling for unratified decisions (the declarative `scheduled` notification rule already covers it).

## Decisions

- **D1 — No new schemas.** Urgency fields on `Decision`, deviation fields on `Meeting`, policy object on `ProcessTemplate`. Alternative (an `UrgentProcedure` schema) rejected: ADR-005/ADR-006 forbid splitting the universal supertype; it would orphan route/lifecycle reuse.
- **D2 — Ratification = ordinary `ratifying` stage.** The enum value and lifecycle already exist; route-progress, timeline tab, and decision-methods resolution work unchanged. Alternative (dedicated `ratification` object) rejected: parallel machinery, zero added expressiveness.
- **D3 — Reversal reuses decision-evolution.** Rejected ratification is recorded as a reversing decision with `repeals`/`supersedes` → derived `effectiveStatus` (precedence already specced). Alternative (mutating the urgent decision's lifecycle backwards) rejected: lifecycle is append-only and audited; backward transitions would corrupt the audit contract.
- **D4 — Emergency meeting = `extraordinary` + deviation record.** No new `emergency` enum value: `extraordinary` is semantically exact; what is missing is only the *recorded deviation* (`shortenedNotice`, `actualNoticeHours`, `noticeDeviationReason`). Alternative (new meetingType) rejected: every meetingType consumer (filters, presets, seeds) would need touching for no semantic gain.
- **D5 — Config lives on `ProcessTemplate.urgencyPolicy`.** process-configuration is the established per-body policy home with fail-closed validation; absent policy ⇒ procedure unavailable. Alternative (fields on `GovernanceBody`) rejected: would fork the policy surface the guard already consults (`workflowTemplate`).
- **D6 — Deadline bounds in hours.** Market demand is hour-granular ("response deadline in hours"); `votingDeadline` is date-time so hours are lossless. Floor for emergency notice is also hours (`minimumNoticeFloorHours`) to sit below the day-granular `noticePeriodDays` without redefining it.

## API Design

No new pass-through CRUD (ADR-022 — the frontend keeps talking to the OR object API). Two thin action endpoints, following the existing lifecycle-action shape:

### `POST /api/decisions/{id}/urgency`
Declares urgency. **Request:** `{ "reason": "…", "ratifyingBody": "<uuid, optional override>" }` **Response:** the updated decision (incl. appended ratifying stage reference) or 403/422. Guard A authorises; orchestration B appends the stage + agenda placement.

### `POST /api/decisions/{id}/expedited-round`
Opens the expedited written round. **Request:** `{ "responseDeadlineHours": 24 }` **Response:** the created VotingRound or 422 naming the configured bounds. (Emergency-meeting variant needs no new endpoint — the existing convocation send gains the deviation fields.)

## Database Changes

None — decidesk owns no tables. All persistence is additive OpenRegister schema changes in `lib/Settings/decidesk_register.json` + one `register.d` fragment (`46-urgency-policy.json`) for `ProcessTemplate.urgencyPolicy`.

## Nextcloud Integration

- Controllers: `DecisionController` (or existing lifecycle controller) gains the two action methods — `#[NoAdminRequired]` + per-object authorization in the body (no-admin-idor gate), routes registered in `appinfo/routes.php` (route-reachability gate).
- Services: `UrgencyTriggerGuard` (lib/Lifecycle), `UrgentRatificationService` (lib/Service); both consume `ObjectService` via the established OR abstraction.
- Mappers/Entities: none (thin client).
- Events/Hooks: none new — the pending-placement retry hooks into the existing meeting-creation flow server-side (checked when a `regular` meeting is saved for a body with pending ratification placements).

## Security Considerations

- Trigger guard fails closed (no policy / unresolvable role ⇒ reject) — never the decidesk#45 nullable-resolver fail-open shape; no `catch (\Throwable) { return null; }` around policy resolution.
- Urgency fields are server-guarded against direct client writes (mirrors `isPublished`); Newman asserts the rejection.
- Both endpoints validate per-object access (IDOR) and CSRF per NC defaults; declared auth attributes match the semantic requirement (semantic-auth gate).
- All declarations/reversals land in the immutable audit trail; `urgencyReason` is stored verbatim (input length-validated, output escaped by Vue).

## NL Design System

Banner and badges use CSS variables only (no hardcoded colours); "urgent — awaiting ratification" uses icon + text, never colour alone (note: nldesign inverts `--color-error` — use the error-fill pattern validated in nldesign#40 context). KPI card reuses the existing `CnStatsBlock`; list badge reuses `CnStatusBadge`.

## File Structure

```
lib/
  Controller/DecisionController.php        (two action methods)
  Lifecycle/UrgencyTriggerGuard.php        (seam A)
  Service/UrgentRatificationService.php    (seam B)
  Settings/decidesk_register.json          (Decision + Meeting props, calc, notifications)
  Settings/register.d/46-urgency-policy.json (ProcessTemplate.urgencyPolicy)
appinfo/routes.php
src/
  components/decision/UrgencyBanner.vue    (detail banner)
  dialogs/DeclareUrgencyDialog.vue         (trigger dialog, own file per modal-isolation)
  dialogs/ExpeditedRoundDialog.vue
  services/noticeRules.js                  (deviation-aware hint)
  views/…                                  (list badge/filter, dashboard KPI)
```

## Seed Data

(ADR-016 — nil-UUID placeholders only; real UUIDs are minted at import.)

### Schema: `decision` (urgent examples, one municipal + one corporate/association)

| Field | Municipal: college spoedbesluit | Corporate: RvB urgent resolution |
|-------|--------------------------------|----------------------------------|
| slug | `spoedbesluit-noodopvang-2026` | `urgent-resolution-datalek-2026` |
| title | Noodopvang statushouders sporthal De Vliet | Emergency engagement of forensic IT firm after data breach |
| decisionType | `policy` | `management-point` |
| lifecycle | `decided` | `decided` |
| isUrgent | `true` | `true` |
| urgencyReason | Acute opvangcrisis; eerstvolgende reguliere raadsvergadering is pas over drie weken (Gemeentewet-spoedbevoegdheid college) | Active data breach requires contracting within 48 hours; statutory urgent-resolution clause art. 14.3, subject to RvC ratification |
| urgencyDeclaredBy | `burgemeester` | `ceo` |
| urgencyDeclaredAt | `2026-06-02T09:15:00+02:00` | `2026-06-10T18:40:00+02:00` |
| awaitingRatification | derives `true` | derives `false` (ratified, see stage 2) |
| route | 2 stages (below) | 2 stages (below) |

### Schema: `decision-stage` (routes for the two urgent seeds)

| Field | Municipal stage 1 | Municipal stage 2 | Corporate stage 1 | Corporate stage 2 |
|-------|-------------------|-------------------|-------------------|-------------------|
| slug | `noodopvang-college-besluit` | `noodopvang-raad-bekrachtiging` | `datalek-rvb-schriftelijk` | `datalek-rvc-bekrachtiging` |
| decision | `00000000-0000-0000-0000-000000000000` | (same) | `00000000-0000-0000-0000-000000000000` | (same) |
| sequence | 1 | 2 | 1 | 2 |
| stageType | `decisive` | `ratifying` | `decisive` | `ratifying` |
| decisionMakerType / assignedBody | body / College van B&W | body / Gemeenteraad | body / Raad van Bestuur | body / Raad van Commissarissen |
| method | `chair-register` | `vote` | `vote` (expedited written round, deadline 24h) | `chair-register` |
| status / outcome | `decided` / `adopted` | `pending` / — | `decided` / `adopted` | `decided` / `adopted` |

A third association-flavoured urgent seed (board acting between ALVs, ratification at the next ALV, `meetingType=general_assembly`) SHOULD be added if seed volume allows; the two above are the mandatory pair.

### Schema: `meeting` (emergency + ratifying meetings)

| Field | Emergency meeting | Ratifying meeting |
|-------|-------------------|-------------------|
| slug | `spoedvergadering-college-2026-06-02` | `raadsvergadering-2026-06-18` |
| meetingType | `extraordinary` | `regular` |
| governanceBody | College van B&W | Gemeenteraad |
| shortenedNotice / actualNoticeHours | `true` / 18 | — |
| noticeDeviationReason | Spoedbesluit noodopvang — reguliere oproeptermijn niet haalbaar | — |
| agenda | — | AgendaItem "Bekrachtiging spoedbesluit noodopvang" linked to the urgent decision |

### Schema: `process-template` (fragment update to two seeded templates)

| Field | Municipal council template | Corporate board template |
|-------|---------------------------|--------------------------|
| urgencyPolicy.allowedTriggerRoles | `["chair"]` | `["chair", "secretary"]` |
| urgencyPolicy.minimumNoticeFloorHours | 24 | 12 |
| urgencyPolicy.responseDeadlineHours | `{min: 12, max: 96}` | `{min: 4, max: 72}` |
| urgencyPolicy.ratificationRequired | `true` | `true` |
| urgencyPolicy.ratifyingBody | Gemeenteraad | Raad van Commissarissen |

Built-ins are read-only: the urgency-enabled municipal/corporate templates are seeded as *duplicated custom* templates (or the fragment bumps the built-ins' seed definitions — decided at apply time per how 43-process-config-v1 seeds are versioned).

**Related items per object:** the municipal decision links the emergency-meeting Minutes; the corporate decision links the expedited VotingRound (24h deadline, 3 for / 0 against) and the RvC chair-register stage.

## Risks / Trade-offs

- [PUT-semantic saves drop urgency fields] → carry-forward on every write path; regression test asserts an unrelated title edit preserves `isUrgent`/`urgencyReason`.
- [Pending agenda placement never retried] → placement check runs on meeting save for the ratifying body; the decision detail shows the pending warning until placed; scheduled ratification-due notification keeps humans in the loop.
- [`awaitingRatification` calculation not expressible over related stages in the current OR calculation dialect] → fallback: derive client-side from the same `route` query the timeline tab already makes, keeping the field read-only server-side (same fallback posture decision-management already specs for `effectiveStatus`).
- [Guard added but never invoked (orphan-auth gate / decidesk#60 class)] → the two controller actions are the only writers of urgency fields and both call the guard; gate 6 verifies invocation.
- [Seed drift: multi-word schema refs] → manifest/seed refs use slugs (`decision-stage`, `process-template`), never PascalCase.

## Migration Plan

1. Ship register changes (additive props + calc + notifications + fragment 44) — inert without the UI/endpoints.
2. Ship guard + orchestration + endpoints; 3. Ship UI (dialog, banner, badge/filter, KPI); 4. Seed data last so every path is demonstrable.
Rollback: revert app code; additive fields/rules stay inert; appended ratifying stages remain valid ordinary stages. No down-migration needed.

## Open Questions

- Whether `urgencyPolicy` on read-only built-in templates is delivered by re-versioning the built-in seeds or by shipping urgency-enabled duplicates (apply-time decision; both satisfy the spec).
