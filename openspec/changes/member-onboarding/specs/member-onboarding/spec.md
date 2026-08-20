# member-onboarding Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- member-onboarding

## Purpose

Provides the guided member onboarding & offboarding workflow (raadswisseling/installatie and its board/association analogues): an `OnboardingTraject` and an `OffboardingTraject` per member, each carrying a structured checklist — beëdiging recording, Nextcloud account linkage with role-based group/RBAC-scope assignment, induction-pack delivery into the member's Files, nevenfuncties intake and fractie assignment by reference — with declarative lifecycle and step reminders (ADR-031), a raadswisseling batch orchestration that diffs a completed Member Import (`admin-settings`) into griffie-confirmed suggestions, a griffie progress dashboard, and list/detail pages. Complements `person-and-membership` (Membership start/end dates), `authorization-via-or-rbac` (role→scope projection), `meeting-pack-board-book` (Files delivery pattern), `fractievoorzitter-fractie-koppeling` (beëdiging/Fractie vocabulary), and `interests-and-integrity` (nevenfuncties register). An onboarding traject is a `schema:Action` (agent = the griffie, object = the incoming member's Person).

## ADDED Requirements

### Requirement: REQ-MOB-001 OnboardingTraject schema on OpenRegister

The system SHALL define an `OnboardingTraject` schema in the decidesk register via a `lib/Settings/register.d/59-member-onboarding.json` fragment (ADR-037 — never by editing `decidesk_register.json`), annotated `x-schema-org: schema:Action`. The schema SHALL carry at minimum: `person` (Person reference, required), `targetBody` (GovernanceBody reference, required), `targetRole` (enum matching the Membership role enum: `chair`, `vice-chair`, `secretary`, `treasurer`, `member`, `observer`, `guest`; required), `trigger` (enum `nieuw-lid`, `raadswisseling-batch`, `tussentijdse-opvolging`; required), `steps` (array of structured step objects, required — see REQ-MOB-004), `lifecycle` (required), the beëdiging fields of REQ-MOB-005, `ncAccount` (Nextcloud user id, optional until linked), `membership` (Membership reference, optional until created), and `batch` (free-form batch label linking trajecten created by one raadswisseling run, optional). Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `onboarding-traject`.

#### Scenario: Fragment adds the schema without touching existing schemas
- GIVEN the register fragment `59-member-onboarding.json` is loaded
- WHEN the decidesk register imports
- THEN the `onboarding-traject` schema exists with all required fields and property titles
- AND no schema outside fragment 59 is created or modified

### Requirement: REQ-MOB-002 OffboardingTraject schema on OpenRegister

The system SHALL define an `OffboardingTraject` schema in the same fragment, annotated `x-schema-org: schema:Action`, carrying at minimum: `person` (Person reference, required), `body` (GovernanceBody reference, required), `membership` (Membership reference to be end-dated, required), `trigger` (enum `individueel`, `raadswisseling-batch`; required), `eindeDatum` (date the membership ends, required), `eindeReden` (enum aligned with the fractievoorzitter vocabulary, including `einde-raadslidmaatschap`, `verhuizing`, `overlijden`, `ontslag-op-eigen-verzoek`, `einde-termijn`; required), `steps` (array of structured step objects, required), `lifecycle` (required), `exitBevestigdDoor` / `exitBevestigdOp` (exit confirmation by the griffie, optional until confirmed), and `batch` (optional batch label). The offboarding checklist SHALL include at minimum these step types: end-dating the Membership (`endDate` per `person-and-membership`), revoking Nextcloud groups and OR RBAC scopes (REQ-MOB-008), a personal-data note step, and the exit confirmation. The personal-data step SHALL reference the `document-annotations` capability's author export for the member's own annotations (GDPR) and SHALL NOT implement its own annotation export. The schema slug SHALL be `offboarding-traject`.

#### Scenario: Offboarding traject created for a departing member
- GIVEN Person "K. Bakker" with an active Membership in "Gemeenteraad Delft"
- WHEN the griffie creates an OffboardingTraject with eindeDatum "2026-03-29" and eindeReden `einde-raadslidmaatschap`
- THEN the traject references the person, body, and membership
- AND its checklist contains the membership end-date, group/scope revocation, personal-data note, and exit-confirmation steps

#### Scenario: Personal-data step points at the annotations export, not a bespoke one
- GIVEN an OffboardingTraject's personal-data step
- WHEN the griffie opens it
- THEN it links to the member's own-annotations export capability (`document-annotations`)
- AND no offboarding-owned annotation export endpoint exists

### Requirement: REQ-MOB-003 Declarative traject lifecycle

Both schemas SHALL declare their status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`default`): field `lifecycle`, initial `gestart`, states `gestart → in-uitvoering → afgerond | vervallen`, with `afgerond` and `vervallen` terminal. A traject SHALL NOT reach `afgerond` while any non-optional step is not completed; for an OffboardingTraject the revocation step (REQ-MOB-008) SHALL always be non-optional. The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: Traject cannot complete with open mandatory steps
- GIVEN an OnboardingTraject in `in-uitvoering` whose beëdiging step is still `open`
- WHEN a user attempts to set the lifecycle to `afgerond`
- THEN the transition is refused with a message naming the open mandatory steps

#### Scenario: Terminal state is final
- GIVEN a traject in lifecycle `afgerond`
- WHEN any user attempts to set the lifecycle back to `gestart` or `in-uitvoering`
- THEN the transition is refused by the declared lifecycle map

### Requirement: REQ-MOB-004 Structured checklist steps with per-step status

The `steps` property on both schemas SHALL be an array of structured step objects, each carrying: `stepType` (enum; onboarding at minimum `beediging`, `account-koppeling`, `groepen-toewijzen`, `introductiepakket`, `nevenfuncties-intake`, `fractie-toewijzing`, `custom`; offboarding at minimum `lidmaatschap-beeindigen`, `groepen-intrekken`, `persoonsgegevens-notitie`, `exit-bevestiging`, `custom`), `title` (required), `status` (enum `open`, `in-uitvoering`, `afgerond`, `overgeslagen`; default `open`), `verplicht` (boolean; default true — `overgeslagen` is only a valid status when `verplicht` is false), `dueDate` (optional), `completedAt`/`completedBy` (set on completion), `note` (optional), and `reference` (optional URL or object UUID linking the step's subject, e.g. the created FractieLidmaatschap or the delivered pack folder). Step updates SHALL always write the full `steps` array carried forward from the freshly read object (OpenRegister saveObject is PUT-semantic), and updating one step SHALL NOT alter any other step.

#### Scenario: Completing one step leaves the others untouched
- GIVEN an OnboardingTraject with steps `beediging` (open) and `introductiepakket` (open, note "iPad reserveren")
- WHEN the griffie marks `beediging` as `afgerond`
- THEN `beediging` carries `completedAt`/`completedBy`
- AND `introductiepakket` still has status `open` and its note intact

#### Scenario: Mandatory step cannot be skipped
- GIVEN a step with `verplicht: true`
- WHEN a user attempts to set its status to `overgeslagen`
- THEN the update is rejected with a validation error

### Requirement: REQ-MOB-005 Beediging recording aligned with the Raadslid vocabulary

The `OnboardingTraject` schema SHALL record the oath as data, using field names aligned with the `fractievoorzitter-fractie-koppeling` vocabulary: `beëdigingsDatum` (date), `beëdigingsType` (enum `eed`, `belofte`), and `beëdigingsVergadering` (Meeting reference — the installatievergadering or raadsvergadering where the member was sworn in). Completing the `beediging` step SHALL require all three fields. The meeting procedure itself (agenda, ceremony) stays with the meetings capabilities; this requirement only records the outcome. When the fractievoorzitter capability is present, its Raadslid/FractieLidmaatschap creation SHALL be able to read `beëdigingsDatum` from the traject (begin-datum equals beëdigings-datum); this change SHALL NOT create Raadslid or FractieLidmaatschap objects itself.

#### Scenario: Recording the oath
- GIVEN an OnboardingTraject for an incoming raadslid and a Meeting "Installatievergadering 2026-03-26"
- WHEN the griffie completes the beëdiging step with beëdigingsDatum "2026-03-26" and beëdigingsType `belofte` and that meeting as beëdigingsVergadering
- THEN the traject stores all three values and the step becomes `afgerond`

#### Scenario: Oath step refuses incomplete data
- GIVEN an OnboardingTraject whose beëdiging step is open
- WHEN the griffie attempts to complete it without a beëdigingsVergadering
- THEN the completion is rejected naming the missing field

### Requirement: REQ-MOB-006 Griffie-confirmed account linkage and role-based provisioning

The `account-koppeling` and `groepen-toewijzen` onboarding steps SHALL be executed by an explicit griffie action, never automatically. Account linkage SHALL reuse the Member Import matching semantics (`admin-settings`): match by email where possible, flag unmatched persons for manual linking or invitation. Group/role provisioning SHALL derive the Nextcloud group set from the traject's `targetBody` + `targetRole` per the configured body-role→group mapping, write memberships via `IGroupManager`, and rely on the `authorization-via-or-rbac` role→scope projection (REQ-RBAC-001) to reconcile OR RBAC scopes from the roster write — the app SHALL NOT write OR scope groups directly. The action SHALL be fail-closed: an unresolved mapping or a failed group write leaves the step not-completed and reports the error; it SHALL never report success it did not verify.

#### Scenario: Provisioning an incoming member
- GIVEN an OnboardingTraject with ncAccount linked and targetRole `member` on body "Gemeenteraad Delft"
- WHEN the griffie confirms the groepen-toewijzen step
- THEN the mapped Nextcloud group memberships for that body/role are created
- AND the step records the resulting group list in its `reference`/`note`
- AND the RBAC scopes follow via the existing role→scope projection

#### Scenario: Failed group write leaves the step open
- GIVEN a body-role→group mapping that resolves to a non-existent group
- WHEN the griffie confirms the provisioning step
- THEN the step stays not-completed and an error names the unresolved group
- AND no partial success is reported

### Requirement: REQ-MOB-007 Induction pack delivered into the member's Files

The `introductiepakket` step SHALL deliver a welcome bundle into the member's own Files as a folder package, reusing the Files delivery pattern of `meeting-pack-board-book`/`MeetingPackageService`: a configurable set of induction documents (governing documents, vergaderschema, handbook, links) assembled per governance body, delivered via the OpenRegister `FileService`/Nextcloud share into the linked `ncAccount`'s Files, with defensive skip-reporting — an undeliverable item MUST NOT fail the delivery; it SHALL be listed as skipped. The step's `reference` SHALL point at the delivered folder. Delivery SHALL require a linked `ncAccount` (the step depends on `account-koppeling`) and SHALL be honest about partial results (skip list shown, never a silent success).

#### Scenario: Welcome bundle delivery
- GIVEN an OnboardingTraject with a linked ncAccount and a configured induction set of 5 documents for the body
- WHEN the griffie triggers the introductiepakket step
- THEN a folder with the deliverable documents appears in the member's Files
- AND the step completes with the folder as reference

#### Scenario: Undeliverable item is skipped, not fatal
- GIVEN one induction document that no longer exists
- WHEN the delivery runs
- THEN the remaining documents are delivered
- AND the result lists the missing document as skipped

### Requirement: REQ-MOB-008 Fail-closed de-provisioning on offboarding

The `groepen-intrekken` offboarding step SHALL remove the member's body-role-derived Nextcloud group memberships via `IGroupManager` and end-date the Membership per `person-and-membership` (`endDate` = `eindeDatum`), so that the `authorization-via-or-rbac` projection reconciles the member out of the body's RBAC scopes. The step SHALL verify and report the resulting memberships after execution; the OffboardingTraject SHALL NOT reach `afgerond` while this step is not `afgerond` (it is always `verplicht`). Groups outside the body-role mapping (e.g. personal or unrelated groups) SHALL NOT be touched. The action is griffie-confirmed, never automatic.

#### Scenario: Revocation removes access
- GIVEN a departing member in the mapped groups of "Gemeenteraad Delft" and an active Membership
- WHEN the griffie confirms the groepen-intrekken step
- THEN the mapped group memberships are removed and the Membership carries endDate = eindeDatum
- AND the step records the verified post-revocation state

#### Scenario: Offboarding cannot complete without revocation
- GIVEN an OffboardingTraject whose groepen-intrekken step is `open`
- WHEN a user attempts to set the traject lifecycle to `afgerond`
- THEN the transition is refused

### Requirement: REQ-MOB-009 Raadswisseling batch orchestration from a completed Member Import

The system SHALL offer a raadswisseling run for a governance body that diffs a completed Member Import (`admin-settings` Member Import) against the body's current active memberships and produces a **suggestion list**: an onboarding suggestion (trigger `raadswisseling-batch`) for each imported person without an active membership, and an offboarding suggestion for each active member absent from the import. The griffie SHALL review the list, may deselect or amend individual suggestions, and confirms; only confirmed suggestions create trajecten, all sharing one `batch` label. The run SHALL NOT create or end-date any Membership itself and SHALL NOT create trajecten without explicit confirmation — never automatic, no background job.

#### Scenario: Diff produces onboarding and offboarding suggestions
- GIVEN a completed Member Import for "Gemeenteraad Delft" containing 25 persons, of whom 8 are new, and 6 current active members absent from the import
- WHEN the griffie starts a raadswisseling run
- THEN a suggestion list shows 8 onboarding and 6 offboarding suggestions with per-row detail
- AND nothing is created yet

#### Scenario: Only confirmed suggestions become trajecten
- GIVEN the suggestion list above
- WHEN the griffie deselects one offboarding suggestion (member re-installed via lijstverbinding) and confirms
- THEN 8 OnboardingTrajecten and 5 OffboardingTrajecten are created with the same batch label
- AND no Membership was created or end-dated by the run itself

### Requirement: REQ-MOB-010 Declarative step reminders

Step reminders SHALL be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031) on both schemas: a trigger when a traject is created (to the griffie group), a scheduled trigger when a non-terminal traject has a step whose `dueDate` is approaching, and a scheduled trigger when a step is past its `dueDate`, each with Dutch and English subjects. The app SHALL NOT dispatch these notifications imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob.

#### Scenario: Overdue step reminder
- GIVEN an OnboardingTraject in `in-uitvoering` with a step whose dueDate is in the past
- WHEN the scheduled notification evaluation runs
- THEN the griffie group receives the overdue notification with the traject and step named

#### Scenario: No imperative dispatch exists
- GIVEN the shipped change
- WHEN the codebase is inspected
- THEN no imperative object-notification dispatch for traject reminders exists; all reminders are declarative rules in fragment 59

### Requirement: REQ-MOB-011 Griffie progress dashboard

The Dashboard manifest page SHALL carry declarative widgets for the griffie: trajecten per lifecycle status (onboarding and offboarding, sourced via manifest widget aggregation on `register: decidesk`, `schema: onboarding-traject` / `offboarding-traject`, `metric: count`, grouped or filtered by `lifecycle`) and an overdue-steps KPI counting non-terminal trajecten having at least one step past its `dueDate` — no imperative counting endpoint. Each widget SHALL route to the corresponding index page pre-filtered to the same set.

#### Scenario: Status overview widget
- GIVEN seeded trajecten across `gestart`, `in-uitvoering`, and `afgerond`
- WHEN the griffie opens the dashboard
- THEN the trajecten widgets show the per-status counts via declarative source aggregations
- AND clicking a segment opens the pre-filtered trajecten index

### Requirement: REQ-MOB-012 List and detail pages

The system SHALL provide Onboarding and Offboarding index pages and traject detail pages as manifest pages in a `src/manifest.d/` fragment (ADR-037), following existing manifest-v2 conventions (`register: decidesk`, schema by slug `onboarding-traject` / `offboarding-traject`; columns for person, body, role/reden, trigger, lifecycle, step progress; quick filters on lifecycle, trigger, body, and batch). The detail page SHALL render the checklist with per-step status and actions (complete, skip where allowed, execute provisioning/delivery), the linked person/body/meeting references as navigable relations, and the audit-trail sidebar.

#### Scenario: Working the checklist from the detail page
- GIVEN an OnboardingTraject in `in-uitvoering`
- WHEN the griffie opens its detail page
- THEN the checklist renders each step with status, due date, and its action
- AND completing a step updates the progress column on the index

#### Scenario: Batch filter on the index
- GIVEN trajecten created by the raadswisseling run with batch "raadswisseling-2026"
- WHEN the griffie filters the index on that batch
- THEN exactly the trajecten of that run are listed

## Non-Functional Requirements

- **Performance:** the raadswisseling diff for a 45-member council SHALL complete interactively (< 3 s) without N+1 object reads (bulk queries on memberships and import rows).
- **Accessibility:** checklist and suggestion-list controls MUST be keyboard-operable and WCAG 2.1 AA compliant; NcSelect usages carry `inputLabel`.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); i18n keys in English.

## Acceptance Criteria

- [ ] Fragment 59 imports both schemas with canonical lifecycle + notification dialects and no edits outside the fragment
- [ ] A full onboarding (beëdiging → account → groups → pack → reference steps) and a full offboarding (end-date → revoke → note → exit confirmation) run end-to-end on seeded data
- [ ] The raadswisseling run never mutates memberships and creates trajecten only on confirmation
- [ ] Departed member's mapped groups and RBAC scopes are verifiably gone after offboarding

## Notes

- Reference-only boundaries: nevenfuncties register = `interests-and-integrity`; Fractie/FractieLidmaatschap = `fractievoorzitter-fractie-koppeling`; annotation export = `document-annotations`; Member Import = `admin-settings`. Steps targeting an absent capability render as informational links and stay completable (the work happens outside decidesk until the sibling lands).
- Related ADRs: ADR-031 (declarative dialects), ADR-037 (register/manifest fragments), ADR-016 (seed data), ADR-005 (i18n).
