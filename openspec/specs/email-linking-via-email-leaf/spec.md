---
status: done
---

# email-linking-via-email-leaf Specification

## Purpose
Links emails to a decision dossier or agenda item through the Nextcloud Mail integration leaf bound to the OpenRegister object, surfaced as an email tab and widget instead of an app-local link store. Reverse lookups are served by the registry's object-link index, and the email tab degrades gracefully when the Mail app is absent. Vote-by-email casting stays in the in-app statutory voting path, and legacy email-link objects are relinked to the registry by an idempotent migration that archives the originals rather than deleting them.

## Requirements

### Requirement: REQ-EMAIL-LEAF-001 Email-to-dossier linking via the Email leaf
The system SHALL link emails to a decision dossier or agenda item through the ADR-019 email integration leaf bound to the OpenRegister object, surfaced as a registry tab + widget. The system SHALL NOT store email-to-object links in an app-local `EmailLink` schema.

#### Scenario: Email leaf surfaced on the decision-dossier detail page
- **GIVEN** an authenticated user viewing a decision-dossier detail page
- **AND** the Nextcloud Mail app is installed and the email leaf is registered
- **WHEN** they open the email tab and link an email
- **THEN** the email is bound to the dossier's OR object through the registry email leaf
- **THEN** no app-local `EmailLink` object is created

#### Scenario: Reverse lookup via the registry
- **GIVEN** an email already linked to a dossier through the leaf
- **WHEN** the system needs to find which dossier an email is linked to
- **THEN** the answer is served by the registry's object-link index, not an `EmailLink` object

#### Scenario: Mail app not installed degrades gracefully
- **GIVEN** the Nextcloud Mail app is not installed
- **WHEN** a user opens a decision-dossier detail page
- **THEN** the email tab is hidden and the page renders normally
- **THEN** no error is raised

### Requirement: REQ-EMAIL-LEAF-002 Vote-by-email stays in the statutory voting path
The system SHALL retain the vote-by-email casting logic (`MailReplyHandler`) in the in-app statutory voting path and SHALL surface the related email thread through the email leaf rather than an app-local `EmailLink` object. Vote casting SHALL NOT move to an integration leaf.

#### Scenario: Email reply casts a statutory vote
- **GIVEN** a voting-notification thread for a motion
- **WHEN** an eligible voter replies with a vote in the body
- **THEN** the vote is cast through the in-app statutory voting path (subject to quorum/eligibility)
- **THEN** the email thread is visible via the email leaf bound to the motion/decision object, not via an `EmailLink` object

### Requirement: REQ-EMAIL-LEAF-003 Migrate legacy EmailLink objects, archived not deleted
The system SHALL provide an idempotent migration that, for each existing `EmailLink` object, creates the equivalent registry email-object link binding the email to the dossier/agenda item, then archives the legacy `EmailLink` object via OpenRegister's archival workflow without hard-deleting it.

#### Scenario: EmailLink relinked and archived
- **GIVEN** an `EmailLink` object mapping an email to a decision
- **WHEN** the migration runs
- **THEN** a registry email-object link binds the email to the decision's OR object
- **THEN** the legacy `EmailLink` object is set to an archived state and remains queryable for audit

#### Scenario: Migration is idempotent
- **GIVEN** the migration has already run
- **WHEN** it runs again
- **THEN** no duplicate registry links are created and already-archived `EmailLink` objects are skipped
