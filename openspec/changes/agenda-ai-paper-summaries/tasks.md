# Tasks: agenda-ai-paper-summaries

## Implementation tasks

### Task 1: PaperSummary schema with its lifecycle and read rule
- **spec_ref**: `openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-001-an-ai-summary-is-a-record-with-a-review-status`
- **files**: `lib/Settings/register.d/92-paper-summaries.json`, `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the register is imported WHEN `paper-summary` is read THEN the schema, its lifecycle and its read rule are present
  - GIVEN a member WHEN he lists paper summaries THEN only `shown` ones return
  - GIVEN a `draft` summary WHEN someone saves it as `requested` THEN OpenRegister rejects the transition
- [x] Implement (fragment 119, not 92: 92 was taken; seeds on the existing budget item `begroting-2026-bespreking` and its papers, not a new Begroting 2027 item; `reviewedBy` holds the clerk's display name)
- [ ] Test (Newman: member and clerk read the same collection and get different counts)

### Task 2: Request a summary or a comparison
- **spec_ref**: `openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper`
- **files**: `lib/Service/PaperSummaryService.php`, `lib/Controller/PaperSummaryController.php`, `appinfo/routes.php`, `tests/Unit/Service/PaperSummaryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a clerk and a paper attached to the item WHEN she posts a summary request THEN a TaskProcessing task is scheduled and a `requested` summary exists
  - GIVEN a file not attached to that item WHEN requested THEN 422 and no task
  - GIVEN a member without the secretariat group WHEN he posts THEN 403
  - GIVEN no TaskProcessing provider WHEN availability is read THEN it says unavailable and a request answers 503
- [x] Implement (PaperSummaryService schedules first and saves only after, so a refused schedule leaves no object; the file check and the text read are PaperText, the caller checks are PaperSummaryAccess; route `paperSummary#create`)
- [x] Test (PHPUnit red-then-green over the real PaperSummaryAccess, PaperText and OCP Task: PaperSummaryServiceTest, PaperTextTest)

### Task 3: Confidential papers stay inside their circle
- **spec_ref**: `openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle`
- **files**: `lib/Service/PaperSummaryService.php`, `tests/Unit/Service/PaperSummaryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an active restriction on the agenda item WHEN a clerk outside its circle requests THEN 403 naming the restriction
  - GIVEN the restriction is lifted WHEN she requests again THEN the task is scheduled
- [x] Implement (a comparison checks both papers)
- [x] Test

### Task 4: The task result lands as a draft
- **spec_ref**: `openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts`
- **files**: `lib/Listener/PaperSummaryTaskListener.php`, `lib/AppInfo/Registrar/*`, `tests/Unit/Listener/PaperSummaryTaskListenerTest.php`
- **acceptance_criteria**:
  - GIVEN a real `TaskSuccessfulEvent` with custom id `paper-summary:<uuid>` WHEN handled THEN that summary becomes `draft` with the output text and the provider id
  - GIVEN a `TaskFailedEvent` WHEN handled THEN the summary becomes `failed`
  - GIVEN an event with another app's custom id WHEN handled THEN nothing changes
- [x] Implement (registered by its own TaskProcessingEventRegistrar, which CrossAppEventRegistrar calls (PlatformIntegrationRegistrar is at phpmd's coupling ceiling); a summary no longer `requested` is never overwritten by a late answer; the part-by-part summary of a long paper is the service's, task 2)
- [x] Test (construct the real OCP event classes, not a fake)

### Task 5: Review and show on the agenda item page
- **spec_ref**: `openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it`
- **files**: `src/components/tabs/AgendaPaperSummariesTab.vue`, `src/registry.js`, `src/manifest.json`, `tests/e2e/paper-summaries.spec.ts`
- **acceptance_criteria**:
  - GIVEN a draft summary WHEN the clerk edits the text and chooses Show to members THEN it is `shown` with her name and the time
  - GIVEN a shown summary WHEN a member opens the item THEN he reads it with the AI-generated label and the reviewer
  - GIVEN no provider WHEN the clerk opens the item THEN the Summarise and Compare actions are absent
- [x] Implement (widget `agenda-paper-summaries`; the availability answer carries `canRequest`, so a member gets no actions; edit is inline in the widget, no modal; review rules and payloads in src/utils/paperSummaries.js with tests/vitest/paperSummaries.spec.js, payloads validated against the PaperSummary schema)
- [ ] Test (Playwright tests/e2e/paper-summaries.spec.ts, written, not run: needs the live instance with the municipality example set; the provider-dependent tests skip with a named reason)

## Verification

- `composer check:strict` and `npm run lint` once before push.
- The e2e spec needs a TaskProcessing provider; CI installs the test provider app, and the spec is skipped with a named reason where none exists.
