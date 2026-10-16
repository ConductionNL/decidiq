# Tasks: agenda-publish-and-invite-members

## Implementation tasks

### Task 1: Publish agenda button
- **spec_ref**: `openspec/changes/agenda-publish-and-invite-members/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members`
- **files**: `src/components/tabs/MeetingAgendaTab.vue`
- **acceptance_criteria**:
  - GIVEN the secretary on a meeting page WHEN she presses Publish agenda THEN the publish endpoint is called and the widget shows the published date
- [x] Implement
- [x] Test (red first)

### Task 2: Invitation with the agenda
- **spec_ref**: `openspec/changes/agenda-publish-and-invite-members/specs/agenda-publication/spec.md#requirement-req-apim-002-a-published-agenda-of-a-public-meeting-can-go-public`
- **files**: `lib/Service/AgendaService.php`, `lib/Settings/register.d/`, `lib/Notification/Notifier.php`
- **acceptance_criteria**:
  - GIVEN three members WHEN the agenda is published THEN each gets a notification and, with email delivery, an invitation listing the items
  - GIVEN a published agenda THEN the stage is unchanged and convocationSentAt is set
- [x] Implement
- [x] Test (red first)

### Task 3: Public publication becomes possible
- **spec_ref**: `openspec/changes/agenda-publish-and-invite-members/specs/agenda-publication/spec.md#requirement-req-apim-002-a-published-agenda-of-a-public-meeting-can-go-public`
- **files**: `lib/Service/PublicationEligibilityService.php`
- **acceptance_criteria**:
  - GIVEN a public meeting whose agenda was published WHEN the clerk opens the Publication widget THEN Publish is offered for the agenda
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
