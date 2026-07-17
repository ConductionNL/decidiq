# Design: woo-diwoo-publication

## Architecture Overview

Decidesk stays a thin client on OpenRegister (ADR-022). The Woo/DiWoo layer is a *decoration* of the existing public-publication machinery — it introduces one config schema, one decorator step inside the payload builders, one public read-only endpoint, and one optional connector service. It never re-implements eligibility, PII stripping, immutability, or withdraw/rectify.

```
WooCategorieMapping (config, fragment 58)      Admin settings (Woo section)
        │ resolved per type at publish time            │ manages mappings + bestuursorgaan + Source slug
        ▼                                              ▼
Existing publish flow ──► payload builders ──► DiWooMetadataService.decorate()
  (eligibility, PII strip — UNCHANGED)             └─ diwoo { informatiecategorie (TOOI URI),
                                                            informatiecategorieBron, bestuursorgaan (gm####),
                                                            openbaarmakingsdatum, documenthandeling }
        ▼
PublicationPayload / predicate-published object (Toezegging, Raadsinformatiebrief)
        ├─► Woo-index sitemap endpoint (#[PublicPage], DiWoo XML, paginated) ◄── KOOP/LV Woo harvester
        └─► optional push: WooIndexConnectorService → OpenConnector Source → LV Woo aggregation point
Coverage = x-openregister-aggregations + manifest widget (no reporting backend)
```

The `WooCategorieMapping` schema, its seeds, and the coverage aggregations ship as ADR-037 register fragment `lib/Settings/register.d/58-woo-diwoo-publication.json` (number 58 is assigned to this change; 40–57 and 59–65 belong to siblings). The one canonical-file edit is the additive optional `diwoo` object property on `PublicationPayload` (and on predicate-published schemas as they exist) in `lib/Settings/decidesk_register.json` — fragments merge whole schemas; property additions to existing schemas belong in the canonical file (same rule records-management-archiving follows for `mdto`).

## Decisions

### Declarative-vs-imperative decision (ADR-031)

Default is declarative via `x-openregister-*` dialects in the register fragment. Imperative service classes only for justified exceptions:

| Concern | Mode | Justification |
|---|---|---|
| WooCategorieMapping storage + validation (TOOI URI pattern, required fields, uniqueness per type) | **Declarative** OR schema in fragment 58 | Pure config objects; OR validates |
| Coverage counters (publications with/without `diwoo`, mappings active/inactive per type) | **Declarative** `x-openregister-aggregations` (pattern: records-management compliance counters) | Count metrics with filters; no bespoke reporting backend |
| Coverage widget + admin-linked gap lists | **Declarative** manifest fragment `src/manifest.d/woo-diwoo.json` (schema refs by **slug**: `woo-categorie-mapping`) | Rendering is manifest-driven |
| Mapping-changed staff notification (optional) | **Declarative** `x-openregister-notifications` (ADR-031 dialect; gate-18 forbids imperative dispatch) | Trigger-condition notification |
| **DiWooMetadataService** (decorator) | Imperative | Derived-data composition exception: resolves mapping/override + bestuursorgaan and composes the `diwoo` block inside the existing builder flow; not expressible as a calculation (cross-schema config lookup + publish-action input) |
| **WooIndexController** (sitemap) | Imperative | Serialization exception: DiWoo sitemap XML generation + pagination over the published predicate (pattern: ori-api public endpoint) |
| **WooIndexConnectorService** (+ `LogWooIndexConnectorService` fallback) | Imperative | External-integration exception: lazy OpenConnector Source lookup, push, ack/failure handling — mirror of `ArchiveConnectorService`/`EIDASSignatureService` |
| Cross-schema coverage residue ("type not installed" detection) | Imperative read-only in the coverage endpoint | Schema-existence introspection exceeds the aggregation dialect; read-only, no writes |

No app-local state machine anywhere: mappings have no lifecycle; publication lifecycle stays owned by public-publication.

### Other key decisions

- **Harvest-first, push optional.** The LV Woo ingests via harvesting the bestuursorgaan's Woo-index sitemap; that is the DiWoo-native mechanism, works with zero external configuration, and is therefore the mandatory path (REQ-WOO-003). Push via OpenConnector (REQ-WOO-004) is an optional accelerator for aggregation points that want active notification. *Alternative considered:* push-only via OpenConnector — rejected: it inverts the standard's harvest model and makes compliance depend on an optional app.
- **Decoration, not modification.** The `diwoo` block is composed by one decorator invoked by the existing payload builders after PII stripping. public-publication's eligibility-gates requirement is already layered on by two sibling changes (toezeggingen-ingekomen-stukken, vragenuur-interpellatie); a third MODIFY would guarantee archive-time conflicts. *Alternative:* MODIFIED requirement on public-publication — rejected for exactly that reason; this capability is ADDED-only.
- **Missing mapping never blocks publication.** Woo metadata is a compliance layer; the legal publication act must not be hostage to configuration. Gaps are made loud through the coverage KPI instead. *Alternative:* hard-require a mapping to publish — rejected: would freeze existing publish flows on day one.
- **TOOI URIs are data, never code.** Seed rows carry concept URIs copied verbatim from the published waardelijst woo-informatiecategorieën at implementation time; the schema pattern-validates the `https://identifier.overheid.nl/tooi/` prefix. Nothing resolves TOOI at runtime.
- **Bestuursorgaan id reuses the records-management convention** (TOOI gemeente code, e.g. `gm0344`, as MDTO archiefvormer already uses) — one org-identity convention across archiving and Woo, configured in the admin section with per-governance-body override.
- **Per-object override lives in the publish action** and is stored in that publication's `diwoo` block with provenance (`default`/`override`) — mapping objects stay pure config; no source-schema changes needed for overrides.

## API Design

Frontend CRUD on mappings goes through OR's object API via `useObjectStore` (no pass-through controllers — redundant-controller gate). App endpoints exist only where imperative services act:

### `GET /apps/decidesk/woo-index.xml` (+ `?page=N`) — public DiWoo sitemap; `#[PublicPage]` `#[NoCSRFRequired]`; lists only `publicatiedatum <= now` and not depublished, `diwoo`-decorated entries; metadata + public resource URLs only
### `GET /api/woo/coverage` — staff coverage report (aggregation results + type-not-installed residue); `#[NoAdminRequired]` + governance-body authority guard in body
### `POST /api/woo/push/{publicationRecordId}` — (re)push one publication via OpenConnector; 409 when no Source configured; `#[NoAdminRequired]` + authority guard

All registered in `appinfo/routes.php` (gates: route-auth, semantic-auth, route-reachability, no-admin-idor).

## Database Changes

None. All entities are OpenRegister objects; no Nextcloud migrations.

## Nextcloud Integration

- Controllers: `WooIndexController` (public sitemap), `WooCoverageController`, `WooPushController`
- Services: `DiWooMetadataService`, `IWooIndexConnectorService`, `WooIndexConnectorService`, `LogWooIndexConnectorService`
- Settings: existing decidesk admin settings gain a Woo section (`OCP\Settings\ISettings`; data via `IInitialState`/`loadState`, never DOM data-attributes; component NOT in vue-router — admin-router gate)
- DI: lazy OpenConnector lookup via `ContainerInterface` (pattern: `EIDASSignatureService`); registrations in `AppInfo\Application`
- Events/Hooks: none imperative — notifications declarative (gate-18)

## Security Considerations

- The public sitemap is the only new anonymous surface: it enumerates references + DiWoo metadata under the exact published predicate OR enforces, never payload bodies, never NC UIDs; Newman negative tests cover unpublished/withdrawn/deny-listed objects.
- Coverage and push endpoints carry `#[NoAdminRequired]` + per-request governance-body authority guards (no IDOR on `publicationRecordId`).
- No secrets in schemas (ADR-064): the push Source credentials live in OpenConnector; decidesk stores only the Source slug.
- Input validation on override URIs (TOOI pattern) server-side, not only in the UI.

## NL Design System

Standard NC components via the manifest renderer and settings framework; nldesign CSS variables only; coverage KPI uses semantic tokens; WCAG 2.1 AA on the coverage widget and the admin Woo section (NcSelect fields carry `inputLabel`).

## File Structure

```
lib/
  Controller/WooIndexController.php            (new — public sitemap)
  Controller/WooCoverageController.php         (new)
  Controller/WooPushController.php             (new)
  Service/DiWooMetadataService.php             (new — decorator)
  Service/IWooIndexConnectorService.php        (new)
  Service/WooIndexConnectorService.php         (new)
  Service/LogWooIndexConnectorService.php      (new — honest fallback)
  Service/<existing payload builder(s)>        (modified — invoke decorator after PII strip)
  Settings/register.d/58-woo-diwoo-publication.json  (new — schema + aggregations + seeds)
  Settings/decidesk_register.json              (modified — additive optional `diwoo` property on PublicationPayload and predicate-published schemas)
  AppInfo/Application.php                      (modified — DI)
appinfo/routes.php                             (modified)
src/manifest.d/woo-diwoo.json                  (new — coverage widget, mapping list page)
src/settings/ (Woo admin section component)    (new — settings framework only)
tests/Unit/Service/{DiWooMetadataServiceTest,WooIndexConnectorServiceTest}.php (new)
tests/integration/decidesk-woo-diwoo.postman_collection.json (new)
```

## Seed Data

Seeds ship as `x-openregister-seeds` inside fragment 58 (convention: `43-process-config-v1.json` / `44-records-management-archiving.json`), realistic for a Dutch municipality. TOOI concept URIs below are placeholders by shape — the implementation task copies the exact URIs verbatim from the published TOOI waardelijst woo-informatiecategorieën; invented ids never ship.

### Schema: `woo-categorie-mapping`
| Field | Obj 1 | Obj 2 | Obj 3 | Obj 4 | Obj 5 | Obj 6 | Obj 7 | Obj 8 |
|---|---|---|---|---|---|---|---|---|
| @self.slug | woo-map-meeting-agenda | woo-map-besluitenlijst | woo-map-minutes | woo-map-decision | woo-map-motie | woo-map-toezegging | woo-map-raadsinformatiebrief | woo-map-regeling |
| objectType | meeting-agenda | besluitenlijst | minutes | decision | motie | toezegging | raadsinformatiebrief | regeling |
| informatiecategorie | tooi:…/c_<vergaderstukken-decentrale-overheden> | same | same | same | same | tooi:…/c_<bereikbaarheidsgegevens-categorie n.v.t. → vergaderstukken> | tooi:…/c_<vergaderstukken-decentrale-overheden> | tooi:…/c_<wetten-en-algemeen-verbindende-voorschriften> |
| informatiecategorieLabel | Vergaderstukken decentrale overheden | Vergaderstukken decentrale overheden | Vergaderstukken decentrale overheden | Vergaderstukken decentrale overheden | Vergaderstukken decentrale overheden | Vergaderstukken decentrale overheden | Vergaderstukken decentrale overheden | Wetten en algemeen verbindende voorschriften |
| active | true | true | true | true | true | true | true | true |
| notes | — | generated document (p2 core-t1) | ORI Verslag | ORI Besluit | sibling: motie-amendement | sibling: toezeggingen (predicate-published) | sibling: RIB fragment 51 (predicate-published) | sibling: verordeningenregister |

(The `motie`/`toezegging`/`raadsinformatiebrief`/`regeling` rows are inert until their sibling schemas land — REQ-WOO-001.)

### App config seeds (admin-managed, not OR objects)
| Key | Seed value |
|---|---|
| bestuursorgaan TOOI id | `gm0344` (Gemeente Utrecht — same example id records-management uses for archiefvormer) |
| push Source slug | — (unset: honest-degradation path is the install default) |

**Related items per object:** none — mappings are pure config; no files, tasks, or contacts. The coverage KPI is testable on install because the existing publication seeds predate the decorator and therefore lack `diwoo` blocks (a real, honest gap the report must show — ADR-016 seeds make the feature demonstrable).

## Risks / Trade-offs

- [Fabricated TOOI URIs in seeds] → implementation copies URIs verbatim from the published waardelijst; schema pattern-validation; review checks each seed URI against the register (proposal Risk 1).
- [LV Woo harvester rejects the sitemap] → serialize against the published DiWoo sitemap schema; validate at build time; harvest is idempotent so fixes propagate on the next run (proposal Risk 2).
- [Public endpoint leaks] → single predicate shared with OR's anonymous surface; references/metadata only; Newman negative suite (proposal Risk 3).
- [Sibling naming drift] → mappings key on schema slug; inert rows for uninstalled types; coverage shows "type not installed" (proposal Risk 4).
- [Aggregation dialect can't express a counter] → degrade to the read-only coverage endpoint computing the residue — never a bespoke reporting backend (records-management precedent).

## Migration Plan

Purely additive: fragment 58 + additive optional `diwoo` property import via the existing register bootstrap; no data migration, no NC migration. Existing published payloads simply lack `diwoo` (visible in coverage, fixable via rectify). Rollback = revert the PR and re-import; the optional property remains valid-but-unused on any decorated payloads.

## Open Questions

Carried from the proposal: harvest-only vs harvest+push for the first pilot (provisional: sitemap mandatory, push optional); override URIs restricted to the TOOI waardelijst (provisional: yes, pattern-validated).
