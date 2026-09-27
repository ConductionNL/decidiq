# Tasks: planning-activity-calendar-by-audience

## Implementation tasks

### Task 1: Audiences on the meeting type
- **spec_ref**: `openspec/changes/planning-activity-calendar-by-audience/specs/activity-calendar/spec.md#requirement-req-acal-001-a-kind-of-meeting-declares-its-audiences`
- **files**: `lib/Settings/register.d/92-activity-calendar.json` (`MeetingType.audiences`), `src/manifest.d/configurable-types.json` (the field on the meeting type form and list), `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN imported THEN `audiences` is facetable and holds only the five values (Newman rejects `press`)
  - GIVEN the seed profile WHEN loaded THEN the three example types carry their audiences
- [ ] Implement
- [ ] Test

### Task 2: The calendar filters and fetches by month
- **spec_ref**: `openspec/changes/planning-activity-calendar-by-audience/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body`
- **files**: `src/views/meetings/MeetingCalendarView.vue`, `src/services/dashboardData.js`, `tests/vitest/meetingCalendarLoad.spec.js`, `tests/e2e/activity-calendar.spec.ts`
- **acceptance_criteria**:
  - GIVEN April is visible WHEN `load()` runs THEN the request carries an April `scheduledDate` range and no `_limit: 500` (vitest, red then green)
  - GIVEN "Executive" is chosen WHEN the grid renders THEN only meetings of executive types show, and the query string carries the filter (Playwright)
  - GIVEN a meeting without `type` WHEN "No audience set" is chosen THEN it shows (Playwright)
  - GIVEN hydra gate `nc-input-labels` WHEN it runs THEN both selects pass
- [ ] Implement
- [ ] Test

### Task 3: The activity source type
- **spec_ref**: `openspec/changes/planning-activity-calendar-by-audience/specs/public-publication/spec.md#requirement-publication-eligibility-gates`
- **files**: `lib/Service/PublicationEligibilityService.php` (`assertActivityEligible()`), `lib/Service/PublicationPayloadService.php` (`buildActivityPayload()`), `lib/Settings/register.d/92-activity-calendar.json` (`PublicationRecord.sourceType` gains `activity`; `PublicationPayload.location`, `audiences`)
- **acceptance_criteria**:
  - GIVEN `isPublic: false` WHEN an activity is published THEN refused and no record (PHPUnit, red then green)
  - GIVEN a public meeting without convocation WHEN published as activity THEN the payload holds only the allow-listed fields and no UID (PHPUnit)
  - GIVEN the payload WHEN read anonymously after `publicationDate` THEN it is returned (Newman)
- [ ] Implement
- [ ] Test

### Task 4: The publish action on the meeting page
- **spec_ref**: `openspec/changes/planning-activity-calendar-by-audience/specs/activity-calendar/spec.md#requirement-req-acal-004-staff-publish-a-public-meeting-to-the-residents-calendar`
- **files**: `src/components/tabs/AgendaPublicationTab.vue`, `tests/e2e/activity-calendar.spec.ts`
- **acceptance_criteria**:
  - GIVEN a public meeting WHEN the secretary presses "Publish to the public calendar" THEN the widget shows it as published and offers withdraw (Playwright)
  - GIVEN a non-public meeting WHEN the widget renders THEN the action is not offered
- [ ] Implement
- [ ] Test

### Task 5: The residents' calendar in the portal
- **spec_ref**: `openspec/changes/planning-activity-calendar-by-audience/specs/activity-calendar/spec.md#requirement-req-acal-005-residents-read-the-calendar-without-an-account`
- **files**: `lib/Portal/PortalContributionProvider.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN portaliq absent WHEN the contribution is built THEN `publicCalendar` is anonymous with the seven fields (PHPUnit)
  - GIVEN portaliq on the dev instance and a published seed activity WHEN `GET /portal/api/contributions` runs without a session THEN the collection is present (live check)
- [ ] Implement
- [ ] Test

### Task 6: Strings and docs
- Dutch and English strings for the filters, the five audiences and the publish action (`test:l10n`, `check:schema-l10n` green).
- `docs/features/activity-calendar.md`: setting audiences, filtering, publishing an activity for residents.
- [ ] Implement
- [ ] Test
