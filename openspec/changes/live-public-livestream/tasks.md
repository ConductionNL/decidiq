# Tasks: live-public-livestream

## Implementation tasks

### Task 1: The broadcast schema and its declared lifecycle
- **spec_ref**: `openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-001-a-public-broadcast-is-readable-without-an-account-once-it-goes-live`
- **files**: `lib/Settings/register.d/92-meeting-broadcast.json` (schema `meeting-broadcast`, `x-openregister-lifecycle`, `authorization.read`, register `schemas` list), `lib/Settings/profiles/municipality.json` (three seed objects), `tests/Unit/Settings/RegisterDescriptorTest.php`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the register imports THEN `meeting-broadcast` is attached to the decidiq register and `RegisterDescriptorTest` passes
  - GIVEN a broadcast without `publicationDate` WHEN an anonymous Newman request lists `meeting-broadcast` THEN the row is absent (red before the rule, green after)
  - GIVEN `ended` WHEN any transition is attempted THEN OpenRegister refuses it
- [x] Implement (fragment 120, not 92: 92 was taken; seeds on the seeded meetings raadsvergadering-2025-01-15 (ended), informatieavond-windpark-noord (planned, test found a problem) and raadsvergadering-2025-04-10 (planned, not announced), since the municipality set has no meetings on 12, 19 and 26 March; schema shape, lifecycle, read rule and seeds asserted in tests/Unit/Settings/MeetingBroadcastRegisterTest.php)
- [ ] Test (Newman: an anonymous list of meeting-broadcast leaves out the row without publicationDate; needs the live instance)

### Task 2: The streaming connection
- **spec_ref**: `openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service`
- **files**: `lib/Settings/connections.json` (`streaming` with `sourceTemplate`), `lib/Service/MeetingBroadcastService.php` (the integriq call through the linked source)
- **acceptance_criteria**:
  - GIVEN no linked source WHEN any broadcast action runs THEN 409 with "No streaming service is connected" (PHPUnit, red then green)
  - GIVEN the connections file WHEN hydra gate `connections-declaration` runs THEN it passes
  - GIVEN the service WHEN grepped THEN it never calls `Db\SourceMapper`
- [x] Implement (design correction: the integriq call lives in its own `lib/Service/StreamingClient.php`, the way `CaseSystemClient` holds the case-system call; `MeetingBroadcastService` calls it. Refusals are `BroadcastRefusedException` with the HTTP status)
- [x] Test (tests/Unit/Service/MeetingBroadcastServiceTest.php: 409 on all six actions with nothing sent, no `SourceMapper` in either file; tests/Unit/Settings/ConnectionsDeclarationTest.php `testTheStreamingServiceIsALinkedSource`; hydra checker check_connections_declaration.js 0 findings)

### Task 3: Test broadcast, go live, pause, resume, stop
- **spec_ref**: `openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see`
- **files**: `lib/Controller/BroadcastController.php`, `lib/Service/MeetingBroadcastService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a member who is not chair or secretary WHEN they call any broadcast route THEN 403 (PHPUnit and a Newman IDOR request)
  - GIVEN a test WHEN it starts THEN `previewUrl` is set and `playerUrl` and `publicationDate` stay empty
  - GIVEN `isPublic: false` WHEN start is called THEN 422
  - GIVEN pause at 5400 and resume at 6300 WHEN windows are read THEN they match REQ-LSTR-004 (PHPUnit, fixed clock)
  - GIVEN hydra gates `route-auth`, `no-admin-idor` and `route-reachability` WHEN they run THEN they pass
- [ ] Implement
- [ ] Test

### Task 4: Live captions from the service
- **spec_ref**: `openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-005-live-captions-come-from-the-streaming-service`
- **files**: `lib/Service/MeetingBroadcastService.php`
- **acceptance_criteria**:
  - GIVEN a stubbed service that refuses captions WHEN the broadcast goes live THEN `liveCaptions` is `unavailable` (PHPUnit)
- [x] Implement (operation `live-captions` on the linked source; `accepted: true` is `requested`, a no or an error answer is `unavailable`, and the broadcast goes live either way)
- [x] Test (MeetingBroadcastServiceTest `testLiveCaptionsAreRequestedFromTheService`, `testAServiceWithoutLiveCaptions`)

### Task 5: The Broadcast widget on the meeting page
- **spec_ref**: `openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see`
- **files**: `src/manifest.json` (`MeetingDetail` widget, layout row and `slots` entry, edited directly per the page note), `src/components/tabs/MeetingBroadcastTab.vue`, `src/registry.js`, `tests/e2e/meeting-broadcast.spec.ts`
- **acceptance_criteria**:
  - GIVEN no linked source WHEN the meeting page opens THEN the widget reads "No streaming service is connected" and shows no buttons
  - GIVEN a test result WHEN the page reloads THEN result, note, author and time are shown
  - GIVEN the Playwright spec WHEN it runs THEN it carries `@e2e` references to the UI scenarios of REQ-LSTR-002, 003 and 004
- [ ] Implement
- [ ] Test

### Task 6: Subtitles derived from the aligned transcript
- **spec_ref**: `openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows`
- **files**: `lib/Service/BroadcastCaptionService.php`, `lib/Controller/BroadcastController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN windows 0 to 5400 and 6300 to 10800 and segments at 5800 and 6400 WHEN derived THEN no cue for 5800 and a cue at 5500 for 6400 (PHPUnit, red then green)
  - GIVEN no `alignedAt` WHEN derived THEN refused
  - GIVEN the file name WHEN checked against `PublicationEligibilityService::isFileDenied()` THEN it is not denied
- [ ] Implement
- [ ] Test

### Task 7: Release of a reviewed caption track, and the narrowed confidentiality rule
- **spec_ref**: `openspec/changes/live-public-livestream/specs/meeting-transcription/spec.md#requirement-confidentiality-and-retention-of-recordings-and-transcripts`
- **files**: `lib/Service/BroadcastCaptionService.php` (release, `OCP\Share\IManager` link share), `lib/BackgroundJob/TranscriptRetentionJob.php` (keep released tracks), `lib/Service/PublicationEligibilityService.php` (unchanged deny-list, new test only)
- **acceptance_criteria**:
  - GIVEN `isPublic: false` WHEN release is called THEN 422 and no share
  - GIVEN a released track WHEN a publish request targets the transcript THEN it is still refused (PHPUnit)
  - GIVEN a released track WHEN the retention job runs THEN the caption file remains (PHPUnit on the job)
- [ ] Implement
- [ ] Test

### Task 8: The anonymous portal collection
- **spec_ref**: `openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-008-residents-see-live-and-recent-broadcasts-through-portaliq`
- **files**: `lib/Portal/PortalContributionProvider.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the provider constructed with portaliq absent WHEN the citizen contribution is read THEN `publicBroadcasts` is `anonymous: true` and its `fields` hold no staff field (PHPUnit)
  - GIVEN portaliq on the dev instance and a live seed broadcast WHEN `GET /portal/api/contributions` is called without a session THEN `publicBroadcasts` is present (live check)
- [ ] Implement
- [ ] Test

### Task 9: Strings and docs
- Dutch and English strings for every widget label and message above, in `l10n/` (hydra `test:l10n` and `check:schema-l10n` green).
- `docs/features/meeting-broadcast.md`: how to connect a streaming service, run a test, go live, pause, and release subtitles. Written with the hydra writing skill.
- [ ] Implement
- [ ] Test
