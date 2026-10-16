# Tasks: motion-change-recommendations

## Implementation tasks

### Task 1: The change-recommendation schema
- **spec_ref**: `openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording`
- **files**: `lib/Settings/register.d/125-change-recommendations.json` (new; next free number), register version move and lock-file test, `tests/Unit/RegisterJsonTest.php`, `l10n/` schema strings
- **acceptance_criteria**:
  - GIVEN the merged register WHEN read THEN `change-recommendation` exists with every property titled, `status` defaulting to `pending`, and only the transitions pending to accepted and pending to rejected
  - GIVEN a user without the chair or secretary role WHEN they create one through the object API THEN OpenRegister refuses it
- [ ] Implement
- [ ] Test (red first)

### Task 2: Line numbers
- **spec_ref**: `openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-001-the-motion-text-can-be-read-with-stable-line-numbers`
- **files**: `src/utils/lineNumbering.js` (new), `src/components/tabs/MotionTextLinesTab.vue` (new, the Regelnummers view), `src/registry.js`, `src/manifest.json` (MotionDetail widget, layout cell and slot; reason-bearing `@custom-widget-ratchet exclude`: the line view computes from text, no data widget does)
- **acceptance_criteria**:
  - the same text gives the same numbers on every render; lines break at word boundaries at 80 characters; paragraph breaks start a new line
- [ ] Implement
- [ ] Test (red first): `tests/unit/utils/lineNumbering.spec.js`

### Task 3: Recommend a change
- **spec_ref**: `openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording`
- **files**: `src/dialogs/ChangeRecommendationDialog.vue` (new), the "Wijziging aanbevelen" button in `MotionTextLinesTab.vue`, `l10n/` (Wijziging aanbevelen, Van regel, Tot en met regel, Huidige tekst, Nieuwe tekst, Toelichting)
- **acceptance_criteria**:
  - picking lines 4 to 5 fills Huidige tekst with exactly those lines' text, which is saved as `targetPassage` with `lineFrom` 4 and `lineTo` 5
  - the button shows only for the chair and secretary (voting-permissions answer)
- [ ] Implement
- [ ] Test (red first): vitest on the dialog

### Task 4: Accept and reject
- **spec_ref**: `openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote`, `#requirement-req-mcr-004-a-rejected-recommendation-changes-nothing`, `#requirement-req-mcr-005-a-recommendation-whose-passage-has-changed-cannot-be-accepted`
- **files**: `lib/Controller/ChangeRecommendationController.php` (new), `lib/Service/ChangeRecommendationService.php` (new), `lib/Service/AmendmentTextMerger.php` (a `kind` for the history entry), the meeting-role guard moved out of `lib/Controller/MotionController.php` into a shared class both controllers use, `appinfo/routes.php`
- **acceptance_criteria**:
  - accept merges and writes `accepted`, `decidedBy`, `decidedAt`; the history entry names the recommendation
  - reject writes only the status and decider
  - changed passage: 409 and nothing written; not pending or motion decided: 409; not chair or secretary: 403
  - hydra gates `route-auth`, `no-admin-idor`, `route-reachability`, `orphan-auth` pass
- [ ] Implement
- [ ] Test (red first): `tests/Unit/Service/ChangeRecommendationServiceTest.php`, controller test through the middleware

### Task 5: The Aanbevolen wijzigingen card
- **spec_ref**: `openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status`
- **files**: `src/components/tabs/MotionChangeRecommendationsTab.vue` (new, reuses `src/components/AmendmentDiffView.vue`), `src/registry.js`, `src/manifest.json` (MotionDetail widget `motion-change-recommendations` below `motion-amendments`, layout, slot), `l10n/`
- **acceptance_criteria**:
  - lists every recommendation of the motion, oldest first, with lines, status and the tracked change; Accept and Reject only for the chair and secretary; "Passage changed" on a stale one
- [ ] Implement
- [ ] Test (red first): vitest on the widget

### Task 6: End to end and docs
- **files**: `tests/e2e/motion-change-recommendations.spec.ts` (new, `page.` steps, tagged with each scenario), `docs/features/motion-change-recommendations.md` (new)
- [ ] Implement
- [ ] Test

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- One live accept on the dev instance before hand-back: the motion text changes and the recommendation reads accepted.
