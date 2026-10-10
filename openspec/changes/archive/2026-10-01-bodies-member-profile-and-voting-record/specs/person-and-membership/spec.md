# person-and-membership Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [bodies-member-profile-and-voting-record](../../changes/bodies-member-profile-and-voting-record/)

## Purpose

Every member has a page: who they are, where they sit, for which party, what they hold, what else they do, and how they voted.

Matrix rows: bod-05 and pub-14 (decidiq `openspec/parity/capabilities.json`).

## ADDED Requirements

### Requirement: REQ-MPR-001 Every person has a profile page

The system SHALL show a profile page at `/people/:id` for every person. It SHALL show the photo, name, biography and email of the person; their memberships, current ones first, each with body, role, party and portfolio; and their declared outside positions. Earlier memberships SHALL be listed separately from current ones.

#### Scenario: A clerk opens a council member's profile

- GIVEN Marie Janssen is a member of Gemeenteraad Amsterdam for D66 and of the D66 group, with a photo and a biography
- AND she declared an unpaid board seat at a housing foundation as a public outside position
- WHEN `griffier1` opens `/people/{id}` for Marie Janssen
- THEN the page shows her photo, name and biography
- AND lists both memberships with body, role and party
- AND lists the housing foundation board seat as unpaid

#### Scenario: A person without a photo

- GIVEN a person with no `image`
- WHEN their profile opens
- THEN the page shows their initials in place of a photo and no broken image

### Requirement: REQ-MPR-002 A membership carries the member's portfolio

A membership SHALL be able to carry a portfolio: a list of subjects the member holds in that body. The profile page SHALL show the portfolio beside the membership it belongs to.

#### Scenario: An alderman's portfolio

- GIVEN Femke Halsema's membership of the executive board has portfolio "Public order and safety" and "Communication"
- WHEN a member opens her profile
- THEN the executive board membership shows both subjects
- AND her council membership shows no portfolio

### Requirement: REQ-MPR-003 Member lists link to the profile

The members widget of a body SHALL link each member's name to their profile page. The participant page SHALL offer "Open profile" when its participant resolves to a person.

#### Scenario: From the council to a member

- GIVEN the Gemeenteraad Amsterdam page with its "Members" widget
- WHEN `griffier1` clicks "Marie Janssen"
- THEN the browser opens `/people/{id}` for Marie Janssen

### Requirement: REQ-MPR-004 The profile shows the member's voting record

The profile page SHALL show the member's votes in closed rounds that were not secret, newest first, each with date, decision, the member's choice, the result, and the member's party at the time. It SHALL NOT show votes of secret rounds or anonymised votes. The record SHALL be read through `GET /api/people/{personId}/voting-record`, which SHALL find the person's participants without creating any person or participant.

#### Scenario: A member reads a colleague's record

- GIVEN Marie Janssen voted for motion "Groen dak op het stadhuis" on 10 April 2025 in an open round that was adopted
- AND voted in a secret round on an appointment the same evening
- WHEN `member1` opens her profile
- THEN the voting record lists "Groen dak op het stadhuis", 10 April 2025, for, adopted, D66
- AND does not list the secret round

#### Scenario: A person without participants

- GIVEN a person whose Nextcloud user id and email match no participant
- WHEN their profile opens
- THEN the voting record says there are no recorded votes
- AND no person or participant was created by the request
