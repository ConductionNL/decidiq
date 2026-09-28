# Design: minutes-draft-and-send

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Draft | `lib/Service/MinutesGenerationService.php:90`, route `appinfo/routes.php:98` |
| AI draft | `lib/Service/MinutesDraftService.php:117,163`, `src/components/tabs/MeetingTranscriptionTab.vue:549` |
| Distribute | `lib/Service/ALVMinutesService.php:173`, route `appinfo/routes.php:104` |

## Approach

1. A minutes actions widget with the two buttons, shown by lifecycle.
2. MinutesDraftRenderer adds an attendance section.
3. A write-back endpoint (or object store save) that copies the AI draft into the minutes record.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: the rendered draft contains the attendance section (red before).
- vitest: Use as minutes saves content onto the minutes; Send to members calls distribute.
