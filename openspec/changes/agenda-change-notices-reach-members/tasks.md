# Tasks: agenda-change-notices-reach-members

## Implementation tasks

### Task 1: decidiq's notifier
- **spec_ref**: `openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown`
- **files**: `lib/Notification/Notifier.php`, `lib/AppInfo/Registrar/PlatformIntegrationRegistrar.php`, `l10n/en.json`, `l10n/nl.json`, `tests/Unit/Notification/NotifierTest.php`
- **acceptance_criteria**:
  - GIVEN a notification with app `decidiq` and subject `agenda_changed` WHEN prepared THEN it has a translated subject and a link to `/meetings/{id}`
  - GIVEN app `openregister` WHEN prepared THEN `UnknownNotificationException` is thrown
  - GIVEN an unknown decidiq subject WHEN prepared THEN `UnknownNotificationException` is thrown
- [x] Implement
- [x] Test (red before the notifier exists: the prepare call throws `IncompleteParsedNotificationException` in the server harness)

### Task 2: In-app delivery through decidiq, not a service that does not exist
- **spec_ref**: `openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-002-preference-aware-in-app-notices-are-sent-as-decidiq`
- **files**: `lib/Service/NotificationPreferenceService.php`, `tests/Unit/Service/NotificationPreferenceServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a member with delivery in-app WHEN `dispatch()` runs THEN `IManager::notify()` receives a decidiq notification with subject `decidiq_message`
  - GIVEN the container has no `OpenRegisterNotificationService` WHEN `dispatch()` runs THEN the in-app count is 1, not 0
- [ ] Implement
- [ ] Test

### Task 3: Agenda notices honour each member's choice
- **spec_ref**: `openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice`
- **files**: `lib/Service/AgendaService.php`, `lib/Service/NotificationPreferenceService.php`, `lib/Service/NotificationPreferenceRequestValidator.php`, `src/components/userSettings/NotificationPreferencesSection.vue`, `src/components/userSettings/userPreferences.js`, `tests/Unit/Service/AgendaServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a member with delivery email WHEN an item of a published agenda is edited THEN he gets an email and no bell notice
  - GIVEN a member who switched agenda changes off WHEN the agenda changes THEN he gets nothing
  - GIVEN a participant with `nextcloudUserId` and a different `owner` WHEN notified THEN the `nextcloudUserId` user receives it
- [ ] Implement
- [ ] Test

### Task 4: One notice for a burst of edits
- **spec_ref**: `openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-004-a-burst-of-agenda-edits-sends-one-notice`
- **files**: `lib/Service/AgendaService.php`, `lib/Settings/register.d/91-agenda-publication-has-its-own-fields.json`, `tests/Unit/Service/AgendaServiceTest.php`
- **acceptance_criteria**:
  - GIVEN three edits within five minutes WHEN each is saved THEN three agenda versions exist and each member got one notice
  - GIVEN an edit six minutes after the last notice WHEN saved THEN a second notice goes out
- [ ] Implement
- [ ] Test

### Task 5: End to end
- **spec_ref**: `openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown`
- **files**: `tests/e2e/agenda-change-notices.spec.ts`
- **acceptance_criteria**:
  - GIVEN a published agenda and a member signed in WHEN the clerk adds an item THEN the member's bell shows "The agenda of <meeting> changed" linking the meeting
- [ ] Implement
- [ ] Test

## Verification

- `composer check:strict` and `npm run lint` once before push; `npm run test:l10n` for the new keys.
