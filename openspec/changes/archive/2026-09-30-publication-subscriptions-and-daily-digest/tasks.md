# Tasks: publication-subscriptions-and-daily-digest

## Implementation tasks

### Task 1: Subscriptions and events as data
- **spec_ref**: `openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often`
- **files**: `lib/Settings/register.d/92-publication-subscriptions.json`, `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN `publication-subscription` and `publication-event` exist with their read rules
  - GIVEN a member WHEN he lists subscriptions THEN only his own return
- [x] Implement
- [x] Test (PHPUnit instead of Newman: `tests/Unit/Settings/PublicationSubscriptionRegisterTest.php` reads the merged register, the read rules and validates the example sets with Opis; `tests/Unit/RegisterAuthorizationTest.php` classifies both blocks)

### Task 2: Record what happened
- **spec_ref**: `openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events`
- **files**: `lib/Service/PublicationEventRecorder.php`, `lib/Service/AgendaService.php`, `lib/Service/PublicationService.php`, `tests/Unit/Service/PublicationEventRecorderTest.php`
- **acceptance_criteria**:
  - GIVEN a published agenda WHEN an item is added THEN one `agenda` event with the meeting, body and a one-line summary is stored
  - GIVEN a decision WHEN it is published THEN one `decision` event with `isPublished` true is stored
  - GIVEN a paper added to a published agenda WHEN the paper listener runs THEN one `paper` event is stored
- [x] Implement
- [x] Test (`tests/Unit/Service/PublicationEventRecorderTest.php` validates each event with Opis against the merged register; the callers in `tests/Unit/Service/AgendaInvitationTest.php`, `tests/Unit/Service/PublicationServiceTest.php` and `tests/Unit/Listener/OfficePaperAddedListenerTest.php`)

### Task 3: The digest job for members
- **spec_ref**: `openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly`
- **files**: `lib/BackgroundJob/PublicationDigestJob.php`, `appinfo/info.xml`, `lib/Service/NotificationPreferenceService.php`, `tests/Unit/BackgroundJob/PublicationDigestJobTest.php`
- **acceptance_criteria**:
  - GIVEN a daily subscriber and five matching events since yesterday WHEN the 07:00 run happens THEN he gets one message listing five events grouped per meeting
  - GIVEN an immediate subscriber WHEN three edits happen within ten minutes THEN one message arrives after the 15 minute wait
  - GIVEN an event about a paper he may not read WHEN the job runs THEN it is left out
- [x] Implement
- [x] Test (a fixed clock: `tests/Unit/Service/PublicationDigestServiceTest.php`, `tests/Unit/BackgroundJob/PublicationDigestJobTest.php`, and `tests/Unit/Service/NotificationPreferenceServiceTest.php` for delivery on default preferences. The job and its rules live in `lib/Service/PublicationDigestService.php`; `publicationDigest` is an always-on event type, because the subscription is the opt-in)

### Task 4: Residents through the portal
- **spec_ref**: `openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-004-residents-subscribe-on-the-portal-and-receive-published-news-only`
- **files**: `lib/Portal/PortalContributionProvider.php`, `lib/BackgroundJob/PublicationDigestJob.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the citizen contribution WHEN read THEN it offers `subscribeToPublications` and lists `citizenSubscriptions`
  - GIVEN a resident subscription and one unpublished and two published events WHEN the job runs THEN one inbox notification lists the two published events
- Delivered in two pull requests: the first (subscriptions) ships the portal action `subscribeToPublications`, `unsubscribeFromPublications` and the `citizenSubscriptions` collection, with `tests/Unit/Portal/PortalContributionProviderTest.php`; the second (daily digest) ships the resident delivery in the digest job and ticks this task.
- [x] Implement
- [x] Test (`testAResidentGetsOneInboxNotificationWithThePublishedEventsOnly` validates the notification with Opis; the portal half in `tests/Unit/Portal/PortalContributionProviderTest.php`)

### Task 5: Members manage their subscriptions
- **spec_ref**: `openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often`
- **files**: `src/components/userSettings/SubscriptionsSection.vue`, the user settings page, `tests/e2e/publication-subscriptions.spec.ts`
- **acceptance_criteria**:
  - GIVEN a member on the settings page WHEN he subscribes to the council's agendas daily THEN the subscription is listed and can be removed
  - GIVEN the nc-input-labels gate WHEN run THEN it passes
- [x] Implement
- [x] Test (Playwright: `tests/e2e/publication-subscriptions.spec.ts`; the rules in `tests/vitest/publicationSubscriptions.spec.js`, payload validated against the merged schema)

## Verification

- `composer check:strict` and `npm run lint` once before push; `npm run test:l10n` for the new strings.
