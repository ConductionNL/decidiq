# Proposal: ori-adoption

Paired with procest change `ori-removal` (procest/openspec/changes/ori-removal/).
**This change ships FIRST**; the procest removal depends on it.

## Summary

decidiq becomes the **sole home of raadsinformatie** (Dutch council information).
procest currently ships `lib/Settings/ori_register.json` — an "ORI (Open
Raadsinformatie)" OpenRegister register with six Dutch-named schemas
(`vergadering`, `agendapunt`, `raadsdocument`, `stemming`, `raadslid`,
`fractie`) plus Voorbeeldstad demo data — which duplicates decidiq's Popolo-
flavoured governance domain. Per the product-owner decision, those registers move
to decidiq **purely**, and they are modelled on Popolo from the start: the ORI
concepts are aligned onto decidiq's existing schemas (`Meeting`, `AgendaItem`,
`DigitalDocument`, `VotingRound`, `Decision`, `Person`, `Membership`,
`GovernanceBody`) rather than importing the Dutch-named schemas as-is.

The ORI standard itself (the Dutch Open Raadsinformatie API/wire shape, VNG
ODS-Open-Raadsinformatie) is supported as an **adapter/mapping layer only** —
never as the storage model. Statutory Dutch wire-format field names
(`vergadering`, `agendapunt`, `aangenomen`, …) exist exclusively inside that
adapter, consistent with the fleet rule that all code and schema identifiers are
English.

## What changes

1. **Schema alignment (additive `MODIFIED` deltas to `decidesk_register.json`).**
   Small, additive extensions to existing Popolo-style schemas so every ORI
   concept has a lossless home (full mapping table in `design.md`):
   - `Meeting.meetingType` gains `informational-session`; `Meeting.lifecycle`
     gains `cancelled`.
   - `AgendaItem` gains `documents` (array of `DigitalDocument` refs).
   - `DigitalDocument` gains `classification`, `url`, `fileName`.
   - `VotingRound` gains `partyResults` (aggregated per-party breakdown that
     cannot be decomposed into per-participant `Vote` objects without inventing
     data) and `subjectDecision` (ref to the `Decision` voted on).
   - `GovernanceBody.bodyType` gains `political-group`; `GovernanceBody` gains
     `seatCount` and `coalitionRole` (`coalition` | `opposition`).
   No property is removed or renamed; all existing decidiq objects stay valid.

2. **ORI import command (the migration target's own importer).**
   `occ decidiq:import-ori` reads objects from a source ORI-shaped OpenRegister
   register (default slug `ori`, the register procest provisioned) and writes
   Popolo-aligned decidiq objects, applying the mapping table. Supports
   `--dry-run` (report-only, counts + per-object mapping preview), is idempotent
   (each created object records its source ORI uuid in `externalReference`), and
   never deletes source data. procest's `ori-removal` change invokes this and
   only removes its register after the dry-run and live-run counts match.

3. **ORI interop adapter (read-side, wire format only).**
   An `OriExportMapper` service + public feed endpoints
   (`/feed/ori/meetings.rss`, `/feed/ori/agenda-items.rss`,
   `/feed/ori/documents.rss` — replacing procest's
   `RaadsinformatieFeedController` feeds) that render stored Popolo objects in
   the Dutch ORI wire shape. This is the only place Dutch statutory field
   names/values appear. The existing `PublicationRecord.oriType`
   (`Besluit`/`Vergadering`/`Verslag`) publication path is reused, not
   duplicated.

4. **Demo seed (dev only).** The Voorbeeldstad demo dataset from procest's
   `ori_register.json` is translated through the same mapping into a decidiq
   `register.d` seed fragment so demo environments keep a populated council.

## Why

- **One canonical home per domain** (ADR-022): meetings, agenda items, votes,
  decisions, people and political groups are decidiq's core domain. Two
  parallel models (Popolo in decidiq, ORI-Dutch in procest) split the record
  and force every consumer to pick a side.
- **English identifiers are a fleet contract**: procest's ORI register violates
  it structurally (`vergadering`, `zetels`, `aangenomen`, …). Moving the data
  onto decidiq's English/Popolo schemas fixes the model; the ORI adapter keeps
  the statutory Dutch wire format where it legally belongs — at the interface.
- **procest sheds a domain it never owned**: its ORI surfaces (feed controller,
  data-quality cron, register repair step) are removed by the paired
  `ori-removal` change; procest cases that reference a council meeting link to
  the decidiq `Meeting` instead.

## Out of scope

- Removing anything from procest (that is `ori-removal`, in the procest repo).
- Bidirectional vendor sync (NOTUBIZ/iBabs) — separate change
  `notubiz-ibabs-griffie-koppeling`.
- Woo/DiWoo publication — already covered by `woo-diwoo-publication`
  (`PublicationRecord`); this change only reuses it.
- Modelling committee structure — `commissievergaderingen` and
  `shared-governance-bodies` own that; this change only references
  `GovernanceBody`.

## Sequencing / depends on

- **Ships before** procest `ori-removal`. Order: (1) this change lands the
  schema deltas + importer + adapter; (2) procest runs the importer against its
  live `ori` register, verifies counts, then removes its register, repair step,
  cron and feeds.
- Depends on OpenRegister (`ObjectService`, `ConfigurationService`) — already a
  decidiq dependency.
