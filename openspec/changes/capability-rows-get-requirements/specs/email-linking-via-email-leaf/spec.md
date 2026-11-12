# email-linking-via-email-leaf Specification (delta)

## ADDED Requirements

### Requirement: A linked email can be read on the decision

The email tab of a decision SHALL let a user link a message from their Nextcloud Mail account, and SHALL list every linked message with its sender, subject and date. Opening a listed message SHALL show its content.

#### Scenario: A user links and reads an email

- **GIVEN** a user with a Nextcloud Mail account on the decision "Budget 2027"
- **WHEN** they link a message from their inbox on the decision's email tab
- **THEN** the tab lists that message with its sender, subject and date
- **AND** opening it shows the message content
