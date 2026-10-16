# Proposal: council-calendar-columns-in-the-readers-language

## Why

Seen in the Zuiddrecht go-live rehearsal: the resident's Vergaderkalender headed its table "Meeting date", "Title", "Body name", "Meeting type". The `publicCalendar` collection declared no labels, so portaliq fell back to the `publication-payload` schema's English property titles. `PortalLabelTranslator` only translates labels the manifest carries.

## What changes

- `publicCalendar` declares `fieldConfigs` labels for the fields it shows: Meeting, Date, Body, Meeting type, Location. They go through decidiq's catalogue, so a Dutch reader reads Vergadering, Datum, Orgaan, Vergaderingstype, Locatie. All five strings already have nl and en entries.
- It declares its columns (date and time, meeting, body, meeting type) and a detail card of the same fields plus the location, so the filter fields `audiences` and `oriType` are not shown.

## Not changed

- The whitelist, filter and sort of the collection.
