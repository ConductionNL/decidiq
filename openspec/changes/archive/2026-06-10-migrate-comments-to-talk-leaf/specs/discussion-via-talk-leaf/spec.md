# Spec: Discussion via Talk leaf

This file contains delta specifications for the migrate-comments-to-talk-leaf change.

---

## ADDED Requirements

### Requirement: REQ-DISC-TALK-001 Discussion is a Talk conversation bound to the artifact
The system SHALL provide discussion on a governance artifact (meeting, motion, amendment, decision) via a Nextcloud Talk conversation bound to the artifact's OpenRegister object through the ADR-019 integration registry. The system SHALL NOT store discussion messages in an app-local `Comment` schema.

#### Scenario: Talk leaf surfaced on the meeting detail page
- **GIVEN** an authenticated participant viewing a meeting detail page
- **AND** the Nextcloud Talk app is installed and the talk leaf is registered in the integration registry
- **WHEN** the participant opens the discussion tab
- **THEN** the registry-driven talk leaf is rendered as a tab/widget bound to the meeting's OpenRegister object
- **THEN** posting a message creates it in the bound Talk conversation, not in an app-local Comment object

#### Scenario: Talk leaf surfaced on the motion detail page
- **GIVEN** an authenticated participant viewing a motion detail page
- **WHEN** they open the discussion tab
- **THEN** the talk leaf bound to the motion's OpenRegister object is rendered through `MeetingIntegrations.vue`

#### Scenario: Talk app not installed degrades gracefully
- **GIVEN** the Nextcloud Talk app is not installed
- **WHEN** a participant opens a meeting or motion detail page
- **THEN** the discussion tab is hidden or shows an "discussion unavailable" state
- **THEN** no error is raised and the rest of the detail page renders normally

### Requirement: REQ-DISC-TALK-002 Legacy comments migrate into Talk, archived not deleted
The system SHALL provide a one-shot, idempotent migration that, for each governance artifact with existing in-app `Comment` objects, ensures a bound Talk conversation exists and replays each comment into it as a Talk message preserving the original author and timestamp. After replay, each legacy `Comment` object SHALL be set to an archived state via OpenRegister's archival workflow and SHALL NOT be hard-deleted.

#### Scenario: Comments replayed into the bound conversation
- **GIVEN** a motion with three existing in-app `Comment` objects
- **WHEN** the migration runs
- **THEN** the motion's bound Talk conversation contains three messages preserving each comment's author and original timestamp
- **THEN** each of the three `Comment` objects is set to an archived state and remains queryable for audit

#### Scenario: Migration is idempotent
- **GIVEN** the migration has already run for a given artifact
- **WHEN** the migration runs again
- **THEN** no duplicate Talk messages are created and already-archived `Comment` objects are skipped

## REMOVED Requirements

### Requirement: Discussion and comments via in-app CommentService
**Reason:** Superseded by the talk integration leaf (REQ-DISC-TALK-001). The in-app `CommentService` and local `Comment` schema duplicated the ADR-019 talk abstraction, a review-blocking anti-pattern under ADR-022.
**Migration:** Existing `Comment` objects are replayed into bound Talk conversations and archived (not purged) per REQ-DISC-TALK-002.
