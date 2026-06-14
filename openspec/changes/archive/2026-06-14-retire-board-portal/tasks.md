# Tasks: retire-board-portal

<!-- Config-first: delete schemas + manifest fragment, then delete views +
     registry, then clean dangling backend refs, then re-seed corp demo.
     Column-0 `- [ ]` count is capped at 20 by the supervisor. -->

## Implementation Tasks

### Task 1: Delete the 7 board schemas from the register
- **spec_ref**: `openspec/changes/retire-board-portal/specs/governance-bodies/spec.md#requirement-parallel-corporate-board-entity`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN `Board`, `BoardMember`, `BoardMeeting`, `BoardVote`, `BoardMinutes`, `BoardMaterial`, `BoardAuditLogEntry` are deleted from `components.schemas` THEN none remains and their inline `x-openregister-seeds` are gone
  - GIVEN the edited JSON WHEN parsed THEN it is valid JSON and the universal schemas are untouched
- [x] Implement
- [x] Test

### Task 2: Delete the board-portal manifest fragment
- **spec_ref**: `openspec/changes/retire-board-portal/specs/meeting-management/spec.md#requirement-parallel-corporate-board-meeting-entity`
- **files**: `src/manifest.d/board-portal.json`
- **acceptance_criteria**:
  - GIVEN the manifest fragment WHEN deleted THEN the bundled manifest no longer injects BoardDashboard / Boards / Board meetings / Resolutions nav items or their pages
  - GIVEN the build WHEN run THEN no board-portal widgets bind a deleted schema slug
- [x] Implement
- [x] Test

### Task 3: Delete the six board views + 2 board modals and strip registry registrations
- **spec_ref**: `openspec/changes/retire-board-portal/specs/governance-bodies/spec.md#requirement-parallel-corporate-board-entity`
- **files**: `src/views/BoardList.vue`, `src/views/BoardDetail.vue`, `src/views/BoardMeetingList.vue`, `src/views/BoardMeetingDetail.vue`, `src/views/ResolutionList.vue`, `src/views/ResolutionDetail.vue`, `src/modals/BoardCreateModal.vue`, `src/modals/BoardMeetingCreateModal.vue`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN the views and modals WHEN deleted THEN no orphan import remains
  - GIVEN `src/registry.js` WHEN edited THEN the 6 board `import` lines and 6 `page(...)` registrations (and the board-portal comment block) are removed
- [x] Implement
- [x] Test

### Task 4: Delete board routes from appinfo/routes.php
- **spec_ref**: `openspec/changes/retire-board-portal/specs/meeting-management/spec.md#requirement-parallel-corporate-board-meeting-entity`
- **files**: `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN the routes file WHEN the board-prefixed routes (`board#*`, `boardMember#*`, `boardMeeting#*`, `resolution#*`, `boardVote#*`, `boardMaterial#*`) are deleted THEN none remains
  - GIVEN the flagged board-coupled routes (conflictOfInterest / auditLog / eIDASSignature / proxyVote / governanceReport / regulatorExport / multilingualReconciliation) WHEN reviewed per design.md THEN each is retargeted onto a unified entity or removed, with no route pointing at a deleted controller method
- [x] Implement
- [x] Test

### Task 5: Remove board DI registrations + CalDAV listener and delete board-only controllers/services
- **spec_ref**: `openspec/changes/retire-board-portal/specs/meeting-management/spec.md#requirement-parallel-corporate-board-meeting-entity`
- **files**: `lib/AppInfo/Application.php`, `lib/Controller/BoardController.php`, `lib/Controller/BoardMemberController.php`, `lib/Controller/BoardMeetingController.php`, `lib/Controller/BoardVoteController.php`, `lib/Controller/BoardMaterialController.php`, `lib/Controller/ResolutionController.php`, `lib/Controller/BoardPortalControllerTrait.php`, `lib/Service/BoardService.php`, `lib/Service/BoardMemberService.php`, `lib/Service/BoardMeetingService.php`, `lib/Service/BoardVoteService.php`, `lib/Service/BoardMaterialAuthorizationService.php`, `lib/Service/BoardCalDavSyncService.php`, `lib/Service/ResolutionService.php`, `lib/Service/WrittenResolutionService.php`, `lib/Lifecycle/ResolutionLifecycleGuard.php`, `lib/Listener/BoardMeetingCalDavBridge.php`
- **acceptance_criteria**:
  - GIVEN `Application.php` WHEN edited THEN board-only DI registrations, the `BoardMeetingCalDavBridge` import + DI + `registerEventListener`, are removed and the container still resolves
  - GIVEN the board-only controller/service/lifecycle/listener files WHEN deleted THEN no remaining file references them and `php -l` passes
- [x] Implement
- [x] Test

### Task 6: Clean remaining dangling refs (search, dashboard, flagged services, register.d, frontend comments)
- **spec_ref**: `openspec/changes/retire-board-portal/specs/resolution-minutes/spec.md#requirement-parallel-corporate-resolution-entity`
- **files**: `lib/Search/DecideskSearchProvider.php`, `lib/Service/EIDASSignatureService.php`, `lib/Service/IEIDASSignatureService.php`, `lib/Service/LogEIDASSignatureService.php`, `lib/Service/ConflictOfInterestService.php`, `lib/Service/AuditLogService.php`, `lib/Service/ProxyVoteService.php`, `lib/Service/GovernanceReportingService.php`, `lib/Service/RegulatorExportService.php`, `lib/Service/MultilingualReconciliationService.php`, `lib/Lifecycle/QesGuard.php`, `lib/Controller/AuditLogController.php`, `lib/Controller/ConflictOfInterestController.php`, `lib/Controller/EIDASSignatureController.php`, `lib/Controller/GovernanceReportController.php`, `lib/Controller/RegulatorExportController.php`, `lib/Controller/ProxyVoteController.php`, `lib/Controller/MultilingualReconciliationController.php`, `lib/Settings/register.d/42-admin-settings-v1.json`, `lib/Settings/register.d/43-process-config-v1.json`, `src/services/noticeRules.js`
- **acceptance_criteria**:
  - GIVEN `DecideskSearchProvider` WHEN edited THEN the `resolution` SCHEMAS entry and label are removed and decisions remain searchable
  - GIVEN each flagged board-coupled service/controller WHEN reviewed per design.md THEN every reference to a deleted schema slug (`board-meeting`, `board-member`, `resolution`, `board-vote`, `board-material`, `board-audit-log-entry`) is retargeted onto `meeting`/`decision`/`minutes`/`auditTrail` or the file is removed, keeping existing auth guards intact
  - GIVEN a repo-wide grep for deleted schema slugs / board components WHEN run after cleanup THEN only legitimate domain prose (e.g. `board-elections` agenda topic) remains
- [x] Implement
- [x] Test

### Task 7: Re-seed the corporate demo on the universal entities
- **spec_ref**: `openspec/changes/retire-board-portal/specs/governance-bodies/spec.md#requirement-req-gbd-003-meeting-creation-from-governance-body`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the `GovernanceBody` schema WHEN a `mode=corp` seed `raad-van-commissarissen-acme-bv` (`bodyType=supervisory-board`) is added THEN it validates against the schema
  - GIVEN the `Meeting` and `Minutes` schemas WHEN corp seeds `rvc-vergadering-2025-q2` and `notulen-rvc-2025-q2` are added THEN both validate and the corporate scenario is demonstrable on install
- [x] Implement
- [x] Test

## Verification

- [x] All tasks checked off
- [x] App boots; nav renders without Boards / Board meetings / Resolutions; unified search returns decisions + meetings
- [x] Repo-wide grep confirms no live reference to a deleted schema/route/view remains

## Quality checklist

- New/changed (retargeted) API endpoints retain their existing auth guards
- UI changes (nav shrink) covered by the manifest renderer
- Dutch (`nl_NL`) and English (`en_US`) strings for deleted board surfaces removed; corp seed strings added (ADR-007)
- `openspec validate` passes
