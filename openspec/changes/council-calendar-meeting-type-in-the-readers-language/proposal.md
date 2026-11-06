# Proposal: council-calendar-meeting-type-in-the-readers-language

## Why

Rehearsal R3, 16 October: the Vergaderkalender showed the stored meeting type `regular` in its Vergaderingstype column. #1753 declared labels for the headings, but the values under them are machine values, and `PortalLabelTranslator` only translates a manifest's `label` and `group`.

## What changes

- `publicCalendar` declares `valueLabels` for `meetingType` (regular, extraordinary, committee, public hearing, general assembly) and for `oriType` (Vergadering, Besluit, Verslag), in English source words with nl and en catalogue entries.
- `PortalLabelTranslator` translates every label under a `valueLabels` map and keeps its keys, the stored values portaliq matches on.

## Not changed

- The stored values and the filters on them.
- The columns and the detail card.

## Rollback

Revert the commit.
