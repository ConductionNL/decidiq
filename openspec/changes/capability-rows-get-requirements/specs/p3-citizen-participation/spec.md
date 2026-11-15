# p3-citizen-participation Specification (delta)

## ADDED Requirements

### Requirement: Staff set up a citizen panel with fixed members and a term

Staff SHALL be able to create a citizen panel as a governance body of type citizen panel, with a term start and end date and a fixed list of members, each member with the date they joined and the date they left.

#### Scenario: Staff create a panel for one year

- **WHEN** a staff member creates the body "Climate panel 2027" of type citizen panel with a term from 1 January to 31 December 2027 and adds twelve members
- **THEN** the panel is stored with that term and those twelve members
- **AND** the panel's page shows the term and the member list
