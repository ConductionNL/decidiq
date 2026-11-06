# Design: publication-subscriptions-and-daily-digest

Read at decidiq development `c0b2f5bb`.

## What exists

| Piece | Where |
|---|---|
| Agenda changes | `lib/Service/AgendaService.php:446` `notifyAgendaChanged()` (records an agenda version on every change to a published agenda), `publishAgenda()`, `reviseAgenda()` |
| Publication | `lib/Service/PublicationService.php:105` `publish(sourceType, sourceId, actorId)` (decisions, agendas, minutes to OpenCatalogi), `:201` `withdraw()`; `resolveBodyId()` gives the body |
| Papers arriving | `openspec/changes/agenda-office-files-to-pdf` (batch 1) specifies `OfficePaperAddedListener` on Nextcloud file events for agenda item and meeting folders |
| Per-person delivery | `lib/Service/NotificationPreferenceService.php:392` `dispatch()` (bell and email per member choice); in-app channel repaired by `agenda-change-notices-reach-members` (batch 1) |
| Member settings | `src/components/userSettings/NotificationPreferencesSection.vue`, `userPreferences.js` |
| Resident inbox | `lib/Portal/PortalContributionProvider.php:276` `citizenNotifications` (schema `notification`, `kind: inbox`, `scopeField: recipientId`); `:308` `citizenActions()` (create actions with `scopeField`, `minTrust`, `fields`, `defaults`) |
| Resident mail | portaliq sends a "new message in the portal" mail for inbox items (its matrix row `cmp-inb-email-alert`; its change `inbox-notifications-and-preferences` on portaliq development) |
| Jobs | `appinfo/info.xml:124-128` background jobs |

## Approach

### Schemas

Fragment `lib/Settings/register.d/113-publication-subscriptions.json` (92 was taken by the time this was built):

`PublicationSubscription` (slug `publication-subscription`):

| Property | Type | Notes |
|---|---|---|
| `subscriberUserId` | string | Nextcloud uid, for members |
| `subscriberRef` | string | portal subject reference, for residents |
| `governanceBodies` | array of uuid, `$ref` GovernanceBody | empty means all bodies |
| `kinds` | array of enum `agenda`, `paper`, `decision`, `minutes` | required |
| `frequency` | enum `immediate`, `daily`, `weekly` | default `daily` |
| `active` | boolean | default true |
| `lastSentAt` | date-time | set by the job |

Exactly one of `subscriberUserId` and `subscriberRef` is set. Authorization: read and write for the owner (`owner` match on `subscriberUserId`, plus the portal's `scopeField` for residents) and administrators.

`PublicationEvent` (slug `publication-event`): `kind`, `governanceBody`, `meeting`, `objectType`, `objectId`, `title`, `summary` (one line, for example "Agenda changed: item 4 added"), `isPublished` (whether a resident may hear of it), `occurredAt`. Written by decidiq only; read `decidiq-administrators`. Events older than 90 days are removed by the job.

### Recording events

`lib/Service/PublicationEventRecorder.php` with one method per source, called from:

- `AgendaService::publishAgenda()` and `notifyAgendaChanged()`: kind `agenda`, `isPublished` from the meeting's `isPublic` and publication state;
- `PublicationService::publish()`: kind `decision`, `minutes` or `agenda` by source type, `isPublished` true;
- the paper listener of `agenda-office-files-to-pdf`, for a paper added to a meeting whose agenda is published: kind `paper`.

### Sending

`lib/BackgroundJob/PublicationDigestJob.php`, a `TimedJob` every 15 minutes, registered in `appinfo/info.xml`:

1. For each active subscription due (immediate: always; daily: first run after 07:00 not yet sent today; weekly: first run on Monday after 07:00), collect events since `lastSentAt` that match its bodies and kinds, dropping events newer than 15 minutes for immediate subscriptions so an editing session arrives as one message.
2. Members: keep events whose object the member may read now (OpenRegister `ObjectService::find()` under that user), then one `NotificationPreferenceService::dispatch()` call with event type `publicationDigest`, a title such as "3 updates from the municipal council" and a message listing the events grouped per body and meeting with links.
3. Residents: keep events with `isPublished` true, then write one `notification` object with `recipientId` = `subscriberRef`, type `publication-digest`, subject and content as above. portaliq shows it in the resident's inbox and mails it on.
4. Set `lastSentAt`.

### Subscribing

- Members: a Subscriptions section on the user settings page (`src/components/userSettings/SubscriptionsSection.vue`), listing, adding and removing their subscriptions through the OpenRegister API.
- Residents: `citizenActions()` gets `subscribeToPublications` (create, schema `publication-subscription`, `scopeField: subscriberRef`, `minTrust: low`, fields `governanceBodies`, `kinds`, `frequency`) and `citizenCollections()` gets `citizenSubscriptions` (read their own, with an unsubscribe that sets `active` false).

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Subscriptions and events as data, read rules | Declarative schemas | Plain data |
| Recording events | Imperative, in the existing services | The sources are imperative services today |
| Grouping and sending digests | Imperative scheduled job | ADR-031 exception: scheduled bulk work across many objects with a per-recipient read check; `x-openregister-notifications` sends one notice per object change and cannot batch into a digest |
| Resident subscribing | Declarative portal contribution | ADR-046 contract |

## Seed data

Municipality example set: a member subscription of the admin user to the municipal council, kinds agenda and paper, frequency immediate; a resident subscription with subject reference `example-resident`, all bodies, kinds agenda and decision, daily; and four `PublicationEvent` rows of last week, so the first job run on a fresh install sends one digest of each kind.

## Files

- `lib/Settings/register.d/113-publication-subscriptions.json`, `lib/Settings/profiles/municipality.json`
- `lib/Service/PublicationEventRecorder.php`, `lib/Service/AgendaService.php`, `lib/Service/PublicationService.php`, the paper listener from `agenda-office-files-to-pdf`
- `lib/BackgroundJob/PublicationDigestJob.php`, `appinfo/info.xml`, `lib/Service/NotificationPreferenceService.php` (event type `publicationDigest`)
- `lib/Portal/PortalContributionProvider.php`
- `src/components/userSettings/SubscriptionsSection.vue`, the user settings page that hosts the sections
- `tests/Unit/BackgroundJob/PublicationDigestJobTest.php`, `tests/Unit/Service/PublicationEventRecorderTest.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/e2e/publication-subscriptions.spec.ts`
