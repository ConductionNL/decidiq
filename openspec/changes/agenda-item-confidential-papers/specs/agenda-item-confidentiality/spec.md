# agenda-item-confidentiality Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [agenda-item-confidential-papers](../../) (this change)

## Purpose

An agenda item that is not public keeps its papers away from everyone but the meeting's body, the griffie and named readers. Covers matrix row age-09. Screen: board DcAgendapunt on the Zuiddrecht design canvas.

## ADDED Requirements

### Requirement: REQ-ACP-001 An agenda item and each paper say whether they are public

AgendaItem and DigitalDocument SHALL carry a `public` field that defaults to true. The item page MUST show it as "Openbaar: Ja" or "Openbaar: Nee" in Kerngegevens, and each paper in the Stukken list MUST show "Openbaar" or "Besloten".

#### Scenario: the griffier closes an item

- GIVEN agenda item 7 "Verlichting fietspaden 2027" is public
- WHEN the griffier sets Openbaar to Nee through Bewerken
- THEN Kerngegevens shows "Openbaar: Nee"
- AND every paper in Stukken shows "Besloten"

### Requirement: REQ-ACP-002 Only authorised readers can read a non-public item and its papers

OpenRegister read rules MUST allow a non-public agenda item and its papers to be read only by the secretariat, the administrators, the members of the meeting's body and the users listed in the item's `authorisedReaders`. Anyone else MUST get no object from any surface: item page, list, search, API or file download.

#### Scenario: a member of the body reads the paper

- GIVEN item 7 of the Gemeenteraad meeting is not public
- AND Sanne Mulder is a member of the Gemeenteraad
- WHEN Sanne opens the paper "Kostenraming verlichting"
- THEN the paper opens

#### Scenario: a user outside the body cannot

- GIVEN item 7 is not public
- AND Pieter is a member of the Rekenkamer only
- WHEN Pieter requests the paper through the API
- THEN the response contains no paper

#### Scenario: the griffier adds a reader

- GIVEN item 7 is not public
- WHEN the griffier adds the external accountant to Lezers toevoegen
- THEN the accountant can open the item's papers
- AND nobody else outside the body can

### Requirement: REQ-ACP-003 A paper of a non-public item is not public

When an agenda item is or becomes non-public, the system MUST set `public` to false on every paper attached to it. A paper added to a non-public item MUST be saved as not public.

#### Scenario: a paper added later

- GIVEN item 7 is not public
- WHEN the griffier adds the paper "Advies Fietsersbond"
- THEN that paper is saved with Openbaar Nee

### Requirement: REQ-ACP-004 An imposed confidentiality restriction closes the item

When a ConfidentialityRestriction with scope item on an agenda item reaches the state imposed, the system MUST set that item's `public` to false. Lifting the restriction MUST NOT set it back to true.

#### Scenario: geheimhouding imposed by the college

- GIVEN item 9 is public
- WHEN a restriction on item 9 with ground "Gemeentewet art. 87" is imposed
- THEN item 9 shows "Openbaar: Nee"

#### Scenario: lifting leaves the choice to the griffier

- GIVEN item 9 was closed by a restriction
- WHEN the restriction is lifted
- THEN item 9 still shows "Openbaar: Nee" until the griffier changes it

### Requirement: REQ-ACP-005 A non-public item stays out of the pack and the public site

The meeting pack MUST leave out the papers of a non-public item and list it as "Besloten punt". Publishing a meeting MUST NOT publish a non-public item or its papers, and people who may not read the item MUST see "Besloten punt" with its number only.

#### Scenario: assembling the pack

- GIVEN a meeting with items 6, 7 and 8, of which 7 is not public
- WHEN the griffier assembles the meeting pack
- THEN the pack holds the papers of items 6 and 8
- AND item 7 is listed as "Besloten punt" without papers

### Requirement: REQ-ACP-006 Every read of a closed paper is logged

Each read of a paper of a non-public item MUST be recorded in OpenRegister's audit trail with the reader and the time. The griffie MUST be able to see these entries on the item's history.

#### Scenario: the griffie checks who read the paper

- GIVEN Sanne opened "Kostenraming verlichting" on 10 October at 21.04
- WHEN the griffier opens the history of item 7
- THEN an entry shows Sanne Mulder, the paper and 10 October 21.04
