# inspraak-agenda-live Specification

## Purpose

Requirements introduced by the inspreekrecht-plenair change.

## Requirements

### Requirement: REQ-INS-007 Approved insprekers render as speaking slots on the agenda

The internal agenda view SHALL render each `goedgekeurd` inspraak-aanmelding as a speaking slot on its `agendaItem` (or, for `niveau: vergadering`, in a dedicated "Insprekers" block on the meeting), ordered by `volgorde` and showing `onderwerp.sprekerNaam`, `organisatie`, the topic, and `spreektijdToegewezenMinuten`. Registrations with status `aangemeld` SHALL appear to griffie/chair as pending (visually distinct, not counted as slots); `afgewezen` registrations SHALL NOT appear on the agenda. After the meeting, slots reflect the outcome status and link to the bijdrage/transcript (REQ-INS-006).

#### Scenario: Chair sees speaking slots on an agenda item

- GIVEN an agenda item with two goedgekeurde inspraak-aanmeldingen (volgorde 1 and 2) and one aangemelde
- WHEN the chair opens the internal agenda view
- THEN the item shows two speaking slots in order with sprekerNaam and assigned minutes
- AND the aangemelde registration is shown as pending, visually distinct from approved slots

#### Scenario: Meeting-level insprekers block

- GIVEN an ALV of a body with `niveau: vergadering` and one goedgekeurde aanmelding
- WHEN a member with internal access opens the meeting detail
- THEN the inspreker appears in the meeting-level "Insprekers" block, not on any agenda item

### Requirement: REQ-INS-008 Anonymised public agenda view per policy

Public agenda and verslag payloads SHALL project approved insprekers according to the body's `inspraak-beleid.publiekeWeergave`: `aantal` — only the count of approved insprekers per item ("3 insprekers"); `voornaam` — first names only; `spreker-naam` — the full `onderwerp` public projection (sprekerNaam, organisatie, onderwerpTekst), the REQ-CVG-011 behaviour. The payload MUST be allow-list built: `contactgegevens` and `afwijzingsReden` are structurally absent in every mode, and `aangemeld`/`afgewezen` registrations never appear publicly.

#### Scenario: Count-only public projection

- GIVEN a body whose inspraak-beleid sets publiekeWeergave to `aantal` and an agenda item with three goedgekeurde aanmeldingen
- WHEN the public agenda payload is built
- THEN the item carries only an inspreker count of 3
- AND no name, organisation, topic, or contact detail of any inspreker is present in the payload

#### Scenario: First-name-only projection

@e2e exclude payload contract — covered by PHPUnit on the projection builder
- GIVEN the same item under publiekeWeergave `voornaam`
- WHEN the public agenda payload is built
- THEN each approved inspreker appears with first name only
- AND contactgegevens fields are structurally absent from the payload

### Requirement: REQ-INS-009 Approved insprekers preload the speaker queue and status flows back

When a meeting reaches `lifecycle: opened` and an agenda item with `goedgekeurd` inspraak-aanmeldingen becomes current, the system SHALL preload the REQ-STM speaker queue with those insprekers in `volgorde` order — each entry labelled as inspreker, using `onderwerp.sprekerNaam` as the display name and `spreektijdToegewezenMinuten` as the entry's time limit — ahead of ad-hoc entries the chair adds. Queue mechanics (reorder, remove, clock) remain wholly owned by REQ-STM. The chair SHALL mark each inspreker entry as spoken or no-show from the SpeakingTimePanel context; marking SHALL transition the linked aanmelding to `gesproken` or `niet-verschenen` via a PUT-semantic save carrying all fields forward. Removing a preloaded entry from the queue SHALL NOT change the aanmelding's status.

#### Scenario: Queue preloaded when the item opens

- GIVEN an opened meeting whose current agenda item has two goedgekeurde aanmeldingen (volgorde 1: Mw. Jansen, 5 min; volgorde 2: Dhr. De Boer, 3 min)
- WHEN the chair opens the SpeakingTimePanel for that item
- THEN the queue starts with the two insprekers in order, labelled as inspreker, with their assigned time limits

#### Scenario: Marking gesproken flows back

- GIVEN Mw. Jansen's preloaded queue entry after she has spoken
- WHEN the chair marks the entry as gesproken
- THEN the linked inspraak-aanmelding transitions to `gesproken`
- AND an unrelated field on the aanmelding (e.g. bijdrageTekst) survives the write unchanged

#### Scenario: No-show recorded

- GIVEN a preloaded inspreker entry whose citizen did not appear
- WHEN the chair marks the entry as niet-verschenen
- THEN the linked aanmelding transitions to `niet-verschenen` and the queue advances to the next entry per REQ-STM

### Requirement: REQ-INS-010 Griffie inspraak overview with deadline warnings and notifications

The system SHALL provide a griffie overview page listing inspraak-aanmeldingen across all meetings and bodies, filterable by status, body, and meeting date, with pending (`aangemeld`) registrations surfaced first. Each pending row SHALL show a deadline indicator computed from the meeting start and the body's `aanmeldDeadlineUren` (registration window closing/closed), warning the griffie about approvals still open close to the meeting. New registrations and status changes SHALL notify the griffie and the registrant via declarative `x-openregister-notifications` triggers (nl/en subjects); no imperative dispatch (gate-18). Moderation (REQ-INS-005) SHALL be actionable directly from the overview.

#### Scenario: Pending approvals across meetings

- GIVEN aangemelde registrations on a raadsvergadering next week and an ALV tomorrow
- WHEN the griffier opens the inspraak overview
- THEN both appear with the ALV registration first, carrying a deadline warning because its meeting is imminent

#### Scenario: Declarative notification on registration

@e2e exclude notification-dialect contract — covered by PHPUnit on the fragment's notification declaration
- GIVEN the fragment's notification declarations
- WHEN an inspraak-aanmelding is created
- THEN a declarative created-trigger notification reaches the griffie group with nl/en subjects
- AND no imperative notification dispatch exists in app code
