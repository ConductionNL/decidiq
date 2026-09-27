# public-publication Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [publication-theme-pages](../../) (this delta)

## Purpose

Lets the griffie gather everything on a long-running topic in a theme and publish it as a page with a timeline. Closes decidiq matrix row pub-20.

**Standards**: Schema.org `CreativeWork` (`hasPart`), OpenRaadsinformatie, Wet open overheid (Woo) actively published information.

## ADDED Requirements

### Requirement: REQ-THP-001 The griffie gathers a long-running topic in a theme

The app SHALL let the secretariat create a theme with a title, an introduction, text blocks, a status, a responsible body and a portfolio holder, and link meetings, agenda items, decisions, commitments and long-term agenda items to it, also from the agenda item and decision pages.

#### Scenario: A theme for the swimming pool
- GIVEN the council has discussed a new swimming pool in three meetings
- WHEN the griffier creates the theme "Nieuw zwembad De Hoge Dijk" and adds the credit request and the motion on sustainability from their pages
- THEN the theme page lists the meetings, the agenda item and the motion

### Requirement: REQ-THP-002 A theme shows a timeline of what belongs to it

The theme page SHALL show a timeline of the linked items by their dates, oldest first, and SHALL mark for staff which entries are not published.

#### Scenario: The story of the pool
- GIVEN the swimming pool theme with five linked items
- WHEN the griffier opens it
- THEN she sees them as a timeline from the first meeting to the planned decision in 2027, with the unpublished commitment marked

### Requirement: REQ-THP-003 A published theme carries only published items

Publishing a theme SHALL use the existing publication path with the publication type theme. The published page SHALL carry the introduction, the blocks and a timeline of only those linked items that have a publication of their own, each linking to it. A theme that is not marked public, or has no published item, SHALL not be published.

#### Scenario: Residents read the theme
- GIVEN the theme is public and three of its five items are published
- WHEN the griffier publishes it
- THEN residents read the theme page with a timeline of those three items, and the other two do not appear

### Requirement: REQ-THP-004 An active published theme stays current

A nightly run SHALL republish every active public theme whose timeline changed since its last version. A concluded theme SHALL not be republished.

#### Scenario: A new decision joins the story
- GIVEN the council adopted and published the construction decision for the pool
- WHEN the nightly run passes
- THEN the published theme's timeline includes the construction decision
