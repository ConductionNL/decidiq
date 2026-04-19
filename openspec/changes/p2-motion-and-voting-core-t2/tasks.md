## Deduplication Check (ADR-012)

- [ ] 0.1 Confirm live tally polling reuses `objectStore.fetchObjects()` — no custom polling endpoint or WebSocket server needed
- [ ] 0.2 Confirm vote behaviour aggregation uses `ObjectService.findAll()` with participant/round filters — no custom analytics entity or separate aggregation store
- [ ] 0.3 Confirm anonymisation uses `ObjectService.saveObject()` per Vote object — no custom delete or archive mechanism
- [ ] 0.4 Confirm `CnChartWidget` (ApexCharts, provided by `@conduction/nextcloud-vue`) covers the voting history donut chart — no custom chart component
- [ ] 0.5 Confirm the `diff` npm library is a transitive dependency of `@conduction/nextcloud-vue` or already in `package.json` — do NOT add a duplicate if already present; document finding either way
- [ ] 0.6 Confirm `IAppConfig` covers voting group presets and motion forwarding flags — no new OpenRegister entity proposed

## 1. Backend — VotingBehaviourService and VotingBehaviourController

- [ ] 1.1 Create `lib/Service/VotingBehaviourService.php` — stateless service tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1` with method:
  - `getStats(string $participantId, string $governanceBodyId): array` — calls `ObjectService.findAll()` to fetch all closed VotingRounds for the body; for each round fetches Votes where `participantId` matches; computes `totalRounds`, `participated`, `participationRate`, `votesFor`, `votesAgainst`, `votesAbstain`, `proxiesGiven`, `proxiesReceived`; returns associative array
- [ ] 1.2 Create `lib/Controller/VotingBehaviourController.php` — thin controller (< 10 lines/method) tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1`:
  - `GET /api/voting-behaviour/{participantId}` → `VotingBehaviourService::getStats()`; enforce: current user may only access own stats UNLESS role is `chair`, `secretary`, or admin — return `403` otherwise
- [ ] 1.3 Register route in `appinfo/routes.php` — specific route before wildcard `{slug}` routes
- [ ] 1.4 Register `VotingBehaviourService` and `VotingBehaviourController` in DI container (`lib/AppInfo/Application.php`)
- [ ] 1.5 Write PHPUnit tests in `tests/Unit/Service/VotingBehaviourServiceTest.php` covering: `getStats` returns correct totals; `getStats` counts proxies correctly; `getStats` returns zero participation for a participant with no votes; controller returns 403 for member accessing other participant's stats

## 2. Backend — ProjectionController and public-state endpoint

- [ ] 2.1 Create `lib/Controller/ProjectionController.php` — annotated `#[PublicPage]` and `#[NoCSRFRequired]`, tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2`:
  - `GET /api/voting-rounds/{id}/public-state` → calls `VotingService::getPublicState()` and returns aggregate counts plus `preselectedOption` field; 404 if round not found; NEVER includes individual `Vote.value` or Participant identity
- [ ] 2.2 Add `getPublicState(string $votingRoundId): array` to `VotingService.php` — fetches VotingRound and linked Motion title; computes leading option from `votesFor/Against/Abstain`; returns `{ motionTitle, votingMethod, isOpen, votesFor, votesAgainst, votesAbstain, preselectedOption, openedAt }`; tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2`
- [ ] 2.3 Register route `GET /apps/decidesk/projection/{votingRoundId}` in `appinfo/routes.php` as a public page serving `ProjectionView.vue`; register `GET /api/voting-rounds/{id}/public-state` route
- [ ] 2.4 Write PHPUnit tests covering: `getPublicState` returns correct preselectedOption for leading option; returns `null` preselectedOption on tie; returns 404 for unknown round; confirm no Participant data in response

## 3. Backend — VotingService extensions (anonymisation + preset support + forwarding)

- [ ] 3.1 Extend `VotingService::closeVotingRound()` with `anonymise: bool` parameter — when `true`: (1) tally and store result; (2) call `OriPublicationService.publish()` if configured; (3) loop Vote objects and call `ObjectService.saveObject()` setting `value: null`; (4) log "Stemmen geanonimiseerd" to `ActivityService`; tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3`
- [ ] 3.2 Extend `VotingService::openVotingRound()` with optional `presetParticipantIds: array` parameter — validate each UUID against active Memberships via `ObjectService.findAll()`; exclude expired UUIDs; store eligible voter list as OpenRegister relation on VotingRound; return list of excluded UUIDs in response for UI warning
- [ ] 3.3 Add `forwardMotion(string $motionId, string $targetBodyId, string $actorId, string $justification): Motion` to `MotionService.php` — (1) check actor role against `IAppConfig` `motion_forwarding_roles`; (2) create new Motion in target body via `ObjectService.saveObject()`; (3) set `lifecycle` per `motion_forwarding_requires_approval` config; (4) create OpenRegister relation forwarded Motion → source Motion; (5) add note on source Motion; (6) send Nextcloud notification to target chair if approval required; tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3`
- [ ] 3.4 Add `POST /api/motions/{id}/forward` route in `appinfo/routes.php`; add handler method in `MotionController.php`
- [ ] 3.5 Write PHPUnit tests in `VotingServiceTest.php`: anonymisation sets Vote.value to null; anonymisation is sequenced after tally and ORI publish; preset UUID validation excludes expired memberships; returns excluded UUID list. Write `MotionServiceTest.php` tests: `forwardMotion` 403 on disallowed role; `forwardMotion` creates motion in target body; `forwardMotion` sends notification when approval required

## 4. Backend — Admin settings for presets and forwarding controls

- [ ] 4.1 Extend `SettingsController.php` to read/write `IAppConfig` keys: `voting_group_presets_{bodyId}` (JSON array of preset objects), `motion_forwarding_roles` (JSON array), `motion_forwarding_requires_approval` (boolean)
- [ ] 4.2 Write PHPUnit tests for settings controller: save and load voting group presets; save and load forwarding config; verify admin-only enforcement

## 5. Frontend — Real-Time Vote Tabulation

- [ ] 5.1 Extend `VotingRoundPanel.vue` with a poll interval — in `mounted()` start `setInterval(fetchTally, 3000)` when `VotingRound.closedAt` is null; clear interval in `beforeUnmount()` and when close response is received; tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-5`
- [ ] 5.2 Add role-differentiated tally display to `VotingRoundPanel.vue` — chair/secretary role with `isSecret: false`: show per-Participant table (name, vote value badge, proxy flag); member/observer/guest: show aggregate count only ("Uitgebracht: X / Y"); secret ballot any role: aggregate only until close
- [ ] 5.3 Add WCAG AA compliance to tally panel — each vote option rendered with both colour token (via NL Design System variable) AND text label; `<table>` with `scope="col"` headers on per-member breakdown; keyboard-navigable rows

## 6. Frontend — Member Voting Behaviour View

- [ ] 6.1 Create `src/views/MemberVotingHistoryView.vue` — route `/members/:id/voting-history`; fetches `GET /api/voting-behaviour/{participantId}`; renders: `CnPageHeader` with Participant name, `CnChartWidget` donut chart (Voor/Tegen/Onthouding), KPI stat blocks (participation rate, total rounds), paginated `CnDataTable` of individual round entries; `CnMassExportDialog` for CSV/JSON export; tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-6`
- [ ] 6.2 Add route `/members/:id/voting-history` to `src/router/index.js` with named route `MemberVotingHistory`
- [ ] 6.3 Add "Stemgedrag" link to `MotionDetail.vue` per-member breakdown rows when user is chair — links to `MemberVotingHistory` route for that Participant
- [ ] 6.4 Wrap all `await` store calls in `MemberVotingHistoryView.vue` in `try/catch` with user-facing error toast via `NcDialog`

## 7. Frontend — Roll-Call Publication (Anonymise action)

- [ ] 7.1 Extend the "Stemronde sluiten" dialog in `VotingRoundPanel.vue` with an "Anonimiseren" checkbox — default unchecked; label: "Individuele stemwaarden anonimiseren na publicatie (GDPR)"; tooltip explains irreversibility; tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-7`
- [ ] 7.2 Pass `anonymise: boolean` in the request body to `POST /api/voting-rounds/{id}/close`; update VotingController to forward the flag to `VotingService::closeVotingRound()`
- [ ] 7.3 Add "Geanonimiseerd" notice to `VotingRoundPanel.vue` result section — when all Vote objects for a closed round have `value: null`, render grey notice "Individuele stemwaarden zijn geanonimiseerd" and suppress per-member breakdown table

## 8. Frontend — Live Voting Projection

- [ ] 8.1 Create `src/views/ProjectionView.vue` — fullscreen layout (no Nextcloud navigation chrome); polls `GET /api/voting-rounds/{id}/public-state` every 3 seconds; renders motion title, vote method, aggregate counts, elapsed time, preselected option tile; no authentication required; tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-8`
- [ ] 8.2 Register public route in `appinfo/routes.php` serving `ProjectionView.vue` at `/apps/decidesk/projection/{votingRoundId}` — public page, no CSRF
- [ ] 8.3 Add "Projectielink kopiëren" button to `VotingRoundPanel.vue` for chair/secretary — uses `navigator.clipboard.writeText(url)` with a fallback `execCommand('copy')` for older browsers; shows "Link gekopieerd" toast on success
- [ ] 8.4 Implement preselected option tile logic in `ProjectionView.vue` — leading option (strict majority) gets `--color-primary-element` border and a checkmark icon (`CnIcon`); tied: all neutral with "Gelijkstand" label; close round: freeze final state display

## 9. Frontend — Voting Group Presets

- [ ] 9.1 Add "Stemgroepen" section to `AdminRoot.vue` (admin settings) — lists existing presets with name, member count, last-modified date; "Nieuwe stemgroep" button opens `CnFormDialog` with name field and Participant multi-select (filtered to GovernanceBody); "Bewerken" and "Verwijderen" per preset; saves to `POST /api/settings`; tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-9`
- [ ] 9.2 Add "Stemgroep" dropdown to "Stemronde openen" dialog in `VotingRoundPanel.vue` — options: blank (no preset, all members eligible) + named presets from settings; on selection, preset UUIDs passed in `POST /api/voting-rounds` body
- [ ] 9.3 Display warning banner in the open-round dialog when backend returns excluded UUIDs — "N stemgroeplid(en) niet meer actief — uitgesloten van stemronde" listing the excluded member names

## 10. Frontend — Motion Forwarding Controls

- [ ] 10.1 Add "Doorzending" section to `AdminRoot.vue` — role checkboxes (Voorzitter, Griffier, Lid) controlling `motion_forwarding_roles`; toggle "Doorzending vereist goedkeuring" controlling `motion_forwarding_requires_approval`; saves to `POST /api/settings`; tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-10`
- [ ] 10.2 Add "Doorsturen" action button to `MotionDetail.vue` — visible only to roles listed in `motion_forwarding_roles` setting (fetched from settings store); opens `CnFormDialog` with GovernanceBody selector and justification text area; on submit calls `POST /api/motions/{id}/forward`
- [ ] 10.3 Add "Doorgestuurd naar" panel to `MotionDetail.vue` — shown when Motion has a forwarding note; displays target body name, forwarding date, link to forwarded Motion
- [ ] 10.4 Add "Afkomstig van" panel to `MotionDetail.vue` — shown when Motion has a source relation; displays source body name and link to source Motion

## 11. Frontend — Amendment Diff

- [ ] 11.1 Add "Vergelijken" tab to `AmendmentDetail.vue` — alongside existing tabs; fetches parent Motion text via `objectStore.findObject(motionId)` (already loaded by relations); uses `diff.diffChars()` (or equivalent from available npm package) to compute character-level diff of `motion.text` vs `amendment.text`; renders as `<pre>` with `<ins>` and `<del>` spans; tagged `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-11`
- [ ] 11.2 Defer diff computation via `setTimeout(0)` when text length > 1000 characters; show `NcLoadingIcon` while computing; clear spinner on render
- [ ] 11.3 Style diff with NL Design System tokens — `<ins>` uses `var(--color-success)` with green `+` prefix; `<del>` uses `var(--color-error)` with red `−` prefix; `<style scoped>` block; no hardcoded hex values

## 12. Translations (ADR-007)

- [ ] 12.1 Add Dutch (`l10n/nl.json`) translation keys for all new user-visible strings: live tally labels ("Uitgebracht", "Stemronde gesloten"), anonymisation dialog and notice, projection page labels, voting group presets UI, motion forwarding dialog and panels, amendment diff tab and loading state, voting history view labels and export headers
- [ ] 12.2 Add English (`l10n/en.json`) keys matching all Dutch keys; verify zero gaps between `nl.json` and `en.json`

## 13. Testing (ADR-008)

- [ ] 13.1 Write PHPUnit tests for `VotingBehaviourServiceTest` — see task 1.5
- [ ] 13.2 Write PHPUnit tests for `ProjectionController` and `VotingService::getPublicState` — see task 2.4
- [ ] 13.3 Write PHPUnit tests for `VotingService` anonymisation extension and `MotionService::forwardMotion` — see task 3.5
- [ ] 13.4 Write Newman/Postman integration tests in `tests/integration/motion-voting-t2.json` for all new API endpoints: `GET /api/voting-behaviour/{id}`, `GET /api/voting-rounds/{id}/public-state`, `POST /api/voting-rounds/{id}/close` with `anonymise: true`, `POST /api/motions/{id}/forward`; cover 403, 404, and 400 error paths
- [ ] 13.5 Write Playwright browser tests for: REQ-RVT-001 (live tally refreshes), REQ-RVT-002 (chair sees per-member breakdown), REQ-RVT-003 (member sees aggregate only), REQ-MVB-001 (voting history stats display), REQ-RCP-001 (atomic close-publish-anonymise), REQ-LVP-001 (projection view loads without auth), REQ-LVP-002 (preselected option tile), REQ-VGP-002 (preset selection in open-round dialog), REQ-MFC-003 (forwarding 403 for disallowed role), REQ-AMD-DIFF-001 (diff tab renders correct changes), REQ-AMD-DIFF-003 (WCAG colour + symbol)

## 14. Verification

- [ ] 14.1 Verify all new PHP classes and public methods have `@spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-N` PHPDoc tags
- [ ] 14.2 Verify all user-visible strings use `t(appName, 'text')` — no hardcoded Dutch or English strings in templates or JS
- [ ] 14.3 Verify no hardcoded CSS colours — only NL Design System CSS custom properties (ADR-010)
- [ ] 14.4 Verify WCAG 2.1 AA: tally table keyboard-navigable; diff colour + symbol encoded; projection view readable without colour; voting history chart has text labels
- [ ] 14.5 Verify `Motion`, `Amendment`, `Vote`, and `VotingRound` schemas in OpenRegister still match ADR-000 exactly — no extra properties added
- [ ] 14.6 Verify seed data extended with 2 Motion, 3 VotingRound, 4 Vote, 2 Amendment objects — all present after fresh install
- [ ] 14.7 Verify projection route (`/apps/decidesk/projection/{id}`) returns 200 without an authenticated session; verify `GET /api/voting-rounds/{id}/public-state` returns no Participant identities
- [ ] 14.8 Verify anonymised Vote objects have `value: null` after close-with-anonymise; verify `VotingRound.votesFor/Against/Abstain` remain non-null
- [ ] 14.9 Verify `MotionService::forwardMotion()` returns 403 for a role not in `motion_forwarding_roles` — backend enforcement only, not frontend-only
- [ ] 14.10 Run `npm run lint` before committing — catch missing package.json entries for diff library; fix `n/no-extraneous-import` errors
