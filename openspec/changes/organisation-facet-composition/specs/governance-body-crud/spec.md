# governance-body-crud Specification

## MODIFIED Requirements

### Requirement: View governance body detail
The app SHALL display a detail view for a GovernanceBody including its properties,
linked Meetings, linked Participants, and — per ADR-004 v2's Organisation cluster —
the body's retirement schedule, term rule, integrity declarations (other positions
and gifts), shared-body participation, and factions, each rendered as a filtered
`object-list` widget scoped to the body's own object id against that register's
existing scoping field. This detail page is the "Organisation" hub: a griffier no
longer has to leave it to find a body's retirement schedule, term rule, or
integrity register.

#### Scenario: User opens a governance body detail page
- **WHEN** the user clicks a row in the governance bodies list
- **THEN** the router navigates to `/governance-bodies/:id` and `CnDetailPage` renders the body's properties

#### Scenario: Related Meetings shown in detail
- **WHEN** the governance body detail page loads
- **THEN** a `CnDetailCard` section lists the Meetings linked to this GovernanceBody via OpenRegister relations

#### Scenario: Related Participants shown in detail
- **WHEN** the governance body detail page loads
- **THEN** a `CnDetailCard` section lists the Participants linked to this GovernanceBody, showing displayName and role

#### Scenario: CnObjectSidebar is available
- **WHEN** the user is on the governance body detail page
- **THEN** `CnObjectSidebar` is rendered with Files, Notes, Tags, Tasks, and Audit Trail tabs

#### Scenario: Retirement schedule shown on the body detail page
- **GIVEN** a GovernanceBody with a generated `rooster-van-aftreden` (`body` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Retirement schedule" widget lists the body's `rooster-van-aftreden` object(s)
- **AND** clicking a row navigates to `RoosterDetail`, which shows the ordered term entries

#### Scenario: No retirement schedule yet
- **GIVEN** a GovernanceBody with no `rooster-van-aftreden` object
- **WHEN** the user views the GovernanceBody detail page
- **THEN** the "Retirement schedule" widget shows its empty state, not an error

#### Scenario: Term rule shown read-only on the body detail page
- **GIVEN** a GovernanceBody with one or more `termijn-regeling` objects (`body` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Term rules" widget lists the body's `termijn-regeling` object(s) with no inline create or edit action
- **AND** clicking a row navigates to `TermijnRegelingDetail`, where the rule is editable

#### Scenario: Integrity declarations shown on the body detail page
- **GIVEN** a GovernanceBody with `nevenfunctie` and `geschenk` objects scoped to it (`governanceBody` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** an "Other positions" widget lists the body's `nevenfunctie` objects and a "Gifts" widget lists the body's `geschenk` objects
- **AND** clicking a row in either navigates to that object's own detail page (`NevenfunctieDetail` / `GeschenkDetail`)

#### Scenario: Shared-body participation shown on the body detail page
- **GIVEN** a GovernanceBody with `bodyType=shared-body` and `body-participation` objects referencing it (`sharedBody` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Participating organisations" widget lists the participating `body-participation` objects
- **AND** a "Zienswijze rounds" widget lists the body's `zienswijzeronde` objects (`sharedBody` = the body's object id)

#### Scenario: A body's own participations in shared bodies are shown
- **GIVEN** a GovernanceBody that itself participates in one or more shared bodies (`body-participation.participant` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Shared-body participations" widget lists those `body-participation` objects

#### Scenario: Factions shown on a body's detail page
- **GIVEN** one or more `GovernanceBody` objects with `bodyType=faction` and `parentBody` set to this body's object id
- **WHEN** the user views this GovernanceBody's detail page
- **THEN** a "Factions" widget lists those child `GovernanceBody` objects
- **AND** clicking a row navigates to that faction's own `GovernanceBodyDetail` page

#### Scenario: No facet widget errors when its register is empty for this body
- **GIVEN** a GovernanceBody with no `nevenfunctie`, `geschenk`, `body-participation`, `zienswijzeronde`, or faction-`bodyType` child objects referencing it
- **WHEN** the user views the GovernanceBody detail page
- **THEN** every added facet widget renders its own empty state and the page does not error
