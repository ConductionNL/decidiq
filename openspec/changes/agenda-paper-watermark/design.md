# Design: agenda-paper-watermark

Read at decidiq development `4d7430ff` and filinq development `7733074`.

## What exists

| Piece | Where |
|---|---|
| Meeting publicity | `lib/Settings/decidesk_register.json` `Meeting.isPublic` |
| Confidentiality on an item | `lib/Settings/register.d/83-confidentiality-in-plain-words.json` `ConfidentialityRestriction` (`targetAgendaItem`, `targetDocument`, `lifecycle`) |
| Papers | OpenRegister `FileService::getFiles()` per agenda item and meeting; `lib/Service/MeetingPackageService.php:95` `assemble()` copies them into the meeting folder (`:299` `getContent()`, `:545` `newFile()`) |
| Reaching filinq | `lib/Support/FilinqPdf.php` and `lib/Support/FleetAppId.php` resolve filinq's services under both app ids |
| filinq page import | filinq `lib/Service/Pdfa3ConversionService.php:480` `importPage()` then `useTemplate()` per page; `lib/Service/MergedPdfDocument.php` uses `setasign\Fpdi\Fpdi` |
| Former spec | archived `2026-06-12-board-meeting-resolutions/specs/multilingual-minutes-and-access.md:35-49` |

## Approach

1. **Schema.** Fragment `lib/Settings/register.d/92-paper-watermark.json` adds to `Meeting`: `watermarkPapers` enum `automatic` (default), `on`, `off`; `watermarkText` string, max 60 characters, default empty.
2. **Decide.** `lib/Service/PaperWatermarkPolicy.php` answers `mustStamp(meeting, agendaItem?)`: `on` yes, `off` no, `automatic` yes when `isPublic` is false or an active `ConfidentialityRestriction` targets the item or the document.
3. **Serve.** `lib/Controller/PaperController.php` `view(int $fileId)` (`#[NoAdminRequired]`): resolves which agenda item or meeting holds the file through `FileService`, checks the caller can read that object (OpenRegister `ObjectService::find()` under the caller's rights, 404 otherwise), asks the policy, and either streams the file as is or streams a stamped copy. The stamp is "<display name>, <Y-m-d H:i>, <watermarkText>" diagonally and in the footer of every page.
4. **Stamp.** `lib/Support/FilinqStamp.php`, the sibling of `FilinqPdf`, resolves filinq's stamping method through `FleetAppId`. When filinq or the method is absent it returns null, and the controller answers 503 with "This paper can only be shown with a watermark, and the watermark service is not installed" rather than serve an unstamped copy.
5. **Cache.** Stamped copies are kept in decidiq's app data under `watermark/<fileId>-<etag>-<uid>-<date>.pdf` and swept daily by the existing background job registry (`appinfo/info.xml` jobs), so a reader re-opening a paper the same day gets the cached copy.
6. **Screens.** The agenda item page's paper list and the meeting page's documents link through `/api/papers/{fileId}/view` when the policy applies; the Planning widget of `MeetingDetail` (`src/manifest.json:561`) shows the two new fields.
7. **Package.** `MeetingPackageService::assemble()` stamps each paper for the requesting user when the policy applies.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Settings on the meeting | Declarative schema fields | Plain data |
| Whether to stamp | Imperative policy class | A rule across Meeting and ConfidentialityRestriction objects |
| Stamping | Imperative, delegated to filinq | ADR-031 exception: document generation |

## Seed data

In the municipality example set, the closed session "Besloten vergadering grondzaken" gets `watermarkPapers: on` and `watermarkText: "Vertrouwelijk"`; the public council meeting keeps `automatic`, so its papers open unstamped.

## Sibling half

filinq: a method that takes a PDF and a text and returns the PDF with the text stamped on every page, on the FPDI and mPDF page loop filinq already has. Listed in this lane's hand-back for the coordinator.

## Files

- `lib/Settings/register.d/92-paper-watermark.json`, `lib/Settings/profiles/municipality.json`
- `lib/Service/PaperWatermarkPolicy.php`, `lib/Support/FilinqStamp.php`, `lib/Controller/PaperController.php`, `appinfo/routes.php`, `lib/Service/MeetingPackageService.php`
- `src/components/tabs/AgendaPaperRenditionsTab.vue` (from `agenda-office-files-to-pdf`), `src/manifest.json`
- `tests/Unit/Service/PaperWatermarkPolicyTest.php`, `tests/Unit/Controller/PaperControllerTest.php`, `tests/e2e/paper-watermark.spec.ts`
