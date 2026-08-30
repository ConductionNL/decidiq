# Design: raadsinformatiebrieven

## Architecture Overview

Pure thin-client extension (ADR-022/ADR-037). Two new OpenRegister schemas — `Raadsinformatiebrief` and `TechnischeVraag` — ship as one `lib/Settings/register.d/51-raadsinformatiebrieven.json` fragment (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load via `ConfigurationService::importFromApp()`; the base file is never edited; number 51 is assigned to this change, 40–50 and 52–65 belong to siblings). All workflow behaviour is declared in OpenRegister dialects; all UI is manifest-v2 pages in a `src/manifest.d/raadsinformatiebrieven.json` fragment rendered by `CnPageRenderer` (the frontend talks to `/apps/openregister/api/objects` directly via the shared object stores — no decidiq CRUD controllers, per the redundant-controller gate).

Unlike the sibling `toezeggingen-ingekomen-stukken` change, this change needs **no service or controller edits at all**: publication is predicate-on-live-object (no derived payloads, so `PublicationEligibilityService`/`PublicationPayloadService` stay untouched), the Q&A thread is an ordinary filtered object list, and the toezegging linkage is a reverse-relation display.

Cross-references, not duplication:
- `Raadsinformatiebrief.afgedaneToezegging` → `Toezegging`; afdoening state and log live exclusively on the Toezegging (toezeggingen-register REQ-002); the ToezeggingDetail page surfaces linked RIBs as afdoening evidence via a reverse relation query (`raadsinformatiebrief` filtered on `afgedaneToezegging`).
- `Raadsinformatiebrief.agendaItem` → an ordinary `AgendaItem` (ter-kennisname placement), so agenda publication, live meeting, and minutes need zero changes.
- `TechnischeVraag.rib` → the RIB; the thread is a related-objects section on the RIB detail (filter on `rib`), not a nested document.

## Nextcloud Integration

- Controllers: none new, none edited (no publish endpoint needed — predicate set/cleared through the standard object save; no bulk actions).
- Services: none new, none edited.
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: none — notifications and lifecycle are OR-side declarative.
- Frontend: manifest pages via `CnPageRenderer`; add-question / record-answer / publish / withdraw actions as dedicated dialog components under `src/dialogs`–`src/modals` (modal-isolation gate); files via the Files leaf (`FileService`) on the detail page; export via `ExportService` + `CnMassExportDialog`.

## Decisions

### D1: Two schemas, one change, one capability spec

RIB and TechnischeVraag are one product feature (the Q&A thread is meaningless without the letter, and every RIS ships them together), share one delivery skeleton (register fragment 51 + manifest fragment), one stakeholder (griffie), and one market gap. One capability spec (`raadsinformatiebrieven-register`) with REQ-RIB-NNN numbering keeps the boundary requirements (REQ-RIB-005) next to the schemas they bound.

**Alternative considered:** a separate `technische-vragen` capability — rejected: a TechnischeVraag cannot exist without a RIB (`rib` is required); splitting would race on the same fragment and double the review surface for no isolation benefit.

### D2: RIB is a first-class schema, not an IngekomenStuk category and not a DigitalDocument

The ingekomen-stukken register models *inbound* mail with routing advice and council confirmation — its `category: collegestuk` files a *received* college document but has no RIB semantics (college-issued numbering, portefeuillehouder, toezegging-afdoening, technische-vragen thread, ter-kennisname lifecycle). Overloading it would corrupt the inbound register's routing lifecycle with outbound states. `DigitalDocument` is a file-metadata schema, not a governance artifact with a lifecycle and thread. A dedicated schema keeps both boundaries clean (REQ-RIB-005).

**Alternative considered:** extend IngekomenStuk with a `direction` discriminator — rejected: two disjoint lifecycles and actor sets in one schema, and it would require MODIFYing the just-specced sibling capability.

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative; this change ships **zero imperative backend code**:

| Behaviour | Mechanism | Why |
|---|---|---|
| RIB status workflow (`verzonden → geagendeerd → betrokken-bij-behandeling`, direct skip allowed) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`states`-only/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| TechnischeVraag workflow (`gesteld → beantwoord`) | `x-openregister-lifecycle` | Same |
| New-RIB notification to council members | `x-openregister-notifications` `created` trigger, recipients `kind:object-acl` + `kind:groups` (never `kind:field` on Person refs, per decidesk-notifications), nl/en subjects | ADR-031 default; gate-18 hard-fails imperative dispatch |
| Public RIB list + answered Q&A | `authorization.read` published-predicate on the live objects (`publicatiedatum <= $now`; TechnischeVraag additionally `lifecycle = beantwoord`) | Toezeggingen-register D4 pattern: schemas are publishable by construction, and the public list must reflect status live without rectify cycles |
| Q&A thread on RIB detail | Manifest related-objects list (filter on `rib`) | Ordinary filtered query; no endpoint |
| Toezegging evidence surfacing | Reverse-relation display on ToezeggingDetail | Query, not state |
| RIB number pattern | JSON-schema `pattern` on `number` | Validation, not code |
| Next-number pre-fill (D5) | Frontend creation dialog computes next free volgnummer from a max-query on the index | Convenience only; correctness is schema validation + review, not a service |

### D4: Publication = predicate on the live object for both schemas

RIBs are the textbook case for the live-predicate carve-out established by the toezeggingen register: the letter is inherently public (actieve informatieplicht), the schema carries no citizen PII and no internal fields **by construction** (REQ-RIB-001 forbids them; drafting/parafering stays in procest), and the public list must show agendering/behandeling status live. Derived payloads would force a rectify cycle per status change — the exact stale-list failure griffies complain about. TechnischeVraag gets the same predicate **plus a structural lifecycle condition** (`beantwoord`), so a mistakenly set predicate can never expose an unanswered question. Askers and portefeuillehouders are public officeholders; publishing their names is WOO-conformant. Consequence: `public-publication`'s eligibility matrix and payload services are untouched — no delta spec on that capability, and no archive-ordering collision with the sibling change's MODIFIED delta.

**Alternative considered:** derived immutable payloads via `PublicationEligibilityService` — rejected per above; also adds imperative code where the dialect suffices (ADR-031). If the OR predicate DSL turns out not to support the compound lifecycle condition on TechnischeVraag, fallback: answered questions are published by the staff action only being offered on `beantwoord` objects (UI-gated) plus a Newman negative test asserting a `gesteld` object with a predicate set is still not eligible for staff to publish — and the open question escalates to an OR issue (never a silent partial rule).

### D5: RIB numbering is user-visible, schema-validated, and pre-filled — not service-generated

`number` (`RIB-{jaar}-{volgnummer}`) is the municipality's official letter number: often assigned by the college's own systems before the letter reaches the griffie, so decidiq must accept an externally given number rather than force-generate one. Mechanism: required property with `pattern: ^RIB-\d{4}-\d+$`; the creation dialog pre-fills the next free volgnummer for the current year (one max-aggregation query on `raadsinformatiebrief`), editable by the user. Uniqueness per year is a review/validation concern, not a hard DB constraint (OR has no cross-object unique constraint today); the index sorts by number so duplicates are immediately visible.

**Alternative considered:** imperative numbering service on create (like the SV-YYYY-NNN plan in fractievoorzitter-fractie-koppeling) — rejected: it would require a decidiq controller in an otherwise controller-free change, and it fights the reality that RIB numbers originate at the college.

### D6: fractie and beantwoordDoor are plain strings, relatedDossier is a forward-compatible ref

`TechnischeVraag.fractie` stays a string until the `Fractie` schema (fractievoorzitter-fractie-koppeling) lands — a hard reference now would create a blocking dependency on an unlanded change. `beantwoordDoor` is an organisational unit label (afdeling), not a governance Person. `relatedDossier` is a nullable reference documented to target `ArchivalDossier` (records-management-archiving) once it lands; until then it degrades to a plain link. All three are optional, so tightening them later is an additive schema-version bump.

## Security Considerations

- **Public predicate surface:** both schemas are publishable by construction (no citizen PII, no internal-only fields — enforced at spec level REQ-RIB-001/REQ-RIB-004 and noted in the schema `description` so the constraint travels with the schema; adding any non-public property requires revisiting D4). Publish/withdraw is an explicit staff action on RBAC-guarded objects. No writeOnly fields exist on either schema (no render-boundary exposure).
- **Unanswered-question leak (Risk 3):** structural compound predicate (`lifecycle = beantwoord`) plus Newman negative test; see D4 fallback.
- **No new endpoints:** no controllers added or changed, so no route-auth/no-admin-idor surface; the only anonymous surface is OR's published-predicate API.
- **File attachments:** letter + bijlagen live in the Files leaf under normal NC ACLs; the published predicate exposes the object's file *references* per OR's standard rendering — the seeded letter documents are public documents by nature. Confidential RIBs (geheimhouding) are simply never published; classified handling is the `embargo-geheimhouding` change's concern.
- **CSRF/auth posture:** unchanged — no touched controller methods.

## File Structure

```
lib/Settings/register.d/51-raadsinformatiebrieven.json   (new — 2 schemas + lifecycle + notifications + predicates + seed)
src/manifest.d/raadsinformatiebrieven.json               (new — index + detail pages, menu entry)
src/dialogs|src/modals/...                               (new — add-question, record-answer, publish/withdraw dialogs)
tests/e2e/...                                            (new — scenario coverage per gate-19)
tests/Unit/...                                           (new — register-fragment shape assertions)
docs/features/raadsinformatiebrieven.md                  (new)
```

## Seed Data

Realistic Dutch municipal examples (fictional municipality, consistent with sibling seeds); references use existing decidiq seed objects (gemeenteraad governance body, scheduled raadsvergadering, seeded wethouder Persons) or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder where a cross-seed reference is resolved at import. `@self` envelope per object: `register: decidesk`, schema slug as below, slug per table.

### Schema: `raadsinformatiebrief`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | rib-2026-012-wachtlijsten-jeugdzorg | rib-2026-013-energietransitie | rib-2026-014-afdoening-motie-fietsveiligheid |
| number | RIB-2026-012 | RIB-2026-013 | RIB-2026-014 |
| onderwerp | "Wachtlijsten jeugdzorg: stand van zaken en maatregelen" | "Voortgang energietransitie gebouwde omgeving" | "Afdoening motie Fietsveiligheid schoolroutes" |
| portefeuillehouder | (Person: wethouder, nil-UUID placeholder) | (Person: wethouder, nil-UUID placeholder) | (Person: wethouder, nil-UUID placeholder) |
| category | toezegging-afdoening | actieve-informatieplicht | motie-afdoening |
| sentAt | 2026-02-20 | 2026-03-05 | 2026-03-12 |
| directedTo | (seed gemeenteraad body ref) | (seed gemeenteraad body ref) | (seed gemeenteraad body ref) |
| letterDocument | (RIB PDF via Files leaf) | (RIB PDF via Files leaf) | (RIB PDF via Files leaf) |
| bijlagen | (rapportage PDF) | — | — |
| agendaItem | (seed LIS agenda item ref) | — | (seed LIS agenda item ref) |
| afgedaneToezegging | (seed toezegging ref — sibling change's toezegging-raadsbrief-jeugdzorg, nil-UUID placeholder) | — | — |
| relatedMotion | — | — | (Decision decisionType=motion, nil-UUID placeholder) |
| lifecycle | geagendeerd | verzonden | betrokken-bij-behandeling |
| publicatiedatum | 2026-02-21T09:00:00Z | — | 2026-03-13T09:00:00Z |

Object 1 exercises the toezegging linkage + agendering; object 2 stays unpublished/unagendeerd so the publish and agendering flows are demoable on install; object 3 exercises the motie link and the terminal state.

**Related items per object:**
- Files: letter PDF on all three, rapportage bijlage on object 1, via the Files leaf.
- Notes/Tasks/Contacts: none (internal follow-up is a VTODO via the existing action-item flow, deliberately not seeded here).

### Schema: `technische-vraag`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | tv-rib-2026-012-doorlooptijd | tv-rib-2026-012-regiocijfers | tv-rib-2026-013-netcapaciteit |
| rib | (seed RIB-2026-012 ref) | (seed RIB-2026-012 ref) | (seed RIB-2026-013 ref) |
| vraag | "Hoe verhoudt de genoemde gemiddelde doorlooptijd zich tot de landelijke norm?" | "Kunnen de wachtlijstcijfers worden uitgesplitst per regiogemeente?" | "Welke wijken lopen vertraging op door netcapaciteit?" |
| gesteldDoor | (Person: raadslid, nil-UUID placeholder) | (Person: raadslid, nil-UUID placeholder) | (Person: raadslid, nil-UUID placeholder) |
| fractie | "GroenLinks" | "VVD" | "D66" |
| gesteldOp | 2026-02-24 | 2026-02-25 | 2026-03-08 |
| antwoord | "De doorlooptijd ligt 12% boven de landelijke norm; het plan van aanpak..." | — | — |
| beantwoordDoor | "Afdeling Sociaal Domein" | — | — |
| beantwoordOp | 2026-03-02 | — | — |
| lifecycle | beantwoord | gesteld | gesteld |
| publicatiedatum | 2026-03-03T09:00:00Z | — | — |

Object 1 is answered **and published** so the public Q&A path is visible on a fresh install; objects 2–3 stay `gesteld` so the record-answer flow is demoable and the compound-predicate negative case (unanswered ⇒ never public) is testable against seed.

**Related items per object:**
- Files/Notes/Tasks/Contacts: none — a technische vraag is plain text by design.

## Migration Plan

1. Land register.d fragment 51 + manifest.d fragment + dialogs + seed + tests + docs in one decidiq PR (fragments are additive; the repair step / `ConfigurationService::importFromApp()` picks up the new schemas on upgrade).
2. `toezeggingen-ingekomen-stukken` must land first or concurrently: `afgedaneToezegging` targets its `Toezegging` schema and the seed references its seeded toezegging. The field is nullable, so a delay degrades gracefully (link renders as plain reference), but the spec text assumes the model.
3. Rollback: revert the PR — fragments disappear, pages unregister. Existing objects remain soft-retained in OR; published objects are withdrawn by clearing the predicate (`depublicatiedatum`) via the normal staff flow if desired. No data migration.

## Risks / Trade-offs

- [Compound predicate unsupported for TechnischeVraag] → D4 fallback (UI-gated publish + Newman negative test + OR issue); never a silent partial rule.
- [Lifecycle dialect drift (`initial` vs `initialState`)] → fragment copies the canonical dialect verbatim from the existing schemas; gates 28/30/51/52 run on register+manifest changes; manifest refs use slugs (`raadsinformatiebrief`, `technische-vraag`), never PascalCase.
- [Toezegging linkage tempts lifecycle duplication] → hard rule REQ-RIB-003: RIB carries no afdoening state; ToezeggingDetail surfacing is a reverse query, not copied data.
- [Technische vragen scope creep toward art. 33] → REQ-RIB-005 forbids deadline/workflow properties on the schema; escalation is manual re-filing once `SchriftelijkeVraag` lands.
- [Duplicate RIB numbers under concurrent entry] → schema pattern + pre-fill + number-sorted index makes duplicates visible; accepted (numbers originate at the college; OR has no unique constraint) — documented in D5.
- [Category free-text fragmentation] → seed ships a sane default option list; the creation dialog offers existing values; a hard enum was deliberately rejected so municipalities can extend without a schema change.

## Trade-offs

See Decisions D1–D6 (alternatives considered are recorded per decision) and Risks above.

## Open Questions

- Exact OR predicate DSL form for the compound TechnischeVraag rule (`publicatiedatum <= $now` AND `lifecycle = beantwoord`) — verify against OpenRegister's authorization rule schema during apply; fallback in D4.
- Whether the creation dialog's next-number pre-fill can use a max aggregation on `number` or needs a volgnummer-sorted first-page query — implementation detail, resolved during apply.
