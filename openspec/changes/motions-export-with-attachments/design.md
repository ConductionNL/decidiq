# Design: motions-export-with-attachments

Read at decidiq development `4d7430ff`, filinq development `7733074`, openregister development `555af72` and nextcloud-vue development `c8aa858`.

## What exists

| Piece | Where |
|---|---|
| Lists | `src/manifest.json:856` `Motions` (schema `decision`, filter `decisionType: motion`), `Decisions`; both rendered by `CnIndexPage` |
| Bulk actions | nextcloud-vue `src/components/CnIndexPage/CnIndexPage.vue:2476` `bulkActions` (`handler: "open-modal"` with `target`, the modal receives `selectedIds`); filters persisted in `$route.query` (`:4898-4913`) |
| Attachments | openregister `lib/Service/FileService.php:1604` `getFiles($object)`; `lib/Service/MeetingPackageService.php:497-530` shows decidiq's use |
| Text to PDF | `lib/Support/FilinqPdf.php:97` `fromHtml($html, $title, $context)` returns PDF bytes or null |
| Merge | filinq `lib/Service/DocumentMergeService.php:134` `merge(inputs [{fileId, label}], options, hostObject)`, `:170` `queue()`, `:241` `shouldQueue()`; refuses unreadable inputs before a job exists and writes no partial file |
| Reaching filinq | `lib/Support/FleetAppId.php` |

## Approach

1. **Action.** Both lists declare `bulkActions: [{ id: "export-bundle", label: "Export with attachments", icon: "FilePdfBox", handler: "open-modal", target: "ExportBundleModal" }]`.
2. **Modal.** `src/modals/ExportBundleModal.vue` (modal isolation gate: own file under `src/modals/`) offers Scope (selected rows, or all rows matching the current filter read from `$route.query`) and Format (one PDF, ZIP). It posts to the endpoint and shows the queued or finished result with a link to the file.
3. **Endpoint.** `lib/Controller/ExportBundleController.php` `create()` (`#[NoAdminRequired]`): `POST /api/exports/decision-bundle` with `{ ids?: [], filter?: {}, format: "pdf"|"zip" }`. It resolves the decisions through OpenRegister's `ObjectService::findAll()` under the caller's session (so read rules apply), orders them by the list's sort, and refuses more than 500.
4. **Service.** `lib/Service/ExportBundleService.php`:
   - PDF: for each decision, render title, number, proposer, dates and text to HTML and through `FilinqPdf::fromHtml()` into a temporary file in the caller's `Decidiq exports/.work` folder; append its attachments from `FileService::getFiles()`; hand the whole ordered list to filinq's `DocumentMergeService` (`queue()` when `shouldQueue()` says so, else `merge()`), bookmarks on, target `Decidiq exports`, name `<list> <Y-m-d>.pdf`. The temporary pages are removed when the job finishes.
   - ZIP: build `<list> <Y-m-d>.zip` with one folder per decision holding its attachments and a `decision.html` with its text.
   - Without filinq the PDF format is disabled in the modal and the endpoint answers 503 for it; the ZIP format works without filinq.
5. **Notice.** A queued PDF sends a decidiq notification when filinq's job finishes (decidiq listens for filinq's merge job reaching done, or polls its status on the next request when filinq emits no event).

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| The action on the lists | Declarative `bulkActions` in the manifest | CnIndexPage vocabulary |
| Building the bundle | Imperative service delegating to filinq | ADR-031 exception: document generation |

## Seed data

None: the example sets have motions with attachments.

## Files

- `src/manifest.json` (`Motions`, `Decisions`), `src/modals/ExportBundleModal.vue`, `src/registry.js`
- `lib/Controller/ExportBundleController.php`, `lib/Service/ExportBundleService.php`, `appinfo/routes.php`
- `tests/Unit/Service/ExportBundleServiceTest.php`, `tests/Unit/Controller/ExportBundleControllerTest.php`, `tests/e2e/export-bundle.spec.ts`
