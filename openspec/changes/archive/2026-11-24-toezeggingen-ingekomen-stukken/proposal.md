---
kind: code
depends_on: [motie-amendement-administratie]
---

# Proposal: toezeggingen-ingekomen-stukken

## Summary

Add the two classic griffie registers every Dutch raadsinformatiesysteem ships and decidiq lacks: a standalone **toezeggingenlijst** (commitments register — political commitments made by a portefeuillehouder/college member to the council, tracked from utterance to afdoening with deadline rappels and a public list) and a **lijst ingekomen stukken** (incoming documents registry — registration, proposed routing advice, placement on the next council meeting's dedicated agenda item, bulk council confirmation via the existing hamerstuk flow, and WOO-aware public publication). Both are new OpenRegister schemas delivered as `lib/Settings/register.d/` fragments plus manifest pages, with declarative lifecycle (`x-openregister-lifecycle`), declarative rappels (`x-openregister-notifications`), and a dashboard KPI for open toezeggingen past deadline.

## Motivation

Market evidence (2026-07-16 deep-dive): GemeenteOplossingen ships a dedicated "GO Toezeggingen" module; live 2025–2026 RIS tenders demand integrated information provision for griffie workflows; related demand clusters q-a-management (928) and deadline-management (782). In decidiq today:

- **Toezeggingen** exist only as a side effect of the `motie-amendement-administratie` change, and there only for *motion-tied* commitments (a college takeover of a motion produces an `UitvoeringsUpdate`). The far more common case — a wethouder committing to something during debate on any agenda item ("ik zeg u toe dat u voor 1 maart een raadsbrief ontvangt") — has no home. Toezeggingen are also **not** action items: `ActionItem` is a CalDAV VTODO (REQ-AI-DECK-001/-004 forbid app-local task stores) for internal work, whereas a toezegging is a political commitment with public accountability, a deadline, an afdoening record, and a published list.
- **Ingekomen stukken** appear only as seed text in an archived design (`"…vaststelling agenda, ingekomen stukken en mededelingen"`). There is no registration, no routing advice, no bulk confirmation, no public list.

Without these registers a griffie cannot run its weekly cycle in decidiq and keeps a parallel Excel/GO instance — a hard adoption blocker for the municipal domain.

## Affected Projects

- [ ] Project: `decidiq` — new `Toezegging` + `IngekomenStuk` schemas (register.d fragment), manifest pages + menu (manifest.d fragment), dashboard KPI widget, publication-eligibility extension, bulk-routing confirmation action, seed data, docs, tests.

No other apps change. OpenRegister is consumed as-is (lifecycle, notifications, aggregations, RBAC published-predicate are existing capabilities).

## Scope

### In Scope

1. **Toezegging schema** (register.d): commitment text, madeBy (Person ref — portefeuillehouder), madeDuring (Meeting + AgendaItem refs), directedTo (GovernanceBody ref), deadline, lifecycle `open → in-uitvoering → afgedaan / vervallen` (x-openregister-lifecycle), afdoening note + evidence link, optional `relatedMotion` cross-reference to a Decision of `decisionType: motion` (afdoening evidence then references the motie change's `UitvoeringsUpdate` — no duplicate execution tracking).
2. **Declarative rappels**: x-openregister-notifications scheduled triggers before and after the deadline to the portefeuillehouder + griffie; no imperative dispatch, no bespoke ReminderJob.
3. **Public toezeggingenlijst**: extend the existing public-publication eligibility/payload machinery (derived immutable payload + `publicatiedatum` RBAC predicate; never an app-local anonymous page).
4. **CSV export** of the toezeggingenlijst via `ExportService` + `CnMassExportDialog` (same pattern as agenda-publication REQ-PUB-005).
5. **IngekomenStuk schema** (register.d): title, sender (with senderType; WOO-aware anonymisation of natural persons in public payloads), receivedAt, category, routing advice enum (`voor-kennisgeving-aannemen` / `in-handen-college-ter-afdoening` / `in-handen-college-ter-voorbereiding` / `betrekken-bij-agendapunt`), lifecycle for follow-up tracking, link to the "Lijst ingekomen stukken" AgendaItem of the next council meeting.
6. **Bulk council confirmation** of routing advice as a hamerstuk flow reusing agenda-live-management REQ-LIV-003 semantics (batch confirm; individual stuk can be pulled off the list for separate discussion).
7. **List + detail pages** for both registers as manifest.d fragment pages per existing manifest-v2 conventions (schema refs by slug).
8. **Dashboard KPI** "Open toezeggingen over deadline" — declarative stat widget on the existing Dashboard manifest page.

### Out of Scope

- Full document management / DMS for ingekomen stukken (files attach via the existing Files leaf / `FileService` only).
- E-mail intake automation — the Email leaf exists (`email-linking-via-email-leaf`); auto-registering an inbound mail as IngekomenStuk is deliberately deferred to a future change.
- WOO request handling (verzoekafhandeling) — different legal process, different app concern.
- Motion execution tracking — stays in `motie-amendement-administratie` (`UitvoeringsUpdate`); this change only cross-references it.
- Q&A management (schriftelijke vragen) — related demand, separate future change.

## Approach

Pure thin-client extension per ADR-022/ADR-037: two new schemas shipped as a `lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json` fragment (never editing `decidesk_register.json`), all behaviour declared in OpenRegister dialects — lifecycle via `x-openregister-lifecycle` (canonical `initial` keyword), rappels via `x-openregister-notifications` scheduled triggers, KPI counts via manifest stat-widget source aggregation. UI is a `src/manifest.d/toezeggingen-ingekomen-stukken.json` fragment (two index + two detail pages + menu entries). The only imperative code: extending `PublicationEligibilityService`/`PublicationPayloadService` with the two new payload types (incl. sender anonymisation) and a small bulk-confirm action on the ingekomen-stukken list (mirroring `processHamerstukken()`). Details in design.md.

## New Dependencies

None. All capabilities used (lifecycle, notifications, aggregations, RBAC published-predicate, ExportService, hamerstuk batch) already exist in OpenRegister, nc-vue, and decidiq.

## Impact

- `lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json` (new — schemas + dialects + seed data).
- `src/manifest.d/toezeggingen-ingekomen-stukken.json` (new — pages + menu).
- `src/manifest.json` (edit — one Dashboard stat widget; fragments replace same-id pages wholesale, so the existing Dashboard page cannot be extended from a fragment).
- `lib/Service/PublicationEligibilityService.php`, `lib/Service/PublicationPayloadService.php` (edit — Toezegging/IngekomenStuk payload types + anonymisation).
- `src/views` or store action for bulk routing confirmation (small; reuses existing dialog components).
- Docs + PHPUnit/e2e per hydra gates.

## Cross-Project Dependencies

- `motie-amendement-administratie` (decidiq change, declared in `depends_on`): the `relatedMotion` cross-reference and the "afdoening via UitvoeringsUpdate" rule assume that change's Motion/UitvoeringsUpdate model. The dependency is soft at runtime (`relatedMotion` is nullable; a standalone toezegging never touches it) but the spec text references its schemas, so it must land first or concurrently.
- OpenRegister: consumed, not changed.

## Risks

### Risk 1: WOO/AVG leakage of natural-person senders

**Severity:** High — **Mitigation:** anonymisation is enforced in `PublicationPayloadService` allow-list construction (server-side, structural), mirroring the existing "totals, never voters" rule; senderType drives it; PHPUnit asserts a natuurlijk-persoon sender never appears in a payload; the live IngekomenStuk object stays RBAC-protected.

### Risk 2: Toezegging drifts into a second action-item store

**Severity:** Medium — **Mitigation:** spec states explicitly that Toezegging is not an ActionItem and MUST NOT be written as a VTODO or counted in the action-item KPI; follow-up work a griffie derives from a toezegging remains a VTODO via the existing extraction flow.

### Risk 3: Overlap with motion execution tracking

**Severity:** Medium — **Mitigation:** hard rule in the spec: when `relatedMotion` is set, execution narrative lives on the motion's UitvoeringsUpdate log; the toezegging only mirrors terminal status. No second event-log schema.

### Risk 4: Dashboard "past deadline" filter needs a relative-date token

**Severity:** Low — **Mitigation:** the stat-widget source filter uses a relative now-token like the existing `@workspace.dateFrom?` tokens; if the widget filter DSL turns out not to support it, fall back to routing the KPI through an index quick-filter and a count on `lifecycle` only (documented in design.md).

## Rollback Strategy

Revert the PR: removing the register.d and manifest.d fragments de-registers schemas/pages on next load/build (ADR-037 fragments are additive; no edits to existing schemas). Already-created Toezegging/IngekomenStuk objects remain in OpenRegister (soft-retained, queryable, no orphaned code paths); published payloads are withdrawn via the existing withdraw flow if needed. No data migration to undo.

## Open Questions

- Exact relative-date token supported by the stat-widget filter DSL (see Risk 4); resolved during implementation against nc-vue's widget source resolver.
