# Tasks: meeting-ad-hoc-with-guests

## Implementation tasks

### Task 1: The organiser edits their meeting
- **spec_ref**: `openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting`
- **files**: `lib/Settings/register.d/`, `tests/Unit/RegisterAuthorizationTest.php`
- **acceptance_criteria**:
  - GIVEN a meeting created by Anna WHEN Anna edits its title THEN it saves
  - GIVEN Pieter who did not create it WHEN he edits THEN it is refused
- [x] Implement (already true on development: Meeting declares no authorization block, so the register baseline applies and OpenRegister's owner bypass lets the creator update and delete; no code change needed)
- [x] Test: `tests/Unit/RegisterAuthorizationTest.php::testTheOrganiserOfAMeetingKeepsEditingIt` pins it (green on first run, because the behaviour already existed; it fails the moment a Meeting block or property rule is added)

### Task 2: Invite a guest by email
- **spec_ref**: `openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting`
- **files**: `src/dialogs/MeetingParticipantAddDialog.vue`, `lib/Service/GuestInvitationService.php`
- **acceptance_criteria**:
  - GIVEN an ad hoc meeting WHEN Anna invites guest@example.org THEN a guest participant is added and one invitation mail is sent with a link to the papers
- [x] Implement: `lib/Service/GuestInvitationService.php`, `lib/Controller/GuestInvitationController.php` (POST /api/meetings/{id}/guests), `src/dialogs/GuestInviteDialog.vue`, `src/utils/guestInvitation.js`, Gast uitnodigen and the Medewerker/Gast column in `src/components/tabs/MeetingParticipantsTab.vue`
- [x] Test: `tests/Unit/Service/GuestInvitationServiceTest.php` (payloads checked against the merged participant and meeting-attendance schemas), `tests/vitest/guestInvitation.spec.js`

### Task 3: The Nieuw overleg page of board DcAdhocOverleg
- **spec_ref**: `openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting`
- **acceptance_criteria**:
  - The board draws one page to set up a meeting without a body: title, date and time, place, a Talk conversation, agenda points, papers, and colleagues and guests together, then Overleg aanmaken. Today the organiser creates the meeting on the Meetings index and adds agenda, papers and guests on the meeting page.
- [x] Implement: `src/views/meetings/AdhocMeetingPage.vue` (custom page AdhocMeetingNew at `/meetings/new`, declared above MeetingDetail), `src/utils/adhocMeeting.js` (payloads and the ordered create), New ad hoc meeting in `src/views/meetings/MeetingViewToggle.vue`, `src/dialogs/GuestInviteDialog.vue` stages a guest when there is no meeting yet; 33 strings in every locale
- [x] Test: `tests/vitest/adhocMeeting.spec.js` (payloads validated against the merged meeting, agenda-item and meeting-attendance schemas; step order; failures; papers upload; Talk room)
- [ ] Playwright: tests/e2e/meeting-ad-hoc-with-guests.spec.ts written (not run: needs the live instance; the invitation mail needs a mail catcher)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
