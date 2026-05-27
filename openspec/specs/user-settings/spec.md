---
status: idea
---

# User Settings Specification

## Purpose
@e2e exclude The personal notification preferences, display preferences, delegation, and communication preferences UIs described in this spec are not yet implemented. The decidesk settings page (manifest type:settings) only shows version-info and register-mapping; no notification channel toggles, no default-view selector, no absence delegation form, and no governance email override field exist in the SPA or in the Nextcloud personal settings panel.

User settings allow individual Decidesk users to configure their personal preferences for notifications, display, and participation. These settings control how and when users receive alerts about meetings, votes, and decisions, as well as display preferences for the dashboard and meeting interface.

**Standards**: Nextcloud Settings API (`OCP\Settings\ISettings`), Nextcloud Notification API
**Feature tier**: MVP

## Requirements

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

## User Stories

1. **Member accessing documents and decision history**: As a member, I want to access meeting minutes, financial reports, and decision history through a self-service portal so that I can stay informed about association governance. (Source: intelligence DB #80)

2. **Supervisory board member accessing secure workspace**: As a supervisory board member, I want a secure digital workspace where I can access management reports, governance documents, and communicate with fellow board members between meetings. (Source: intelligence DB #27)

3. **Board member accessing board pack on mobile**: As a supervisory board member, I want to access the board pack on my tablet or smartphone with offline capability, so that I can prepare for meetings while traveling. (Source: intelligence DB #18)

## Acceptance Criteria

- Notification preferences are configurable per event type (vote, meeting, decision, action item)
- Delivery channels (Nextcloud notification, email) are independently toggleable
- Meeting reminder timing is configurable (default: 24h + 1h before)
- Display preferences support default view, items per page, and date format
- Absence delegation notifies the delegate but does not grant voting rights
- Communication preferences allow separate governance email
- Settings use Nextcloud personal settings section via OCP\Settings\ISettings
