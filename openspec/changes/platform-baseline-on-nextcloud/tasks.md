# Tasks: platform-baseline-on-nextcloud

## Implementation Tasks

### Task 1: Record the inherited platform rows
- **spec_ref**: `openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-001-members-sign-in-with-the-organisations-single-sign-on-through-nextcloud` (+ REQ-PLB-002 to REQ-PLB-005)
- **files**: `openspec/parity/capabilities.json` (rows plt-01, plt-06, plt-21, plt-28, plt-32, pen-01)
- **acceptance_criteria**:
  - GIVEN each of the six rows WHEN read THEN `built.change` names this change and `built.requirement` names its requirement
  - GIVEN `grep -rniE 'two.?factor|totp|saml|oidc' lib src` WHEN run THEN decidiq holds no login or second factor of its own (REQ-PLB-001, REQ-PLB-002)
  - GIVEN `grep -rnoE 'https?://' lib` WHEN run THEN no hardcoded outbound host is called (REQ-PLB-005)
- [x] Implement
- [x] Test

### Task 2: Send the speaker to the room system
- **spec_ref**: `openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-006-the-rooms-cameras-and-microphones-follow-the-speaker`
- **files**: `lib/Settings/register.d/128-room-speaker.json` (`Meeting.currentSpeaker` and `Meeting.currentSpeakerSeat`, and an `x-openregister-notifications` rule on a change of `currentSpeaker` with a transport to the integriq `room-system` connection that `live-room-conference-system-link` adds), `src/components/liveMeeting/SpeakerQueuePanel.vue` and `src/utils/speakerQueue.js` (patch the meeting when the chair gives the floor)
- **acceptance_criteria**:
  - GIVEN a linked room system WHEN the chair gives a member the floor THEN the meeting's `currentSpeaker` changes and one message with the participant and `Participant.roomSeat` reaches the connection
  - GIVEN no linked room system WHEN the chair gives the floor THEN no outbound call is made and the queue behaves as before
  - GIVEN the rule WHEN declared THEN it uses only `changed` or `equals` conditions, which the OpenRegister dispatcher supports on updated triggers
- **depends_on**: `live-room-conference-system-link` Task 1 (the `room-system` connection and `Participant.roomSeat`)
- [ ] Implement
- [ ] Test

### Task 3: Ancillary positions on the public portal
- **spec_ref**: `openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-007-residents-read-members-ancillary-positions-without-an-account`
- **files**: `lib/Portal/PortalContributionProvider.php` (an anonymous `publicAncillaryPositions` collection on schema `ancillary-position` with a `fields` whitelist: person, organisation, role, remunerated, startDate, endDate), `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the provider WHEN its public records are read THEN `publicAncillaryPositions` is anonymous, listable and whitelists only the six fields
  - GIVEN a position without `publicationDate`, or with a past `depublicationDate` WHEN read anonymously THEN it is not returned (OpenRegister's published predicate on `lib/Settings/register.d/81-integrity-disclosures-in-plain-words.json`)
- **follow-up**: portaliq draws the positions on the member page (board PtStemgedrag or a member page board); that is a portaliq change
- [ ] Implement
- [ ] Test

### Task 4: Every page at phone width
- **spec_ref**: `openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-008-every-page-works-at-phone-width`
- **files**: `tests/e2e/phone-width.spec.ts` (new: every route in `src/manifest.json` at 360 by 740, asserting `document.documentElement.scrollWidth <= 360` and that the page's primary action is visible), and the page components the test finds broken (for example `src/views/LiveMeeting.vue`, `src/components/tabs/MeetingAgendaTab.vue`)
- **acceptance_criteria**:
  - GIVEN the test WHEN run against development THEN it lists every page that scrolls sideways at 360 pixels
  - GIVEN each listed page WHEN fixed THEN the fix is a layout rule on that page, not a phone-only copy of it
  - GIVEN the full run WHEN finished THEN no page scrolls sideways
- [ ] Implement
- [ ] Test
