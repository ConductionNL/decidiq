# Tasks: configurable-types-domain-model

Staged deliberately. **Stage 1 is this PR** — it establishes the pattern and
proves it end to end with the acceptance scenario. Stages 2–4 are follow-ups so
one PR stays reviewable; a rename detonates every diff-scoped gate at once, and
collapsing 24 schemas in one commit would do the same.

## Stage 1 — the type layer and the acceptance scenario (this PR)

### Schemas

- [x] 1.1 New fragment `lib/Settings/register.d/70-configurable-types.json`
- [x] 1.2 `MeetingType` — `governanceBody` FK, `allowedAgendaItemTypes[]`, quorum/voting defaults, `initialLifecycle`, publish gate (`isDraft`/`validFrom`/`validUntil`)
- [x] 1.3 `AgendaItemType` — `owningBody` FK (independent of the meeting's body), `votable`, `decisionType` FK, `fields` fragment, lifecycle
- [x] 1.4 `PositionType` — `governanceBody` back-FK, `seats`, `order`, `allowedHoldTypes[]`, `termDurationMonths`, `maxConsecutiveTerms`, `votingWeight`
- [x] 1.5 `PositionHold` — `membership` FK, `position` FK (`x-relation-filter` scoped to the membership's body), `holdType`, `startDate`, `endDate`, `termNumber`, `appointedBy`
- [x] 1.6 `GovernanceBodyComposition` — `composite`, `component`, `compositionType`, `seats`, `seatPosition` FK, dates
- [x] 1.7 `Meeting.type` FK added (enum `meetingType` retained, deprecated)
- [x] 1.8 `AgendaItem.type` FK added
- [x] 1.9 `Decision.type` FK added; `DecisionTemplate` gains `competentBody` + `competentPositionTypes`
- [x] 1.10 Bump register `info.version` — the importer skips a version that is not higher

### Migration evidence

- [x] 1.11 `occ openregister:tables:reconcile` run; new columns asserted present
- [x] 1.12 Descriptor verified `installed == shipped` at the new version
- [x] 1.13 Object counts recorded before/after — a schema landing is not data moving

### Acceptance scenario (Ruben's)

- [x] 1.14 Seed `GovernanceBody` **Management team**
- [x] 1.15 Seed `GovernanceBody` **Development team**
- [x] 1.16 Seed `GovernanceBody` **Pub quiz**, composed of both via two `direct` compositions
- [x] 1.17 Seed `MeetingType` "Pub quiz night" on Pub quiz
- [x] 1.18 Seed a **pub quiz meeting** of that type
- [x] 1.19 Seed votable `AgendaItemType` "Quiz question" + three questions with voting rounds
- [x] 1.20 Verify in a browser that the meeting and its votable questions render

### UI (Part C)

- [x] 1.21 `RunningProcessesWidget` margin — fixed as a **defect class**: every decidiq-local dashboard widget insets 0px where shared-lib widgets inset ~17px
- [x] 1.22 Dashboard reorder — the two KPI-shaped widgets stranded at `gridY 14`/`18` move into the KPI band at the top
- [x] 1.23 Meeting index calendar view — decidiq-local toggle, no shared-library edit
- [ ] 1.24 Coloured widget icons — **not done here.** Comes free from the shared `nextcloud-vue` change owned by another agent this wave

### Nav

- [x] 1.25 "Factions & bodies" → a single label; the UI stops implying two concepts

## Stage 2 — collapse the agenda-item schemas (follow-up)

- [ ] 2.1 Seed five `AgendaItemType` objects replacing `MondelingeVraag`, `Interpellatieverzoek`, `IngekomenStuk`, `Raadsinformatiebrief`, `KascommissieVerklaring`
- [ ] 2.2 Migrate existing objects of those five schemas onto `AgendaItem` + type; report a **count**, treat `0` as failure
- [ ] 2.3 Retire the five schemas and their five menu leaves; keep every route resolvable (gate-53 / ADR-044)
- [ ] 2.4 Retire `VragenuurConfiguratie` into `AgendaItemType`

## Stage 3 — retire the retirement schedule (follow-up)

- [ ] 3.1 Build the derived retirement-schedule view over `Membership.endDate` ∪ `PositionHold.endDate`
- [ ] 3.2 Migrate `RoosterRegel` rows onto `PositionHold`, counted
- [ ] 3.3 Retire `RoosterVanAftreden`, `RoosterRegel`, `TermijnRegeling`
- [ ] 3.4 Move `publicationDate`/`depublicationDate` onto the export artifact
- [ ] 3.5 Supersede `Post` and `BodyParticipation`

## Stage 4 — competence enforcement (follow-up, security-reviewed)

- [ ] 4.1 `DecisionCompetenceGuard::assertCompetent()` — throws, never returns nullable
- [ ] 4.2 Invoke it from the decision write path **before** persistence
- [ ] 4.3 Test that fails when the *call* is removed, not only when the guard is
- [ ] 4.4 Run `hydra-gate-orphan-auth`, `hydra-gate-unsafe-auth-resolver`, `hydra-gate-no-admin-idor`, `hydra-gate-semantic-auth`
- [ ] 4.5 No implicit competence: a type with no `competentBody` falls back to the meeting's body, else refuses

## Stage 5 — humaniq handoff (not implemented here)

- [x] 5.1 Inventory what decidiq holds and what humaniq already ships
- [x] 5.2 Write `humaniq-handoff.md` — adopt onboarding/offboarding, move terms, delete the rooster
- [ ] 5.3 Open the proposal on humaniq's backlog, sequenced after `humaniq-rule-compliance-enforcement`
- [ ] 5.4 Delete decidiq's `OnboardingTraject` / `OffboardingTraject` once humaniq accepts

## Cancelled

- [x] C.1 **`fractievoorzitter-fractie-koppeling`'s `Fractie` schema is cancelled.** A faction is a `GovernanceBody` with `bodyType: faction`; a faction leader is a `PositionHold`. Landing a `Fractie` schema would re-introduce the duplication ADR-006 retired.
