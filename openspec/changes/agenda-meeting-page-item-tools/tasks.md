# Tasks: agenda-meeting-page-item-tools

## Implementation tasks

### Task 1: The page asks the server which meeting roles the caller holds
- **spec_ref**: `openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-001-the-meeting-page-asks-the-server-for-the-callers-meeting-roles`
- **files**: `appinfo/routes.php`, `lib/Controller/MeetingController.php`, `tests/Unit/Controller/MeetingControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a user holding the secretary role on a meeting WHEN `GET /api/meetings/{meetingId}/my-roles` THEN 200 with `secretary: true`, `chair: false`
  - GIVEN an anonymous request WHEN the endpoint is called THEN 401
  - GIVEN the route file WHEN the route-reachability and route-auth gates run THEN both pass
- [x] Implement
- [x] Test (PHPUnit with a real `ParticipantResolver` double built with `onlyMethods`, red before the method exists)

### Task 2: Reorder from the meeting page
- **spec_ref**: `openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page`
- **files**: `src/components/tabs/MeetingAgendaTab.vue`, `src/services/agendaRules.js` (reuse), `tests/vitest/meetingAgendaReorder.spec.js`
- **acceptance_criteria**:
  - GIVEN a chair on the meeting page WHEN they drag item 3 above item 1 THEN one `PUT /api/agendas/{id}/reorder` is sent with the new id order and the rows renumber
  - GIVEN a secretary WHEN they use Move up on a row THEN the same call is sent (keyboard alternative, WCAG 2.5.7)
  - GIVEN a member without either role WHEN the widget renders THEN no drag handle and no move actions show
  - GIVEN a parent item with sub-items WHEN the parent moves THEN its sub-items move with it
- [x] Implement
- [ ] Test (vitest on the tree order, Playwright drag and keyboard move)

### Task 3: Open an agenda item and the live screen from the meeting page
- **spec_ref**: `openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-003-every-agenda-row-opens-its-item-page`, `openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-004-the-meeting-page-links-the-live-meeting-screen`
- **files**: `src/components/tabs/MeetingAgendaTab.vue`
- **acceptance_criteria**:
  - GIVEN any user who can read the meeting WHEN they choose Open on an agenda row THEN the agenda item page opens with its Documents widget
  - GIVEN a chair, secretary or admin WHEN the agenda widget renders THEN an Open live meeting button routes to `/meetings/{id}/live`
  - GIVEN a member without those roles WHEN the widget renders THEN the button is absent
- [x] Implement
- [ ] Test (Playwright: open item, attach a file on the item page, see it listed)

### Task 4: Per-item minutes on the minutes page
- **spec_ref**: `openspec/changes/agenda-meeting-page-item-tools/specs/resolution-minutes/spec.md#requirement-req-amp-005-the-minutes-page-carries-the-per-item-minutes-editor`
- **files**: `src/components/tabs/MinutesItemNotesTab.vue`, `src/registry.js`, `src/manifest.json` (`MinutesDetail` widget `minutes-item-notes` and one layout row)
- **acceptance_criteria**:
  - GIVEN draft minutes of a meeting with four regular agenda items WHEN the secretary opens the minutes page THEN four per-item note fields show and a typed note autosaves into `itemNotes`
  - GIVEN minutes past the draft stage WHEN anyone opens the page THEN the notes show read-only
  - GIVEN the manifest WHEN `tests/validate-manifest.js` runs THEN it passes
- [ ] Implement
- [ ] Test (Playwright: type a note, reload, note is still there)

## Verification

- `composer check:strict` and `npm run lint` once before push; the route gates (route-auth, route-reachability, semantic-auth) are part of the hydra gates.
- Each task's Playwright spec names its scenario with `@e2e` so gate-19 counts it.
