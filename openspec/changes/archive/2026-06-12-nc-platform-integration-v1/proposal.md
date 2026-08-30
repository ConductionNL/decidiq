# Proposal: Nextcloud platform integration v1 (Activity, Search, Files folders, deadline reminders)

## Why

The seeded `nextcloud-integration` spec is `status: partial` (2026-06-12 audit): ~3/6 requirements are delivered through the Talk/Deck/Email leaves, `BoardCalDavSyncService` (calendar), and the existing notification senders. Four platform hooks are still missing:

1. **Activity feed** — no `OCP\Activity` provider exists; governance events (decision status changes, meeting lifecycle transitions, vote initiation, resolution adoption) never reach the Nextcloud Activity stream.
2. **Universal search** — decidesk registers deep-link patterns with OpenRegister's unified search provider, but ships no `OCP\Search\IProvider` of its own, so decisions/meetings/resolutions are invisible in Nextcloud's universal search when OR's provider is disabled or scoped out.
3. **Meeting Files folders** — the spec'd `Decidesk/<body>/<date> <title>/` folder structure with `Agenda Documents` + `Minutes` subfolders is not created on meeting creation (only adopted-motion dossier folders exist, in `VotingService`).
4. **Voting deadline reminder** — the spec'd 24h-before-deadline reminder notification has no background job; voters who have not voted get no warning.

## What Changes

- **Activity integration**: `lib/Activity/` provider + setting + filter registered via `appinfo/info.xml` `<activity>` (NC32 Activity API — `IRegistrationContext` has no activity registration method). A new `ActivityPublisherService` publishes entries from the existing transition call sites: `MeetingService::transition`, `BoardMeetingService::runLifecycleTransition`, `VotingService::openVotingRound`, `ResolutionService::openVote`/`conclude`, `LiveDecisionService::recordDecision`, `DecisionController::publish`. Publication is fail-soft — an Activity failure never breaks the governance transition.
- **Universal search**: `lib/Search/DecideskSearchProvider` (`OCP\Search\IProvider`, registered via `registerSearchProvider`) searches decisions, meetings, and resolutions via OR `ObjectService::findAll(search: …)` under the searching user's session, so OR RBAC visibility filtering applies (security requirement: only objects the user may read are returned). Results deep-link to `/apps/decidesk/#/{decisions|meetings|resolutions}/{uuid}`.
- **Meeting Files folders**: `MeetingFolderService` + `MeetingFolderListener` (OR `ObjectCreatedEvent`) create `Decidesk/<body>/<date> <title>/` with `Agenda Documents` and `Minutes` subfolders on meeting creation, reusing the OpenRegister `FileService::createFolder` pattern from `VotingService::createDossierFolder`. Per-member read/write ACLs are NOT implementable from plain `OCP\Files` app code without the groupfolders app — the folder lives under the OR-managed root whose access follows OR register sharing; the ACL residue is documented honestly in design.md and the spec delta.
- **Voting deadline reminder**: `VotingDeadlineReminderService` (pure, unit-testable 24h-window calculation + recipient resolution skipping participants who already voted) driven by `VotingDeadlineReminderJob` (`TimedJob`, hourly), registered in `appinfo/info.xml` `<background-jobs>` (the proven decidesk pattern — `OverdueActionItemsJob`/`TranslationQueueJob`; never the invalid `IRegistrationContext::registerJob`). A `deadlineReminderSentAt` marker on the voting-round prevents duplicate reminders.

## Capabilities

### Modified Capabilities

- `nextcloud-integration`: Activity Integration requirement implemented (provider + publication call sites); Search Integration requirement implemented (per-user-visibility-filtered provider); Files Integration meeting-folder scenario implemented with an honest ACL clarification; Notification Integration deadline-reminder scenario implemented via background job.

## Impact

- **New code**: `lib/Activity/{DecideskProvider,GovernanceSetting,GovernanceFilter}.php`, `lib/Search/DecideskSearchProvider.php`, `lib/Service/{ActivityPublisherService,MeetingFolderService,VotingDeadlineReminderService}.php`, `lib/Listener/MeetingFolderListener.php`, `lib/BackgroundJob/VotingDeadlineReminderJob.php`.
- **Modified**: `lib/AppInfo/Application.php` (search provider + service/listener registrations), `appinfo/info.xml` (`<activity>` block, reminder job), the six transition call sites listed above, `l10n/` (en/nl/de/fr/es/it strings for activity subjects, search labels, reminder notifications).
- **No new HTTP endpoints** — all four gaps are platform providers/jobs, so no routes, no Newman delta, no IDOR surface. Existing endpoints unchanged.
- **Tests**: PHPUnit for the provider, search provider, folder service, publisher, and the reminder window/selection logic. No Playwright delta — all surfaces are NC platform chrome (per-scenario `@e2e exclude` with reasons).
