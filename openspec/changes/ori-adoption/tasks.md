# Tasks — ori-adoption

> Scope reminder: decidesk becomes the sole home of raadsinformatie; ORI
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

- [ ] 2.2 Create `lib/Command/ImportOriCommand.php` (`occ decidesk:import-ori`)
  with `--source-register=ori`, `--dry-run`, `--rollback`, `--strict`,
  registered in `appinfo/info.xml`. Dry-run performs zero writes and prints
  per-schema source/target counts; rollback deletes only `ori:*`-tagged
  objects.
  **Acceptance:** on a dev instance seeded with procest's ORI register:
  dry-run leaves decidesk object counts unchanged (measure counts before/after,
  not the command's own report); live run then re-run produce identical counts;
  rollback restores the pre-import decidesk count while the source register is
  untouched.

## 3. ORI interop adapter

- [ ] 3.1 Create `lib/Service/Ori/OriExportMapper.php` — stateless projection
  of `Meeting`/`AgendaItem`/`DigitalDocument`/`VotingRound` into the ORI wire
  shape. This is the only file where Dutch statutory field names/values appear.
  **Acceptance:** unit tests round-trip an imported object (ORI source →
  Popolo storage → ORI wire) and assert field-level equality on the wire shape;
  a repo-wide grep confirms no Dutch identifier from the mapper leaks into any
  schema or other class.

- [ ] 3.2 Add `lib/Controller/OriFeedController.php` + routes
  `/feed/ori/meetings.rss`, `/feed/ori/agenda-items.rss`,
  `/feed/ori/documents.rss` (`#[PublicPage]`, `#[NoCSRFRequired]`, GET only),
  serving public objects via the mapper. Feature-parity with procest's retired
  `RaadsinformatieFeedController` output.
  **Acceptance:** anonymous `curl` (no session cookie) returns HTTP 200 +
  `application/rss+xml` for all three feeds; a non-public object does not
  appear; hydra gates (route-auth, no-admin-idor) pass.

## 4. Demo seed

- [ ] 4.1 Translate the Voorbeeldstad demo dataset from procest's
  `ori_register.json` through the mapping into a new register fragment
  `lib/Settings/register.d/66-ori-raadsinformatie-seed.json` (bodies, persons,
  memberships, meetings, agenda items, documents, decisions, voting rounds).
  **Acceptance:** double import creates each object exactly once; seed values
  keep Dutch *content* (names, titles) but zero Dutch *identifiers*.

## 5. Verification & handover

- [ ] 5.1 Run the full check suite and the e2e smoke on the feeds; document the
  release note "decidesk is now the home of raadsinformatie; procest instances
  migrate via `occ decidesk:import-ori` (see procest change `ori-removal`)".
  **Acceptance:** `composer check:strict` clean; feeds e2e green; release note
  present in the PR body; procest `ori-removal` is unblocked (its tasks
  reference this change by name).
