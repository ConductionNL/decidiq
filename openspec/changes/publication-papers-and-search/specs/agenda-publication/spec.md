# agenda-publication Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [publication-papers-and-search](../../) (this delta)

## Purpose

Agendas and minutes are published to OpenCatalogi as titles only: the agenda payload strips every document reference, so citizens never get the papers, and there is nothing to filter on. Moves decidiq matrix rows pub-01, pub-09 toward built.

## ADDED Requirements

### Requirement: REQ-PPS-001 Public papers are published with the agenda

Publishing an agenda SHALL publish the non-confidential papers of its public items, with the body, date, document type and theme citizens filter on.

#### Scenario: A resident reads the papers
- GIVEN the council agenda of 14 October has papers on three items, one confidential
- WHEN the clerk publishes the agenda
- THEN a resident finds the papers of the two public items in the public catalogue and can filter on the council and the date
