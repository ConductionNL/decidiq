# Tasks: planning-long-term-agenda-tracks-and-report

## Implementation tasks

### Task 1: Tracks on the schema
- **spec_ref**: `openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-001-a-long-term-agenda-item-can-be-a-track-under-a-topic`
- **files**: `lib/Settings/register.d/92-long-term-agenda-tracks.json` (`parentItem`, `trackCount`, `openTrackCount`), `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN a topic with two open tracks WHEN read THEN `openTrackCount` is 2 (Newman against the seeded profile)
  - GIVEN a track realised WHEN the topic is read THEN `openTrackCount` is 1
- [ ] Implement
- [ ] Test

### Task 2: One level, on save
- **spec_ref**: `openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-002-tracks-are-one-level-deep`
- **files**: `lib/Listener/PlannedAgendaTrackGuardListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- **acceptance_criteria**:
  - GIVEN the real `ObjectCreatingEvent` with a parent that is a track WHEN handled THEN refused with the topic named (PHPUnit, red then green)
  - GIVEN an item with tracks WHEN it gets a parent THEN refused; GIVEN a self reference THEN refused
- [ ] Implement
- [ ] Test

### Task 3: The tracks widget and the topics filter
- **spec_ref**: `openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-001-a-long-term-agenda-item-can-be-a-track-under-a-topic`
- **files**: `src/manifest.d/termijnagenda.json` (Tracks `object-list` on `PlannedAgendaDetail`, "Topics only" quick filter and topic column on `PlannedAgenda`), `tests/e2e/long-term-agenda-tracks.spec.ts`
- **acceptance_criteria**:
  - GIVEN the seeded topic WHEN its page opens THEN the Tracks widget lists both tracks and adds a third in place (Playwright)
  - GIVEN hydra gate `dashboard-antipattern` and the manifest validator WHEN they run THEN they pass
- [ ] Implement
- [ ] Test

### Task 4: Portfolio holder and author
- **spec_ref**: `openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-003-the-list-filters-by-portfolio-holder-and-by-author`
- **files**: `lib/Settings/register.d/92-long-term-agenda-tracks.json` (`owner` facetable, `author`), `src/manifest.d/termijnagenda.json` (two columns)
- **acceptance_criteria**:
  - GIVEN the facet "Portfolio holder" WHEN De Boer is picked THEN only her items list (Playwright)
  - GIVEN the facet "Author" WHEN S. Visser is picked THEN two items list (Playwright)
- [ ] Implement
- [ ] Test

### Task 5: The formatted report
- **spec_ref**: `openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report`
- **files**: `lib/Service/PlannedAgendaReportService.php`, `lib/Controller/PlannedAgendaReportController.php`, `appinfo/routes.php`, `src/manifest.d/termijnagenda.json` (header action)
- **acceptance_criteria**:
  - GIVEN filters and a denied object WHEN rendered THEN the report holds only readable matching rows, grouped by topic (PHPUnit, red then green)
  - GIVEN `FilinqPdf` returns null WHEN rendered THEN the HTML is saved and the note returned (PHPUnit)
  - GIVEN no matching rows WHEN requested THEN 422
  - GIVEN hydra gates `route-auth`, `route-reachability` and `semantic-auth` WHEN they run THEN they pass
- [ ] Implement
- [ ] Test

### Task 6: Strings and docs
- Dutch and English strings for the widget, columns, facets, the action and the report headings (`test:l10n`, `check:schema-l10n` green).
- `docs/features/long-term-agenda.md` (new): tracks under a topic, the two filters, and the report.
- [ ] Implement
- [ ] Test
