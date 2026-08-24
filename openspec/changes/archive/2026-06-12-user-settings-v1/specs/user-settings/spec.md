---
status: draft
---

# Spec Delta: User Settings (user-settings-v1)

## Purpose

Implements the four seeded user-settings requirements (notification, display,
delegation/absence, communication preferences). The requirement texts match the
seeded spec; this delta adds e2e traceability annotations and the implemented
behaviour. On archive, the main spec flips to `status: done`.

## MODIFIED Requirements

---

### Requirement: Notification Preferences

The system MUST allow users to configure their notification preferences for Decidesk events. Users MUST be able to enable or disable notifications per event type and choose delivery channels (Nextcloud notification, email, or both).

**Feature tier**: MVP

#### Scenario: Configure vote notification preferences

- GIVEN a user in their Decidesk personal settings
- WHEN they enable "Pending vote" notifications via both Nextcloud notification and email
- THEN the user MUST receive a Nextcloud notification AND an email when a new vote is initiated in their body
- AND the notification MUST include the decision title, body, and voting deadline

#### Scenario: Disable meeting reminder notifications

- GIVEN a user who prefers to use their calendar for reminders
- WHEN they disable "Meeting reminder" notifications
- THEN the user MUST NOT receive Decidesk meeting reminders
- AND calendar events (if synced) MUST still have their own reminders

#### Scenario: Configure notification timing for meeting reminders

- GIVEN a user who wants early reminders
- WHEN they set meeting reminder timing to "48 hours before" and "1 hour before"
- THEN the user MUST receive reminders at both configured times
- AND the default MUST be "24 hours before" and "1 hour before"

---

### Requirement: Display Preferences

The system MUST allow users to configure display preferences for the Decidesk interface including: default view (dashboard, meetings, decisions), items per page in list views, date/time format, and preferred language.

**Feature tier**: MVP

#### Scenario: Set default landing page

- GIVEN a secretary who primarily works with meetings
- WHEN they set their default view to "Meetings"
- THEN opening Decidesk MUST navigate directly to the meetings list instead of the dashboard

#### Scenario: Configure date format preference

- GIVEN a user who prefers DD-MM-YYYY format
- WHEN they set date format to "DD-MM-YYYY"
- THEN all dates in the Decidesk interface MUST use this format
- AND the default MUST follow the Nextcloud locale setting

---

### Requirement: Delegation and Absence

The system MUST allow users to configure a delegate who receives their notifications and can act on their behalf during a configured absence period. This supports vacation coverage for governance roles.

**Feature tier**: MVP

#### Scenario: Configure absence delegation

- GIVEN a board member going on vacation from 2026-07-01 to 2026-07-14
- WHEN they configure member B as their delegate for that period
- THEN member B MUST receive all of member A's Decidesk notifications during the period
- AND member B MUST be able to view member A's pending votes and action items
- AND the delegation MUST expire automatically on 2026-07-14

#### Scenario: Delegate cannot vote without explicit proxy

@e2e exclude server-side voting guard inside VotingService::castVote; requires a seeded two-member voting round with an active absence delegation, which has no deterministic UI fixture — verified by PHPUnit (VotingServiceTest delegation-message cases) and the Newman cast-vote negative request

- GIVEN member B is a delegate for member A during absence
- WHEN member B attempts to cast a vote on member A's behalf
- THEN the system MUST block the vote with a message: "Delegation does not include voting rights. A formal proxy (volmacht) is required for voting."
- AND the system MUST provide a link to the proxy granting process

---

### Requirement: Communication Preferences

The system MUST allow users to set their preferred communication channel for governance matters: email address, phone number for urgent matters, and preferred language for communications.

**Feature tier**: MVP

#### Scenario: Set preferred contact for governance communications

- GIVEN a member with both personal and work email addresses
- WHEN they set their governance communication email to their work address
- THEN all Decidesk-related emails (convocations, minutes, reminders) MUST be sent to the work address
- AND the default MUST be the Nextcloud account email
