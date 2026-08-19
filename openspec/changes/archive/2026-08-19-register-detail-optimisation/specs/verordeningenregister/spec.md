# verordeningenregister Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- verordeningenregister
- register-detail-optimisation

## Purpose

The regulations register (`regeling`/`regeling-versie`) needs its detail pages to surface what a griffie/clerk actually needs to answer "which text is in force, and on whose authority": the version history in date order with a link to the amending Decision, and the in-force status + CVDR identifier foregrounded rather than buried in a generic field list. This spec adds that register-optimised presentation on top of the `RegelingDetail` / `RegelingVersieDetail` page skeletons shipped by the `verordeningenregister` change (ADR-036 manifest v2).

## ADDED Requirements

### Requirement: REQ-VOR-009 Version timeline widget on RegelingDetail

The `RegelingDetail` page MUST render a `version-timeline` widget listing every `regeling-versie` object whose `regeling` reference points at the current object, ordered by effective date (`inwerkingtreding`, ascending), each entry showing version number, effective date, lapse date (if any), a status badge, and a resolved link to the amending Decision (`vastgesteldDoor`) that navigates to the existing Decision detail page.

#### Scenario: Regulation with three versions renders an ordered timeline
- GIVEN a `regeling` with three `regeling-versie` objects effective on 2024-01-01, 2024-06-01, and 2025-01-01
- WHEN the user opens `RegelingDetail` for that regeling
- THEN the version-timeline widget renders the three versions in ascending date order
- AND each entry shows its status badge and a link to its amending Decision

#### Scenario: Version entry links to its amending Decision
- GIVEN a `regeling-versie` with `vastgesteldDoor` set to a Decision object
- WHEN the user activates that version's Decision link in the timeline
- THEN the app navigates to the existing Decision detail page for that object

#### Scenario: Regulation with no versions yet renders an empty timeline state
- GIVEN a `regeling` with zero `regeling-versie` objects referencing it
- WHEN the user opens `RegelingDetail`
- THEN the version-timeline widget renders an empty-state message instead of an empty list or a loading spinner stuck indefinitely

### Requirement: REQ-VOR-010 In-force status and CVDR identifier are foregrounded on RegelingDetail

`RegelingDetail` MUST present the regulation's in-force status and CVDR identifier prominently — in the first visual group of the data widget, not interleaved alphabetically with lower-priority fields — so a reader can answer "is this regulation currently in force, and under which CVDR number" without scanning the full field list.

#### Scenario: In-force regulation shows status and CVDR identifier first
- GIVEN a `regeling` with `status: "in-effect"` and a non-empty `cvdrIdentifier`
- WHEN the user opens `RegelingDetail`
- THEN the status badge and CVDR identifier render in the leading fields of the data widget

### Requirement: REQ-VOR-011 Computed in-force-date columns render formatted, not raw

The `Regelingen` index column showing the current in-force date (a value computed by `RegelingConsolidationService` and not declared as an OpenRegister schema property) MUST declare an explicit date-format hint on the column definition so it renders through the same locale-aware date formatter as schema-declared date fields, instead of an unformatted raw datetime string.

#### Scenario: Current-in-force-date column renders a formatted date
- GIVEN a `regeling` whose computed current-in-force date is `2025-03-01T00:00:00Z`
- WHEN the user views the `Regelingen` index
- THEN the current-in-force-date column renders a locale-formatted date (e.g. `01-03-2025`)
- AND does not render the raw ISO/SQL datetime string

## Non-Functional Requirements

- **Performance:** The version-timeline widget MUST resolve its version list and Decision links via the object store without triggering an N+1 request per timeline row (a single filtered list query for the versions, resolved references batched or lazily loaded on demand — consistent with the `related` widget's existing resolution pattern).
- **Accessibility:** The version-timeline widget and its Decision links MUST be keyboard-navigable with correct focus order and accessible names (WCAG 2.2 AA, ADR-010).
- **Internationalization:** All new widget labels MUST support Dutch and English (ADR-005/025).

## Acceptance Criteria

- [ ] `RegelingDetail` renders the version-timeline widget for `regeling-versie` ordered by effective date
- [ ] Each timeline entry links to its amending Decision
- [ ] In-force status and CVDR identifier are foregrounded on `RegelingDetail`
- [ ] The `Regelingen` index current-in-force-date column renders a formatted date, not a raw string

## Notes

Builds on the `regeling`/`regeling-versie` schemas and `RegelingDetail`/`Regelingen` page skeletons already shipped by the `verordeningenregister` change (`lib/Settings/register.d/53-verordeningenregister.json`, `src/manifest.d/verordeningenregister.json`). The "geldend op" (in-force-on-date) date-picker control and CSV export remain owned by `verordeningenregister`'s own follow-up tasks — this spec only adds the version-timeline widget, status/CVDR foregrounding, and the date-format fix.
