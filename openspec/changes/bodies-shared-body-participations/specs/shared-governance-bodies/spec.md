# shared-governance-bodies Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [bodies-shared-body-participations](../../) (this delta)

## Purpose

A joint body can record the organisations that take part in it, but only through object lists nobody has verified can add a row. Moves decidiq matrix rows bod-13 toward built.

## ADDED Requirements

### Requirement: REQ-SGBP-001 The secretary keeps the participations of a shared body

The shared body page SHALL let the secretary add, edit and end a participation and SHALL show seats and filled seats per organisation.

#### Scenario: adding a participating municipality
- GIVEN secretary Anna on the page of the shared body Veiligheidsregio
- WHEN she adds Gemeente Oss with 2 seats and weight 3
- THEN the widget lists Gemeente Oss, 2 seats, 0 filled

#### Scenario: a member fills a seat
- GIVEN Gemeente Oss takes part with 2 seats
- WHEN Anna adds Pieter as member on behalf of Gemeente Oss
- THEN the widget reads 1 of 2 seats filled for Gemeente Oss
