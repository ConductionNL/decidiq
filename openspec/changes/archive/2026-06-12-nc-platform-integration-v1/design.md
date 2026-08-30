# Design: NC platform integration v1

## Context

Decidesk stores all governance objects (meetings, decisions, voting rounds, resolutions, board meetings) in OpenRegister. Existing platform hooks follow two patterns this change reuses:

- **OR event listeners** — `BoardMeetingCalDavBridge` subscribes to `OCA\OpenRegister\Event\ObjectCreatedEvent`/`ObjectUpdatedEvent`, filters on schema slug, fails soft (catch `\Throwable`, log).
- **Container-resolved services** — services receive `Psr\Container\ContainerInterface` and resolve OR's `ObjectService`/`FileService` lazily, degrading gracefully when OR is absent.

## Decisions

### D1 — Activity registration via info.xml, not IRegistrationContext

Verified against the NC32 server source (`lib/public/AppFramework/Bootstrap/IRegistrationContext.php`): there is **no** `registerActivityProvider`/`registerActivitySetting` method. Activity providers, settings, and filters are declared in `appinfo/info.xml` under `<activity><settings/><filters/><providers/></activity>` (reference: `apps/files/appinfo/info.xml`). The fleet has shipped broken registrations from invented `register*` methods before (decidesk `registerRepairStep` bug, fleet `registerJob` bugs) — this change registers ONLY through mechanisms verified in the server source:

- Activity provider/setting/filter → `info.xml` `<activity>`.
- Search provider → `IRegistrationContext::registerSearchProvider()` (verified present).
- Background job → `info.xml` `<background-jobs>` (the pattern that already schedules `OverdueActionItemsJob` + `TranslationQueueJob`).
- Event listener → `IRegistrationContext::registerEventListener()` (existing pattern).

### D2 — One activity type, rich subjects parsed by one provider

A single activity type `decidesk_governance` (one `ActivitySettings` subclass, default-enabled for notifications, one `IFilter` scoped to the app) keeps the user's Activity settings page compact. `DecideskProvider::parse()` translates five subject identifiers — `decision_recorded`, `decision_published`, `meeting_transition`, `vote_initiated`, `resolution_adopted` — into parsed + rich subjects using `OCP\L10N\IFactory` in the event's language. Subject parameters carry `{title, status, uuid, segment}` so the provider can build the deep link (`/apps/decidesk/#/<segment>/<uuid>`) without re-fetching objects. `IEvent::setObject()` requires an `int` object id; OR uuids are strings, so the uuid travels in the subject parameters and `crc32(uuid)` is used as the numeric object id (only used by Activity for grouping).

### D3 — ActivityPublisherService: fail-soft, audience = governing-body members

`ActivityPublisherService::publishGovernanceEvent()` wraps `OCP\Activity\IManager`. Audience resolution ("visible to all members of the governing body") uses the canonical `ParticipantResolver::resolveMeetingParticipants()` → `nextcloudUserId` (legacy `owner` fallback) when a meeting id is available; the acting session user is always included so an event is never published to nobody. Every call site wraps the publish in `try/catch (\Throwable)` + debug log: Activity is an observability surface and must never make `transition()`/`openVotingRound()`/`conclude()` fail (mirrors the CalDAV bridge posture). Board-portal call sites (`BoardMeetingService`, `ResolutionService`) pass the board-meeting's resolved audience when cheaply available and otherwise fall back to the acting user — honest, bounded behaviour instead of an expensive cross-register fan-out.

### D4 — Search visibility is delegated to OR RBAC (security requirement)

`DecideskSearchProvider::search(IUser $user, ISearchQuery $query)` runs inside the searching user's session and calls `ObjectService::findAll(['search' => $term, 'limit' => …])` per schema (`decision`, `meeting`, `resolution`) with RBAC **enabled** (the `findAll(…, $_rbac = true)` default). OR's MagicMapper applies per-user read ACLs at query time — the same mechanism the app's controllers already rely on ("ObjectService::find() returns null when the caller lacks read access", `MeetingService::transition`). The provider therefore never widens visibility: a user only finds objects they could open in the app. No `_rbac: false` anywhere in the provider. Results map to `SearchResultEntry` (app icon thumbnail, title, status subline, absolute deep link). `getOrder()` returns `-1` inside the app route, `25` elsewhere.

### D5 — Meeting folders: OR FileService pattern; ACL residue documented

`MeetingFolderService::ensureMeetingFolders()` builds `Decidesk/<body name>/<YYYY-MM-DD> <title>/` (+ `Agenda Documents`, `Minutes`) and creates it via OpenRegister `FileService::createFolder()` — the exact pattern of `VotingService::createDossierFolder()`. `createFolder` is idempotent (get-or-create under the OpenRegister root), so re-running on update events is safe. Path components are sanitised (`/`, `\` and control chars stripped) to prevent path traversal via meeting titles. The trigger is `MeetingFolderListener` on OR `ObjectCreatedEvent` for schema `meeting` (covers both app-UI and OR-API creation paths).

**Honest limitation (spec residue):** the spec scenario asks for per-member ACLs ("all body members read, secretary and chair write"). Plain `OCP\Files` exposes no per-folder ACL API to app code — real ACLs require the **groupfolders** app (its `ACLManager` is app-internal, not OCP). Folders created under the OR root inherit OpenRegister's register sharing, which is the decidesk RBAC source of truth, but role-differentiated read-vs-write per body member is NOT enforced by this change. The spec delta rewrites the ACL line to the implementable guarantee and records the groupfolders requirement; the main spec status-note keeps this as named residue.

### D6 — Deadline reminder: pure window logic in a service, thin TimedJob

`VotingDeadlineReminderService` owns all logic so it is unit-testable without scheduler plumbing:

- `isWithinReminderWindow(now, deadline)` — pure: `now < deadline && deadline <= now + 24h`.
- `findRoundsNeedingReminder(now)` — open rounds (`result` empty, `closedAt` set) inside the window without a `deadlineReminderSentAt` marker.
- `remindRound(round, now)` — resolves the round's motion → meeting → participants (the relation walk already used by `VotingService::saveShowOfHandsTally`), skips participants who already voted in the round, maps `nextcloudUserId`, sends per-user notifications via the existing `OpenRegisterNotificationService` container key (the pattern of `DecisionNotificationService`/`ALVMinutesService`) with an English-source, per-user-language translated body, then stamps `deadlineReminderSentAt` via `saveObject` so the hourly job never double-reminds.

`VotingDeadlineReminderJob` extends `TimedJob`, `setInterval(3600)`, and delegates `run()` to the service; registered in `info.xml` `<background-jobs>`. Background jobs run without a user session, matching how `OverdueActionItemsJob` already reads/writes OR objects.

### D7 — i18n

All new user-visible strings (activity subjects, activity setting/filter names, search provider name/sublines, reminder notification) use ENGLISH source keys with translations in `l10n/{en,nl,de,fr,es,it}.json`. `de/fr/es/it.json` are new files carrying this change's keys (Nextcloud merges partial catalogues; untranslated legacy keys fall back to English).

## Risks / Trade-offs

- **Audience fan-out cost**: publishing one IEvent per body member is O(members) per transition; governance bodies are small (≤ ~50). Acceptable; no batching needed.
- **Reminder marker write**: stamping `deadlineReminderSentAt` mutates the voting-round object (schema allows additional properties via OR). Alternative (appconfig bookkeeping) would leak unbounded keys; object marker chosen.
- **Search term semantics**: OR `findAll(search:)` is full-text per OR's search handler; result ordering is OR's. Good enough for v1; `IFilteringProvider` facets deferred.

## Migration

None — additive only. No schema changes, no routes, no data migrations.
