# Design: termijnagenda

## Architecture Overview

Pure thin-client extension (ADR-022/ADR-037). One new OpenRegister schema — `TermijnagendaItem` — ships as the assigned `lib/Settings/register.d/50-termijnagenda.json` fragment (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load via `ConfigurationService::importFromApp()`; the base file is never edited; numbers 40–49 and 51–65 belong to sibling wave-2 changes). All workflow behaviour is declared in OpenRegister dialects (lifecycle, notifications, publication predicate); all UI is manifest-v2 pages in a `src/manifest.d/termijnagenda.json` fragment rendered by `CnPageRenderer` — the frontend talks to `/apps/openregister/api/objects` directly via the shared object stores, no decidesk CRUD controllers (redundant-controller gate).

Cross-references, not duplication:
- `originToezegging` → `toezegging` (`toezeggingen-ingekomen-stukken`); `originMotie` → `Decision` (`decisionType: motion`, `motie-amendement-administratie`); `originDecision` → `Decision`. All nullable; execution narrative stays on the motie change's UitvoeringsUpdate log.
- `realisedAgendaItem` → `AgendaItem`, `realisedDecision` → `Decision` — manual/assistive links; the termijnagenda never creates or moves agenda items or meetings (out of scope by proposal).

There is no new backend service: the reschedule interaction is a client-side composition (reason dialog → one PUT-semantic `saveObject` carrying all fields forward, appending to `verschuifHistorie`), and everything else is dialect-evaluated on the OR side.

## Decisions

### D1: Shift history is an embedded append-only array, not a separate schema

`verschuifHistorie` is an array property on `TermijnagendaItem` (items: `{van, naar, reden, door, op}` — from-period, to-period, reason, actor, timestamp), not a separate `Verschuiving` schema. A shift has no independent lifecycle, is never queried outside its item, and must travel with the item into the public payload (the public termijnagenda shows why things moved). The OR audit trail additionally records every save, so tamper-evidence does not depend on the array; the array exists to make the shift narrative a first-class, publishable part of the object.

**Alternative considered:** separate schema with item reference — rejected: N+1 on board/detail rendering, publication would need a second predicate, and nothing ever consumes a shift outside its item.

### D2: `plannedPeriod` is one canonical string with a strict pattern and a derived sort key

One property, pattern `^\d{4}-(Q[1-4]|(0[1-9]|1[0-2]))$` (quarter `2026-Q4` or month `2026-11`). Sort-key convention (defined once, used by board ordering, list sorting, and the overdue cut): a period maps to its **last day** — `2026-11` → `2026-11-30`, `2026-Q4` → `2026-12-31`. Chronological order = order by last day (ties: month before quarter, so a specific month renders before the enclosing quarter's column boundary); *overdue* = last day < today ∧ lifecycle non-terminal ∧ no realisation link. This keeps mixed granularity in one column axis without a second field the UI could desync from.

**Alternative considered:** split `periodYear` + `periodQuarter`/`periodMonth` fields — rejected: two nullable fields with an XOR constraint are harder to validate declaratively and every consumer would reimplement the merge.

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Status workflow (`gepland → verschoven ⟲ → gerealiseerd \| vervallen`) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`states`-only/`default`, the silently-ignored drift dialect) | Pure guarded state machine; `verschoven → verschoven` self-transition allows repeat shifts; zero app code |
| `vervallen` requires `redenVervallen`; `portefeuillehouder` requires `owner` | Schema conditional validation (dialect/`required`-when rules in the fragment) | Data-shape constraint, not behaviour |
| `plannedPeriod` format | JSON-Schema `pattern` | Same |
| Period-arrival rappel (owner + griffie when period arrived, non-terminal, no realisation link) | `x-openregister-notifications` scheduled trigger, nl/en subjects | ADR-031 default for reminders; notification-dialect gate (gate-18) hard-fails imperative dispatch; no bespoke ReminderJob |
| Public termijnagenda | OR RBAC published-predicate (`authorization.read`, `public` group, `publicatiedatum <= $now`) | Same live-predicate carve-out as the toezeggingenlijst (see D4) |
| Dashboard KPI "Termijnagenda over termijn" | Manifest stat-widget `source` aggregation (`metric: count`) | Declarative count like every existing KPI widget |
| Board columns per period, list, detail, filters, CSV export | Manifest-v2 pages + `ExportService`/`CnMassExportDialog` | Existing declarative page machinery |
| Drag-to-reschedule → reason dialog → save | **Imperative (frontend only)** — drop handler opens the reason dialog (own component, modal-isolation gate); confirm composes lifecycle `verschoven` + new `plannedPeriod` + history append in ONE `saveObject` | Multi-field interactive composition with a mandatory human input; not expressible as a dialect. No backend code: the OR lifecycle map still guards the transition server-side |
| Realisation suggestions (matching agenda items of the same body) | **Imperative (frontend only)** — dialog queries the standard OR list API filtered on body/upcoming | Assistive UX; the link itself is a plain reference confirmed by the user |

The mandatory-reason rule is enforced at the UI layer (dialog cannot confirm without a reason) and structurally encouraged at the schema layer (history items require `reden`); a raw API write that shifts the period without history is still legal OR-wise but is captured by the audit trail — accepted, consistent with the thin-client trust model (staff-only RBAC on writes).

### D4: Public termijnagenda = predicate on the live object

Identical rationale to the toezeggingen change's D4: the public termijnagenda must be *live* (a reschedule must show immediately — a stale public LTA is the exact failure mode this instrument exists to prevent), and the schema is designed to contain only publishable fields (no internal-notes property; shift reasons are deliberately public). So: `authorization.read` for `public` while `publicatiedatum <= $now`; publish/withdraw = staff setting `publicatiedatum`/`depublicatiedatum`. Adding any non-public property to `TermijnagendaItem` later requires revisiting this decision — noted in the schema `description` so the constraint travels with the schema.

**Alternative considered:** derived payloads via `PublicationPayloadService` — rejected: every reschedule would need a rectify cycle, guaranteeing staleness; there is no PII to strip (owners are public officeholders; no natural-person senders here).

### D5: Board view is a manifest page; drag capability follows the deck-board precedent

The board is a manifest-v2 page grouping one selected governance body's items into period columns (columns derived from the distinct `plannedPeriod` values plus the next N empty periods, ordered by the D2 sort key). Drag/drop reuses the shared board interaction family already established by `action-item-deck-board`; drop targets are period columns instead of lifecycle lanes. Keyboard alternative (WCAG 2.2 SC 2.5.7): every card has a "Verschuiven…" action opening the same reason dialog with a period picker. If the current nc-vue board component cannot bind columns to a value-derived axis, fallback is a custom-widget board slot on the manifest page (documented, ratchet-gate-aware) — the list view remains the functional path either way.

### D6: Dashboard KPI lives in the base manifest, not the fragment

Same constraint as the toezeggingen change: `buildManifest()`'s `mergePages()` replaces a same-id page wholesale, so a fragment cannot add one widget to the existing Dashboard page. The KPI widget is a direct edit to `src/manifest.json`; the fragment carries only the new pages + menu entries. Overdue filter needs a relative "today" comparison against the period's last day; reuse whatever relative-date token the toezeggingen change lands (their open question D6), with the same fallback: KPI counts non-terminal unrealised items and the overdue cut happens on the pre-filtered index (documented, never a silently wrong count).

## Nextcloud Integration

- Controllers: none new (no app-side CRUD; publication uses the OR predicate directly, not `PublicationController` payload types).
- Services: none new. CSV export via the existing `ExportService` + `CnMassExportDialog` path.
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: none — notifications and lifecycle are OR-side declarative.
- Frontend: manifest pages via `CnPageRenderer`; reschedule/realise/vervallen dialogs as own components under `src/dialogs`/`src/modals` (modal-isolation gate); object I/O via the shared object stores (PUT-semantic saveObject, all fields carried forward — never a partial write that nulls schema props).

## Security Considerations

- **Write access:** TermijnagendaItem writes are staff-only via OR RBAC (griffie/college roles per governance-body scoping); no decidesk endpoints exist to guard (no new attack surface, no no-admin-idor exposure).
- **Public predicate:** the schema carries no non-public fields by construction (D4); publish/withdraw is an explicit staff action; no writeOnly fields exist on the schema (no render-boundary exposure).
- **Lifecycle integrity:** terminal states and the transition map are enforced server-side by OR regardless of client behaviour; the mandatory-reason UI rule degrades to audit-trail traceability for raw API writes (D3, accepted).
- **CSRF/auth posture:** no new app routes; the only anonymous surface is the OR published-predicate.

## File Structure

```
lib/Settings/register.d/50-termijnagenda.json   (new — schema + lifecycle + notifications + predicate + seed)
src/manifest.d/termijnagenda.json               (new — board, index, detail pages + menu entry)
src/manifest.json                               (edit — 1 Dashboard stat widget)
src/dialogs/ or src/modals/                     (new — reschedule-reason, realise, vervallen dialogs)
tests/Unit/                                     (edit — fragment/manifest validation coverage where applicable)
tests/e2e/                                      (new — scenario coverage per gate-19)
docs/features/termijnagenda.md                  (new)
```

## Seed Data

Realistic Dutch municipal examples (fictional "Gemeente Voorbeeldingen"), per ADR-016. References use existing decidesk seed objects (gemeenteraad governance body, seeded raadsvergadering/agenda items, wethouder Person) or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder resolved at import. `@self` envelope for every object: register `decidesk`, schema `termijnagenda-item`, slug as below.

### Schema: `termijnagenda-item`

| Field | Object 1 | Object 2 | Object 3 | Object 4 | Object 5 |
|-------|----------|----------|----------|----------|----------|
| slug | lta-herziening-parkeerbeleid | lta-rib-wachtlijsten-jeugdzorg | lta-themabijeenkomst-energietransitie | lta-kadernota-2027 | lta-verordening-marktgelden |
| onderwerp | "Herziening parkeerbeleid binnenstad" | "RIB wachtlijsten jeugdzorg" | "Themabijeenkomst energietransitie" | "Kadernota 2027" | "Actualisatie verordening marktgelden" |
| governanceBody | (seed gemeenteraad ref) | (seed gemeenteraad ref) | (seed commissie/gemeenteraad ref) | (seed gemeenteraad ref) | (seed gemeenteraad ref) |
| plannedPeriod | 2026-Q4 | 2026-11 | 2027-Q1 | 2027-Q2 | 2026-Q1 |
| expectedType | raadsvoorstel | raadsinformatiebrief | themabijeenkomst | begrotingsstuk | raadsvoorstel |
| ownerType | college | portefeuillehouder | griffie | college | portefeuillehouder |
| owner | — | (Person: wethouder, nil-UUID placeholder) | — | — | (Person: wethouder, nil-UUID placeholder) |
| originToezegging | — | (toezegging ref, nil-UUID placeholder) | — | — | — |
| originMotie | (Decision decisionType=motion, nil-UUID placeholder) | — | — | — | — |
| originDecision | — | — | — | (Decision ref, nil-UUID placeholder) | — |
| lifecycle | verschoven | gepland | gepland | gepland | vervallen |
| verschuifHistorie | [{van: 2026-Q3, naar: 2026-Q4, reden: "Wacht op parkeeronderzoek regio", door: griffier, op: 2026-06-15T10:00:00Z}] | [] | [] | [] | [] |
| redenVervallen | — | — | — | — | "Opgegaan in de bredere herziening van de legesverordening" |
| realisedAgendaItem | — | — | — | — | — |
| publicatiedatum | 2026-06-20T09:00:00Z | 2026-06-20T09:00:00Z | 2026-06-20T09:00:00Z | — | 2026-06-20T09:00:00Z |

**Related items per object:**
- Files: startnotitie PDF on object 1 via the Files leaf; none elsewhere (documents belong to the realised agenda item, not the plan).
- Notes/Tasks/Contacts: none — internal follow-up work is a VTODO via the existing action-item flow, deliberately not seeded here.

Object 5's `2026-Q1` planned period lies in the past at seed time and object 1 has a shift history, so on a fresh install the board shows a shifted card, the public list shows a withdrawn-worthy `vervallen` item with its reason, and the dashboard KPI is exercised; one additional tweak at import time: object 2's period is seeded relative-past if the install date is after 2026-11 — otherwise the KPI counts 0 overdue open items, which is acceptable but less demoable, so seed object 1's period is chosen one quarter before "now" when generating `_registers.json` entries (ADR-016 testability).

## Migration Plan

1. Land register.d fragment 50 + manifest.d fragment + Dashboard widget edit + dialogs + seed data + tests + docs in one decidesk PR (fragments are additive; the repair step / `ConfigurationService::importFromApp()` picks up the new schema on upgrade).
2. `toezeggingen-ingekomen-stukken` and `motie-amendement-administratie` provide the origin-link target semantics; both fields are nullable references and degrade to absent links if those changes land later.
3. Rollback: revert the PR — fragment disappears, pages/menu unregister, KPI edit reverts, notification rules stop evaluating. Existing objects remain soft-retained in OR; published items are withdrawn by setting `depublicatiedatum` via the normal staff flow if desired. No data migration in either direction.

## Risks / Trade-offs

- [Mixed period granularity desyncs board/overdue math] → single canonical string + one last-day sort-key convention (D2) consumed by board, list, and KPI; schema pattern rejects free text.
- [Drag bypasses the mandatory reason] → drop never saves; only the confirmed dialog composes the transition; cancel restores the card with zero writes; e2e asserts no request on cancel.
- [Lifecycle/notification dialect drift silently disables the workflow] → canonical dialects copied from gate-checked sibling fragments; hydra gates 18/28/30/51/52 on register+manifest changes; manifest refs use the slug `termijnagenda-item`, never PascalCase.
- [Board component cannot bind period-valued columns] → D5 fallback to a custom-widget board slot; list view is the functional path regardless.
- [KPI relative-date token unsupported] → D6 fallback (pre-filtered index does the overdue cut); never a silently wrong count.
- [Raw API write shifts period without history entry] → accepted per D3: OR audit trail still records the change; staff-only write RBAC bounds the actor set.

## Open Questions

- Exact relative-date token in the widget/notification filter DSL for "period last day vs now" — align with whatever `toezeggingen-ingekomen-stukken` lands for its deadline KPI (their D6) during apply.
- Whether the shared board component supports value-derived (period) columns or the D5 custom-widget fallback is needed — verify against nc-vue during apply.
- Rappel cadence after the period has arrived (once, or weekly until realised/dropped?) — provisional: once at period arrival + weekly while overdue; griffie-configurable tuning deferred to a future admin-settings change.
