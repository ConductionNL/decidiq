# Tasks: NC platform integration v1

## 1. Activity feed

- [x] 1.1 `lib/Activity/GovernanceSetting.php` — `ActivitySettings` subclass, identifier `decidesk_governance`, translated name, group `other`, notification default-enabled.
- [x] 1.2 `lib/Activity/GovernanceFilter.php` — `OCP\Activity\IFilter` scoped to app `decidesk`, allowed type `decidesk_governance`, app icon.
- [x] 1.3 `lib/Activity/DecideskProvider.php` — `OCP\Activity\IProvider::parse()` for subjects `decision_recorded`, `decision_published`, `meeting_transition`, `vote_initiated`, `resolution_adopted`; parsed + rich subjects via `OCP\L10N\IFactory`; deep link + app icon; throws `UnknownActivityException` for foreign events.
- [x] 1.4 `lib/Service/ActivityPublisherService.php` — fail-soft publisher over `OCP\Activity\IManager`; audience resolution via `ParticipantResolver` (nextcloudUserId, owner fallback) + acting session user; `crc32(uuid)` numeric object id.
- [x] 1.5 Register: `appinfo/info.xml` `<activity>` block (settings/filters/providers); `Application::register()` service factory for `ActivityPublisherService`.
- [x] 1.6 Hook call sites (each fail-soft try/catch): `MeetingService::transition`, `BoardMeetingService::runLifecycleTransition`, `VotingService::openVotingRound`, `ResolutionService::openVote`, `ResolutionService::conclude` (adopted), `LiveDecisionService::recordDecision`, `DecisionController::publish`.

## 2. Universal search

- [x] 2.1 `lib/Search/DecideskSearchProvider.php` — `OCP\Search\IProvider`; searches `decision`, `meeting`, `resolution` schemas via `ObjectService::findAll(['search' => term, 'limit' => …])` with OR RBAC enabled (per-user visibility — security requirement); `SearchResultEntry` with app icon, title, status subline, deep link `/apps/decidesk/#/{segment}/{uuid}`; `getOrder()` -1 in-app / 25 global; empty term short-circuits.
- [x] 2.2 Register via `IRegistrationContext::registerSearchProvider()` in `Application::register()`.

## 3. Meeting Files folders

- [x] 3.1 `lib/Service/MeetingFolderService.php` — `ensureMeetingFolders(meeting)` builds sanitised `Decidesk/<body>/<date> <title>/` + `Agenda Documents` + `Minutes` and creates them idempotently via OR `FileService::createFolder` (dossier-folder pattern); body name resolved from the meeting's governance-body relation.
- [x] 3.2 `lib/Listener/MeetingFolderListener.php` — OR `ObjectCreatedEvent`, schema filter `meeting`, fail-soft; registered via `registerEventListener` + service factory in `Application::register()`.
- [x] 3.3 Document the ACL limitation (groupfolders required for per-member read/write differentiation) in design.md + spec delta.

## 4. Voting deadline reminder

- [x] 4.1 `lib/Service/VotingDeadlineReminderService.php` — `isWithinReminderWindow()` (pure 24h window), `findRoundsNeedingReminder()` (open rounds in window, no `deadlineReminderSentAt`), `remindRound()` (motion→meeting→participants walk, skip already-voted, per-user-language notification via `OpenRegisterNotificationService`, stamp marker via `saveObject`).
- [x] 4.2 `lib/BackgroundJob/VotingDeadlineReminderJob.php` — `TimedJob`, hourly, delegates to the service; registered in `appinfo/info.xml` `<background-jobs>` (proven decidesk job pattern).

## 5. i18n

- [x] 5.1 English-source strings for activity subjects, setting/filter names, search provider name + sublines, reminder notification in `l10n/en.json`; translations in `l10n/{nl,de,fr,es,it}.json` (de/fr/es/it new files with this change's keys).

## 6. Tests + gates

- [x] 6.1 PHPUnit: `DecideskProviderTest` (subject parsing, language, UnknownActivityException), `GovernanceSettingTest`/`GovernanceFilterTest`, `ActivityPublisherServiceTest` (audience resolution, fail-soft), `DecideskSearchProviderTest` (id/name/order, RBAC-delegated search mapping, empty term), `MeetingFolderServiceTest` (path building/sanitisation, subfolders, missing-OR degradation), `VotingDeadlineReminderServiceTest` (window calculation edges, round selection, already-voted skip, marker stamping), `VotingDeadlineReminderJobTest` (interval + delegation), `MeetingFolderListenerTest` (schema filter, fail-soft).
- [x] 6.2 `php -l` on all changed PHP; full unit suite green via `phpunit-unit.xml`.
- [x] 6.3 `@spec openspec/specs/nextcloud-integration/spec.md` tags on all new/changed methods; SPDX headers on all new files; hydra gates pass on the diff.
- [x] 6.4 Update main spec `openspec/specs/nextcloud-integration/spec.md` status/status-note honestly after archive.
