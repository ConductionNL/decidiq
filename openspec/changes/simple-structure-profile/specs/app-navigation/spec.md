# app-navigation Specification (delta)

**Scope**: decidiq
**OpenSpec changes**:
- [simple-structure-profile](../../)

## ADDED Requirements

### Requirement: REQ-SSP-001 Two structures are built from one manifest

decidiq MUST build a simple structure and a full structure from the same manifest and the same fragments. The full structure MUST be exactly what `buildManifest` makes from the manifest, the fragments and `src/menu-layout.json`. Neither structure may add or remove a page or a route.

#### Scenario: The full structure is unchanged
@e2e exclude An equality between two built manifests, asserted in structureProfile.spec.js against the library's real buildManifest.
- **GIVEN** `menu_structure` is `full`
- **WHEN** the manifest is built
- **THEN** it MUST equal what `buildManifest` makes from the manifest, the fragments and `src/menu-layout.json`
- **AND** the menu MUST count 44 entries: 24 in the main list, 4 in the footer and 16 in settings

#### Scenario: Every page keeps its route
@e2e exclude A comparison of two page lists, asserted in structureProfile.spec.js.
- **GIVEN** either structure
- **WHEN** the manifest is built
- **THEN** it MUST hold the same pages, with the same ids, in the same order

### Requirement: REQ-SSP-002 The simple menu shows eight entries under three captions

In the simple structure the main menu MUST be flat and MUST show, in this order: the caption Home with Dashboard and My actions; the caption Decision making with Proposals, Meetings, Decisions and Commitments; the caption Your organisation with Bodies and members and Registers. Settings and the footer MUST hold what they hold in the full structure.

#### Scenario: Somebody opens decidiq on a new instance
- **GIVEN** an instance where `menu_structure` was never set
- **WHEN** somebody opens decidiq
- **THEN** the main menu MUST show the three captions and the eight entries, in that order
- **AND** no entry the full structure nests under a parent MUST be in the main menu

#### Scenario: The menu reads in Dutch as the design writes it
@e2e exclude A reading of the labels against l10n/nl.json, asserted in structureProfile.spec.js.
- **GIVEN** the simple structure and a Dutch reader
- **WHEN** the menu is drawn
- **THEN** it MUST read Start, Dashboard, Mijn acties, Besluitvorming, Voorstellen, Vergaderingen, Besluiten, Toezeggingen, Organisatie, Organen en leden, Registers

#### Scenario: The organisation mode still words the bodies entry
@e2e exclude A reading of the mode map, asserted in structureProfile.spec.js.
- **GIVEN** the simple structure
- **WHEN** the organisation mode is `corp` or `ops`
- **THEN** the bodies entry MUST read Board and members or Teams and members
- **AND** the caption MUST read the same in every mode

### Requirement: REQ-SSP-003 Nothing the full menu offers is lost

Every entry the full menu offers MUST, in the simple structure, be in the menu, in settings or in the footer, or be linked from a page the simple menu opens.

#### Scenario: A list that left the menu is one link away
- **GIVEN** the simple structure
- **WHEN** somebody opens Proposals
- **THEN** the page MUST offer links to Consultations, Works-council consultations, Public consultations and Urgent decisions
- **AND** each of those pages MUST still open by its own address

#### Scenario: Meetings and bodies link to what was nested under them
@e2e exclude A reading of the built pages, asserted in structureProfile.spec.js.
- **GIVEN** the simple structure
- **WHEN** the manifest is built
- **THEN** Meetings MUST link to Long-term agenda and P&C cycles
- **AND** Bodies and members MUST link to Position holders

#### Scenario: The links exist in the simple structure only
@e2e exclude A comparison of two built pages, asserted in structureProfile.spec.js.
- **GIVEN** the full structure
- **WHEN** the manifest is built
- **THEN** Motions, Meetings and Governance bodies MUST have no header links

### Requirement: REQ-SSP-004 The structure is an app setting and simple is the default

decidiq MUST show the simple structure unless the app setting `menu_structure` holds the word `full`. The admin settings page MUST offer the choice between Simple and Full. The page controller MUST provide the setting as initial state, so the menu is right on the first render. Only an administrator may change it.

#### Scenario: An administrator brings the full menu back
@e2e exclude The e2e instance runs on the full structure for the whole suite (tests/e2e/ci-seed.sh sets it); MenuStructureTest and structureProfile.spec.js cover the setting and the save.
- **GIVEN** an administrator on the admin settings page
- **WHEN** they choose Full under Menu structure
- **THEN** `menu_structure` MUST be stored as `full`
- **AND** the next time somebody opens decidiq the menu MUST be the full one

#### Scenario: A stored value that is not a structure
@e2e exclude A rule on a string, covered by MenuStructureTest and structureProfile.spec.js.
- **GIVEN** `menu_structure` holds `ful`
- **WHEN** somebody opens decidiq
- **THEN** the menu MUST be the simple one

#### Scenario: A save the server did not keep is not reported as saved
@e2e exclude A rule on a response body, covered by structureProfile.spec.js and MenuStructureTest.
- **GIVEN** the settings write answers success without the stored word
- **WHEN** the admin page saves a structure
- **THEN** it MUST report that the menu was not saved

### Requirement: REQ-SSP-005 A profile may change a page and never add or remove one

A structure profile MAY overlay a page by id: replace config keys, patch list items by name, append list items, order a list, and add slots. An overlay that names a page the manifest does not have MUST be skipped and reported.

#### Scenario: An overlay names a page that does not exist
@e2e exclude A rule on the build function, covered by structureProfile.spec.js.
- **GIVEN** a profile with an overlay for `NoSuchPage`
- **WHEN** the manifest is built
- **THEN** no such page MUST exist in the result
- **AND** a warning MUST name the page

### Requirement: REQ-SSP-006 Registers opens one page that leads to every register

The Registers entry of the simple menu MUST open a page that shows one tile per register: governing documents, delegations and mandates, the confidentiality register, gifts, other positions, proxy authorizations, onboarding, offboarding, audit statements, goals and the archive. Each tile MUST show how many records that register holds and MUST open its list.

#### Scenario: Registers opens a page with a tile for each register
- **GIVEN** the simple structure
- **WHEN** somebody chooses Registers
- **THEN** the page at `/registers` MUST open
- **AND** it MUST show a tile for each of the eleven registers

#### Scenario: A tile counts the list it opens
@e2e exclude A reading of the page config, asserted in structureProfile.spec.js.
- **GIVEN** the Registers page
- **WHEN** its tiles are read
- **THEN** each tile MUST count the schema of the list it opens, with no filter
