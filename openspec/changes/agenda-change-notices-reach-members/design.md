# Design: agenda-change-notices-reach-members

Read at decidiq development `4d7430ff`.

## What exists

| Piece | Where |
|---|---|
| Agenda change notice | `lib/Service/AgendaService.php:446` `notifyAgendaChanged()` records a version and calls `notifyParticipants(meetingId, 'agenda_changed')` (`:472`); `:570` `notifyParticipants()` loops `ParticipantResolver::resolveMeetingParticipants()` and skips those with `leftAt`; user id from `owner` (`:580`); `:175-194` `sendAgendaNotification()` builds `setApp('decidiq')->setObject('meeting', id)->setSubject(subject, ['meetingId' => id])` |
| Listener | `lib/Listener/AgendaItemChangeListener.php:86`, registered `lib/AppInfo/Registrar/ObjectListenerRegistrar.php:132` |
| Other `decidiq` notices | `lib/Service/MotionNotifier.php:76-83`, `lib/Service/ProxyDelegationService.php:229-236` (per decidiq#1381) |
| Preference-aware dispatch | `lib/Service/NotificationPreferenceService.php:47` `DEFAULTS`, `:205` `shouldNotify()`, `:392` `dispatch()`, `:431` `sendInApp()` resolving `OpenRegisterNotificationService` (does not exist), `sendEmail()` through `IMailer` |
| Toggles | `src/components/userSettings/NotificationPreferencesSection.vue`, `src/components/userSettings/userPreferences.js`, validator `lib/Service/NotificationPreferenceRequestValidator.php` |
| Registration seam | `lib/AppInfo/Registrar/PlatformIntegrationRegistrar.php` |

## Approach

1. **Notifier.** `lib/Notification/Notifier.php` implements `OCP\Notification\INotifier`, registered with `$context->registerNotifierService()` in `PlatformIntegrationRegistrar`. `prepare()` throws `UnknownNotificationException` unless `getApp() === 'decidiq'`. It maps each known subject to a translated parsed subject and message, sets the icon, and sets a link built with `IURLGenerator::linkToRouteAbsolute('decidiq.dashboard.page')` (route at `appinfo/routes.php:345`) plus the object route (`/meetings/{id}`, `/motions/{id}`, `/decisions/{id}`). Subjects: `agenda_published`, `agenda_revised`, `agenda_revision_started`, `agenda_changed`, the subjects `MotionNotifier` sends, `proxy_granted`, and `decidiq_message` (the generic subject step 2 uses).
2. **In-app through decidiq.** `NotificationPreferenceService::sendInApp()` creates `setApp('decidiq')->setSubject('decidiq_message', ['title' => ..., 'message' => ..., 'link' => ...])` and calls `IManager::notify()`. The notifier renders `decidiq_message` from its parameters. This repairs every caller of `dispatch()` at once.
3. **Agenda notices honour preferences.** `notifyParticipants()` calls `dispatch(personId, 'agendaChanged', title, message, deepLink)` for `agenda_changed`, `agenda_revised` and `agenda_published`. `agendaChanged` joins `DEFAULTS` (true), the validator's allowed keys and the settings toggles. The person id resolves from `nextcloudUserId`, then `owner`.
4. **Coalescing.** Before dispatching `agendaChanged`, the service checks the meeting's last notice time per recipient (kept on the meeting as `agendaNoticeSentAt`, a map of uid to time, in the existing `91-agenda-publication-has-its-own-fields.json` fragment) and skips when under five minutes. The version record is still written every time.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Rendering notices | Imperative `INotifier` | Nextcloud's API; there is no declarative notifier |
| Who is told | Imperative, existing service | ADR-031 exception: the recipients are active participants resolved through governance body memberships, which no `recipients[]` entry in `x-openregister-notifications` can express |
| Per-member channel | Existing `NotificationPreferenceService` | Already the one place preferences are read |

gate-18 warns on imperative object notifications in a leaf app; this change adds no new sender, it repairs the delivery of existing ones.

## Seed data

No new schema. The fragment `91-agenda-publication-has-its-own-fields.json` gains `agendaNoticeSentAt` (object, map of uid to date-time) on `Meeting`, empty in the example sets.

## Files

- `lib/Notification/Notifier.php` (new), `lib/AppInfo/Registrar/PlatformIntegrationRegistrar.php`
- `lib/Service/AgendaService.php`, `lib/Service/NotificationPreferenceService.php`, `lib/Service/NotificationPreferenceRequestValidator.php`
- `lib/Settings/register.d/91-agenda-publication-has-its-own-fields.json`
- `src/components/userSettings/NotificationPreferencesSection.vue`, `src/components/userSettings/userPreferences.js`, `l10n/*`
- `tests/Unit/Notification/NotifierTest.php` (real `OC\Notification\Notification` via `IManager::createNotification()` in the server test harness, or a double built with `onlyMethods`), `tests/Unit/Service/AgendaServiceTest.php`, `tests/Unit/Service/NotificationPreferenceServiceTest.php`, `tests/e2e/agenda-change-notices.spec.ts`
