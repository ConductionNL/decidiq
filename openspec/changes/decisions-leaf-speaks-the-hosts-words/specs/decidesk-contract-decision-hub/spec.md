## ADDED Requirements

### Requirement: REQ-DCDH-009 — The decisions leaf speaks the host's words

The `decidesk-decisions` widget MUST accept `emptyTitle`, `emptyText` and `createLabel` from its host, as text already in the reader's language. A prop that is missing or blank MUST fall back to the leaf's own translated words.

When the host gives `emptyTitle` and the host object has no decisions, the widget MUST draw an empty-state panel with the title, the host's text when given, the create-proposal button and "Open in decidiq" as a link. Without `emptyTitle` the widget MUST keep its one-line empty state.

#### Scenario: A ticket names its empty state

@e2e exclude the words come from the host page and are checked on the host's board; the leaf's choice of words is asserted by tests/vitest/decisionsLeafHostWording.spec.js.
- **WHEN** pipelinq mounts the leaf on a ticket without decisions with `emptyTitle` "Geen besluit gekoppeld" and `createLabel` "Voorstel maken voor dit ticket"
- **THEN** the panel shows that title, a button "Voorstel maken voor dit ticket" and a link "Openen in decidiq"

#### Scenario: A host that names nothing keeps the leaf's words

@e2e exclude same reason. Asserted by tests/vitest/decisionsLeafHostWording.spec.js.
- **WHEN** a host passes no wording
- **THEN** the button reads "Create proposal for this object" and the empty state is the single line
