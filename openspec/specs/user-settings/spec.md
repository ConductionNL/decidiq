---
status: idea
---

# User Settings Specification

## Purpose

User settings allow individual Decidesk users to configure their personal preferences for notifications, display, participation, delegation, and communication. These settings control how and when users receive alerts about meetings, votes, and decisions, display preferences for the dashboard and meeting interface, and delegation/absence management for governance roles. Settings are rendered using `NcAppSettingsDialog` within the Decidesk app context.

**Standards**: Nextcloud Settings API (`OCP\Settings\ISettings`), Nextcloud Notification API (`OCP\Notification\IManager`), NcAppSettingsDialog component
**Feature tier**: MVP

**Evidence sources**: Intelligence DB user stories #4, #5, #18, #27, #63, #80, #82, #97, #142, #179, #197, #208, #255, #313, #318, #328, #330, #331, #335; Requirement clusters #34 (Notifications, 451 reqs/153 tenders), #18 (Authorization/RBAC, 876 reqs/205 tenders); Category features: notifications, role-based-views, calendar-integration

## Requirements

---

### REQ-US-01: Notification Preferences

The system MUST allow users to configure their notification preferences for Decidesk events. Users MUST be able to enable or disable notifications per event type and choose delivery channels (Nextcloud notification, email, or both). Event types MUST include: pending vote, meeting reminder, decision status change, action item assignment, action item due, convocation received, minute approval request, quorum at risk, and proxy request.

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

#### Scenario: Configure quorum-at-risk alerts

- GIVEN a chair who needs to monitor quorum during meetings
- WHEN they enable "Quorum at risk" notifications
- THEN the user MUST receive an immediate notification when a member leaves and quorum drops below threshold
- AND the notification MUST show current attendance count vs. required quorum

---

### REQ-US-02: Display Preferences

The system MUST allow users to configure display preferences for the Decidesk interface including: default view (dashboard, meetings, decisions, action items), items per page in list views, date/time format, preferred language (nl/en), and compact/comfortable list density.

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

#### Scenario: Set preferred language

- GIVEN a user in a bilingual organization (nl/en)
- WHEN they set their preferred language to English
- THEN all Decidesk interface labels, menu items, and system messages MUST render in English
- AND generated documents (minutes, resolutions) MUST use the meeting's language setting, not the user's preference

#### Scenario: Configure list density

- GIVEN a user who prefers seeing more items at once
- WHEN they set list density to "compact"
- THEN list views (meetings, decisions, action items) MUST show more rows with reduced spacing
- AND the default MUST be "comfortable"

---

### REQ-US-03: Delegation and Absence Management

The system MUST allow users to configure a delegate who receives their notifications and can act on their behalf during a configured absence period. Delegation MUST NOT automatically include voting rights; formal proxy (volmacht) is a separate process.

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

#### Scenario: Grant formal proxy for voting

- GIVEN a member who cannot attend the ALV
- WHEN they grant a formal proxy (volmacht) to another member
- THEN the proxy holder MUST be able to cast votes on behalf of the grantor
- AND the proxy MUST specify whether it is general (all items) or item-specific
- AND the system MUST enforce the maximum number of proxies per person as configured on the body
- AND both parties MUST receive confirmation of the proxy arrangement

#### Scenario: Cancel or modify delegation

- GIVEN an active delegation to member B
- WHEN member A returns early and cancels the delegation
- THEN member B MUST immediately lose delegate access
- AND member A MUST resume receiving their own notifications
- AND any pending action items viewed by the delegate MUST be logged in the audit trail

---

### REQ-US-04: Communication Preferences

The system MUST allow users to set their preferred communication channel for governance matters: email address, phone number for urgent matters, and preferred language for communications.

**Feature tier**: MVP

#### Scenario: Set preferred contact for governance communications

- GIVEN a member with both personal and work email addresses
- WHEN they set their governance communication email to their work address
- THEN all Decidesk-related emails (convocations, minutes, reminders) MUST be sent to the work address
- AND the default MUST be the Nextcloud account email

#### Scenario: Configure urgent contact method

- GIVEN a chair who needs to be reachable for quorum emergencies
- WHEN they add a phone number for urgent governance matters
- THEN the system MUST store the phone number securely
- AND the phone number MUST only be visible to secretaries and chairs within the same body
- AND the system MAY use this for future SMS notification integration

---

### REQ-US-05: Speaking Time and Meeting Participation

The system MUST allow users to configure their participation preferences for meetings, including speaking time alerts, preparation reminders, and preferred participation mode (physical, digital, hybrid).

**Feature tier**: V1

#### Scenario: Configure speaking time alerts

- GIVEN a council member who wants to manage their speaking time
- WHEN they set speaking time alert at "30 seconds remaining"
- THEN the user MUST receive a visual alert when their remaining speaking time reaches 30 seconds
- AND the alert MUST be visible on both desktop and mobile interfaces
- AND the default alert timing MUST be "1 minute remaining"

#### Scenario: Set preferred participation mode

- GIVEN a board member who usually attends remotely
- WHEN they set preferred participation mode to "digital"
- THEN new meeting invitations MUST default to digital attendance for this user
- AND the meeting organizer MUST see the preference when viewing the participant list
- AND the user MAY override this per individual meeting

#### Scenario: Configure preparation reminder timing

- GIVEN a member who needs extra preparation time
- WHEN they set preparation reminders to "72 hours before meeting"
- THEN the user MUST receive a reminder to review meeting documents 72 hours before each meeting
- AND the reminder MUST include links to unread meeting documents
- AND the default MUST be "48 hours before meeting"

---

### REQ-US-06: Dashboard Customization

The system MUST allow users to customize their personal dashboard view with configurable widgets showing relevant governance information.

**Feature tier**: V1

#### Scenario: Configure dashboard widgets

- GIVEN a secretary opening their dashboard settings
- WHEN they enable widgets for "Upcoming meetings", "Pending action items", "Open votes", and "Recent decisions"
- THEN the dashboard MUST display these widgets in the user's configured order
- AND each widget MUST show a configurable number of items (default: 5)
- AND widgets MUST be collapsible and reorderable via drag-and-drop

#### Scenario: Configure action item dashboard

- GIVEN a board member with multiple body memberships
- WHEN they filter the action items widget to show only "Bestuur" items
- THEN the widget MUST show only action items from the "Bestuur" body
- AND overdue items MUST be highlighted with a warning indicator
- AND the filter MUST persist across sessions

---

### REQ-US-07: Mobile and Offline Preferences

The system MUST allow users to configure mobile-specific settings for accessing governance materials on tablets and smartphones.

**Feature tier**: V1

#### Scenario: Configure offline document sync

- GIVEN a supervisory board member who prepares on their tablet during travel
- WHEN they enable "Offline sync" for meeting documents
- THEN upcoming meeting documents (within 7 days) MUST be cached for offline access
- AND annotations made offline MUST sync when connectivity is restored
- AND the sync MUST respect data limits (configurable max download size)

#### Scenario: Configure mobile notification behavior

- GIVEN a user on a mobile device
- WHEN they configure "Do not disturb" hours for Decidesk notifications
- THEN notifications MUST be silenced during configured hours (e.g., 22:00-07:00)
- AND critical notifications (quorum at risk, voting deadline imminent) MUST still be delivered

---

### REQ-US-08: Keyword Alerts and Topic Following

The system MUST allow users to set up keyword-based alerts for governance topics they want to follow across all bodies they are a member of.

**Feature tier**: V2

#### Scenario: Set alerts for topics of interest

- GIVEN a member interested in financial matters
- WHEN they add keywords "budget", "begroting", "financial", "kosten" to their alert configuration
- THEN the user MUST receive a notification when new agenda items, decisions, or motions match any of these keywords
- AND the notification MUST include the matching item title, body, and meeting date
- AND keyword matches MUST search across titles, descriptions, and attached document names

#### Scenario: Follow specific decision topics

- GIVEN a citizen or member who wants to track a specific topic
- WHEN they subscribe to notifications for the topic "Sustainability policy"
- THEN they MUST receive notifications when new decisions, motions, or agenda items reference this topic
- AND they MUST be able to unsubscribe at any time

---

### REQ-US-09: Meeting Scorecard Preferences

The system MUST allow users to configure their personal meeting efficiency scorecard showing KPIs for their meeting behavior.

**Feature tier**: V2

#### Scenario: Configure personal meeting scorecard

- GIVEN a manager who wants to optimize meeting time
- WHEN they enable the personal meeting scorecard
- THEN the scorecard MUST show: meetings per week (with trend), average meeting duration, decision rate (decisions per meeting), action item completion rate, and focus time ratio
- AND all metrics MUST be calculated from the user's actual meeting data
- AND the scorecard MUST be visible only to the user (private by default)

---

### REQ-US-10: Accessibility Preferences

The system MUST respect Nextcloud's accessibility settings and allow users to configure Decidesk-specific accessibility options.

**Feature tier**: V1

#### Scenario: Configure high-contrast mode

- GIVEN a user with visual accessibility needs
- WHEN Nextcloud's high-contrast theme is enabled
- THEN all Decidesk interfaces MUST render with sufficient contrast ratios (WCAG AA minimum)
- AND voting buttons MUST use both color and icon indicators (not color alone)
- AND the speaking time indicator MUST use both visual and optional audio alerts

#### Scenario: Configure keyboard navigation preferences

- GIVEN a user who navigates primarily via keyboard
- WHEN they are on the voting interface
- THEN all vote options (for, against, abstain) MUST be reachable via Tab key
- AND the current focus MUST be clearly indicated
- AND keyboard shortcuts MUST be documented and customizable

## User Stories

1. **Member accessing documents and decision history**: As a member, I want to access meeting minutes, financial reports, and decision history through a self-service portal so that I can stay informed about association governance. (Source: intelligence DB #80, priority: medium)

2. **Supervisory board member accessing secure workspace**: As a supervisory board member, I want a secure digital workspace where I can access management reports, governance documents, and communicate with fellow board members between meetings. (Source: intelligence DB #27, priority: must)

3. **Board member accessing board pack on mobile**: As a supervisory board member, I want to access the board pack on my tablet or smartphone with offline capability, so that I can prepare for meetings while traveling. (Source: intelligence DB #18, priority: must)

4. **Shareholder submitting proxy vote digitally**: As a shareholder, I want to submit my proxy vote digitally for each resolution item, so that my vote is counted even though I cannot attend the AGM in person. (Source: intelligence DB #4, priority: must)

5. **Member granting proxy vote digitally**: As a member who cannot attend the ALV, I want to grant a proxy to another member digitally so that my vote is represented without paper forms. (Source: intelligence DB #63, priority: high)

6. **Member delegating vote via proxy**: As a member, I want to delegate my vote to another member via proxy so that my voice is counted even when I cannot attend. (Source: intelligence DB #313, priority: must)

7. **Shareholder submitting proxy before AGM**: As a shareholder, I want to submit my proxy vote before the AGM so that my shares are voted even if I cannot attend. (Source: intelligence DB #318, priority: must)

8. **Council member accessing documents offline**: As a raadslid, I want to download meeting documents for offline reading on my tablet, so that I can prepare even without internet access. (Source: intelligence DB #142, priority: should)

9. **Council member viewing motions dashboard**: As a raadslid, I want to see a dashboard showing all adopted motions with their current status, so that I can hold the executive accountable. (Source: intelligence DB #179, priority: should)

10. **Citizen setting alerts for topics of interest**: As a burger, I want to set up keyword-based alerts for council agenda items and decisions, so that I am notified when topics that affect me are being discussed. (Source: intelligence DB #208, priority: could)

11. **Department head approving within delegation**: As a department head, I want to see budget requests alongside my remaining budget and delegation limits, so that I can make informed approval decisions. (Source: intelligence DB #97, priority: high)

12. **Ledenraad member preparing with constituency input**: As a ledenraad member, I want to review the agenda, consult my constituency, and prepare my voting position so that I effectively represent my members. (Source: intelligence DB #82, priority: medium)

13. **Manager tracking personal meeting KPIs**: As a manager, I want a personal meeting scorecard showing my KPIs so that I can optimize my own meeting behavior. (Source: intelligence DB #331, priority: must)

14. **Accessibility officer checking document compliance**: As a toegankelijkheidsmedewerker, I want to automatically check documents for accessibility issues before publication. (Source: intelligence DB #197, priority: must)

15. **Council member monitoring executive commitments**: As a raadslid, I want a dashboard showing all open commitments from the executive with deadlines and status. (Source: intelligence DB #203, priority: should)

16. **Alderman reviewing participation results**: As an alderman, I want a dashboard showing all active and completed participation processes with key results. (Source: intelligence DB #255, priority: high)

17. **CFO viewing enterprise risk dashboard**: As a CFO, I want a real-time risk dashboard showing all enterprise risks, control effectiveness, and compliance status. (Source: intelligence DB #21, priority: should)

18. **Institutional investor managing proxy voting at scale**: As an institutional investor, I want to manage proxy voting across all portfolio company AGMs from a single dashboard. (Source: intelligence DB #5, priority: should)

19. **Meeting organizer displaying live cost ticker**: As a meeting organizer, I want to display a live cost ticker showing the running cost of the current meeting. (Source: intelligence DB #335, priority: could)

20. **Participation coordinator conducting ranked preference poll**: As a participation coordinator, I want citizens to rank their preferred options so that we find solutions with broadest support. (Source: intelligence DB #328, priority: should)

## Acceptance Criteria

1. Notification preferences are configurable per event type (vote, meeting, decision, action item, convocation, quorum, proxy)
2. Delivery channels (Nextcloud notification, email) are independently toggleable per event type
3. Meeting reminder timing is configurable with multiple time points (default: 24h + 1h before)
4. Display preferences support default view, items per page, date format, language, and list density
5. NcAppSettingsDialog is used for the settings interface within the Decidesk app
6. Absence delegation allows notification forwarding and read access but not voting
7. Formal proxy (volmacht) is a separate process from delegation with body-configured limits
8. Proxy granting supports general and item-specific proxies with confirmation
9. Communication preferences allow separate governance email and urgent contact number
10. Speaking time alerts are configurable with visual and optional audio indicators
11. Preparation reminders include links to unread meeting documents
12. Dashboard widgets are configurable, reorderable, and filterable per body
13. Mobile/offline preferences support document caching and do-not-disturb hours
14. Keyword alerts match across titles, descriptions, and document names
15. Personal meeting scorecard shows private KPIs (meetings/week, duration, decision rate)
16. Accessibility preferences respect Nextcloud themes and support keyboard navigation
17. All user preferences are stored per-user via Nextcloud's `IConfig` personal values
18. Settings changes take effect immediately without page reload
19. Default values are sensible and documented for all preferences
20. Delegation and proxy audit trail is maintained for compliance
