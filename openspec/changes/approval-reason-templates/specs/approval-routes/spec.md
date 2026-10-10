## ADDED Requirements

### Requirement: A host MAY offer ready-made reasons on the approval chain leaf

The approval chain leaf SHALL accept a list of reason templates from its host, each with an id, a
name and a body. When the list is not empty, the leaf SHALL show a picker above the reason field,
both when the person acts themselves and when they act on someone's behalf. Picking a template
SHALL fill the reason field with its body, and the person SHALL be able to edit it before acting.
The leaf SHALL NOT read any app's template library itself.

#### Scenario: A picked template fills the reason

- **GIVEN** a host passes two reason templates and the route waits on the viewer
- **WHEN** the viewer picks "Terug: de motivering ontbreekt"
- **THEN** the reason field SHALL hold that text
- **AND** rejecting SHALL record it as the reason

#### Scenario: No templates, no picker

- **GIVEN** a host that passes no reason templates
- **WHEN** the leaf renders a live step
- **THEN** no template picker SHALL be shown
