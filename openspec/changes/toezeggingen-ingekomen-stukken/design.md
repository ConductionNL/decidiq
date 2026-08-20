# Design: toezeggingen-ingekomen-stukken

## Architecture Overview

Pure thin-client extension (ADR-022/ADR-037). Two new OpenRegister schemas — `Toezegging` and `IngekomenStuk` — ship as one `lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json` fragment (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load; the base file is never edited). All workflow behaviour is declared in OpenRegister dialects; all UI is manifest-v2 pages in a `src/manifest.d/toezeggingen-ingekomen-stukken.json` fragment rendered by `CnPageRenderer` (the frontend talks to `/apps/openregister/api/objects` directly via the shared object stores — no decidesk CRUD controllers, per the redundant-controller gate).

Imperative code is limited to the two places declarative dialects genuinely cannot reach:

1. `PublicationEligibilityService` / `PublicationPayloadService` — the `IngekomenStuk` payload type with structural sender anonymisation (extends the existing eligibility matrix and allow-list payload builder).
2. A small bulk-routing action (place-on-list + chair bulk-confirm), mirroring `AgendaService::processHamerstukken()` semantics.

Cross-references, not duplication:
- `Toezegging.relatedMotion` → `Decision` (`decisionType: motion`); execution narrative stays on the motie change's `UitvoeringsUpdate` log.
- `IngekomenStuk.listAgendaItem` → the ordinary "Lijst ingekomen stukken" `AgendaItem`, so agenda publication, live meeting, and minutes need zero changes.
- ActionItem stays a CalDAV VTODO; a toezegging is never written to that store and never counted in its KPI.

## Decisions

### D1: Two schemas, one change

Toezegging and IngekomenStuk are distinct registers (different lifecycle, different publics, different actors) but share the same delivery skeleton (register fragment + manifest fragment + publication extension), the same griffie stakeholder, and one market gap. One change keeps the fragments and seed data coherent; the specs stay separate capabilities so they archive independently.

**Alternative considered:** two changes — rejected: they would race on the same `register.d`/`manifest.d` fragment numbering and double the review surface for no isolation benefit.

### D2: Toezegging is a first-class schema, not a Decision subtype and not a VTODO

The existing `Decision` supertype (ADR-005, `decisionType` discriminator, used for motions/amendments) models *council* decisions with voting; a toezegging is a unilateral commitment by a college member — no ballot, no proposer/co-signer machinery, and a fundamentally different lifecycle. Squeezing it into the 42-field Decision supertype would inherit dozens of never-applicable fields and a wrong lifecycle. It is also not an ActionItem: REQ-AI-DECK-001/-004 make VTODO the authoritative record for *internal work items*, while a toezegging is a political accountability record with a public list — different authority, different audience, different retention.

**Alternative considered:** `decisionType: toezegging` — rejected per above; also the Decision lifecycle (`draft → … → enacted`) is guarded by an existing transition map that does not fit `open → afgedaan`.

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Toezegging status workflow (`open → in-uitvoering → afgedaan \| vervallen`) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`states`-only/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| IngekomenStuk follow-up workflow (`geregistreerd → geagendeerd → routering-vastgesteld → afgedaan`, `aangehouden` loop) | `x-openregister-lifecycle` | Same |
| Deadline rappels before/after deadline | `x-openregister-notifications` scheduled triggers (filter on non-terminal lifecycle + deadline window), recipients = madeBy + griffie group, nl/en subjects | ADR-031 default for reminders; the notification-dialect gate (gate-18) hard-fails legacy/imperative dispatch; no bespoke `ReminderJob` (deliberate contrast with the motie change's older imperative design) |
| "Toezegging registered / afgedaan" notifications | `x-openregister-notifications` `created`/`updated` triggers | Same |
| Dashboard KPI "open toezeggingen over deadline" | Manifest stat-widget `source` aggregation (`metric: count`) | Declarative count like every existing KPI widget |
| IngekomenStuk payload anonymisation | **Imperative** — `PublicationPayloadService` | Allow-list payload construction with conditional sender rendering is by design imperative in decidesk (existing pattern); no dialect builds derived payloads |
| Bulk routing confirmation | **Imperative** — small service action batching lifecycle transitions | Multi-object transactional action tied to chair authority; not expressible as a dialect |

### D4: Public toezeggingenlijst = predicate on the live object; ingekomen stukken = derived payload

Toezegging: the public list must be *live* (an `afgedaan` status change must show without republication), and the schema is designed to contain only publishable fields (no internal-notes property — internal work belongs in the sidebar audit trail or a VTODO). So the schema declares `authorization.read` for the `public` group while `publicatiedatum <= $now`, and publish/withdraw = staff setting `publicatiedatum`/`depublicatiedatum`. This deviates from public-publication's derived-payload rule deliberately; the delta spec records the carve-out. (`@self.published` is removed from OR and is not used.)

IngekomenStuk: the sender may be a natural person (WOO/AVG), so the live object can never be public. It follows the full public-publication machinery: immutable allow-list payload, `senderType`-driven anonymisation ("Inwoner" label; name/contact structurally absent), eligibility gate `routering-vastgesteld | afgedaan` + public meeting, OpenCatalogi routing when configured, withdraw/rectify flows reused as-is.

**Alternative considered:** derived payloads for toezeggingen too — rejected: every status change would need a rectify cycle, guaranteeing a stale public list (the exact failure mode griffies complain about in GO).

### D5: Bulk confirmation rides the existing hamerstuk flow

The "Lijst ingekomen stukken" is a normal AgendaItem tagged `hamerstuk` (REQ-LIV-003). The chair's existing batch adoption settles the *agenda item*; this change adds the object-side effect: a `confirmRouting(listAgendaItem)` action that transitions every placed stuk `geagendeerd → routering-vastgesteld`. Pulling one stuk mirrors "Uit hamerstukken halen": the stuk becomes `aangehouden` and drops off the batch. Chair/secretary authority is checked the same way as `processHamerstukken()` (opened meeting + chair role), and each transition still passes the declared lifecycle map.

### D6: Dashboard KPI lives in the base manifest, not the fragment

`buildManifest()`'s `mergePages()` replaces a same-id page wholesale — a fragment cannot *add* one widget to the existing `Dashboard` page without duplicating the whole page definition (drift magnet). The one-widget addition is therefore a direct edit to `src/manifest.json`'s Dashboard page; the fragment carries only the four new pages + menu entries. The KPI filter needs a relative "today" comparison on `deadline`; provisional token `{"deadline": {"lt": "@now"}}` alongside `lifecycle: ["open", "in-uitvoering"]` — to be verified against nc-vue's widget source resolver, with fallback: KPI counts non-terminal toezeggingen with any deadline and the overdue cut happens on the pre-filtered index (documented, not silent).

### D7: Sender modelled as plain fields, not a Person reference

`IngekomenStuk.sender` is a string + `senderType` enum, not a reference to the `Person` schema. External letter-writers are not governance persons; creating Person objects for them would pull citizen PII into the shared people register and complicate WOO anonymisation. `Toezegging.madeBy` *is* a Person reference (portefeuillehouders are governance persons already in the register).

## Nextcloud Integration

- Controllers: `PublicationController` (existing — gains the `ingekomen-stuk` payload type routing; no new controllers). Bulk-confirm action goes through the existing governance-scoped controller pattern with chair authorization (`GovernanceControllerTrait` / scope guard), `#[NoAdminRequired]` + per-object guard (no-admin-idor gate).
- Services: `PublicationEligibilityService`, `PublicationPayloadService` (extended); new `IngekomenStukRoutingService` (place-on-list + bulk confirm; thin — transitions via `ObjectService::saveObject()` carrying **all** fields forward, PUT-semantic).
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: none new — notifications and lifecycle are OR-side declarative.
- Frontend: manifest pages via `CnPageRenderer`; export via `ExportService` + `CnMassExportDialog`; files via the Files leaf on the detail pages.

## Security Considerations

- **WOO/AVG (high):** natural-person sender anonymisation is enforced in payload construction (server-side, structural), mirroring the "totals, never voters" discipline; PHPUnit asserts absence of the name in the payload; live `IngekomenStuk` objects stay behind OR RBAC. Attachment files are never carried into payloads (reference stripped, like confidential agenda-item documents).
- **Public predicate on Toezegging:** the schema carries no non-public fields by construction; publish/withdraw is an explicit staff action on RBAC-guarded objects; no writeOnly fields exist on either schema (no render-boundary exposure).
- **Bulk confirm authority:** chair/secretary of an opened meeting only; guarded per-object (governance scope), not merely by route annotation (semantic-auth gate).
- **CSRF/auth posture:** standard NC attributes on any touched controller methods; no public app routes — the only anonymous surface is OR/OpenCatalogi.

## File Structure

```
lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json   (new — schemas + dialects + seed)
src/manifest.d/toezeggingen-ingekomen-stukken.json               (new — 4 pages + menu)
src/manifest.json                                                (edit — 1 Dashboard stat widget)
lib/Service/PublicationEligibilityService.php                    (edit — IngekomenStuk gate)
lib/Service/PublicationPayloadService.php                        (edit — payload + anonymisation)
lib/Service/IngekomenStukRoutingService.php                      (new — place-on-list, bulk confirm)
lib/Controller/PublicationController.php                         (edit — payload type routing)
appinfo/routes.php                                               (edit — bulk-confirm route)
tests/Unit/Service/...                                           (new/edit — eligibility, anonymisation, routing)
tests/e2e/...                                                    (new — scenario coverage per gate-19)
docs/features/toezeggingen.md, docs/features/ingekomen-stukken.md (new)
```

## Seed Data

Realistic Dutch municipal examples (fictional municipality "Gemeente Voorbeeldingen"); all references use existing decidesk seed objects (council governance body, scheduled raadsvergadering) or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder where a cross-seed reference is resolved at import.

### Schema: `toezegging`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | toezegging-raadsbrief-jeugdzorg | toezegging-schouw-marktplein | toezegging-evaluatie-afvalbeleid |
| text | "Wethouder Van Dijk zegt toe de raad vóór 1 maart per raadsbrief te informeren over de wachtlijsten jeugdzorg." | "Wethouder Pietersen zegt toe binnen zes weken een schouw van het Marktplein te organiseren met de klankbordgroep." | "Het college zegt toe de evaluatie van het afvalbeleid gelijktijdig met de kadernota aan te bieden." |
| madeBy | (Person: wethouder, nil-UUID placeholder) | (Person: wethouder, nil-UUID placeholder) | (Person: wethouder, nil-UUID placeholder) |
| meeting | (seed raadsvergadering ref) | (seed raadsvergadering ref) | (seed raadsvergadering ref) |
| agendaItem | (seed agenda item ref) | (seed agenda item ref) | — |
| directedTo | (seed gemeenteraad body ref) | (seed gemeenteraad body ref) | (seed gemeenteraad body ref) |
| deadline | 2026-03-01 | 2026-05-15 | 2026-06-30 |
| lifecycle | open | in-uitvoering | afgedaan |
| afdoeningsToelichting | — | — | "Evaluatierapport aangeboden bij kadernota 2026; besproken in commissie Middelen." |
| afdoeningsBewijs | — | — | (link to raadsbrief document) |
| relatedMotion | — | — | (Decision decisionType=motion, nil-UUID placeholder) |
| publicatiedatum | 2026-01-20T09:00:00Z | 2026-02-10T09:00:00Z | 2026-02-10T09:00:00Z |

**Related items per object:** Files: raadsbrief PDF on object 3 via Files leaf. Notes/Tasks/Contacts: none (internal follow-up is a VTODO, deliberately not seeded here).

One of objects 1–2 gets a deadline in the past at seed time so the dashboard KPI is non-zero on a fresh install (ADR-016 testability).

### Schema: `ingekomen-stuk`

| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | brief-verkeersveiligheid-schoolzone | petitie-behoud-zwembad | collegestuk-jaarverantwoording | uitnodiging-veiligheidsregio |
| title | "Brief inzake verkeersveiligheid schoolzone Lindelaan" | "Petitie behoud zwembad De Plons (1.243 ondertekenaars)" | "Jaarverantwoording kinderopvang 2025" | "Uitnodiging werkbezoek Veiligheidsregio" |
| sender | "J. Jansen" | "Actiecomité De Plons" | "College van B&W" | "Veiligheidsregio Midden-Nederland" |
| senderType | natuurlijk-persoon | organisatie | bestuursorgaan | bestuursorgaan |
| receivedAt | 2026-01-12 | 2026-01-15 | 2026-01-18 | 2026-01-19 |
| category | brief-inwoner | petitie | collegestuk | uitnodiging |
| routingAdvice | in-handen-college-ter-afdoening | betrekken-bij-agendapunt | voor-kennisgeving-aannemen | voor-kennisgeving-aannemen |
| targetAgendaItem | — | (seed agenda item ref) | — | — |
| listAgendaItem | (seed LIS agenda item ref) | (seed LIS agenda item ref) | (seed LIS agenda item ref) | — |
| directedTo | (seed gemeenteraad body ref) | (seed gemeenteraad body ref) | (seed gemeenteraad body ref) | (seed gemeenteraad body ref) |
| lifecycle | routering-vastgesteld | geagendeerd | routering-vastgesteld | geregistreerd |

**Related items per object:** Files: scanned letter PDF (object 1), petition PDF (object 2) via Files leaf. Seed also adds one "Lijst ingekomen stukken" AgendaItem (tagged `hamerstuk`) to the seeded upcoming raadsvergadering so placement/bulk-confirm is demoable on install.

Object 1 is seeded as *published* (payload with anonymised sender "Inwoner") so the anonymisation path is visible on a fresh install; object 4 stays `geregistreerd` so the placement flow is demoable.

## Migration Plan

1. Land register.d + manifest.d fragments, service edits, seed data, tests, docs in one decidesk PR (fragments are additive; repair step / `ConfigurationService::importFromApp()` picks up the new schemas on upgrade).
2. `motie-amendement-administratie` must land first or concurrently only for the `relatedMotion` cross-reference target semantics; the field is a nullable reference and degrades to a plain link if that change is delayed.
3. Rollback: revert the PR — fragments disappear, pages unregister, publication types refuse again. Existing objects remain soft-retained in OR; published toezeggingen are withdrawn by clearing the predicate (`depublicatiedatum`) via the normal staff flow if desired.

No data migration — both registers start empty apart from seed data.

## Risks / Trade-offs

- [Anonymisation regression] → structural allow-list construction + PHPUnit asserting the natural-person name never appears in a payload; mutation-style test (change senderType, assert flip) so a no-op fake green is caught.
- [Live-predicate toezegging leaks a future internal field] → schema rule: adding any non-public property to `Toezegging` requires revisiting D4; noted in the schema `description` so the constraint travels with the schema.
- [Lifecycle dialect drift (`initial` vs `initialState`)] → fragment uses the canonical dialect verbatim from the existing Decision schema; gates 28/30/51/52 run on register+manifest changes; manifest refs use slugs (`toezegging`, `ingekomen-stuk`), never PascalCase.
- [Bulk confirm partially fails mid-batch] → per-stuk saveObject with collected failures reported to the chair; already-confirmed stukken stay confirmed (idempotent re-run confirms the remainder).
- [KPI relative-date token unsupported] → fallback in D6; never a silent wrong count.

## Open Questions

- Exact relative-date token in the widget source filter DSL (D6) — verify against nc-vue `useDashboardWidgets`/source resolver during apply.
- Rappel windows (14 days before / weekly after deadline?) — provisional values in the notification triggers; griffie-configurable tuning deferred to a future admin-settings change.
