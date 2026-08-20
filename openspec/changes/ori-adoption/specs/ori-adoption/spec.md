---
status: draft
---

# Spec: ORI adoption — decidesk as the sole home of raadsinformatie

## Purpose

decidesk absorbs the raadsinformatie (council information) domain that procest
provisioned as the ORI OpenRegister register. Storage is Popolo-aligned on
decidesk's existing schemas; the Dutch ORI wire format survives only as an
adapter. Paired with procest change `ori-removal`, which ships second.

## ADDED Requirements

### Requirement: REQ-ORIA-001 — decidesk SHALL be the sole storage home of raadsinformatie

All council-information concepts formerly stored in the procest ORI register
(`vergadering`, `agendapunt`, `raadsdocument`, `stemming`, `raadslid`,
`fractie`) SHALL be storable losslessly in decidesk's Popolo-aligned schemas
(`Meeting`, `AgendaItem`, `DigitalDocument`, `VotingRound` + `Decision`,
`Person` + `Membership`, `GovernanceBody`) per the mapping table in
`design.md`. decidesk SHALL NOT introduce any Dutch-named schema or property
for this domain; Dutch identifiers are confined to the ORI adapter
(REQ-ORIA-004).

#### Scenario: Every ORI schema has a mapped Popolo home
- **GIVEN** the six ORI schemas in procest's `ori_register.json`
- **WHEN** each schema and each of its properties is checked against the mapping table
- **THEN** every property has a decidesk target (an existing property, an additive property from REQ-ORIA-002, or a documented derived/default) and none maps to a new Dutch-named identifier

### Requirement: REQ-ORIA-002 — Schema deltas SHALL be additive only

`decidesk_register.json` SHALL gain exactly the following, and nothing SHALL be
renamed or removed: `Meeting.meetingType` value `informational-session`;
`Meeting.lifecycle` value `cancelled`; `AgendaItem.documents` (array of
`DigitalDocument` refs); `DigitalDocument.classification`, `DigitalDocument.url`
(`format: uri`), `DigitalDocument.fileName`; `VotingRound.partyResults` (array
of `{party, value, seatCount}`), `VotingRound.subjectDecision` (`Decision`
ref); `GovernanceBody.bodyType` value `political-group`,
`GovernanceBody.seatCount` (integer), `GovernanceBody.coalitionRole` (enum
`coalition` | `opposition`).

#### Scenario: Existing objects remain valid
- **GIVEN** a decidesk instance with existing Meeting/AgendaItem/VotingRound/GovernanceBody objects
- **WHEN** the updated register is imported via `ConfigurationService::importFromApp()`
- **THEN** every pre-existing object still validates against its schema and no property was removed or renamed

#### Scenario: New enum values are accepted
- **GIVEN** the updated register is active
- **WHEN** a Meeting is written with `lifecycle: cancelled` and `meetingType: informational-session`, and a GovernanceBody with `bodyType: political-group`
- **THEN** both writes succeed with HTTP 201/200 and no validation error

### Requirement: REQ-ORIA-003 — The ORI importer SHALL migrate source objects idempotently, with dry-run and rollback

`occ decidesk:import-ori` SHALL read all objects from a source ORI-shaped
register (default slug `ori`), apply the mapping table (including enum-value
translations such as `aangenomen`→`adopted` — value renames are data
migrations executed here, nowhere else), and write decidesk objects in
dependency order (bodies → persons → meetings → documents → agenda items →
decisions/voting rounds). Each created object SHALL carry
`externalReference: ori:<source-uuid>`. The command SHALL support `--dry-run`
(no writes; per-schema source/target counts and a per-object mapping preview)
and `--rollback` (delete only objects carrying the `ori:` tag). Re-running
SHALL update tagged objects instead of duplicating them. The command SHALL
never modify or delete the source register.

#### Scenario: Dry run reports counts without writing
- **GIVEN** a source `ori` register containing N objects across the six schemas
- **WHEN** `occ decidesk:import-ori --dry-run` runs
- **THEN** it reports per-schema source counts and the target objects that would be created, and the decidesk register's object count is unchanged

#### Scenario: Import is idempotent
- **GIVEN** a completed import run
- **WHEN** the command runs a second time against the same source
- **THEN** no duplicate objects are created (matched by the `ori:<source-uuid>` tag) and the final counts equal the first run's

#### Scenario: Dangling reference is reported, not dropped
- **GIVEN** a source `agendapunt` whose `vergadering` slug matches no source meeting
- **WHEN** the import runs
- **THEN** the object is reported as unresolvable (and fails the run in strict mode) instead of being silently skipped or imported with a null meeting

#### Scenario: Rollback removes only imported objects
- **GIVEN** a completed import and pre-existing native decidesk objects
- **WHEN** `occ decidesk:import-ori --rollback` runs
- **THEN** exactly the objects tagged `ori:*` are deleted and every native object survives

#### Scenario: Party aggregates are preserved, not decomposed
- **GIVEN** a source `stemming` with `politicalGroupResults`
- **WHEN** it is imported
- **THEN** a `VotingRound` is created whose `partyResults` reproduces the aggregate per party, and no per-participant `Vote` objects are fabricated

### Requirement: REQ-ORIA-004 — ORI wire-format interop SHALL live in an adapter only

An `OriExportMapper` service SHALL render stored Popolo objects in the Dutch
ORI wire shape (Dutch statutory field names and enum values). It SHALL be the
only decidesk component emitting Dutch identifiers, and it SHALL be a stateless
projection — the ORI shape SHALL NOT be persisted. Public read-only feed
endpoints `/feed/ori/meetings.rss`, `/feed/ori/agenda-items.rss` and
`/feed/ori/documents.rss` SHALL serve the adapter's output (replacing procest's
retired `/apps/procest/feed/ori/*.rss` feeds), declared `#[PublicPage]` +
`#[NoCSRFRequired]`, and SHALL expose only objects whose visibility is public.

#### Scenario: Adapter emits ORI field names from Popolo storage
- **GIVEN** an imported Meeting with `lifecycle: cancelled`
- **WHEN** the ORI adapter renders it
- **THEN** the output uses the ORI wire vocabulary (e.g. status `afgelast`) while the stored object keeps its English identifiers

#### Scenario: Public feeds serve without a session
- **GIVEN** an anonymous client with no Nextcloud session
- **WHEN** it requests `/apps/decidesk/feed/ori/meetings.rss`
- **THEN** it receives HTTP 200 with an RSS document listing public meetings, and non-public objects are absent

### Requirement: REQ-ORIA-005 — The Voorbeeldstad demo dataset SHALL be re-seeded Popolo-aligned

The demo objects shipped in procest's `ori_register.json` (8 fracties, 33
raadsleden, 10 vergaderingen, 40 agendapunten, 15 raadsdocumenten, 6
stemmingen for the fictional municipality of Voorbeeldstad) SHALL be translated
through the same mapping table into a decidesk `register.d` seed fragment so
demo/dev environments keep a populated council. Seed objects SHALL use English
identifiers throughout (Dutch proper names and titles in *values* are data,
not identifiers, and are kept).

#### Scenario: Demo seed loads idempotently
- **GIVEN** a fresh decidesk install with the seed fragment present
- **WHEN** the register configuration is imported twice
- **THEN** the Voorbeeldstad bodies, persons, meetings, agenda items, documents and voting rounds exist exactly once
