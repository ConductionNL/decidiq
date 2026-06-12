# Design — User Settings v1

## Storage model: two tiers, one rule

**Rule: anything a server-side service must consult lives on the OpenRegister
preference object; anything only the SPA reads lives in IConfig user values.**

### Tier 1 — OpenRegister object (schema `notification-preference`)

One object per person (`person` = Nextcloud UID), register `decidesk`. Extended
properties:

| Property | Type | Default | Why OR |
|---|---|---|---|
| `meetingCreated` … `commentMention` | bool | true | existing event toggles |
| `meetingReminder` | bool | true | reminder dispatch consults it |
| `reminderTimes` | string[] enum `1h,4h,24h,48h,1w` | `["24h","1h"]` | reminder scheduling is server-side |
| `deliveryMethod` | enum `in-app,email,both` | `in-app` | dispatcher picks channels |
| `delegate` | string (NC UID) or null | null | notification fan-out + voting gate |
| `delegationFrom` / `delegationUntil` | date or null | null | automatic expiry is a server-side date comparison — no cron needed: every consult checks `from <= today <= until` |
| `governanceEmail` | email or null | null (falls back to NC account email) | mail dispatch reads it |
| `urgentPhone` | string or null | null | stored for secretariat use |
| `communicationLanguage` | enum `nl,en,de,fr,es,it` or null | null (NC locale) | mail templates can consult it |

This also fixes the pre-existing defect that the schema the service references was
never registered in `decidesk_register.json` (`RegisterJsonTest` count 34 → 35).

### Tier 2 — IConfig user values (existing `/api/preferences/{key}` endpoints)

Display preferences are pure UI state: `pref_default-view`, `pref_items-per-page`,
`pref_date-format`. The existing `PreferencesController` already provides per-user
sandboxed key/value storage — reusing it avoids a redundant controller (gate-17) and
keeps UI-only state out of the register.

Preferred UI language is **not** duplicated: Nextcloud core owns interface language;
the Display section links there. `communicationLanguage` (Tier 1) covers governance
correspondence.

## Authorization

Both notification-preference routes stay `@NoAdminRequired` and derive the person
exclusively from `IUserSession` — the request can never name another user, so there is
no IDOR surface. Same for the IConfig preference endpoints (pre-existing).

## Notification dispatch (preference-aware, delegation-aware)

New `NotificationPreferenceService::dispatch($personId, $eventType, $title, $message,
$deepLink)`:

1. Event filter — the *originating* person's toggle decides whether the event
   notifies at all (their delegate inherits exactly what they would have received).
2. Recipient expansion — `getNotificationRecipients()` returns `[person]` plus the
   active delegate when `delegationFrom <= now <= delegationUntil`.
3. Channel selection per recipient — each recipient's own `deliveryMethod` decides
   in-app (`OpenRegisterNotificationService`) and/or email (`OCP\Mail\IMailer`, to
   `governanceEmail` ?? account email).

Wired call sites:

- `DecisionNotificationService::notifyOnPublish` → `decisionPublished` (replaces the
  unconditional send).
- `VotingService::openVotingRound` → `votingOpened` to all meeting participants
  (decision/motion title + body + deadline per the spec scenario). Best-effort: failures
  are logged and never break the round opening.

Both call sites resolve the service from the DI container with an `instanceof` guard so
unit tests with a generic container mock stay green and a missing service degrades to
the previous behaviour.

Meeting reminders: `meetingReminder` + `reminderTimes` are stored and exposed now;
the reminder *scheduler* belongs to the meeting-notification capability and consults
`shouldNotify($uid, 'meetingReminder')` + `reminderTimes`. (Disabling the toggle
suppresses any decidesk reminder; calendar reminders are untouched because decidesk
never writes calendar VALARMs.)

## Voting gate (delegation ≠ proxy)

In `VotingService::castVote`, the existing proxy-grant check stays authoritative. The
only change: when no grant is found **and** the claimed delegator has an active absence
delegation to the casting participant, the error becomes the spec-mandated message —
"Delegation does not include voting rights. A formal proxy (volmacht) is required for
voting." — plus a pointer to the proxy-granting process
(`POST /apps/decidesk/api/voting-rounds/{id}/proxy`). Identifier note: the preference
`person` field stores the same identifier the caller uses (NC UID or participant UUID,
matching `findPreference`'s existing contract), so the consult is a direct lookup.

"Delegate can view the delegator's pending votes and action items" is satisfied
read-only: the delegate receives the same notifications with the same deep links;
no write access is granted.

## UI surfaces (one component set, three mounts)

Four section components under `src/components/userSettings/` share one logic module
`userPreferences.js` (load/save, validation, two-toggle ↔ `deliveryMethod` enum
mapping, date formatting). Mounts:

1. **Nextcloud personal settings** (`/settings/user/decidesk`) — `PersonalSettings`
   (ISettings) + `PersonalSection`, template mounts `src/personal.js` →
   `PersonalRoot.vue`. This is the spec's required standard surface and the gate-19
   Playwright target.
2. **SPA page** `/apps/decidesk/user-settings` — `UserSettingsPage.vue` via an ADR-037
   manifest fragment (`src/manifest.d/user-settings.json`) + registry entry, linked
   from the nav settings section ("Personal settings").
3. **`UserSettings.vue` dialog** — the existing (orphaned) dialog drops its empty
   state and renders the same sections, so any future dialog trigger is real.

Channel toggles: the UI shows two independent switches ("Nextcloud notification",
"Email") per the acceptance criteria and maps them onto the storage enum
(`in-app`/`email`/`both`); turning both off is rejected client-side with a hint,
because the enum has no `none` and per-event toggles already express "no
notifications".

Delegate picker: `NcSelect` (with `inputLabel`) fed by the standard sharees OCS
endpoint (user results only) — available to non-admin users, unlike the users
provisioning API.

Default view on boot: `main.js` reads `pref_default-view` (fire-and-forget fetch with
a short timeout) and `router.replace()`s when the user lands on `/` — deep links are
never overridden.

Date format: shared `formatDate()` in `userPreferences.js` honours
`pref_date-format` (`locale` default → `toLocaleDateString`).

## i18n

English source strings as keys (`t('decidesk', '…')`); new keys added to `l10n/en.json`
(extraction-checked by `tests/l10n/check-l10n.js`) with translations in `l10n/en_US.json`
and `l10n/nl.json` — the only l10n files that exist on development (repo reality; the
fleet 5-language program tracks de/fr/es/it separately) — following the existing
`{"translations": {...}, "plurals": ""}` shape.

## Test strategy

- **PHPUnit** `tests/Unit/Service/NotificationPreferenceServiceTest.php` (defaults
  merge, delegation window incl. boundary expiry, recipients fan-out, governance
  email fallback, dispatch channel matrix) and
  `tests/Unit/Controller/NotificationPreferenceControllerTest.php` (field
  whitelisting, validation rejections, user scoping); `VotingServiceTest` gains the
  delegation-message case.
- **Vitest** `tests/vitest/userPreferences.spec.js` — channel mapping, validation,
  date formatting, fetch envelope handling (axios mocked).
- **Playwright** `tests/e2e/spec-coverage/user-settings.spec.ts` — drives the personal
  settings panel UI per scenario.
- **Newman** `tests/integration/user-settings.postman_collection.json` — REST
  round-trips + validation failures (API assertions live here, not in Playwright).
