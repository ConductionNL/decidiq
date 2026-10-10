# Tasks: platform-microsoft-365-calendar-and-teams

## Implementation tasks

### Task 1: Declare the meeting dates for the feed
- **spec_ref**: `openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-001-a-meetings-dates-reach-openregisters-calendar-feed`
- **files**: `lib/Settings/register.d/92-meeting-calendar-feed.json` (`Meeting.configuration.calendarProvider`)
- **acceptance_criteria**:
  - GIVEN the fragment imported on the dev instance WHEN a token is minted over `meeting` and the feed read THEN the seeded meetings appear as VEVENTs with start, end and location (Newman, red before the fragment and green after)
  - GIVEN a moved meeting WHEN the feed is read again THEN only the new date is present (Newman)
- [ ] Implement
- [ ] Test

### Task 2: Remove the dead calendar call
- **spec_ref**: `openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-002-publishing-an-agenda-makes-no-calendar-call`
- **files**: `lib/Service/AgendaService.php`, `tests/Unit/Service/AgendaServiceTest.php`, `tests/Stubs/OpenRegisterServices.php` (drop the stub if nothing else needs it)
- **acceptance_criteria**:
  - GIVEN the tree WHEN `git grep updateMeetingEvent lib` runs THEN no hit
  - GIVEN `AgendaServiceTest` WHEN it publishes an agenda THEN the version is recorded without a calendar double
  - GIVEN hydra gate `stub-scan` WHEN it runs THEN no unused injected dependency is reported for AgendaService
- [ ] Implement
- [ ] Test

### Task 3: The calendar subscription section
- **spec_ref**: `openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings`
- **files**: `src/components/userSettings/CalendarSubscriptionSection.vue`, `src/views/settings/UserSettingsPage.vue`, `tests/e2e/calendar-subscription.spec.ts`
- **acceptance_criteria**:
  - GIVEN a member WHEN they subscribe THEN an `.ics` address and the Outlook line show (Playwright)
  - GIVEN one subscription WHEN they stop it THEN the list is empty and the address answers 404 (Playwright plus a request)
- [ ] Implement
- [ ] Test

### Task 4: The Teams field and the create action
- **spec_ref**: `openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting`
- **files**: `lib/Settings/register.d/92-meeting-calendar-feed.json` (`Meeting.teamsMeeting` with the `joinUrl` pattern), `lib/Settings/connections.json` (`microsoft-365`), `lib/Service/TeamsMeetingService.php`, `lib/Controller/TeamsMeetingController.php`, `appinfo/routes.php`, `src/manifest.json` (Planning widget includes `teamsMeeting`), `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN a stubbed integriq call WHEN create runs THEN `teamsMeeting` holds join address, id, author and time (PHPUnit, red then green)
  - GIVEN a non-staff member WHEN they call the route THEN 403 (PHPUnit and Newman)
  - GIVEN a non-Teams address WHEN saved THEN OpenRegister rejects it (Newman)
  - GIVEN hydra gates `connections-declaration`, `route-auth`, `no-admin-idor` WHEN they run THEN they pass
- [ ] Implement
- [ ] Test

### Task 5: Join from the meeting and the live meeting page
- **spec_ref**: `openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-005-members-join-the-teams-meeting-from-decidiq`
- **files**: `src/views/LiveMeeting.vue`, `tests/e2e/calendar-subscription.spec.ts`
- **acceptance_criteria**:
  - GIVEN the seeded digital meeting WHEN the live page opens THEN "Join in Microsoft Teams" links to the join address in a new tab (Playwright)
- [ ] Implement
- [ ] Test

### Task 6: Strings and docs
- Dutch and English strings for the section, the button and the refusals (`test:l10n`, `check:schema-l10n` green).
- `docs/features/microsoft-365.md` (new): subscribing in Outlook, how often Outlook refreshes, creating a Teams meeting, and signing in with a Microsoft account through Nextcloud's `user_oidc`.
- [ ] Implement
- [ ] Test
