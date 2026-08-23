# vve-alv-pack Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [vve-alv-pack](../../changes/vve-alv-pack/)

## Purpose

The VvE ALV statutory pack: the thin statutory layer that lets decidiq's association domain serve a Vereniging van Eigenaars. It owns VvE statutory decision *content* (built-in decision templates with their default majorities), modelreglement presets (1992/2006/2017) with per-splitsingsakte overrides, breukdelen *presentation and validation* over the existing `votingWeight` machinery, the kascommissie verslag/verklaring flow feeding decharge, and the VvE-specific statutory agenda-items warning. The weighted tally, thresholds, and quorum calculation stay in `voting-system`/`process-configuration`; recurring year cycles stay in `pc-cyclus`; registering the splitsingsakte stays in `governing-documents-register`.

**Standards**: Schema.org (`HowTo`, `AssessAction`, `AuthorizeAction`), BW 5:112/5:124–139 (splitsing, VvE), BW 2:38/2:48 (ALV, decharge), Modelreglementen 1992/2006/2017, Woningwet (reservefonds/MJOP per 2018)
**Feature tier**: V1

## ADDED Requirements

### Requirement: REQ-VVE-001 VvE statutory schemas on OpenRegister

The system SHALL define four schemas in the decidesk register via the `lib/Settings/register.d/57-vve-alv-pack.json` fragment (ADR-037, never editing `decidesk_register.json`; fragment number 57 is assigned to this change — 40–56 and 58–65 belong to siblings): `VveConfiguration` (annotated `x-schema-org: schema:OwnershipInfo`), `VveDecisionTemplate` (`schema:HowTo`), `ModelreglementPreset` (`schema:HowTo`), and `KascommissieVerklaring` (`schema:AssessAction`). `VveConfiguration` SHALL carry at minimum: `governanceBody` (GovernanceBody reference, required, one configuration per body), `modelreglement` (ModelreglementPreset reference, required), `breukdelenDenominator` (integer, required, default 10000), `splitsingsakteDocument` (string reference to the governing document registered by the `governing-documents-register` capability — reference only, never a duplicate registration), and `majorityOverrides[]` (per-akte deviations: each with `decisionCategory`, `voteThreshold`, `quorumFraction`, and a free-text `akteArtikel` source note). `VveDecisionTemplate` SHALL carry: `name` (required), `decisionCategory` (required), `description`, `proposedText` (the standard besluittekst), `builtIn` (boolean, default false), `defaultVoteThreshold` (enum mirroring the VotingRound `voteThreshold` values: `simple-majority`, `qualified-majority-two-thirds`, `qualified-majority-three-quarters`, `unanimous`), `defaultQuorumFraction` (string fraction, e.g. `2/3`, nullable = no quorum beyond the body default), and `reglementSource` (free text naming the modelreglement article). `KascommissieVerklaring` SHALL carry: `boekjaar` (integer, required), `verdict` (required enum: `goedkeurend`, `met-voorbehoud`, `afkeurend`), `toelichting`, `agendaItem` (AgendaItem reference — the jaarrekening item), `governanceBody` (GovernanceBody reference, required), and its verslag file attached via OpenRegister's FileService (no app-local file storage). Every property SHALL carry a `title`; the manifest and all widget/filter sources SHALL reference schemas by slug (`vve-configuration`, `vve-decision-template`, `modelreglement-preset`, `kascommissie-verklaring`). No schema in this fragment SHALL carry financial ledger properties (no balances, no bijdragen).

#### Scenario: Register fragment is additive

- GIVEN a decidiq installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the four schemas register from the `57-vve-alv-pack.json` fragment
- AND no existing schema in `decidesk_register.json` is modified

#### Scenario: VvE configuration binds a body to its statutory context

- GIVEN the seeded association governance body for VvE Zeewaarts
- WHEN its `vve-configuration` object is inspected
- THEN it references the body, the `modelreglement-2017` preset, denominator 10.000, and the splitsingsakte governing-document reference
- AND omitting the body, preset, or denominator is rejected by OpenRegister schema validation

### Requirement: REQ-VVE-002 Built-in VvE statutory decision templates

The system SHALL ship six built-in `VveDecisionTemplate` seeds via the fragment's seed-data path, following the process-configuration built-in pattern (`builtIn: true`, read-only, duplicable): **decharge bestuur** (`decisionCategory: decharge`, simple-majority), **vaststelling jaarrekening** (`jaarrekening`, simple-majority), **dotatie reservefonds** (`reservefonds-dotatie`, simple-majority), **vaststelling/actualisatie MJOP** (`mjop-vaststelling`, simple-majority), **machtiging bestuur onderhoud boven drempel** (`machtiging-boven-drempel`, qualified-majority-two-thirds with quorumFraction `2/3`), and **wijziging huishoudelijk reglement** (`wijziging-huishoudelijk-reglement`, qualified-majority-two-thirds). Each template SHALL name its modelreglement source in `reglementSource`. Edit and delete of a built-in template SHALL be refused server-side; duplication SHALL yield an editable copy with a fresh slug and `builtIn` cleared, leaving the original unchanged (same rule and mechanism as `ProcessTemplateService` built-ins). Instantiating a template SHALL create a normal `Decision` object pre-filled with the template's name, proposed text, and category — decision lifecycle, routes, and voting stay in the existing decision model; this capability never forks it.

#### Scenario: Fresh install has usable VvE decision templates

- GIVEN a fresh decidiq installation
- WHEN the VvE decision templates are listed
- THEN all six built-ins are available with their default majorities, quorum fractions, and reglement sources, immediately usable without configuration

#### Scenario: Built-in template is read-only but duplicable

- GIVEN the built-in `decharge-bestuur` template
- WHEN a user attempts to edit or delete it
- THEN the operation is refused
- AND duplicating it yields an editable copy with a fresh slug and `builtIn` cleared, leaving the original unchanged

#### Scenario: Decharge from the template is a normal Decision

- GIVEN the seeded VvE and its jaarrekening flow
- WHEN the decharge template is instantiated for boekjaar 2025
- THEN an ordinary `Decision` object is created (no VvE-specific decision schema) pre-filled with the template's proposed text
- AND it proceeds through the existing decision lifecycle and voting machinery unchanged

### Requirement: REQ-VVE-003 Modelreglement presets with splitsingsakte override

The system SHALL ship three built-in `ModelreglementPreset` seeds (`modelreglement-1992`, `modelreglement-2006`, `modelreglement-2017`; `builtIn: true`, read-only, duplicable) each mapping decision categories to their required `voteThreshold` and `quorumFraction` per that modelreglement (e.g. `machtiging-boven-drempel`: 1992 → three-quarters with 2/3 quorum, 2006/2017 → two-thirds with 2/3 quorum; each mapping carries its article reference). A VvE SHALL pick its preset at body setup via `VveConfiguration.modelreglement`. When a majority is resolved for a decision category the precedence SHALL be: explicit caller-supplied value (always wins, mirroring process-configuration) > `VveConfiguration.majorityOverrides[]` entry for the category (the splitsingsakte deviation) > preset mapping for the category > the template's `defaultVoteThreshold`/`defaultQuorumFraction`. Resolution SHALL feed the existing round-open voting-rule defaults (voting-system / process-configuration machinery) — this capability SHALL NOT introduce a second threshold enum, tally path, or quorum calculator. A body without a `VveConfiguration` SHALL be entirely unaffected (fail-soft to existing behaviour).

#### Scenario: Preset majority applied at round-open

- GIVEN VvE Zeewaarts configured with `modelreglement-2017`
- WHEN a voting round is opened for a decision instantiated from the `machtiging-boven-drempel` template without an explicit threshold
- THEN the round is created with `voteThreshold = qualified-majority-two-thirds` and the 2/3 quorum requirement from the preset
- AND an explicit caller-supplied threshold takes precedence over the preset

#### Scenario: Splitsingsakte override beats the preset

- GIVEN VvE Zeewaarts' configuration carries a `majorityOverrides` entry for `wijziging-huishoudelijk-reglement` requiring `qualified-majority-three-quarters` (akte art. 62)
- WHEN a round is opened for a decision in that category without an explicit threshold
- THEN the override's three-quarters threshold is applied instead of the preset's two-thirds
- AND the applied threshold and its source (override/preset/template) are recorded with the round configuration

#### Scenario: Non-VvE bodies are unaffected

- GIVEN a governance body with no `vve-configuration` object
- WHEN a voting round is opened for one of its decisions
- THEN the existing template/method defaults apply unchanged and no VvE resolution runs

### Requirement: REQ-VVE-004 Breukdelen presentation over existing votingWeight

The system SHALL render voting weights as breukdelen fractions for bodies that have a `VveConfiguration`: an attendee's `Membership.votingWeight` (meeting-attendees REQ-MAT-006) SHALL be displayed as `<votingWeight>/<breukdelenDenominator>` (e.g. `150/10.000`) in the attendee list, meeting detail, live tally, and closed-round results. Meeting totals SHALL be expressed in breukdelen (present + represented breukdelen out of the denominator), the quorum display SHALL show the required and present breukdelen fractions, and vote results SHALL show for/against/abstain in breukdelen alongside the existing head-count. This is presentation only: the weighted tally engine, threshold evaluation, and quorum calculation of `voting-system` SHALL be reused unchanged, and bodies without a `VveConfiguration` SHALL keep the existing plain-number weight display.

#### Scenario: Attendee weight rendered as a fraction

- GIVEN VvE Zeewaarts (denominator 10.000) with a member whose Membership has votingWeight 620
- WHEN the meeting attendee list is displayed
- THEN the member's weight renders as `620/10.000`
- AND for a body without a VvE configuration the weight still renders as a plain number

#### Scenario: Quorum and totals in breukdelen

- GIVEN an ALV of VvE Zeewaarts where members holding 5.600 of the 10.000 breukdelen are present or represented
- WHEN the chair views the quorum display for a machtigingsbesluit requiring a 2/3 quorum
- THEN it shows `5.600/10.000` present against a required `6.667/10.000`, and the quorum is reported as not met
- AND the underlying quorum decision comes from the existing voting-system quorum machinery, not a parallel calculator

#### Scenario: Vote result in breukdelen alongside head-count

- GIVEN a closed voting round in a VvE ALV with 14 members for (7.200 breukdelen) and 6 against (2.100 breukdelen)
- WHEN the results are displayed
- THEN both representations are shown: 14 voor / 6 tegen and `7.200/10.000` voor / `2.100/10.000` tegen
- AND the adopted/rejected outcome remains exactly the weighted result computed by the existing tally engine

### Requirement: REQ-VVE-005 Breukdelen sum validation warning

The system SHALL validate, for a body with a `VveConfiguration`, that the sum of active members' `votingWeight` values equals `breukdelenDenominator`, and SHALL surface a non-blocking warning naming the actual sum and the expected denominator wherever memberships or the VvE configuration are managed and on the meeting quorum display. The warning SHALL NOT block saving memberships, the configuration, or the conduct of a meeting (a VvE mid-mutation legitimately has a temporary mismatch); it SHALL disappear once the sum matches. Expired memberships (endDate in the past) SHALL be excluded from the sum, consistent with meeting-attendees REQ-MAT-001.

#### Scenario: Mismatch produces a warning, not a block

- GIVEN VvE Zeewaarts with denominator 10.000 whose active memberships sum to 9.850 breukdelen
- WHEN the body's membership management or a meeting's quorum display is viewed
- THEN a warning states the sum is 9.850 of the expected 10.000
- AND memberships remain saveable and the meeting can proceed

#### Scenario: Matching sum clears the warning

- GIVEN the missing 150-breukdelen membership is added
- WHEN the display refreshes
- THEN the warning is gone

### Requirement: REQ-VVE-006 Kascommissie verslag and verklaring feed the decharge

The system SHALL support recording a `KascommissieVerklaring` for a boekjaar: the kascommissie's verslag is uploaded as a FileService attachment on the verklaring object, the `verdict` (`goedkeurend`, `met-voorbehoud`, `afkeurend`) and `toelichting` are recorded, and the verklaring is linked to the jaarrekening agenda item of the ALV. A decharge decision instantiated from the `decharge-bestuur` template for that boekjaar SHALL reference the verklaring and SHALL surface its verdict to the meeting: when no verklaring exists for the boekjaar, or the verdict is `afkeurend`, the decharge decision SHALL carry a visible warning — never a hard block (the ALV remains sovereign; BW 2:48 requires the verslag to be heard, not obeyed).

#### Scenario: Record the kascommissie verklaring on the jaarrekening item

- GIVEN the ALV agenda of VvE Zeewaarts contains the jaarrekening 2025 agenda item
- WHEN the kascommissie verslag PDF is uploaded and the verdict `goedkeurend` is recorded
- THEN a `kascommissie-verklaring` object exists for boekjaar 2025 with the file attached via FileService and linked to that agenda item

#### Scenario: Decharge decision surfaces the verklaring

- GIVEN the recorded `goedkeurend` verklaring for boekjaar 2025
- WHEN the decharge decision for boekjaar 2025 is instantiated from the template
- THEN the decision references the verklaring and displays its verdict
- AND with no verklaring recorded, or verdict `afkeurend`, the decision shows a warning while remaining decidable by the ALV

### Requirement: REQ-VVE-007 VvE statutory ALV agenda-items completeness

The system SHALL extend the statutory agenda-items warning concept (agenda-management) for VvE bodies as an additive rule set owned by this capability: for a `general_assembly` meeting of a body that has a `VveConfiguration`, the missing-items warning SHALL additionally check for the VvE statutory items — kascommissieverslag, jaarrekening, begroting, and MJOP-status — using the same synonym-matching, warn-and-list behaviour as the existing ALV items. The existing `STATUTORY_ALV_ITEMS` list and its behaviour for non-VvE bodies SHALL remain unchanged (the agenda-management requirement is not modified); for VvE bodies the VvE items SHALL appear in the same warning surface alongside the base ALV items.

#### Scenario: VvE ALV agenda missing MJOP-status is flagged

- GIVEN a `general_assembly` meeting for VvE Zeewaarts whose agenda has the base ALV items and the jaarrekening but no MJOP item
- WHEN the agenda completeness warning is evaluated
- THEN the warning lists "MJOP-status" (and any other missing VvE items) alongside missing base items

#### Scenario: Non-VvE association ALV is unchanged

- GIVEN a `general_assembly` meeting for an ordinary association without a VvE configuration
- WHEN the agenda completeness warning is evaluated
- THEN only the existing statutory ALV items are checked and no VvE-specific item is required

## Non-Functional Requirements

- **Performance:** breukdelen rendering and the sum validation operate on the already-fetched membership/attendee data — no additional per-row API calls.
- **Accessibility:** warnings (sum mismatch, missing verklaring, missing statutory items) are conveyed by icon + text, never colour alone; fraction displays carry accessible labels (WCAG 2.1 AA).
- **Internationalization:** Dutch and English MUST be supported; i18n keys in English (ADR-005/ADR-007) — Dutch statutory terms (breukdelen, kascommissie, decharge, MJOP) remain the domain vocabulary in both locales.

## Acceptance Criteria

- Register fragment 57 adds the four schemas additively; seeds plant three presets, six built-in templates, and the VvE Zeewaarts demo set.
- Built-ins are read-only and duplicable server-side, mirroring ProcessTemplateService.
- Majority resolution precedence (caller > akte override > preset > template default) feeds the existing round-open defaults; non-VvE bodies unaffected.
- Breukdelen appear in attendee list, quorum display, live tally, and results — presentation only, tally engine untouched.
- Sum-of-breukdelen warning is non-blocking and clears on match.
- Kascommissie verklaring with FileService verslag links the jaarrekening agenda item and surfaces on the decharge decision.
- VvE statutory agenda items warn additively for VvE bodies; base ALV behaviour unchanged.

## Notes

- Boundaries: `pc-cyclus` owns the recurring year cycle (decharge as step outcome); `governing-documents-register` owns splitsingsakte registration (referenced here); `voting-system`/`process-configuration` own tally, thresholds, quorum (reused here). This delta is ADDED-only — notably it never modifies public-publication's eligibility-gates requirement nor agenda-management's statutory-items requirement.
- Seeded majorities name their modelreglement article and require juridical review before release (proposal Risk 1 / Open Questions).
