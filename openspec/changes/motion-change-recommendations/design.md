# Design: motion-change-recommendations

Read at decidiq development `0174d8b7` (7 October 2026).

## Screen

Board **DcVoorstel** on the Zuiddrecht design canvas (https://claude.ai/artifact/5NkFW28vZUUij43xzxHg5a, page dcb81aee8d83) is the motion page; board **DcAmendement** shows how one text change reads. Neither board has a recommendation panel yet, so this change places it from what the boards already have:

| Board element | This change |
|---|---|
| DcVoorstel "Het voorstel" and "Beslispunten" | a toggle "Regelnummers" above the text shows the line-numbered view; "Wijziging aanbevelen" sits next to it for the chair and secretary |
| DcVoorstel "Amendementen" card ("Alle 2 bekijken", "Amendement indienen") | a sibling card "Aanbevolen wijzigingen" directly below it, same card style: per row the lines ("r. 4-5"), the first words, the status, and Accept / Reject |
| DcAmendement "Tekstwijzigingen": "Huidige tekst", "Voorgestelde tekst", "Verwijderde tekst is rood en doorgestreept, toegevoegde tekst is groen en onderstreept." | each recommendation row expands to the same two-column tracked change, rendered by the existing `AmendmentDiffView` |
| DcAmendement "Alleen de voorzitter of de secretaris kan de volgende stap zetten." | the same line under Accept and Reject for anyone else |

The Zuiddrecht design session owns the canvas; this change asks it for the "Aanbevolen wijzigingen" card on DcVoorstel and does not edit the canvas.

## What exists

| Piece | Where |
|---|---|
| Merge a passage into the motion text, keep `originalText` and `amendmentHistory`, never apply twice | `lib/Service/AmendmentTextMerger.php` `merge()`, called from `lib/Service/MotionAmendmentService.php` `applyAmendment()` (REQ-AMT-001) |
| Motion fields | `lib/Settings/register.d/91-an-adopted-amendment-changes-the-motion-text.json` (`targetPassage`, `proposedText`, `originalText`, `amendmentHistory` on `decision`) |
| Tracked-change view | `src/components/AmendmentDiffView.vue`, used by `src/components/tabs/AmendmentDiffTab.vue` |
| Chair or secretary guard on a motion | `lib/Controller/MotionController.php` `requireChairOrSecretary()` (resolves the meeting through the motion) |
| Motion page | `src/manifest.json` page `MotionDetail`, widgets `motion-data` to `motion-conflicts`, `slots` map |

## Approach

- **Schema.** A new fragment `lib/Settings/register.d/125-change-recommendations.json` (or the next free number when this is built) declares `change-recommendation`: `motion` (uuid of a `decision` with `decisionType` motion, required), `lineFrom` and `lineTo` (integers, as shown when it was made), `targetPassage` (the exact words of those lines, required), `proposedText` (required, may be empty to delete), `reason`, `status` (`pending`, `accepted`, `rejected`; default `pending`), `decidedBy`, `decidedAt`. A declared lifecycle on `status` with only `pending → accepted` and `pending → rejected`. Every property carries a `title` for the form and the schema-l10n check.
- **Line numbers.** One pure function `src/utils/lineNumbering.js` `numberLines(text, width = 80)` breaks the motion text at word boundaries into lines of at most 80 characters, keeping paragraph breaks, and returns `{ number, text, start, end }` per line. The same function serves the view and the dialog, so a number means the same thing everywhere. The server never relies on line numbers: the stored `targetPassage` is the anchor.
- **Making one.** `src/dialogs/ChangeRecommendationDialog.vue` (NcDialog, own file per ADR-004): pick "Van regel" and "Tot en met regel", the dialog fills "Huidige tekst" read-only and asks "Nieuwe tekst" and "Toelichting". It saves through the object store; the register's authorization block limits create to the chair and secretary roles (authorization-via-or-rbac).
- **Accept and reject.** `POST /api/change-recommendations/{id}/accept` and `/reject` on a new `ChangeRecommendationController`, guarded by the same meeting-role check as `MotionController::requireChairOrSecretary()` (moved to a small shared guard rather than copied). Accept calls `AmendmentTextMerger::merge()` with the recommendation as the change source; the merger gets a `kind` so the history entry records `recommendation` and the recommendation id instead of `amendment`. Both refuse unless the motion is in `draft`, `proposed` or `deliberating`, and unless the recommendation is `pending`. A merge refusal (passage not in the text) answers 409 "The passage has changed since this recommendation was made" and leaves both objects untouched.
- **Widget.** `src/components/tabs/MotionChangeRecommendationsTab.vue` lists the motion's recommendations through the object store, oldest first, marks "Passage changed" by checking `targetPassage` against the current text in the browser, and shows Accept and Reject from the existing voting-permissions answer.

## Declarative or imperative

The schema, its lifecycle and its authorization are declarative. Accept is imperative because it writes two objects (the motion text and the recommendation status) and must refuse when the merge fails; a lifecycle action on the recommendation cannot write the motion. No background job.

## Tests

- PHPUnit `tests/Unit/Service/ChangeRecommendationServiceTest.php`: accept replaces the passage and appends a history entry of kind recommendation; accept twice is refused; a changed passage is refused with both objects unchanged; reject writes only the status; a decided motion refuses both. The fixtures validate against the merged register (Opis), as `OriVotePublicationTest` does.
- PHPUnit on the controller: a member who is not chair or secretary gets 403 (asserted through the OCS middleware path, so the 403 is not turned into 200).
- vitest `tests/unit/utils/lineNumbering.spec.js`: the same text always yields the same numbers; a word longer than the width is not split; paragraph breaks start a new line.
- vitest on the widget: "Passage changed" shows when the text no longer contains the passage.
- Playwright `tests/e2e/motion-change-recommendations.spec.ts` with `page.` steps.
