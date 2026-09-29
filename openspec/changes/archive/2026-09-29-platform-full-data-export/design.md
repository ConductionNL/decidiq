# Design: platform-full-data-export

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Read API | `appinfo/routes.php:263-264` |
| Regulator export | `lib/Service/RegulatorExportService.php` |

## Approach

1. A FullExportJob pages through ObjectServiceInterface::findAll per schema and writes to an app data ZIP; files copied from the meeting folders.

## Declarative or imperative

Imperative: an export job; no schema change.

## Tests

- PHPUnit: the export writes one JSON per schema with every object (red before: class missing).

## As built (29 Sep, lane 12)

- `lib/Service/FullExportService.php` pages `ObjectServiceInterface::findAll` per shipped schema (500 per page, `_rbac: false`, `_multitenancy: false`), writes `<slug>.json`, the files of meeting, agenda-item and decision objects (OpenRegister FileService `getFiles`) under `files/<slug>/<id>/`, and `manifest.json` (schemas with version, count and relations read from `$ref`/`format: uuid`; `skipped` and `missingFiles` so nothing is left out in silence). The archive lives in app data `exports/`; only the newest is kept, and the old one is removed only after the new one is stored.
- `lib/BackgroundJob/FullExportJob.php` (QueuedJob) builds it and notifies the administrator: `full_export_ready` links to `GET /api/export/full/{name}`, `full_export_failed` says it failed.
- `lib/Controller/FullExportController.php`: `POST /api/export/full` queues, `GET /api/export/full` returns the newest, `GET /api/export/full/{name}` streams it; all `#[AuthorizedAdminSetting(AdminSettings::class)]`, the name must match `decidiq-export-YYYYmmdd-HHMMSS.zip`.
- Admin settings: `src/views/settings/FullExportSettings.vue` (Export all data, link to the newest export).
- Correction: the design named `src/views/settings/` only; the job and controller are new files. No import (out of scope).
