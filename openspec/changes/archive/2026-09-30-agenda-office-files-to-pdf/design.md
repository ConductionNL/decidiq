# Design: agenda-office-files-to-pdf

Read at decidiq development `4d7430ff`, openregister development `555af72` and filinq development `7733074`.

## What exists

| Piece | Where |
|---|---|
| filinq conversion | filinq `lib/Service/PdfConversionService.php:84` `convertToPdf(File $source): File` and `:104` `convertToPdfReporting()` (returns the file and the backend that made it); backends in order: office app through the Nextcloud conversion broker, LibreOffice headless, PhpWord plus mPDF, mPDF, EML; `lib/Service/Conversion/MpdfBackend.php:214-220` writes `<basename>.pdf` into the source's parent folder |
| Reaching filinq | `lib/Support/FilinqPdf.php:97` resolves `FleetAppId::getService($this->container, 'filinq', 'Service\PdfService')` under every name filinq has had; `lib/Support/FleetAppId.php` |
| Object folders | openregister `lib/Service/File/FolderManagementHandler.php:634` names an object's folder after its uuid; `lib/Service/FileService.php:858` `getObjectFolder()`, `:1604` `getFiles()` |
| Papers in the package | `lib/Service/MeetingPackageService.php:497-530` `resolveItemFiles()` reads each agenda item's files through `FileService::getFiles()` |
| Agenda item page | `src/manifest.json:821` `AgendaItemDetail`, `agenda-files` files integration widget |
| Listener registration | `lib/AppInfo/Registrar/PlatformIntegrationRegistrar.php` (platform events), `lib/AppInfo/Registrar/ObjectListenerRegistrar.php` (OpenRegister object events) |

## Approach

1. **Detect.** `lib/Listener/OfficePaperAddedListener.php` listens to `OCP\Files\Events\Node\NodeCreatedEvent` and `NodeWrittenEvent`. It returns at once unless the node's mime type is on an allow-list (doc, docx, xls, xlsx, ppt, pptx, odt, ods, odp, rtf) and its parent folder's name is a uuid. It then asks OpenRegister's `ObjectService` whether an object with that uuid exists in the decidiq register with schema `agenda-item` or `meeting`. Only then does it queue `lib/BackgroundJob/ConvertPaperToPdfJob.php` with the file id and the object uuid. The listener does no conversion itself.
2. **Convert.** The job resolves filinq's `PdfConversionService` through `FleetAppId::getService()`. It skips when a sibling `<basename>.pdf` exists and is newer than the source. It calls `convertToPdfReporting()` and writes the outcome onto the object's new `paperRenditions` array: `sourceFileId`, `pdfFileId`, `backend`, `convertedAt`, or `failedAt` with the attempts filinq returned. When filinq is absent it records nothing and logs once per run at info level, like `FilinqPdf`.
3. **Show.** The agenda item page's Documents widget stays OpenRegister's. A small custom widget `agenda-paper-renditions` lists each paper once: the PDF as the link, the original as "Original (Word)" for the secretariat, and a failed conversion with its reason and a Try again action.
4. **Bundle.** `MeetingPackageService::resolveItemFiles()` prefers a rendition's PDF over its source when both are present, so the package members download is PDF.
5. **Switch.** App config key `convert_office_papers` (default `true`) in `SettingsService`, shown on the admin settings page with filinq's presence.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Detecting a new paper | Imperative listener | A Nextcloud Files event; no `x-openregister-*` extension observes Files |
| Converting | Imperative queued job delegating to filinq | ADR-031 exception: document generation and an external converter |
| Recording renditions | Declarative schema property `paperRenditions` on `AgendaItem` and `Meeting` | Plain data on the owning object |

## Schema

Fragment `lib/Settings/register.d/111-office-papers-to-pdf.json` adds to `AgendaItem` and `Meeting`:

`paperRenditions`: array of objects `{ sourceFileId: integer, sourceName: string, pdfFileId: integer|null, backend: string|null, convertedAt: date-time|null, failedAt: date-time|null, failure: string|null }`, read-only in forms.

## Seed data

One agenda item in the municipality example set, "Begroting 2027", gets a `paperRenditions` entry for "Programmabegroting 2027.docx" converted by the `office` backend, and one entry for "Bijlage investeringen.xlsx" with `failedAt` and failure "No backend could convert this file", so both states show on a fresh install.

## Files

- `lib/Listener/OfficePaperAddedListener.php`, `lib/BackgroundJob/ConvertPaperToPdfJob.php`, `lib/AppInfo/Registrar/PlatformIntegrationRegistrar.php`
- `lib/Settings/register.d/111-office-papers-to-pdf.json`, `lib/Settings/profiles/municipality.json`
- `lib/Service/MeetingPackageService.php`, `lib/Service/SettingsService.php`, `src/views/settings/AdminRoot.vue` (the switch)
- `src/components/tabs/AgendaPaperRenditionsTab.vue`, `src/registry.js`, `src/manifest.json` (`AgendaItemDetail`)
- `tests/Unit/Listener/OfficePaperAddedListenerTest.php`, `tests/Unit/BackgroundJob/ConvertPaperToPdfJobTest.php`, `tests/e2e/office-papers-to-pdf.spec.ts`

## Corrections at build (30 Sep 2026)

- The fragment is `111-office-papers-to-pdf.json`; 92 was taken by then.
- The listener is registered by its own `lib/AppInfo/Registrar/FilesEventRegistrar.php`, called from `CrossAppEventRegistrar`, not by `PlatformIntegrationRegistrar`.
- The widget sits on both the agenda item and the meeting page, as `AgendaPaperRenditionsTab`. Try again is `POST /api/papers/{schema}/{objectId}/{fileId}/convert`, limited to the chair, the secretary and administrators, and only for a paper the page records.
- The admin switch is its own panel, `src/views/settings/OfficePaperSettings.vue`, and `GET /api/settings` reports `filinq` so the panel can say conversion needs it.
- The example set puts both states on the agenda item "Kadernota begroting 2026" (`Kadernota 2026.docx` converted, `Bijlage investeringen.xlsx` failed); the municipality profile has no "Begroting 2027" item.
