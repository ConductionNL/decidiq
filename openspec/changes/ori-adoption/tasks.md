# Tasks — ori-adoption

> Scope reminder: decidiq becomes the sole home of raadsinformatie; ORI
> concepts are stored Popolo-aligned per the mapping table in `design.md`; the
> ORI wire format lives in an adapter only. Paired with procest `ori-removal`,
> which MUST NOT start until tasks 1–3 here are merged and released.
>
> Acceptance gates: a checkbox flips only when its acceptance criteria pass —
> run the listed commands, do not mark by inspection.

## 1. Schema deltas (additive)

- [ ] 1.1 Extend `lib/Settings/decidesk_register.json` with the additive deltas
  from REQ-ORIA-002: `Meeting.meetingType` += `informational-session`,
  `Meeting.lifecycle` += `cancelled`, `AgendaItem.documents`,
  `DigitalDocument.{classification,url,fileName}`,
  `VotingRound.{partyResults,subjectDecision}`, `GovernanceBody.bodyType` +=
  `political-group`, `GovernanceBody.{seatCount,coalitionRole}`. No renames, no
  removals. Bump the register `version` so `occ upgrade` re-imports (a version
  left unchanged makes the import a no-op).
  **Acceptance:** register JSON validates; `importFromApp()` on an instance
  with existing objects succeeds; writes using each new enum value/property
  return 2xx; `composer check:strict` is clean.
  > 2026-11-14: the deltas were already in development (`decidesk_register.json` enums, `register.d/999-ori-adoption.json`). Added there: `externalReference` on Meeting, AgendaItem, DigitalDocument, VotingRound, Person and Membership (the import tag of 2.1), with schema versions one above the highest declared; `OriAdoptionRegisterTest::testEverySchemaTheImportWritesCarriesTheImportTag`. Box left open: the live acceptance (importFromApp on an instance with objects, 2xx writes) is not run here.
  > Built: the three enum values in `decidesk_register.json`, the new
  > properties in `register.d/999-ori-adoption.json` (an enum list in a
  > fragment would be appended by the merger but replaced by index by other
  > readers). `OriAdoptionRegisterTest` validates the merged result. Left
  > unticked until `importFromApp()` and the writes are checked on an instance.

## 2. ORI importer (data migration target)

- [ ] 2.1 Create `lib/Service/Ori/OriImportService.php` implementing the
  mapping table (schema map, property map, enum-value translations, dependency
  order, slug→uuid resolution, `externalReference: ori:<uuid>` tagging,
  update-on-rerun). Party aggregates map to `VotingRound.partyResults`; no
  per-participant `Vote` objects are fabricated. Dangling source refs are
  collected and reported; `--strict` fails the run on any.
  **Acceptance:** PHPUnit unit tests cover every row of the mapping table
  (including `brief`→`letter`, `aangenomen`→`adopted`, `proposal`→`resolution`,
  `coalitiepartij`→`coalition`, role→membership mapping incl. alderman→
  executive board) and the idempotency + dangling-ref behaviours;
  `composer check:strict` is clean.
  > 2026-11-14 built: `OriImportService` (import, preview, rollback, previewRollback) over `OriExportMapper::read*()`, with `OriImportReport`. `OriImportServiceTest` covers every mapping row named above, idempotency (second run: zero created), dangling refs and unknown values reported, rollback of `ori:*` only, and no `Vote` objects. A member's group membership is tagged `ori:<uuid>#group` (two memberships come from one source member); derived bodies are tagged `ori:council:<name>`, `ori:executive:<name>`, `ori:committee:<name>`. Box left open: `composer check:strict` as a whole is not run here (phpcs, phpmd, phpstan clean on the new files).

- [ ] 2.2 Create `lib/Command/ImportOriCommand.php` (`occ decidiq:import-ori`)
  with `--source-register=ori`, `--dry-run`, `--rollback`, `--strict`,
  registered in `appinfo/info.xml`. Dry-run performs zero writes and prints
  per-schema source/target counts; rollback deletes only `ori:*`-tagged
  objects.
  **Acceptance:** on a dev instance seeded with procest's ORI register:
  dry-run leaves decidiq object counts unchanged (measure counts before/after,
  not the command's own report); live run then re-run produce identical counts;
  rollback restores the pre-import decidiq count while the source register is
  untouched.
  > 2026-11-14 built: `ImportOriCommand`, registered in `appinfo/info.xml`; `--strict` previews first and writes nothing when the preview finds a problem. `ImportOriCommandTest`. Box left open: the acceptance runs on a dev instance seeded with the source register.

## 3. ORI interop adapter

- [ ] 3.1 Create `lib/Service/Ori/OriExportMapper.php` — stateless projection
  of `Meeting`/`AgendaItem`/`DigitalDocument`/`VotingRound` into the ORI wire
  shape. This is the only file where Dutch statutory field names/values appear.
  **Acceptance:** unit tests round-trip an imported object (ORI source →
  Popolo storage → ORI wire) and assert field-level equality on the wire shape;
  a repo-wide grep confirms no Dutch identifier from the mapper leaks into any
  schema or other class.
  > 2026-11-14 built: `OriExportMapper` holds the whole ORI vocabulary (reads for the importer, renders for the feeds). `OriExportMapperTest` round-trips meeting, document, agenda item and vote through the importer and back, and scans the change's other files for the mapper's Dutch identifiers. Box left open with 2.1 (check:strict).

- [ ] 3.2 Add `lib/Controller/OriFeedController.php` + routes
  `/feed/ori/meetings.rss`, `/feed/ori/agenda-items.rss`,
  `/feed/ori/documents.rss` (`#[PublicPage]`, `#[NoCSRFRequired]`, GET only),
  serving public objects via the mapper. Feature-parity with procest's retired
  `RaadsinformatieFeedController` output.
  **Acceptance:** anonymous `curl` (no session cookie) returns HTTP 200 +
  `application/rss+xml` for all three feeds; a non-public object does not
  appear; hydra gates (route-auth, no-admin-idor) pass.
  > 2026-11-14 built: `OriFeedController` (`#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`, GET only) over `OriFeedService` and a generic `Feed\RssDocument`; routes in `appinfo/routes.php`. Public means a meeting with `isPublic: true`, the agenda items of such a meeting, and the documents attached to those items; each item's description is the ORI wire record. `OriFeedServiceTest`, `OriFeedControllerTest`, `RssDocumentTest`. route-auth passes; no-admin-idor reports the same 22 findings as development, none in these files (they are public pages). Box left open: the anonymous curl acceptance needs a live instance, and parity with procest's retired feed could not be compared (procest is not reachable from this session).

## 4. Demo seed

- [ ] 4.1 Translate the Voorbeeldstad demo dataset from procest's
  `ori_register.json` through the mapping into a new register fragment
  `lib/Settings/register.d/66-ori-raadsinformatie-seed.json` (bodies, persons,
  memberships, meetings, agenda items, documents, decisions, voting rounds).
  **Acceptance:** double import creates each object exactly once; seed values
  keep Dutch *content* (names, titles) but zero Dutch *identifiers*.

## 5. Verification & handover

- [ ] 5.1 Run the full check suite and the e2e smoke on the feeds; document the
  release note "decidiq is now the home of raadsinformatie; procest instances
  migrate via `occ decidiq:import-ori` (see procest change `ori-removal`)".
  **Acceptance:** `composer check:strict` clean; feeds e2e green; release note
  present in the PR body; procest `ori-removal` is unblocked (its tasks
  reference this change by name).
