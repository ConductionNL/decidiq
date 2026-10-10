# Tasks: publication-theme-pages

## Implementation tasks

### Task 1: The theme and its links
- **spec_ref**: `openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme`
- **files**: `lib/Settings/register.d/92-theme-pages.json`, `lib/Settings/profiles/municipality.json`, `src/manifest.d/theme-pages.json`, `src/modals/AddToThemeModal.vue`, `src/registry.js`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN `governance-theme` exists and `PublicationRecord.sourceType` accepts `theme`
  - GIVEN a decision page WHEN the griffier chooses Add to theme and picks the swimming pool theme THEN the decision appears on the theme page
  - GIVEN the manifest and modal-isolation gates WHEN run THEN they pass
- [ ] Implement
- [ ] Test (Playwright)

### Task 2: The timeline
- **spec_ref**: `openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it`
- **files**: `lib/Service/ThemeTimelineBuilder.php`, `src/components/tabs/ThemeTimelineTab.vue`, `tests/Unit/Service/ThemeTimelineBuilderTest.php`
- **acceptance_criteria**:
  - GIVEN the seeded theme WHEN its timeline is built THEN five entries come out oldest first with the right dates per kind
  - GIVEN the staff timeline WHEN shown THEN entries not yet published are marked as such
- [ ] Implement
- [ ] Test

### Task 3: Publish a theme
- **spec_ref**: `openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-003-a-published-theme-carries-only-published-items`
- **files**: `lib/Service/PublicationEligibilityService.php`, `lib/Service/PublicationPayloadService.php`, `tests/Unit/Service/PublicationPayloadServiceTest.php`, `tests/newman/theme-pages.json`
- **acceptance_criteria**:
  - GIVEN a public theme with three published and two unpublished linked items WHEN published THEN the payload's timeline holds three entries, each with its publication link, and is readable anonymously
  - GIVEN a theme not marked public WHEN publishing is asked THEN it is refused
  - GIVEN a theme whose items are all unpublished WHEN publishing is asked THEN it is refused with a reason
- [ ] Implement
- [ ] Test (red first: `assertEligible('theme', ...)` throws "Unknown publication source type" today)

### Task 4: Keep published themes current
- **spec_ref**: `openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-004-an-active-published-theme-stays-current`
- **files**: `lib/BackgroundJob/ThemeRefreshJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN an active public theme and a newly published linked decision WHEN the nightly job runs THEN a new payload version includes it
  - GIVEN a concluded theme WHEN the job runs THEN nothing is republished
- [ ] Implement
- [ ] Test

## Verification

- `composer check:strict` and `npm run lint` once before push.
