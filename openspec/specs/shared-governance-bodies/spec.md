# shared-governance-bodies Specification

## Purpose
The secretary of a shared body, such as a joint arrangement of municipalities, keeps the organisations that take part in it, with their seats and voting weight, and sees how many seats each fills.

## Requirements

### Requirement: REQ-SGBP-001 The secretary keeps the participations of a shared body

The shared body page SHALL let the secretary add, edit and end a participation and SHALL show seats and filled seats per organisation.

#### Scenario: adding a participating municipality
- GIVEN secretary Anna on the page of the shared body Veiligheidsregio
- WHEN she adds Gemeente Oss with 2 seats and weight 3
- THEN the widget lists Gemeente Oss, 2 seats, 0 filled

@e2e exclude no live shared body in the e2e seed yet; the row, payload and schema check are covered by tests/vitest/bodyParticipations.spec.js "adding a participating municipality"; a Playwright run is owed (STATE.md Still owed)

#### Scenario: a member fills a seat
- GIVEN Gemeente Oss takes part with 2 seats
- WHEN Anna adds Pieter as member on behalf of Gemeente Oss
- THEN the widget reads 1 of 2 seats filled for Gemeente Oss

@e2e exclude covered by tests/vitest/bodyParticipations.spec.js "a member fills a seat" (onBehalfOf payload against the merged membership schema, filled-seat count); a Playwright run on a seeded shared body is owed (STATE.md Still owed)
