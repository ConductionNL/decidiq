# Design: delegatie-mandaatregister

## Architecture Overview

Pure thin-client extension (ADR-022/ADR-037). One new OpenRegister schema — `Bevoegdheidstoedeling` — ships as `lib/Settings/register.d/54-delegatie-mandaatregister.json` (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load; the base file is never edited for new schemas). All workflow behaviour is declared in OpenRegister dialects; all UI is manifest-v2 pages in a `src/manifest.d/delegatie-mandaatregister.json` fragment rendered by `CnPageRenderer` (the frontend talks to `/apps/openregister/api/objects` directly via the shared object stores — no decidesk CRUD controllers, per the redundant-controller gate).

Imperative code is limited to the single place a declarative dialect genuinely cannot reach: the ondermandaat guard (parent permits ondermandaat + the parent chain is acyclic), a small server-side save-path check that fails closed.

Cross-references, not duplication:
- `Bevoegdheidstoedeling.besluit` / `.ingetrokkenDoor` → existing `Decision` supertype (the delegatie-/mandaatbesluit and the intrekkingsbesluit); decision content, routes, and publication stay entirely in decision-management/decision-route.
- `Bevoegdheidstoedeling.delegans` / `.delegatarisBody` → `GovernanceBody`; `.delegatarisPersoon` → Popolo `Person` (person-and-membership) — no new people or body modelling.
- `Decision.bevoegdheidsgrondslag` (one nullable property added to the base register file, same edit pattern as urgent-decision-procedure's urgency fields) → `Bevoegdheidstoedeling`; assistive display only.
- The delegatiebesluit *document* (when it qualifies as a regeling) publishes via the verordeningenregister sibling; this register never touches CVDR/DROP.

## Decisions

### D1: Bevoegdheidstoedeling is a first-class schema, not a Decision subtype and not a Membership variant

A toedeling is a durable *relation* established by a besluit, with its own lifecycle (a mandaat outlives — and is revoked independently of — the besluit's procedural lifecycle) and its own public register obligation. Folding it into the Decision supertype (`decisionType: mandaatbesluit`) would conflate the besluit with the authority it confers: one mandaatbesluit typically establishes dozens of toedelingen, and an intrekkingsbesluit revokes specific rows, not the whole besluit. It is also not a Popolo `Membership`: membership models seat-holding in a body (role, party, voting weight), while a toedeling assigns an *exercisable competence* to a body, function, or person — frequently to a function that is not a member of anything (afdelingshoofd).

**Alternative considered:** annex table on the Decision object — rejected: not independently queryable, no per-row lifecycle, no per-row publication predicate.

### D2: Delegans and delegataris as polymorphic field pairs, not a single required reference

Awb practice needs three delegataris shapes (body, function description, natural person) and two delegans shapes (body, role such as "burgemeester" who is an eenhoofdig bestuursorgaan, not a GovernanceBody in every deployment). The schema therefore carries optional `delegans` + `delegansOmschrijving` and optional `delegatarisBody` + `delegatarisFunctie` + `delegatarisPersoon`, with schema-level "at least one of" validation (`anyOf`/`minProperties` composition in the fragment, enforced by OR validation). Function descriptions stay plain text — HR/formatie management is explicitly out of scope; a function register can later be referenced without breaking these rows.

**Alternative considered:** forcing every delegataris to be a Person — rejected: mandaten run to functions, not persons (personnel churn must not invalidate the register), and creating Person objects for external volmacht holders would pull non-governance PII into the shared people register (same reasoning as toezeggingen D7).

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Status workflow (`concept → van-kracht → ingetrokken \| vervallen`) | `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys — never `initialState`/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| Required fields, type enum, at-least-one delegans/delegataris | OR schema validation (`required`, `enum`, `anyOf`) | Structural validation is the schema's job |
| Geldigheid-expiry rappels (60/14 days before `geldigTot`) | `x-openregister-notifications` scheduled triggers (filter: status `van-kracht`, `geldigTot` within window), nl/en subjects | ADR-031 default for reminders; the notification-dialect gate (gate-18) hard-fails imperative dispatch; no bespoke ReminderJob |
| Public register exposure | `authorization.read` published-predicate on the live object (`public` group while `publicatiedatum <= $now`) | Declarative RBAC; see D4 |
| "Geldig op" (in-force-on-date) view | Plain OR list query (`status = van-kracht`, `geldigVanaf <= X`, `geldigTot` empty or `>= X`) via manifest filters | Unlike regeling versions, toedelingen form no exclusive timeline — no resolution service exists to write |
| Ondermandaat guard (parent permits + acyclic chain) | **Imperative** — thin save-path guard service | Cross-object graph validation (walk the parent chain) is not expressible as a schema constraint or lifecycle rule; fail closed |
| CSV export | Existing `ExportService` + `CnMassExportDialog` | Established imperative surface, reused unchanged |

### D4: Public register = predicate on the live object (toezeggingen-register D4 pattern), no derived payload

The register must be *live*: an intrekking or lapse must show on the public register immediately — a stale mandaatregister is worse than none (acts signed under a revoked mandaat). And the schema is designed to contain only publishable fields: no internal-notes property exists (internal working notes belong in the sidebar audit trail), delegataris persons are governance persons already public in the people register, and function descriptions are organisational facts. So the schema declares `authorization.read` for the `public` group while `publicatiedatum <= $now`; publish/withdraw = staff setting `publicatiedatum`/`depublicatiedatum`. This intentionally does NOT touch public-publication's eligibility-gates requirement (two sibling changes already modify it — a third MODIFIED delta risks archive data-loss); the carve-out lives as ADDED requirements in this capability's own spec, exactly as toezeggingen-register did.

**Alternative considered:** derived allow-list payloads per public-publication — rejected: every status change would need a rectify cycle, guaranteeing a stale public register; there is nothing to strip (no PII beyond already-public governance persons), so the derived-payload machinery buys only staleness.

### D5: Ondermandaat as a self-reference with an imperative fail-closed guard

`parentToedeling` is a nullable UUID self-reference. The guard runs server-side on save: parent must resolve, parent must have `ondermandaatToegestaan: true`, and walking the ancestor chain (bounded, e.g. 20 hops) must never revisit a node — self-parent, 2-cycles, and longer cycles all reject. Depth is *displayed* by walking the loaded chain in the detail view (cheap: chains are 1–3 deep in practice), never stored — a stored depth would go stale when a middle link is revoked. Revoking a parent does not cascade to children automatically: Awb intrekkingspraktijk requires an explicit besluit per toedeling, so the UI surfaces children of an ingetrokken parent for staff follow-up instead of silently mutating them.

**Alternative considered:** declarative `maxDepth`/cycle rule in the schema — no such dialect exists in OR; inventing one for a single consumer violates ADR-031's "dialects must be register-generic" bar.

### D6: `Decision.bevoegdheidsgrondslag` is a base-file property edit and strictly assistive

The one nullable property on the existing `Decision` schema is a direct edit to `lib/Settings/decidesk_register.json` (fragments add schemas; they cannot add one property to an existing schema without wholesale replacement — same reasoning as urgent-decision-procedure's urgency fields and toezeggingen D6). It is display-only by design: no lifecycle guard, no transition hook, no warning reads it. Enforcement ("block decisions taken without mandate") is a legal judgement decidesk must not automate — the proposal marks it out of scope, and the spec carries a negative scenario so the boundary is testable. Reverse lookup ("decisions taken under this toedeling") uses the standard `fetchUsed` reverse-relation pattern (as governance-bodies REQ-GBD-002).

## Nextcloud Integration

- Controllers: one thin endpoint for the ondermandaat guard validation on save (`#[NoAdminRequired]` + per-object governance scope guard, no-admin-idor gate) — or, preferred if the OR save-path hook supports it, no controller at all and the guard registers as a save validator; decided at apply time against the OR extension surface actually available.
- Services: new `OndermandaatGuardService` (parent permission + cycle walk via `ObjectService`; PUT-semantic `saveObject()` carrying all fields forward).
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: none new — lifecycle, notifications, and publication are OR-side declarative.
- Frontend: manifest pages via `CnPageRenderer`; filters and "geldig op" date control as manifest quick-filters; export via `ExportService` + `CnMassExportDialog`; Decision detail gains the assistive reference display; chain display on the toedeling detail page.

## Security Considerations

- **Public predicate on the live object:** the schema carries no non-public fields by construction (recorded in the schema `description` — adding any internal-only property requires revisiting D4); publish/withdraw is an explicit staff action on RBAC-guarded objects; no writeOnly fields exist (no render-boundary exposure).
- **Ondermandaat guard:** fail closed — resolver errors reject the save (never the `catch (\Throwable) { return null; }` fail-open shape, unsafe-auth-resolver gate); guarded per object, not merely by route annotation (semantic-auth gate).
- **No enforcement surface:** because bevoegdheidsgrondslag never gates anything, it cannot be abused to block decisions; conversely the register grants no authority — it documents it.
- **CSRF/auth posture:** standard NC attributes on any touched controller method; no public app routes — the only anonymous surface is the OR published-predicate.

## File Structure

```
lib/Settings/register.d/54-delegatie-mandaatregister.json   (new — schema + lifecycle + notifications + predicate + seed)
lib/Settings/decidesk_register.json                         (edit — Decision.bevoegdheidsgrondslag property only)
lib/Service/OndermandaatGuardService.php                    (new — parent permission + cycle walk)
lib/Controller/... / appinfo/routes.php                     (edit — guard endpoint, only if no OR save-hook; explicit auth attributes)
src/manifest.d/delegatie-mandaatregister.json               (new — index/detail pages + menu + filters)
src/manifest.json                                           (edit — Decision detail assistive reference display, if not fragment-expressible)
tests/Unit/Service/OndermandaatGuardServiceTest.php         (new — cycles, self-parent, forbidden parent, fail-closed)
tests/e2e/...                                               (new — scenario coverage per gate-19)
docs/features/delegatie-mandaatregister.md                  (new)
```

## Seed Data

Realistic Dutch municipal examples (ADR-016); references use existing decidesk seed objects (gemeenteraad and college governance bodies, seed decisions) or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder resolved at import. All objects carry the `@self` envelope (`register: decidesk`, `schema: bevoegdheidstoedeling`, slug as below).

### Schema: `bevoegdheidstoedeling`

| Field | Object 1 | Object 2 | Object 3 | Object 4 | Object 5 |
|-------|----------|----------|----------|----------|----------|
| slug | delegatie-uitwerkingsplannen | mandaat-subsidies-secretaris | ondermandaat-subsidies-samenleving | volmacht-inkoop-teamleider | machtiging-woo-vertegenwoordiging |
| type | delegatie | mandaat | mandaat | volmacht | machtiging |
| delegans | (gemeenteraad body ref) | (college body ref) | — | — | (college body ref) |
| delegansOmschrijving | — | — | gemeentesecretaris | burgemeester | — |
| delegatarisBody | (college body ref) | — | — | — | — |
| delegatarisFunctie | — | gemeentesecretaris | afdelingshoofd Samenleving | teamleider Inkoop | juridisch adviseur team Recht |
| delegatarisPersoon | — | — | — | — | (Person ref, nil-UUID placeholder) |
| onderwerp | "Vaststellen van uitwerkingsplannen als bedoeld in art. 3.6 Wro" | "Beslissen op subsidieaanvragen tot het genoemde plafond" | "Beslissen op subsidieaanvragen binnen het programma Samenleving" | "Aangaan van privaatrechtelijke rechtshandelingen voor inkoop" | "Vertegenwoordiging bij Woo-bezwaarprocedures" |
| financieelPlafond | — | 25000 | 5000 | 50000 | — |
| beperkingen | "Uitsluitend binnen door de raad vastgestelde kaders" | "Geen subsidies met precedentwerking" | "Uitsluitend reguliere subsidieregelingen" | "Binnen het vastgestelde inkoopbeleid" | — |
| ondermandaatToegestaan | false | true | false | false | false |
| wettelijkeGrondslag | ["Awb art. 10:13", "Wro art. 3.6"] | ["Awb art. 10:3", "Asv 2026 art. 4"] | ["Awb art. 10:9"] | ["BW art. 3:60", "Gemeentewet art. 171"] | ["Awb art. 10:3"] |
| besluit | (seed Decision "Delegatiebesluit 2026", nil-UUID placeholder) | (seed Decision "Algemeen mandaatbesluit 2026", nil-UUID placeholder) | (seed Decision "Ondermandaatbesluit Samenleving 2026", nil-UUID placeholder) | (seed Decision "Volmachtbesluit inkoop 2026", nil-UUID placeholder) | (seed Decision "Machtigingsbesluit Woo 2025", nil-UUID placeholder) |
| parentToedeling | — | — | (object 2 ref) | — | — |
| geldigVanaf | 2026-01-01 | 2026-01-01 | 2026-02-01 | 2026-03-01 | 2025-01-01 |
| geldigTot | — | — | 2026-08-15 | — | 2026-01-01 |
| status | van-kracht | van-kracht | van-kracht | concept | ingetrokken |
| ingetrokkenDoor | — | — | — | — | (seed Decision "Intrekkingsbesluit machtiging Woo", nil-UUID placeholder) |
| publicatiedatum | 2026-01-05T09:00:00Z | 2026-01-05T09:00:00Z | 2026-02-05T09:00:00Z | — | 2025-01-10T09:00:00Z |

Object 3's `geldigTot` falls inside the 60-day rappel window at seed time so the expiry notification path is demonstrable on a fresh install (ADR-016 testability); objects 2→3 demonstrate a published ondermandaat chain (depth 1); object 4 stays `concept`/unpublished so the publish flow is demoable; object 5 demonstrates the intrekking trace and shows live on the public register as `ingetrokken`.

**Related items per object:**
- Files: scanned mandaatbesluit PDF on object 2 via the Files leaf (the besluit *document*; register publication of regeling-type texts stays with verordeningenregister).
- Notes: none (internal working notes deliberately not seeded — the schema must stay fully publishable, D4).
- Tasks: none (follow-up work is a VTODO via the existing action-item flow, not part of this register).
- Contacts: none.

## Migration Plan

1. Land register.d fragment, base-register property edit, guard service, manifest fragment, seed data, tests, docs in one decidesk PR (fragments are additive; the repair step / `ConfigurationService::importFromApp()` picks up the new schema on upgrade).
2. No ordering dependency on siblings: `verordeningenregister` (fragment 53) and this change (54) touch disjoint files; the shared `wettelijkeGrondslag` citation shape is convention, not code.
3. Rollback: revert the PR — fragment disappears, pages unregister, guard route removed; the `bevoegdheidsgrondslag` property edit reverts (stored values remain as harmless extra data). Published rows are withdrawn via `depublicatiedatum` through the normal staff flow if desired. No data migration — the register starts empty apart from seed data.

## Risks / Trade-offs

- [Live predicate leaks a future internal field] → schema-description rule (D4): adding any non-public property requires revisiting the publication decision; no writeOnly fields exist on the schema.
- [Cycle guard bypassed via direct OR API writes] → the guard sits server-side on the save path used by the app; direct OR writes are RBAC-limited to staff who can equally corrupt any register — accepted residual risk, and the chain renderer detects and flags (never infinite-loops on) an existing cycle.
- [Lifecycle dialect drift (`initial` vs `initialState`)] → fragment uses the canonical dialect verbatim from sibling fragments; gates 28/30/51/52 run on register+manifest changes; manifest refs use the slug `bevoegdheidstoedeling`, never PascalCase (multi-word schema — the exact silent-breakage case).
- [Register read as enforcement] → negative scenario in REQ-DMR-006 + UI copy "genomen krachtens (documentatie)"; no code path reads the field in any guard.
- [Delegans polymorphism confuses filtering] → per-delegans filter matches either the body reference or the role description; covered by an explicit filter test in the index e2e.

## Open Questions

- Whether the ondermandaat guard can register as an OR save-path validator instead of a decidesk endpoint (preferred; decided at apply time against the available OR extension surface).
- Rappel windows (60/14 days) are provisional; griffie-configurable tuning deferred to a future admin-settings change.
