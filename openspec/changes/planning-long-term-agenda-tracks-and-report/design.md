# Design: planning-long-term-agenda-tracks-and-report

Kind: code. A schema patch, a save guard, manifest changes and one report
service. Read against decidiq `development` at 4d7430ff.

## What exists today

- **The schema.** `lib/Settings/register.d/86-the-last-two-dutch-names.json:6`
  `PlannedAgendaItem` (slug `planned-agenda-item`, properties from `:129`):
  `subject`, `governanceBody`, `plannedPeriod`, `expectedType`, `ownerType`
  (`:160`, facetable), `owner` (`:168`, `$ref Person`, not facetable),
  `lifecycle` (declared `x-openregister-lifecycle`), `shiftHistory`, the origin
  and realisation links, and the publication dates. No parent and no author.
- **The page.** `src/manifest.d/termijnagenda.json`: index `PlannedAgenda`
  with lifecycle quick filters (`:28`), columns ending with `ownerType`
  (`:40`), `showMassExport: true` (`:46`); detail `PlannedAgendaDetail` with a
  data widget, a files widget and a Related widget.
- **The precedent for nesting.** `AgendaItem.parentItem`
  (`lib/Settings/decidesk_register.json`, AgendaItem) nests an agenda item
  under a heading, one level.
- **A save guard.** `SubmissionDeadlineListener`
  (`lib/Listener/SubmissionDeadlineListener.php`) refuses a save on
  OpenRegister's `ObjectCreatingEvent`.
- **PDF.** `FilinqPdf::fromHtml()` (`lib/Support/FilinqPdf.php:97`) renders
  through filinq and returns null when filinq is absent;
  `MinutesDocumentService` (`lib/Service/MinutesDocumentService.php:96`) then
  saves the source document with an honest note (ADR-075).

## D1. A track points at its topic

`parentItem` (uuid, `$ref: planned-agenda-item`, facetable, nullable) in a new
fragment `lib/Settings/register.d/92-long-term-agenda-tracks.json`, following
`AgendaItem.parentItem`. The topic is an ordinary item: it keeps its own
subject, period and status.

`x-openregister-aggregations` on the schema:

- `trackCount`: count `planned-agenda-item` where `parentItem = @self.id`.
- `openTrackCount`: the same, with `lifecycle` in `planned`, `postponed`.

`PlannedAgendaDetail` gains an `object-list` widget "Tracks" filtered
`parentItem: @objectId`, with columns subject, body, period, portfolio holder
and status, and `allowCreate` so a track is added in context. The index gains a
quick filter "Topics only" (`parentItem` empty) and a column for the topic.

## D2. One level, enforced on save

`PlannedAgendaTrackGuardListener` on `ObjectCreatingEvent` and
`ObjectUpdatingEvent` for `planned-agenda-item`:

- refuses a `parentItem` whose target has a `parentItem` itself;
- refuses a `parentItem` on an item that has tracks;
- refuses a `parentItem` pointing at the item itself.

Each refusal names the topic in plain words. A schema rule cannot express a
condition on the referenced object, so this is the ADR-031 guard seam.

## D3. Portfolio holder and author are filters

`owner` gains `facetable: true` and a column `{ key: 'owner', label: 'Portfolio
holder', widget: 'fkResolve', widgetProps: { register: 'decidiq', schema:
'person', labelField: 'name' } }`. The column `ownerType` stays, labelled
"Owner type".

`author` (uuid, `$ref: Person`, facetable, nullable): the official who drafts
the proposal. A `Person` like `owner`, so both resolve the same way and an
official is recorded once.

## D4. The formatted report

`PlannedAgendaReportService::render(array $filters)`: reads the rows the page's
current filters select, through OpenRegister's object API with the caller's
own access (so the report never shows a row the list would hide), groups tracks
under their topic, orders by `plannedPeriod`, and writes an HTML document with
a heading, the filters in plain words, the date and per row subject, body,
period, portfolio holder, author and status. `FilinqPdf::fromHtml()` turns it
into a PDF saved in the user's Files under `Decidiq/Reports/`, and the
response carries the path.

Route `POST /api/planned-agenda/report`, `#[NoAdminRequired]`: the report reads
through the caller's access, so there is no object to guard beyond that.

The page gets a header action "Download report" that posts the active filters
and opens the file.

## Declarative or imperative

- `parentItem`, `author`, the facets and the aggregations: declared.
- The tracks widget, columns and quick filter: declared in
  `src/manifest.d/termijnagenda.json`.
- The one-level rule: imperative, a guard, because it reads the referenced
  object.
- The report: imperative, under the ADR-031 exception for rendered document
  output, through filinq (ADR-075).

## Seed data

In `lib/Settings/profiles/municipality.json`:

1. Topic "Energietransitie", owner wethouder De Boer, period 2026-Q3, no
   parent.
2. Track "Warmtevisie 2.0" under it, body gemeenteraad, 2026-Q4, author
   beleidsmedewerker S. Visser, `planned`.
3. Track "Regionale energiestrategie 2.0" under it, body commissie Ruimte,
   2027-Q1, `postponed`, one entry in `shiftHistory`.

## Risks

- A long report on a slow filinq. The report is bounded by the filters and the
  page's own result limit; a filter that selects nothing returns 422 "No items
  match these filters" rather than an empty document.
