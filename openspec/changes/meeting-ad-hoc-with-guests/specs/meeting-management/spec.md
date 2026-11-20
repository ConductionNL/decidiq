# meeting-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [meeting-ad-hoc-with-guests](../../) (this delta)

## Purpose

Any signed-in user can create a meeting, but only administrators can change it afterwards, and invitees come only from existing participant records. Moves decidiq matrix rows pla-20 toward built.

## ADDED Requirements

### Requirement: REQ-MAH-001 An organiser runs their own ad hoc meeting

The user who created a meeting SHALL be able to edit and delete it, and SHALL be able to invite guests from outside by email.

Guests from outside are invited only to a meeting without a governing body. This is the security default, confirmed by Ruben on 9 Oct 2026 (decision 138): a public link to a council folder must never reach its confidential papers.

The Nieuw overleg page (board DcAdhocOverleg, route `/meetings/new`, reached through New ad hoc meeting on the meeting index) SHALL set up such a meeting on one page: title, date and time with an end time, place, an optional Talk conversation, agenda points, papers, and colleagues and guests in one list (Naam, E-mail, Soort, Acties). Nothing is written until Overleg aanmaken. Then the meeting is saved first as a draft without a body, followed by its agenda items, the colleagues' attendance, the papers and the Talk conversation; guests are invited last so their mail lists the agenda. A step that fails after the meeting exists is listed on the page with a link to the meeting.

#### Scenario: A project lead invites an outside advisor
- GIVEN project lead Anna created meeting Kick-off windpark
- WHEN she invites guest advisor@example.org
- THEN the advisor gets an invitation with the date, the agenda and a link to the papers

@e2e exclude needs a mail catcher on the live instance; covered by tests/Unit/Service/GuestInvitationServiceTest.php::testTheOrganiserInvitesAnOutsideAdvisor and testTheInvitationCarriesTheDateTheAgendaAndTheLink; Playwright owed (tasks.md Task 3)

#### Scenario: Someone who did not organise the meeting cannot invite or edit
- GIVEN Pieter did not create meeting Kick-off windpark
- WHEN he invites a guest or edits the meeting
- THEN it is refused

@e2e exclude covered by tests/Unit/Service/GuestInvitationServiceTest.php::testSomeoneWhoDidNotOrganiseTheMeetingIsRefused and tests/Unit/RegisterAuthorizationTest.php::testTheOrganiserOfAMeetingKeepsEditingIt; a two-user Playwright run is owed

#### Scenario: A meeting of a governing body takes no guests from outside
- GIVEN a council meeting whose folder can hold confidential papers
- WHEN the organiser tries to invite a guest by email
- THEN it is refused, because a public link to that folder must never reach confidential papers

@e2e exclude covered by tests/Unit/Service/GuestInvitationServiceTest.php::testAMeetingOfAGoverningBodyTakesNoGuests

#### Scenario: The participants list shows who is a guest (board DcAdhocOverleg)
- GIVEN an ad hoc meeting with a colleague and an invited guest
- WHEN the organiser opens the participants
- THEN each row reads Medewerker or Gast, and Gast uitnodigen sits next to Add participant

@e2e exclude covered by tests/vitest/guestInvitation.spec.js (isGuest, the button only on a meeting without a body); a Playwright run is owed

#### Scenario: The organiser sets up a meeting with guests on one page
- GIVEN Anna opens New ad hoc meeting on the meeting index
- WHEN she fills in the title, date and place, adds agenda point Opening en kennismaking and guest k.ouali@example.org, and chooses Overleg aanmaken
- THEN a draft meeting without a body is created with that agenda item, the guest is invited, and the meeting page opens

Covered by tests/vitest/adhocMeeting.spec.js (payloads valid against the merged meeting, agenda-item and meeting-attendance schemas; the order of the steps; failures reported) and tests/e2e/meeting-ad-hoc-with-guests.spec.ts (not yet run on a live instance).
