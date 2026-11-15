# example-set-cards Specification (delta)

## ADDED Requirements

### Requirement: The setup wizard offers a ready set for each kind of organisation

The app SHALL ship a ready example set for a municipality, an association, a company and a works council, and the example-set step of the setup wizard SHALL offer each of them. Loading one SHALL fill the app with that organisation's bodies, meetings and decisions.

#### Scenario: An administrator starts an association

- **WHEN** an administrator opens the example-set step of the setup wizard on a fresh installation
- **THEN** sets for a municipality, an association, a company and a works council are offered
- **AND** loading the association set fills the app with an association's bodies, meetings and decisions
