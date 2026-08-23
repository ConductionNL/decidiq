# Design: member-onboarding

## Architecture Overview

Two new OpenRegister schemas (`OnboardingTraject`, `OffboardingTraject`) in fragment `lib/Settings/register.d/59-member-onboarding.json` carry the workflow as data: a traject per member with a structured `steps` array, a declarative lifecycle, and declarative reminders. The thin-client pattern holds — pages, filters, and dashboard widgets are manifest fragments querying OpenRegister directly. Imperative code is limited to three side-effectful surfaces no OR dialect can express: NC group provisioning/revocation, Files bundle delivery, and the Member-Import-vs-membership diff. Everything the traject *references* (Membership, Fractie, nevenfuncties, annotations, meetings) is owned elsewhere and linked by UUID, never duplicated.

```
Member Import (admin-settings)          OnboardingTraject ──steps──► beediging ── Meeting ref
        │ completed import                     │                     account-koppeling ── NC user
        ▼                                      │                     groepen-toewijzen ── IGroupManager ─► RBAC projection
RaadswisselingService ── diff ──► suggestions ─┤ (griffie confirms)  introductiepakket ── InductionPackService ─► Files
        │                                      │                     nevenfuncties-intake ── interests-and-integrity (ref)
        ▼                                      │                     fractie-toewijzing ── fractievoorzitter (ref)
   OffboardingTraject ──steps──► lidmaatschap-beeindigen ── Membership.endDate
                                 groepen-intrekken ── IGroupManager (fail-closed, verified)
                                 persoonsgegevens-notitie ── document-annotations export (ref)
                                 exit-bevestiging
```

## Decisions

### D1: Trajecten are first-class schemas, not Membership decorations

A raadswisseling onboards a person *before* a Membership exists (the membership is an outcome of the traject, created after beëdiging) and offboarding must survive the membership's end-dating. Putting workflow state on Membership would corrupt the Popolo model (`person-and-membership` deliberately keeps Membership thin) and break the multi-body case. **Alternative considered:** checklist on Person — rejected: a person can be onboarded to several bodies with independent trajecten.

### D2: Checklist steps as a structured array property, not separate step objects

Steps live in a `steps` array on the traject. A raadswisseling creates ~30–50 trajecten × ~6 steps; separate step objects would mean ~300 objects, N+1 list rendering, and cross-object lifecycle coupling for the "all mandatory steps done" completion guard. The array keeps the traject atomic and the guard evaluable on one object. Trade-off: PUT-semantic saves require carrying the full array forward on every step update (spec REQ-MOB-004 pins this; a test asserts an untouched step survives). **Alternative considered:** `OnboardingStap` schema with traject relation — rejected for the volume/N+1 and guard-evaluation reasons above.

### Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where no dialect can express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Traject status workflow (`gestart → in-uitvoering → afgerond \| vervallen`) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`default`) | Pure guarded state machine; zero app code |
| Completion guard (no `afgerond` with open mandatory steps) | Lifecycle transition condition on the declared map where the dialect supports property predicates; otherwise the transition action validates server-side before the lifecycle write (documented deviation, see Open Questions) | Guard belongs to the state machine |
| Step reminders (created / due-soon / overdue) | `x-openregister-notifications` scheduled triggers, recipients = griffie group, nl/en subjects | ADR-031 default for reminders; gate-18 hard-fails imperative dispatch; no bespoke ReminderJob |
| Dashboard widgets (trajecten per status, overdue-steps KPI) | Manifest stat-widget `source` aggregations (`metric: count`) | Declarative counts like every existing KPI widget |
| Index/detail pages, filters, menu | Manifest.d fragment (slug refs) | Standard manifest v2 |
| NC group provisioning + revocation | **Imperative** — `OnboardingProvisioningService` via `IGroupManager` | No OR dialect writes Nextcloud groups; must be fail-closed and verified; RBAC scopes follow via the existing `authorization-via-or-rbac` projection (we never write scope groups directly) |
| Induction pack delivery | **Imperative** — `InductionPackService` (MeetingPackageService folder-delivery pattern, skip-report) | Files side effect, no dialect |
| Raadswisseling diff + confirm | **Imperative** — `RaadswisselingService` | Cross-source set diff producing a reviewed suggestion list; not expressible as a dialect; never runs unattended |

### D3: Provisioning derives groups from a body-role→group mapping and lets the RBAC projection do the rest

The traject stores `targetBody` + `targetRole`; a per-body mapping (admin-settings surface, reusing the body configuration) resolves the NC groups. The service writes group memberships only, and `authorization-via-or-rbac` REQ-RBAC-001's projector reconciles chair/signatory scopes from the roster — writing scope groups directly would duplicate the authority and drift. Fail-closed: unresolved mapping or failed write = step stays open with a named error; the step records the *verified* post-write group list. **Alternative considered:** direct RBAC-scope writes — rejected: two writers for one scope set.

### D4: Raadswisseling batch = diff → suggestion list → explicit confirm

The diff (import rows vs active memberships, keyed by matched NC account/email, spec REQ-MOB-009) is pure computation; creation happens only per confirmed suggestion, all tagged with one `batch` label for filtering and progress tracking. Nothing ever end-dates a Membership as part of the run — end-dating is a checklist step on each created OffboardingTraject, itself griffie-confirmed. This is the "diff-based suggestion, griffie confirms — never automatic" contract from the proposal, and it keeps the two dangerous side effects (membership mutation, access revocation) behind two separate human confirmations.

### D5: Beëdiging is data on the traject, aligned with the fractievoorzitter vocabulary

`beëdigingsDatum` / `beëdigingsType` (`eed`/`belofte`) / `beëdigingsVergadering` (Meeting ref). The fractievoorzitter change states FractieLidmaatschap begin-datum = beëdigings-datum; keeping the field name identical lets that change read the traject without mapping. This change does not create Raadslid/FractieLidmaatschap/Fractie objects — the `fractie-toewijzing` step stores only a reference to the created FractieLidmaatschap once the sibling capability performs it.

### D6: Reference-only steps degrade gracefully

`nevenfuncties-intake` and `fractie-toewijzing` steps carry a capability link + reference UUID. When the owning sibling change is present, the step deep-links into it; when absent, the step renders as an informational link and remains completable by the griffie (the real-world task still happens). No conditional schema, no duplicated objects.

## Nextcloud Integration

- Controllers: `OnboardingController` (provisioning execute, pack delivery, raadswisseling diff + confirm) — routed in `appinfo/routes.php` with correct auth attributes and per-body governance guards (no-admin-idor / semantic-auth gates).
- Services: `OnboardingProvisioningService` (`IGroupManager`, `IUserManager`), `InductionPackService` (OR `FileService`, folder-package pattern), `RaadswisselingService` (OR ObjectService bulk reads).
- Events/Hooks: none new — RBAC scope reconciliation rides the existing `authorization-via-or-rbac` roster projection.
- No DB migrations, no app tables (thin client).

## Security Considerations

- Provisioning and revocation endpoints are griffie/secretary-gated per governance body (per-object guard, not admin-only), CSRF-protected, and fail-closed (REQ-MOB-006/008); revocation is verified post-write and blocks traject completion until done.
- The raadswisseling run computes suggestions read-only; the confirm endpoint validates every suggestion against the current state again (TOCTOU: a membership created between diff and confirm invalidates its suggestion instead of double-creating).
- Trajecten contain personal data (oath, end reason) — access inherits OR RBAC; they are never published and carry no public predicate.
- Group writes are scoped to the body-role mapping; unrelated groups are never touched.

## NL Design System

Standard NC components via nc-vue manifest rendering; checklist and suggestion list use existing list/table components with CSS variables only; dialogs live in `src/dialogs`/`src/modals` (modal-isolation gate); NcSelect with `inputLabel` (WCAG AA).

## File Structure

```
lib/Settings/register.d/59-member-onboarding.json   # schemas + lifecycle + notifications + seeds
lib/Controller/OnboardingController.php
lib/Service/OnboardingProvisioningService.php
lib/Service/InductionPackService.php
lib/Service/RaadswisselingService.php
appinfo/routes.php                                  # new routes
src/manifest.d/member-onboarding.json               # pages + menu
src/manifest.json                                   # dashboard widgets (base page edit, see toezeggingen D6 precedent)
src/dialogs/…, src/components/…                     # checklist actions, suggestion list
tests/Unit/Service/…, tests/e2e/…
docs/features/member-onboarding.md
```

## Seed Data

Per ADR-016, fragment 59 seeds realistic objects for **each** new schema so the feature is demoable on install, linked to already-seeded Persons/GovernanceBodies/Meetings (nil-UUID placeholders only where a ref cannot resolve at seed time):

### Schema: `onboarding-traject` (3 objects)

1. Trigger `raadswisseling-batch`, batch `raadswisseling-2026`, lifecycle `in-uitvoering`: beëdiging step `afgerond` (beëdigingsDatum 2026-03-26, type `belofte`, ref to the seeded raadsvergadering), account-koppeling `afgerond`, groepen-toewijzen `open` with dueDate in the past (**drives the overdue KPI and the overdue rappel**), introductiepakket/nevenfuncties/fractie steps `open`.
2. Trigger `tussentijdse-opvolging`, lifecycle `gestart`: all steps `open`, dueDates in the near future (drives the due-soon reminder).
3. Trigger `nieuw-lid` (association analogue — ALV-elected board member of the seeded vereniging body), lifecycle `afgerond`: all mandatory steps `afgerond`, nevenfuncties step `overgeslagen` (`verplicht: false`), demonstrating the completed state and the non-municipal domain.

### Schema: `offboarding-traject` (2 objects)

1. Trigger `raadswisseling-batch`, batch `raadswisseling-2026`, eindeReden `einde-raadslidmaatschap`, lifecycle `in-uitvoering`: lidmaatschap-beeindigen `afgerond`, groepen-intrekken `open` (shows the completion block of REQ-MOB-008 live).
2. Trigger `individueel`, eindeReden `ontslag-op-eigen-verzoek`, lifecycle `afgerond`: all steps `afgerond` with exitBevestigdDoor/exitBevestigdOp set.

Seeds use general organisation data (works for municipality, association, corporate board) and Dutch names consistent with existing decidiq seeds. The `raadswisseling-2026` batch label ties objects 1+1 together so the batch filter and dashboard widgets are demoable immediately.

## Migration Plan

Additive only: new fragment, new manifest fragment, new services/routes. Deploy = register re-import picks up fragment 59. Rollback = revert the PR; existing traject objects stay inert and can be pruned via register admin. No data migration.

## Risks / Trade-offs

- [Steps array grows on one object] → step count is bounded (~6–8) and per-step payload small; array stays well under object-size concerns.
- [PUT-semantic save clobbers concurrent step edits] → UI patches within a freshly read object and the API validates the full array; test asserts untouched-step survival (memory: saveObject nulls omitted props).
- [Completion guard expressibility in the lifecycle dialect] → see Open Questions; fallback is a server-side validation on the transition action, documented, never a silent pass.
- [Sibling vocabulary drift (parallel wave)] → field names pinned in spec; deferred review question raised.

## Open Questions

- Can `x-openregister-lifecycle` express the "no `afgerond` while a `verplicht` step is not completed" transition condition as a property predicate, or must the guard run as server-side validation on the transition endpoint? To verify against OR's lifecycle dialect at apply time; the spec allows the documented fallback.
- Where does the body-role→group mapping live — extend the existing admin-settings body configuration or a dedicated settings key? Provisional: extend body configuration (one authority for body metadata).
- Exact deep-link target for the nevenfuncties-intake step once `interests-and-integrity` lands (its change dir is empty at time of writing).
