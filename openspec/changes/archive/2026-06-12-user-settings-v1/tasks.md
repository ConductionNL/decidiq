# Tasks — user-settings-v1

## 1. Schema & storage

- [x] 1.1 Register the `NotificationPreference` schema (slug `notification-preference`) in
      `lib/Settings/decidesk_register.json` with the extended property set
      (event toggles + `meetingReminder`, `reminderTimes`, `deliveryMethod`,
      `delegate`, `delegationFrom`, `delegationUntil`, `governanceEmail`,
      `urgentPhone`, `communicationLanguage`). Fixes the pre-existing defect
      that the service referenced a schema the register never imported.
- [x] 1.2 Update `tests/Unit/RegisterJsonTest.php` (34 → 35 schemas, expected-name list).

## 2. Backend services

- [x] 2.1 Extend `NotificationPreferenceService` DEFAULTS with the new fields and add
      `getPreferenceWithDefaults()`.
- [x] 2.2 Add the delegation consult API: `getActiveDelegate()`,
      `hasActiveDelegationTo()` (matches delegate by NC UID or participant UUID,
      active only while `delegationFrom <= today <= delegationUntil` — automatic
      expiry is a date comparison, no cron), `getNotificationRecipients()`.
- [x] 2.3 Add `getGovernanceEmail()` (preference override → NC account email fallback).
- [x] 2.4 Add preference-aware, delegation-aware `dispatch()` (event filter on the
      originating person, recipient fan-out to the active delegate, per-recipient
      channel selection in-app and/or email).
- [x] 2.5 Route `DecisionNotificationService::notifyOnPublish` through `dispatch()`
      (`decisionPublished`), with graceful fallback to the previous direct send.
- [x] 2.6 `VotingService::openVotingRound` dispatches `votingOpened` to all meeting
      participants with decision/motion title, body and voting deadline (fail-soft).
- [x] 2.7 `VotingService::castVote` delegation gate: when no formal proxy grant exists
      and the claimed delegator has an active absence delegation to the caster,
      reject with "Delegation does not include voting rights. A formal proxy
      (volmacht) is required for voting." plus a pointer to the proxy-granting
      endpoint. Existing proxy mechanics stay authoritative and unchanged.

## 3. REST surface

- [x] 3.1 `NotificationPreferenceController::update` accepts + validates the new fields
      (reminderTimes whitelist, delegation period sanity incl. mandatory expiry,
      e-mail format, phone shape, communication-language whitelist) — 422 on bad input.
- [x] 3.2 `NotificationPreferenceController::show` returns the defaults-merged
      preference plus `accountEmail` so the UI can show the Nextcloud default.
- [x] 3.3 Display preferences reuse the existing per-user `/api/preferences/{key}`
      endpoints (IConfig user values) — no new routes.

## 4. Nextcloud personal settings panel (OCP\Settings\ISettings)

- [x] 4.1 `lib/Settings/PersonalSettings.php` + `lib/Sections/PersonalSection.php` +
      `templates/settings/personal.php`, registered in `appinfo/info.xml`.
- [x] 4.2 `src/personal.js` webpack entry mounting `PersonalRoot.vue`.

## 5. Frontend

- [x] 5.1 `src/components/userSettings/userPreferences.js` logic module (fetch/save,
      channel ↔ `deliveryMethod` enum mapping, validation, `formatDate()`).
- [x] 5.2 Four section components under `src/components/userSettings/`
      (Notification / Display / Delegation / Communication).
- [x] 5.3 `src/views/settings/PersonalRoot.vue` (personal settings mount).
- [x] 5.4 `src/views/settings/UserSettingsPage.vue` SPA page + manifest fragment
      `src/manifest.d/user-settings.json` + registry entry + settings-section menu link.
- [x] 5.5 Replace the `UserSettings.vue` empty state with the real sections.
- [x] 5.6 `src/main.js` honours the `default-view` display preference on boot
      (router.replace on `/` only — deep links never overridden).

## 6. i18n

- [x] 6.1 English source keys; translations added to `l10n/en.json`,
      `l10n/en_US.json` and `l10n/nl.json` (repo reality — the only l10n files
      on development).

## 7. Tests

- [x] 7.1 PHPUnit: `NotificationPreferenceServiceTest` (defaults merge, delegation
      window incl. boundary expiry, recipient fan-out, governance-email fallback,
      dispatch channel matrix), `NotificationPreferenceControllerTest` (validation
      rejections, session-user scoping), `VotingServiceDelegationGateTest`
      (delegation-without-proxy message + generic no-proxy fallback).
- [x] 7.2 Vitest: `tests/vitest/userPreferences.spec.js` (channel mapping,
      validation, date formatting, fetch envelope handling).
- [x] 7.3 Playwright: `tests/e2e/spec-coverage/user-settings.spec.ts` referencing the
      spec scenarios with `@e2e` annotations (defensive skips).
- [x] 7.4 Newman: `tests/integration/decidesk-user-settings.postman_collection.json`
      (round-trips, validation 422s, 401 posture, cast-vote delegation negative),
      wired into `tests/newman/run-all.sh`.

## 8. Verification & archive

- [x] 8.1 `php -l` on every changed PHP file; `npm run build`; PHPUnit + vitest green.
- [x] 8.2 All hydra gates green (`run-hydra-gates.sh`).
- [x] 8.3 `openspec validate user-settings-v1`, archive, flip the seeded spec to
      `status: done`.
