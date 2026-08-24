---
kind: code
---

# Proposal: User Settings v1 — Personal Preferences for Decidesk

## Problem

The seeded spec `openspec/specs/user-settings/spec.md` (status: idea, 0/4 requirements
built) defines four MVP requirements — Notification Preferences, Display Preferences,
Delegation and Absence, and Communication Preferences — but none of them have a user
surface. `src/views/settings/UserSettings.vue` ships an empty state ("No settings
available yet"), and only a minimal backend pair exists
(`NotificationPreferenceService` + `NotificationPreferenceController`, covering five
event toggles and one delivery-method enum).

Concrete gaps:

- The `notification-preference` schema the service reads/writes is **not registered**
  in `lib/Settings/decidesk_register.json` (pre-existing defect — the service
  references a schema OpenRegister never imports).
- No meeting-reminder event type and no reminder-timing configuration (spec default:
  24h + 1h before).
- No display preferences (default landing view, items per page, date format).
- No absence delegation (delegate, period, automatic expiry, notification fan-out,
  proxy-voting gate with the "volmacht required" message).
- No communication preferences (governance email override, urgent phone, preferred
  communication language).
- No Nextcloud personal settings panel (`OCP\Settings\ISettings`), which the spec's
  acceptance criteria require.

## What Changes

- **MODIFIED** `lib/Settings/decidesk_register.json` — register the
  `NotificationPreference` schema (slug `notification-preference`) and extend it with
  meeting-reminder, reminder-timing, delegation, and communication properties.
- **MODIFIED** `lib/Service/NotificationPreferenceService.php` — new defaults
  (`meetingReminder`, `reminderTimes`, delegation + communication fields) and new
  consult API: `getActiveDelegate()`, `hasActiveDelegationTo()`,
  `getNotificationRecipients()`, `getGovernanceEmail()`, and a preference-aware
  `dispatch()` that fans notifications out to the active delegate and honours each
  recipient's delivery method (Nextcloud notification and/or email).
- **MODIFIED** `lib/Controller/NotificationPreferenceController.php` — accept and
  validate the new fields (reminder times whitelist, delegation period sanity,
  e-mail format); responses include the account e-mail so the UI can show the
  Nextcloud default.
- **MODIFIED** `lib/Service/DecisionNotificationService.php` — route decision-published
  notifications through the preference-aware dispatcher instead of unconditional sends.
- **MODIFIED** `lib/Service/VotingService.php` — dispatch `votingOpened` notifications
  when a voting round opens (decision title, body, deadline), and block a delegate
  casting a proxy vote without a formal proxy with the spec-mandated message and a
  pointer to the proxy-granting endpoint.
- **NEW** `lib/Settings/PersonalSettings.php` + `lib/Sections/PersonalSection.php` +
  `templates/settings/personal.php` + `src/personal.js` webpack entry — Nextcloud
  personal settings panel per `OCP\Settings\ISettings`.
- **NEW** `src/components/userSettings/` — four section components
  (Notification / Display / Delegation / Communication) + a `userPreferences.js`
  logic module (fetch/save/validation, channel↔enum mapping, date formatting).
- **MODIFIED** `src/views/settings/UserSettings.vue` — replace the empty state with
  the four sections; **NEW** `src/views/settings/UserSettingsPage.vue` SPA page
  (manifest fragment `src/manifest.d/user-settings.json`, registry entry).
- **MODIFIED** `src/main.js` — honour the `default-view` display preference on boot.
- Display preferences persist via the existing per-user `/api/preferences/{key}`
  endpoints (IConfig user values) — no new routes needed for them.
- Tests: PHPUnit (service + controller), vitest (userPreferences logic), Playwright
  spec-coverage suite, Newman collection for the REST surface.
- i18n: English-source keys with en/en_US/nl translations (the l10n files that
  exist on development; de/fr/es/it are tracked by the fleet 5-language program).

## Goals

1. All four seeded requirements implemented and traceable (gate-16 `@spec`,
   gate-19 `@e2e`).
2. Per-user scoping everywhere — no IDOR; a user can only read/write their own
   preference object.
3. Server-side consumability: delegation and governance e-mail live on the OR object
   so notification dispatch and voting guards can consult them.

## Non-Goals

- A formal proxy (volmacht) granting UI — the existing voting-round proxy endpoints
  remain the granting process; this change only gates and signposts it.
- Retroactive re-formatting of every historic date render in third-party/lib-rendered
  surfaces (CnIndexPage cell renderers) — the date-format preference is applied via the
  shared formatter consumed by app-owned views.
- SMS delivery (the citizen Notification schema's `sms` channel is out of scope for
  personal governance preferences).

## Impact

- New REST fields on an existing per-user endpoint; backwards compatible (all new
  fields optional, defaults preserved).
- One new schema in the register import; additive, no data migration.
- Voting cast path gains one extra guard branch (delegation-aware message); behaviour
  for valid proxies is unchanged.
