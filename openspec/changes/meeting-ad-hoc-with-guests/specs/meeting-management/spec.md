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
