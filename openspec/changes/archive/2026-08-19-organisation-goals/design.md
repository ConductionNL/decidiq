# Design: organisation-goals

## Architecture Overview

Decidesk is a thin OpenRegister client (no own DB tables). This change adds exactly one new OpenRegister schema (`Goal`) plus three one-property patches to existing schemas, all as declarative JSON merged at settings-load time:

```
lib/Settings/decidesk_register.json           (base register — ActionItem patched here)
lib/Settings/register.d/
  45-toezeggingen-ingekomen-stukken.json       (Toezegging patched here)
  50-termijnagenda.json                        (TermijnagendaItem patched here)
  66-organisation-goals.json                   (NEW — Goal schema + seed data)
src/manifest.d/
  organisation-goals.json                      (NEW — Goals index/detail pages + menu entry)
```

`SettingsService::mergeRegisterFragments()` globs `register.d/*.json` (ADR-037) and `main.js`'s `require.context('./manifest.d/', false, /\.json$/)` globs `manifest.d/*.json` — both mechanisms already exist and pick up new fragment files with zero code change. No controller, no service class, no migration class is introduced.

```
Goal ◄──────────── parentGoal (self, single level)
 ▲  ▲  ▲
 │  │  └── ActionItem.goal   (read-only CalDAV VTODO projection, ADR-002)
 │  └───── Toezegging.goal
 └──────── TermijnagendaItem.goal
```

Goal itself references `owner` (→ Person) and `body` (→ GovernanceBody) — both existing schemas, unchanged.

## Goals / Non-Goals

**Goals:**
- Give every organisational level (council, board, department, commission — via `GovernanceBody.bodyType`) a place to declare a measurable, owned, deadlined objective (ISO 9001 §6.2)
- Let existing commitments (Toezegging) and tasks (ActionItem) roll up into goal progress without duplicating their own lifecycle
- Let the Termijnagenda (forward-planning calendar) show which planned topics serve which goal
- Do all of this as declarative JSON, per ADR-031's default-declarative posture

**Non-Goals:**
- Multi-level (grandparent+) progress cascade — see REQ-005 Scenario 2 in the spec; single-level `parentGoal` rollup only
- A custom progress-visualisation widget (progress bars, burn-down charts) — the detail page uses the existing generic `data` widget to display the aggregated counts/percentages as plain fields, same posture as every other Decidesk detail page
- Declarative notifications (`x-openregister-notifications`) on Goal (e.g. "goal at risk", "deadline approaching") — every sibling schema (Toezegging, TermijnagendaItem) has these, and the dialect is proven, but the product decision for this change did not ask for them. Left as a natural, low-risk follow-up (same dialect, same file) rather than scope creep here.
- Nav placement inside the "Tasks & Commitments" cluster — declared as a placement note only; `ia-six-clusters` (concurrent, separate change) owns the actual cluster layout

## Decisions

### D1: Goal is a custom schema.org `PlanAction`-adjacent type, not a Popolo extension
ADR-001 (Popolo) covers organisational structure (Organization, Membership, Post) and events (Motion, VotingRound) — it has no concept of a forward-looking objective/target. `TermijnagendaItem` already set the precedent of using `schema:PlanAction` for a "planned thing" that isn't a Popolo concept; `Goal` follows the same choice (`x-schema-org: schema:PlanAction`) rather than inventing a new vocabulary or misusing `schema:Action` (which `Toezegging` uses for a *commitment event*, not an *objective*).
**Alternative considered:** Model Goal as a `Decision` with a new `decisionType: "goal"` (ADR-005's supertype pattern). Rejected — a Goal is not a discrete formal decision made at one meeting; it is a standing, monitored object with its own lifecycle and progress rollup, structurally closer to Meeting/TermijnagendaItem than to Decision.

### D2: Progress rollup is declarative aggregation + calculation, not a PHP service
Per ADR-031, the default is `x-openregister-aggregations`/`x-openregister-calculations` in the schema register, following the exact dialect already proven on `Meeting` (`totalParticipantCount`, `quorumPercentage` — see `lib/Settings/decidesk_register.json` lines ~2246-2317). None of ADR-031's imperative exceptions apply (this is not external integration, document generation, NLP, a domain rule selector, a lifecycle guard, or scheduled bulk work) — it is exactly the "derived/summary field" case the declarative path is designed for.
**Alternative considered:** A `GoalProgressService` PHP class computing rollups on read. Rejected per ADR-031 default and because the identical shape (count + percentage) already works declaratively on Meeting.

### D3: Single-level `parentGoal` cascade only
The `x-openregister-aggregations` `filter` dialect supports one hop (`{"parentGoal": "@self.id"}` against the `Goal` schema itself). A multi-level rollup (grandchild counts folding into a grandparent) would need either a recursive query the dialect does not have, or an imperative walk. Per ADR-031's default-declarative posture, an imperative walk is not justified for this — it does not match any of the six named exceptions (external integration / document generation / NLP / domain rule selector / lifecycle guard / scheduled bulk work). So this change ships single-level rollup only and documents the limitation (REQ-005 Scenario 2); a deeper cascade is future work, either as a dialect extension or as a `ScheduledWorkflow` (the "scheduled bulk work" exception) if it is ever prioritised.
**Alternative considered:** Compute cumulative cascade at write time (denormalise up the tree on every child update). Rejected — that IS the imperative service ADR-031 asks us to avoid, and this change has no evidence yet that the fleet needs it.

### D4: ActionItem's `goal` reference needs no ActionItemWriter change
ActionItem is a read-only OpenRegister projection over CalDAV VTODOs (ADR-002). `ActionItemWriter::toTaskData()` already treats every payload key that is not in its `coreKeys` list (`title`, `summary`, `description`, `dueDate`, `due`, `status`, `priority`, `id`, `uid`, `calendarId`, `completed`, `created`, `objectUuid`, `registerId`, `schemaId`, `fields`) as a pass-through field into the `fields` blob, which lands in the VTODO's `X-OPENREGISTER-DATA` property. `decision` and `meeting` already round-trip this way today. `goal` is not in `coreKeys`, so it round-trips identically — the only change needed is documenting `goal` as a declared property on the `ActionItem` schema in `decidesk_register.json` so the projection's shape is complete and the property is documented/facetable like its siblings. **This confirms the whole change can be `kind: config`** — no PHP file changes, only a verification task to prove the round-trip live (see tasks.md).
**Alternative considered:** Add an explicit `goal` mapping to `ActionItemWriter::toTaskData()`'s core-field handling. Rejected — unnecessary; the generic pass-through already covers it, and adding an explicit case would be dead code duplicating what the generic loop does.

### D5: No `authorization` block on Goal
Unlike `Toezegging`/`TermijnagendaItem` (which are WOO/DIWOO public-transparency instruments with a `publicationDate`-gated public read rule), `Goal` is internal governance/management data — the product decision does not ask for public goal publication, and none of the sibling internal schemas (`Meeting`, `GovernanceReport`, `Decision`) declare an `authorization` block either (verified — both return `null` in the current register). Goal follows that default (no explicit `authorization` key = standard object-ACL + org membership rules apply).
**Alternative considered:** Give Goal the same public-predicate pattern as Toezegging/TermijnagendaItem for future WOO-adjacent transparency reporting. Deferred — not asked for, and adding it later is a pure JSON addition with no migration.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path chosen | Rationale |
|---|---|---|
| Goal status lifecycle (draft → active → at-risk → achieved/abandoned) | **Declarative** `x-openregister-lifecycle` | Identical dialect already proven on Toezegging/TermijnagendaItem/Meeting; no domain rule beyond guarded state transitions |
| Goal progress from linked Toezegging/ActionItem (counts + percentage) | **Declarative** `x-openregister-aggregations` + `x-openregister-calculations` | Identical shape to Meeting's `totalParticipantCount`/`quorumPercentage`; no ADR-031 exception applies |
| Single-level `parentGoal` child count | **Declarative** `x-openregister-aggregations` (self-referential filter) | Same dialect, first self-referential use in this app — flagged as a verify-at-import risk (proposal.md Risk 1), not a reason to go imperative |
| Multi-level cascade (grandchild+ rollup) | **Out of scope this change** (see D3) | Would require either a dialect the engine doesn't have or an imperative walk that matches no ADR-031 exception; deferred rather than built |
| `goal` reference on Toezegging/TermijnagendaItem | **Declarative** (schema property only) | A plain `$ref`, no behaviour attached |
| `goal` reference on ActionItem | **Declarative** (schema property only, D4) | Rides the existing generic non-core field pass-through; zero PHP |
| Goals index/detail pages + menu entry | **Declarative** (manifest.d fragment) | Same shape as the existing `termijnagenda.json` fragment; generic `data`/`related` widgets, no custom Vue component |

No new `lib/Service/*Service.php` class is introduced by this change.

## Nextcloud Integration

- Controllers: none new — Goal CRUD goes through the existing generic OpenRegister objects API (`/apps/openregister/api/objects/...`), consumed directly from the frontend per ADR-022 (apps consume OpenRegister abstractions; no redundant per-schema controller)
- Services: none new — see D2/D3/D4
- Mappers/Entities: none new — OpenRegister owns object storage
- Events/Hooks: none new — no `x-openregister-notifications` in this change (see Goals/Non-Goals)

## Security Considerations

Goal follows the default authorization posture (no `authorization` block — see D5): standard OpenRegister object-ACL and authenticated-member access, matching `Meeting`, `Decision`, and `GovernanceReport`. No public/anonymous read path is introduced. The `owner`/`body`/`parentGoal`/`goal` references are all `$ref` UUID pointers resolved through OpenRegister's existing relation-resolution and RBAC (ADR-022) — no new authorization surface, no IDOR risk beyond what already applies to every other cross-schema `$ref` in this register. `ActionItem.goal` inherits the existing read-only-projection security posture (ADR-002) — writes still only ever go through `ActionItemWriter`/`TaskService`, never `ObjectService::saveObject`.

## NL Design System

The Goals index/detail pages (REQ-009) use exactly the generic manifest-driven components already NL Design System / WCAG 2.2 AA compliant elsewhere in Decidesk: the index list (`CnIndexPage` equivalent driven by `type: "index"` + `columns`/`quickFilters`), and on detail, the `data` widget (`content.columns`) and `related` widget. No new component is introduced, so no new design-system surface is introduced either.

## File Structure

```
lib/Settings/
  decidesk_register.json                        (MODIFIED — ActionItem gains `goal` property)
  register.d/
    45-toezeggingen-ingekomen-stukken.json       (MODIFIED — Toezegging gains `goal` property)
    50-termijnagenda.json                        (MODIFIED — TermijnagendaItem gains `goal` property)
    66-organisation-goals.json                   (NEW — Goal schema + seed data)
src/manifest.d/
  organisation-goals.json                        (NEW — Goals index/detail pages + menu entry)
```

## Seed Data

Five `Goal` seed objects spanning all three `horizon` values, three `status` values, one parent/child pair (demonstrates REQ-005), one owned goal and one body-only goal (demonstrates REQ-002 Scenario 2), and cross-links into the existing `toezegging`/`termijnagenda-item` seed data (demonstrates REQ-006/REQ-008). References resolve by slug against existing decidesk seed objects; the nil UUID `00000000-0000-0000-0000-000000000000` is the established placeholder (ADR-001 precedent, used identically in the termijnagenda/toezeggingen fragments) where no matching seed `Person` exists for a body with no seeded membership.

### Schema: `goal`

| Field | goal-acme-groeidoelstelling-2028 | goal-acme-mt-operationele-effectiviteit-2026 | goal-amsterdam-klimaatneutraal-2050 | goal-amsterdam-parkeerbeleid-kwartaal | goal-vng-digitale-dienstverlening-2026 |
|---|---|---|---|---|---|
| slug | goal-acme-groeidoelstelling-2028 | goal-acme-mt-operationele-effectiviteit-2026 | goal-amsterdam-klimaatneutraal-2050 | goal-amsterdam-parkeerbeleid-kwartaal | goal-vng-digitale-dienstverlening-2026 |
| title | Duurzame omzetgroei 2028 | Operationele effectiviteit 2026 | Amsterdam klimaatneutraal | Herzien parkeerbeleid vastgesteld | Digitale dienstverlening leden |
| description | Structurele omzetgroei realiseren binnen de duurzaamheidsdoelstellingen van de raad van bestuur. | Procesdoorlooptijden binnen norm brengen als uitvoering van de groeidoelstelling. | Netto CO2-uitstoot van de gemeentelijke organisatie naar nul in 2050, conform het raadsbesluit klimaatakkoord. | Vaststellen van het herziene parkeerbeleid binnenstad (koppeling met de termijnagenda). | Alle VNG-leden kunnen digitaal diensten afnemen bij de vereniging. |
| horizon | multi-year | annual | multi-year | quarterly | annual |
| body | raad-van-bestuur-acme-bv | managementteam-acme-bv | gemeenteraad-amsterdam | gemeenteraad-amsterdam | ledenraad-vng |
| owner | 00000000-0000-0000-0000-000000000000 | 00000000-0000-0000-0000-000000000000 | femke-halsema | 00000000-0000-0000-0000-000000000000 | (omitted — collectively owned) |
| startDate | 2026-01-01 | 2026-01-01 | 2026-01-01 | 2026-04-01 | 2026-01-01 |
| deadline | 2028-12-31 | 2026-12-31 | 2050-01-01 | 2026-09-30 | 2026-12-31 |
| status | active | active | active | at-risk | draft |
| targetValue | 20 | 90 | 100 | (omitted) | (omitted) |
| currentValue | 6 | 78 | 42 | (omitted) | (omitted) |
| unit | % omzetgroei | % doorlooptijd binnen norm | % CO2-reductie behaald | (omitted) | (omitted) |
| parentGoal | (none — org-wide) | goal-acme-groeidoelstelling-2028 | (none — org-wide) | goal-amsterdam-klimaatneutraal-2050 | (none — org-wide) |

**Cross-links into existing seed data (added by this change's fragment, not by editing the other fragments' seed arrays — the seed import merges by schema key per ADR-037):**
- `toezegging-schouw-marktplein` (existing seed, slug in `45-toezeggingen-ingekomen-stukken.json`) gets `goal: goal-amsterdam-klimaatneutraal-2050` — demonstrates REQ-006. *(Implemented as a patch to that seed object in `45-toezeggingen-ingekomen-stukken.json`, not a duplicate object — see tasks.md Task 2.)*
- `lta-herziening-parkeerbeleid` (existing seed, slug in `50-termijnagenda.json`) gets `goal: goal-amsterdam-parkeerbeleid-kwartaal` — demonstrates REQ-008, and pairs naturally: both are about the same "herziening parkeerbeleid binnenstad" topic. *(Patch to that seed object in `50-termijnagenda.json` — see tasks.md Task 3.)*
- No existing `ActionItem` seed object exists in the current seed data (ActionItem seeds, if any, are created live via CalDAV during demo/test flows, not via `x-openregister.seedData`, per ADR-002's read-only-projection posture — OpenRegister seed import writes objects directly, which the `action-item` schema's read-only object-source would reject). REQ-007's round-trip is verified live (tasks.md verification task), not via seed data.

**Related items per object:** none (Goal has no Files leaf, notes, or tasks integration in this change — the `related` widget surfaces the `$ref` relations already declared, no separate attachment).

## Trade-offs

- **Single-level cascade vs. full recursive rollup**: chose single-level (D3) to stay declarative per ADR-031; a griffie wanting "total progress of everything under the org-wide goal, three levels down" will not get that from `childGoalCount` alone in this change — they can still see it by drilling into each child goal's own rollup manually. Accepted for now; revisit if fleet usage shows this is a frequent complaint.
- **No notifications on Goal in this change**: consistent with keeping the change minimal and declarative-only; means no automatic "goal at risk" or "deadline approaching" reminder ships today, unlike Toezegging/TermijnagendaItem which both have rappel notifications. This is a straightforward follow-up (same dialect) once the base Goal object is live.
- **No public/WOO exposure**: unlike Toezegging/TermijnagendaItem, Goal has no `publicationDate`/`authorization` public-read predicate. If a body later wants a public "our goals" transparency page, that is an additive JSON change (D5), not a redesign.
