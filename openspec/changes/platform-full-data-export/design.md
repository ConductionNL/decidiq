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
