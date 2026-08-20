# Design: pc-cyclus

## Architecture Overview

Pure thin-client extension (ADR-022/ADR-037). Three new OpenRegister schemas — `PCCyclus`, `CyclusTemplate`, `CyclusStap` — ship as one `lib/Settings/register.d/52-pc-cyclus.json` fragment (fragment number 52 is assigned to this change; 40–51 and 53–65 belong to sibling changes — never renumber). All workflow behaviour is declared in OpenRegister dialects; UI is manifest-v2 pages in `src/manifest.d/pc-cyclus.json` rendered by `CnPageRenderer` (frontend talks to `/apps/openregister/api/objects` via the shared object stores — no decidesk CRUD controllers, per the redundant-controller gate).

```
CyclusTemplate (builtIn seeds: municipal-pc-cyclus, association-jaarstukken)
      │  generate(year)                          generateNextYear(cyclus)
      ▼                                                   ▼
PCCyclus (body, year) ──1:N──▶ CyclusStap ──ref──▶ AgendaItem (existing agenda flow)
   progress aggregations          │        ──ref──▶ Decision   (existing decision model)
                                  ├─ x-openregister-lifecycle (status)
                                  ├─ x-openregister-notifications (rappels)
                                  └─ FileService document slots
```

Imperative code is limited to `CyclusGenerationService` — the two multi-object transactional actions no dialect expresses: instantiate steps from a template for a year, and next-year generation with date shifting.

Cross-references, not duplication:
- `CyclusStap.agendaItem` → the ordinary AgendaItem, so agenda publication, live meeting, and minutes need zero changes; the step never creates meetings or agenda items (meeting-series keeps owning recurring meetings).
- `CyclusStap.decision` → the ordinary Decision (vaststellingsbesluit / decharge-besluit); decision content, routes, and voting stay in decision-management/decision-route.
- Decharge is a step **outcome** (`decharge-verleend|geweigerd`); VvE statutory decision templates are `vve-alv-pack`'s, hard boundary.

## Decisions

### D1: Three schemas — template, cycle, step

A single "cycle with embedded steps JSON" was rejected: steps need their own lifecycle dialect, their own notification triggers, their own FileService attachments, and a KPI aggregation over *steps* — all of which require steps to be first-class objects. A template without a cycle object was rejected because progress aggregation and next-year generation need a per-year anchor. This mirrors decision-route's Decision→DecisionStage shape (flat related objects ordered by `sequence`) and process-configuration's template pattern.

### D2: CyclusTemplate is a new schema, not a ProcessTemplate extension

`ProcessTemplate` (43-process-config-v1.json) models a decision *state machine* (states/transitions/voting rule). A cycle template is a *calendar step list* (step types, default dates, document slots, behandeling targets) — different shape, different consumers. Extending ProcessTemplate would bolt an unrelated `steps[]` onto a guard-policy schema. The *pattern* is reused (builtIn seeds via the fragment's seed-data path, read-only-but-duplicable, admin-managed), not the schema.

**Alternative considered:** `ProcessTemplate.cycleSteps[]` — rejected per above; also ProcessTemplate is consulted by the transition guard, and irrelevant cycle data in that hot path invites accidents.

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Step status workflow (`gepland → stukken-ontvangen → in-behandeling → vastgesteld \| afgerond`, plus `stukken-ontvangen → afgerond`) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`states`-only/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| Aanlevering-late rappel | `x-openregister-notifications` scheduled trigger (status `gepland` + `aanleverDeadline` past), recipients = griffie group, nl/en subjects | ADR-031 default for reminders; gate-18 hard-fails imperative dispatch; no bespoke `ReminderJob` |
| Behandeling-approaching-unscheduled rappel | `x-openregister-notifications` scheduled trigger (non-terminal + `behandelingDatum` in window + empty `agendaItem`) | Same |
| Progress counters (`stepCount`, `completedStepCount`, `overdueStepCount`) | Declarative aggregations on PCCyclus, decision-route C4 style | Consumers read materialised values, never recompute |
| Dashboard KPI "P&C-stappen over deadline" | Manifest stat-widget `source` aggregation (`metric: count`) | Declarative count like every existing KPI widget |
| Step generation from template | **Imperative** — `CyclusGenerationService::generate()` | Multi-object transactional creation resolving month/day defaults + `subjectYearOffset` into concrete dates; not expressible as a dialect |
| Next-year generation | **Imperative** — `CyclusGenerationService::generateNextYear()` | Copies the *customised* source dates +1 year, resets slots, refuses duplicate body+year; transactional, guarded |
| Built-in template read-only guard | **Imperative** — refuse edit/delete when `builtIn: true`, allow duplicate | Same rule and mechanism as ProcessTemplateService built-ins |

### D4: Cycle year semantics via subjectYearOffset

`PCCyclus.year` is the **execution** year (when the steps happen). Each template step carries `subjectYearOffset`: kadernota/begroting +1 (in 2026 you treat begroting 2027), berap 0, jaarrekening/jaarstukken/decharge −1 (in 2026 you settle boekjaar 2025). Generation derives `CyclusStap.betreftJaar = year + subjectYearOffset`. This keeps one cyclus per calendar year per body — matching how a griffie actually plans — instead of splitting cycles per subject year, which would scatter one year's workload across three objects.

**Alternative considered:** `year` = subject year — rejected: the jaarrekening 2025 and begroting 2027 both happen in calendar 2026; a subject-year anchor would make the year-view timeline span fragments of three cycli.

### D5: Steps generated as editable copies; template edits never regenerate

Like meeting-series instances (REQ-MSR-002) and unlike its template regeneration (REQ-MSR-003): once generated, steps are only edited individually. A template edit affects future generations only — an in-flight year is a working plan with customised dates, and silently regenerating it would destroy griffie edits (the union-merge-drops-modifications failure class). Next-year generation therefore shifts the **source cyclus's actual dates**, not the template defaults, preserving the body's customisations year over year.

### D6: Dashboard KPI lives in the base manifest, not the fragment

Same rationale as toezeggingen-ingekomen-stukken D6: `mergePages()` replaces a same-id page wholesale, so a fragment cannot add one widget to the Dashboard page. The KPI widget is a direct edit to `src/manifest.json`; the fragment carries only the new pages + menu. Filter needs a relative now-token on `aanleverDeadline` (`{"aanleverDeadline": {"lt": "@now"}}` + non-terminal statuses) — verify against nc-vue's widget source resolver; fallback: count non-terminal steps and cut overdue on the pre-filtered index (documented, never a silent wrong count).

### D7: Year-view timeline is one custom detail widget

The cyclus detail's year view (steps plotted across the year with deadline/window/behandeling markers) is not expressible as a manifest grid/detail leaf; it ships as one custom timeline component embedded in the cyclus detail page (custom-widget ratchet gate: one new widget, justified here; the step *list* fallback remains a plain manifest grid so the page degrades gracefully). It renders from the step objects + the declarative counters, keyboard-navigable, overdue flagged by icon+text, not colour alone.

## Nextcloud Integration

- Controllers: one thin `CyclusController` for `generate`/`generateNextYear` actions (`#[NoAdminRequired]` + per-object governance guard — no-admin-idor/semantic-auth gates); template built-in guard rides the existing admin-gated settings pattern. No CRUD controllers (redundant-controller gate).
- Services: `CyclusGenerationService` (generate, generateNextYear, duplicate-year refusal; transitions and saves via container-lazy `OCA\OpenRegister\Service\ObjectService::saveObject()` carrying **all** fields forward — PUT-semantic, nulls omit schema props).
- Mappers/Entities: none — no app tables (thin client). Files via OR `FileService`, container-resolved as in `MeetingFolderService`.
- Events/Hooks: none new — rappels and lifecycle are OR-side declarative.
- Frontend: manifest pages via `CnPageRenderer`; timeline widget in `src/`; dialogs in `src/dialogs`/`src/modals` (modal-isolation gate).

## Security Considerations

- **Generation authority:** generate/next-year are governance-scoped actions guarded per-object (body scope, same discipline as `processHamerstukken()`), not merely by route annotation (semantic-auth gate). Duplicate-year refusal is server-side.
- **Built-in template integrity:** edit/delete refusal for `builtIn: true` is enforced server-side, mirroring ProcessTemplateService; duplication clears the flag.
- **No public surface:** cycli/steps are internal planning objects behind OR RBAC; no anonymous routes, no published predicate in this change. Document slots hold ordinary FileService attachments under existing file ACLs.
- **No writeOnly/secret fields** on any of the three schemas (no render-boundary exposure); no financial data by construction.
- **CSRF/auth posture:** standard NC attributes on the two new controller methods; routes registered with matching methods (route-reachability gate).

## File Structure

```
lib/Settings/register.d/52-pc-cyclus.json      (new — 3 schemas + dialects + seeds)
src/manifest.d/pc-cyclus.json                  (new — cycli index, cyclus detail, step detail, menu)
src/manifest.json                              (edit — 1 Dashboard stat widget)
lib/Service/CyclusGenerationService.php        (new — generate, next-year, built-in guard)
lib/Controller/CyclusController.php            (new — generate/generateNextYear actions)
appinfo/routes.php                             (edit — 2 routes)
src/widgets/CyclusTimeline.vue (or equivalent) (new — year-view timeline)
tests/Unit/Service/CyclusGenerationServiceTest.php (new)
tests/e2e/pc-cyclus.spec.ts                    (new — per gate-19)
docs/features/pc-cyclus.md                     (new)
```

## Seed Data

Realistic data per ADR-016 (fictional "Gemeente Voorbeeldingen" + "Vereniging De Voorbeeldingen"); references use existing decidesk seed objects (gemeenteraad/ALV governance bodies, seeded meetings) or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder resolved at import. Envelope for every object: `@self = { register: decidesk, schema: <slug>, slug: <below> }`.

### Schema: `cyclus-template` (built-ins, `builtIn: true`)

| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | municipal-pc-cyclus | association-jaarstukken |
| name | Gemeentelijke P&C-cyclus | Verenigings-/jaarstukkencyclus |
| context | legislative | association |
| builtIn | true | true |
| steps | jaarrekening (aanlever 15-04, behandeling 02-07, offset −1) · berap-1 (aanlever 01-05, behandeling 10-06, offset 0) · kadernota (aanlever 15-05, behandeling 02-07, offset +1) · berap-2 (aanlever 20-09, behandeling 04-11, offset 0) · begroting (aanlever 15-09, vragen 16-09→05-10, commissie 20-10, raad 05-11, offset +1) | jaarplan (aanlever 01-10, ALV 25-11, offset +1) · begroting (aanlever 01-10, ALV 25-11, offset +1) · tussenrapportage (aanlever 15-05, ALV 20-06, offset 0) · jaarstukken (aanlever 01-03, ALV 15-04, offset −1) · decharge (ALV 15-04, offset −1) |

Document slots per step, e.g. begroting: "Programmabegroting" (required), "Aanbiedingsbrief" (required), "Beantwoording technische vragen" (optional); jaarstukken: "Jaarrekening", "Bestuursverslag", "Kascommissieverslag".

### Schema: `pc-cyclus`

| Field | Object 1 (municipal P&C year) | Object 2 (association jaarstukken cycle) |
|-------|-------------------------------|------------------------------------------|
| slug | pc-cyclus-2026-gemeenteraad | jaarcyclus-2026-vereniging |
| name | P&C-cyclus 2026 | Jaarcyclus 2026 |
| year | 2026 | 2026 |
| governanceBody | (seed gemeenteraad body ref) | (seed ALV body ref, nil-UUID placeholder) |
| template | municipal-pc-cyclus | association-jaarstukken |

### Schema: `cyclus-stap` (10 objects: 5 per cyclus, generated shape)

Municipal 2026: jaarrekening-2025 (`vastgesteld`, outcome vastgesteld, decision + agendaItem nil-UUID placeholders, betreftJaar 2025) · berap-1-2026 (`afgerond`, betreftJaar 2026) · kadernota-2027 (`in-behandeling`, agendaItem placeholder, betreftJaar 2027) · berap-2-2026 (`gepland`, **aanleverDeadline in the past at seed time** so the KPI is non-zero on install, betreftJaar 2026) · begroting-2027 (`gepland`, technische-vragen window + commissie- and raadsdatum set, empty agendaItem — so the "behandeling unscheduled" rappel path is demoable, betreftJaar 2027).

Association 2026: jaarstukken-2025 (`stukken-ontvangen`, slots filled) · decharge-2025 (`gepland`, outcome empty) · tussenrapportage-2026 (`gepland`) · jaarplan-2027 (`gepland`) · begroting-2027-vereniging (`gepland`), betreftJaar per offsets.

**Related items per object:**
- Files: "Programmabegroting 2027 (concept).pdf" on begroting-2027 and "Jaarstukken 2025.pdf" on jaarstukken-2025, attached via FileService into their document slots.
- Notes/Tasks/Contacts: none (internal follow-up work remains a VTODO via the existing action-item flow, deliberately not seeded).

## Migration Plan

1. Land register.d + manifest.d fragments, generation service + controller, timeline widget, base-manifest KPI edit, seeds, tests, docs in one decidesk PR (fragments are additive; `ConfigurationService::importFromApp()` / repair step picks up new schemas on upgrade).
2. No coordination needed with `vve-alv-pack` (boundary only, no shared files except sibling-owned fragment numbers — 52 is this change's, exclusively).
3. Rollback: revert the PR — fragments disappear, pages unregister, routes vanish. Existing cyclus/step objects remain soft-retained in OR; linked AgendaItems/Decisions untouched. No data migration.

## Risks / Trade-offs

- [Lifecycle dialect drift silently ignored] → canonical `initial` keyword copied verbatim from a known-good fragment; gates 28/30/51/52 on register+manifest changes; manifest schema refs by slug only.
- [Generation partially fails mid-batch] → per-step `saveObject` with collected failures reported; re-run is idempotent per body+year (duplicate refusal covers the whole-cyclus case; step creation checks existing slugs).
- [Template edit expectations] → D5 documented in the template editor UI copy ("changes apply to newly generated years"); never silent regeneration of in-flight years.
- [KPI relative-date token unsupported] → D6 fallback; never a silently wrong count.
- [Timeline widget scope creep] → one widget, list fallback stays a plain manifest grid; no Gantt editing in v1 (dates edit through the step form).

## Open Questions

- Exact relative-date token in the widget source filter DSL (D6) — same question as toezeggingen-ingekomen-stukken; verify once against nc-vue's source resolver during apply.
- Rappel window sizes (behandeling approaching = 21 days before? weekly repeat after late aanlevering?) — provisional values in the notification triggers; admin-configurable tuning deferred.
