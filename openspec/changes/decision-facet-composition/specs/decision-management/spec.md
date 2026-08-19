# decision-management Specification (delta)

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- decision-facet-composition (this change)

## Purpose

Delta on top of the existing `decision-management` capability: the Decision Detail page becomes the hub for every already-shipped object type that references a Decision, closing the gap where six sibling changes (works-council-consultation, advisory-opinion-workflow, shared-governance-bodies, embargo-geheimhouding, toezeggingen-ingekomen-stukken, constituency-consultation) each gave their schema a reference back to `Decision` but nothing rendered the reverse edge.

## ADDED Requirements

### Requirement: Decision detail surfaces referencing consultations (REQ-DFC-001)

The Decision Detail page MUST render three declarative `object-list` widgets, each reverse-filtered on the current decision's id, for the three consultation kinds that can reference a Decision: `public-consultation` (filtered on its `decision` property), `member-consultation` (filtered on its `decision` property), and `consultation-request` — the WOR traject — (filtered on its `relatedDecision` property). Each widget MUST link its rows to the consultation kind's existing detail route and MUST render an empty-state message when no matching records exist.

#### Scenario: A decision referenced by a public consultation

- GIVEN a `PublicConsultation` object whose `decision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Public consultations" widget lists that consultation
- AND clicking the row navigates to `ConsultationDetail` for that consultation

#### Scenario: A decision with no member consultations

- GIVEN Decision D has no `MemberConsultation` object referencing it
- WHEN a user opens Decision D's detail page
- THEN the "Member consultations" widget renders its configured empty-state text instead of an empty table

#### Scenario: A decision referenced by a WOR consultation request

- GIVEN a `ConsultationRequest` object whose `relatedDecision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Works council (WOR)" widget lists that request
- AND clicking the row navigates to `WorTrajectDetail` for that request

### Requirement: Decision detail surfaces advisory-opinion requests (REQ-DFC-002)

The Decision Detail page MUST render a declarative `object-list` widget listing `adviceRequest` (Adviesaanvraag) objects whose `relatedDecision` property equals the current decision's id, linking each row to `AdviesaanvraagDetail`. The widget is not required to resolve or list the `Advies` records answering each request (those remain reachable one click away, on the Adviesaanvraag's own detail page).

#### Scenario: A decision with an open advisory-opinion request

- GIVEN an `Adviesaanvraag` object whose `relatedDecision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Advisory opinions" widget lists that request with its subject and lifecycle status
- AND clicking the row navigates to `AdviesaanvraagDetail`

### Requirement: Decision detail surfaces zienswijzerondes and zienswijzen (REQ-DFC-003)

The Decision Detail page MUST render two declarative `object-list` widgets: one listing `zienswijzeronde` objects whose `decision` property equals the current decision's id, and one listing `zienswijze` objects whose `decision` property equals the current decision's id. Both MUST link their rows to `ZienswijzerondeDetail` (zienswijze records have no standalone detail route in the shipped `shared-governance-bodies` fragment; they are viewed through their parent ronde, matching that fragment's own index-page convention).

#### Scenario: A decision is a shared body's closing vaststellingsbesluit

- GIVEN a `Zienswijzeronde` object whose `decision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Zienswijzerondes" widget lists that ronde

#### Scenario: A decision is a participant council's raadsbesluit adopting a zienswijze

- GIVEN a `Zienswijze` object whose `decision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Zienswijzen" widget lists that zienswijze
- AND clicking the row navigates to `ZienswijzerondeDetail` for its parent ronde

### Requirement: Decision detail surfaces commitments (REQ-DFC-004)

The Decision Detail page MUST render a declarative `object-list` widget listing `toezegging` objects whose `relatedMotion` property equals the current decision's id, linking each row to `ToezeggingDetail`. This widget is separate from the existing `decision-actions` widget (ActionItemsSurface), which projects Deck-board action items rather than griffie commitments.

#### Scenario: A motion produced a commitment

- GIVEN a `Toezegging` object whose `relatedMotion` field is set to Decision D (decisionType `motion`)
- WHEN a user opens Decision D's detail page
- THEN the "Commitments" widget lists that commitment with its deadline and lifecycle status

### Requirement: Decision detail surfaces confidentiality status (REQ-DFC-005)

The Decision Detail page MUST render a read-only declarative `object-list` widget listing `geheimhouding` objects whose `targetDecision` property equals the current decision's id (the case where this decision, or the content it represents, is itself under geheimhouding), showing the resolved ground, the lifecycle state, and the `ratificationDeadline`. This widget MUST NOT offer create/edit actions (`allowCreate: false`) — geheimhouding is imposed through the geheimhoudingenregister's own imposing flow, not from the Decision detail page. Widgets for `Geheimhouding.ratificationDecision` and `Geheimhouding.dissolutionDecision` (this decision acting as another record's confirming or lifting besluit) are explicitly out of scope for this requirement.

#### Scenario: A decision is under active geheimhouding

- GIVEN a `Geheimhouding` object in lifecycle state `opgelegd` whose `targetDecision` field is set to Decision D
- WHEN a user opens Decision D's detail page
- THEN the "Confidentiality" widget shows one row with the geheimhouding's ground, lifecycle state, and ratification deadline
- AND the widget offers no add action

#### Scenario: A decision with no confidentiality restriction

- GIVEN Decision D has no `Geheimhouding` object referencing it as `targetDecision`
- WHEN a user opens Decision D's detail page
- THEN the "Confidentiality" widget renders its configured empty-state text
