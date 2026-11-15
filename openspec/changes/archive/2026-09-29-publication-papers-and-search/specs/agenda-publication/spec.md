# agenda-publication Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [publication-papers-and-search](../../) (this delta)

## Purpose

Agendas and minutes are published to OpenCatalogi as titles only: the agenda payload strips every document reference, so citizens never get the papers, and there is nothing to filter on. Moves decidiq matrix rows pub-01, pub-09 toward built.

## ADDED Requirements

### Requirement: REQ-PPS-001 Public papers are published with the agenda

Publishing an agenda SHALL publish the non-confidential papers of its public items, with the body, date and document type citizens filter on.

#### Scenario: A resident reads the papers
- GIVEN the council agenda of 14 October has papers on three items, one confidential
- WHEN the clerk publishes the agenda
- THEN a resident finds the papers of the two public items in the public catalogue and can filter on the council and the date
- AND each publication names its document type (agenda, decision or minutes), and body and meeting date are facetable

#### Scenario: Withdrawing the agenda takes its papers offline
- GIVEN the agenda of 14 October was published with the papers of two items
- WHEN the clerk withdraws the publication
- THEN those papers are no longer public, and a rectified version keeps online the papers it still publishes

### Requirement: REQ-PPS-002 A confidential item never reaches the public

An agenda item under an imposed or ratified confidentiality restriction (scope item) SHALL NOT appear on any public surface: not its title in the published agenda, not its papers, not in what the catalogue indexes. A paper labelled confidential on a public item SHALL NOT be published. Restrictions are read in system context, so the answer does not depend on what the publishing clerk may read. When the restrictions cannot be read, the agenda SHALL NOT be published.

#### Scenario: A confidential item stays out
- GIVEN item 2 of the agenda is under an imposed restriction and item 4 under a ratified one
- WHEN the clerk publishes the agenda
- THEN neither title nor any paper of items 2 and 4 is in the stored payload, the publication record or the catalogue publication

#### Scenario: A dissolved restriction no longer keeps the item out
- GIVEN the restriction on item 3 is dissolved
- WHEN the clerk publishes the agenda
- THEN item 3 is on the published agenda with its papers

#### Scenario: Unreadable restrictions stop the publication
- GIVEN the confidentiality restrictions cannot be read
- WHEN the clerk publishes the agenda
- THEN the publication is refused with a message, no payload is stored, nothing reaches the catalogue and no paper is made public
