# woo-diwoo-publication Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- [woo-diwoo-publication](../../) _(active)_ — Woo/DiWoo compliance layer decorating the existing publication machinery (kind: code)

## Purpose

Makes decidesk publications compliant with the Woo (Wet open overheid) actieve-openbaarmaking duty for vergaderstukken of decentrale overheden: every published object carries DiWoo metadata (Woo informatiecategorie as a TOOI waardelijst URI, bestuursorgaan TOOI id, openbaarmakingsdatum, documenthandeling) and is discoverable by the KOOP/LV Woo harvester through a Woo-index sitemap. This capability *decorates* the payloads produced by the existing public-publication machinery — it never changes publication eligibility, PII stripping, or the type deny-list, which public-publication owns (and which sibling changes toezeggingen-ingekomen-stukken and vragenuur-interpellatie already extend).

**Standards**: Woo (Wet open overheid, actieve openbaarmaking art. 3.3), DiWoo (metadata standard + Woo-index sitemap of KOOP/LV Woo), TOOI (waardelijst woo-informatiecategorieën for informatiecategorie URIs; gemeente identifiers for bestuursorgaan, same convention as records-management-archiving's MDTO archiefvormer), OpenRaadsinformatie (payloads keep their existing `oriType` mappings), Schema.org (`CreativeWork` publication annotations preserved).

## ADDED Requirements

### Requirement: REQ-WOO-001 Woo informatiecategorie mapping configuration

The system MUST provide a `WooCategorieMapping` schema in the decidesk register (via the `lib/Settings/register.d/58-woo-diwoo-publication.json` fragment per ADR-037, never by editing `decidesk_register.json`) carrying at minimum: `objectType` (the publishable type this row maps, identified by schema slug or payload kind: `meeting-agenda`, `besluitenlijst`, `minutes`, `decision`, `motie`, `toezegging`, `raadsinformatiebrief`, `regeling`; required, unique), `informatiecategorie` (TOOI concept URI from the waardelijst woo-informatiecategorieën, pattern-validated against `https://identifier.overheid.nl/tooi/`, required), `informatiecategorieLabel` (human-readable label, required), `active` (boolean, required), and `notes` (optional). Every property MUST carry a `title`; the slug is `woo-categorie-mapping`. Mappings are per-type defaults; a per-object override MUST be capturable at publish time (stored in that publication's `diwoo` block with provenance `default` or `override`, see REQ-WOO-002) — the mapping objects themselves are never edited by a publish action. Seed mappings MUST use concept URIs taken verbatim from the published TOOI waardelijst (the vergaderstukken-decentrale-overheden category for agenda/besluitenlijst/verslag types); invented URIs MUST NOT ship. A mapping row whose `objectType` schema is not installed (sibling change not yet landed) MUST be inert — it never blocks import, publication, or the coverage report.

#### Scenario: Register import creates the mapping schema and seeds

- GIVEN a clean instance
- WHEN the decidesk register is imported
- THEN the `woo-categorie-mapping` schema exists from fragment 58 and seed mappings exist for the publishable types, each carrying a TOOI waardelijst URI and label

#### Scenario: Invalid TOOI URI refused

@e2e exclude schema-validation contract — covered by Newman against the OR object API
- GIVEN an admin editing a mapping
- WHEN they set `informatiecategorie` to a URI outside `https://identifier.overheid.nl/tooi/`
- THEN OR schema validation rejects the write

#### Scenario: Mapping for a not-yet-installed type is inert

- GIVEN the seed mapping for `motie` while the Motie schema (sibling change) is not installed
- WHEN the register imports and publications run
- THEN nothing fails; the coverage report lists the type as not installed rather than unmapped

---

### Requirement: REQ-WOO-002 DiWoo metadata decoration of publication payloads

When an object is published through the existing publication machinery, the system MUST decorate the public payload with a `diwoo` object property carrying at minimum: `informatiecategorie` (TOOI URI resolved from the active `WooCategorieMapping` for the object's type, or the staff-supplied per-object override), `informatiecategorieBron` (`default` | `override`), `bestuursorgaan` (TOOI organization identifier of the publishing governance body, same convention as the MDTO archiefvormer in records-management-archiving, e.g. `gm0344`, configured per instance/body), `openbaarmakingsdatum` (equal to the payload's `publicatiedatum`), and `documenthandeling` (DiWoo handeling: `vaststelling` with the source object's decision/approval date when known, otherwise `ontvangst` with the publication date). The decoration MUST be additive: existing payload fields, `oriType` mappings, PII stripping, immutability, and the eligibility gates of public-publication are unchanged and MUST NOT be re-implemented or altered by this capability. For types that publish via a `publicatiedatum` predicate on the live object rather than a derived payload (e.g. `Toezegging`, `Raadsinformatiebrief`), the same `diwoo` block MUST be written onto the object as an optional property in the same staff publish action. When no active mapping exists for the type and no override is supplied, publication MUST still succeed without a `diwoo` block, and the omission MUST be counted by the coverage report (REQ-WOO-005) — Woo metadata never blocks lawful publication.

#### Scenario: Published decision payload carries the DiWoo block

- GIVEN an active mapping for `decision` and a configured bestuursorgaan TOOI id
- WHEN staff publish an enacted decision
- THEN the publication payload contains `diwoo.informatiecategorie` (the mapped TOOI URI), `diwoo.bestuursorgaan`, `diwoo.openbaarmakingsdatum` equal to `publicatiedatum`, and a `documenthandeling` of `vaststelling` with the decision date

#### Scenario: Staff override the categorie at publish time

- GIVEN a publish action for a set of approved minutes
- WHEN staff select a different informatiecategorie from the waardelijst in the publish dialog
- THEN the payload's `diwoo.informatiecategorie` is the override URI and `informatiecategorieBron` is `override`, while the `WooCategorieMapping` object remains unchanged

#### Scenario: Unmapped type publishes without DiWoo block

@e2e exclude degradation contract — covered by PHPUnit on the decorator plus Newman payload assertion
- GIVEN no active mapping for `meeting-agenda` and no override supplied
- WHEN staff publish a public meeting agenda
- THEN the publication succeeds exactly as before, the payload has no `diwoo` block, and the coverage report counts the payload as missing Woo metadata

#### Scenario: Eligibility and PII behavior untouched

@e2e exclude regression contract — covered by the existing public-publication PHPUnit/Newman suites re-run against the decorated builders
- GIVEN the DiWoo decorator installed
- WHEN a draft decision publish is attempted and a published payload is read
- THEN eligibility refusal and PII-stripped field sets behave exactly per the public-publication spec (no field added or removed other than `diwoo`)

---

### Requirement: REQ-WOO-003 Harvestable Woo-index sitemap

The system MUST expose a publicly accessible, read-only Woo-index sitemap endpoint (DiWoo sitemap XML) enumerating publications: it MUST list only objects whose `publicatiedatum` is set and in the past and whose `depublicatiedatum` is absent or in the future — the same predicate OR's anonymous published surface enforces — and only entries carrying a `diwoo` block. Each entry MUST carry the public resource URL (the OR/OpenCatalogi publication surface URL, never an app-local content page), the DiWoo metadata (informatiecategorie, bestuursorgaan, openbaarmakingsdatum, documenthandeling), and the last-modification date. The endpoint MUST be annotated `#[PublicPage]` + `#[NoCSRFRequired]` (pattern: REQ-ORI-005), MUST serve references and metadata only (never payload bodies), and MUST paginate per the sitemap protocol so harvesting stays O(pages). Withdrawn (depublished) publications MUST disappear from the index on the next request. The system SHALL NOT serve any other app-local anonymous surface for published governance data (consistent with public-publication).

**Nextcloud OCP interface:** `#[PublicPage]`, `#[NoCSRFRequired]` route attributes

#### Scenario: Harvester fetches the Woo-index

- GIVEN three published payloads with `diwoo` blocks and one without
- WHEN an unauthenticated client GETs the Woo-index endpoint
- THEN it receives valid DiWoo sitemap XML listing exactly the three DiWoo-decorated publications with their TOOI informatiecategorie, bestuursorgaan, and public resource URLs

#### Scenario: Withdrawn publication leaves the index

@e2e exclude predicate contract — covered by Newman before/after a withdraw
- GIVEN a publication listed in the Woo-index
- WHEN staff withdraw it (public-publication withdraw flow sets `depublicatiedatum`)
- THEN the next unauthenticated fetch of the index no longer lists it

#### Scenario: Index never serves non-public data

@e2e exclude negative security contract — covered by Newman
- GIVEN unpublished, draft, and deny-listed objects in the register
- WHEN the Woo-index endpoint is fetched
- THEN none of them appear and no payload bodies, NC UIDs, or internal identifiers are exposed

---

### Requirement: REQ-WOO-004 Optional LV Woo push delivery via OpenConnector

In addition to the harvest path, the system MUST support optional push notification of new/changed/withdrawn publications to an LV Woo aggregation endpoint through a configured OpenConnector Source (external-integration exception under ADR-031), resolved lazily by slug in the same pattern as the `eidas-qes` Source and records-management-archiving REQ-RMA-005. Push MUST be per-publication, recorded on the corresponding `PublicationRecord` (delivery state + remote reference), and retryable on failure. When OpenConnector is absent or no Woo Source is configured, the system MUST degrade honestly: the harvestable Woo-index (REQ-WOO-003) remains the delivery mechanism, the admin UI states that push is unavailable, and publication flows are unaffected — the system MUST NOT fail or pretend delivery happened. A failed push MUST NOT change any publication state.

#### Scenario: Push on publish with a configured Source

- GIVEN a configured OpenConnector Woo Source
- WHEN staff publish an enacted decision with a `diwoo` block
- THEN a push notification is delivered through the Source and the `PublicationRecord` stores the delivery acknowledgement reference

#### Scenario: OpenConnector absent degrades honestly

- GIVEN an instance without OpenConnector
- WHEN staff publish and then open the Woo admin section
- THEN publication succeeds normally, the Woo-index still lists the publication, and the admin UI states that push delivery is unavailable

#### Scenario: Push failure surfaces and is retryable

@e2e exclude remote-failure branch — covered by PHPUnit with a failing connector client
- GIVEN a configured Source whose call errors
- WHEN a push runs
- THEN the `PublicationRecord` marks the push as failed-retryable, staff see the failure, and the publication itself remains published and harvestable

---

### Requirement: REQ-WOO-005 Woo coverage report

The system MUST provide an aggregate Woo-coverage view answering: which publishable object types have no active `WooCategorieMapping` (distinguishing "unmapped" from "type not installed"), and which published objects lack a `diwoo` block, with a headline KPI (percentage of currently-published objects carrying DiWoo metadata). Counters MUST be declarative (`x-openregister-aggregations` in fragment 58) wherever the dialect can express them; any cross-schema residue is computed read-only server-side and MUST NOT introduce a bespoke reporting backend. The view MUST link each gap to the corrective action (the mapping row to activate in settings, or the publication to rectify) and MUST be visible to staff with governance-body authority via a dashboard widget and the admin mapping section.

#### Scenario: Coverage KPI reflects seeded gaps

- GIVEN seeded publications of which one lacks a `diwoo` block and one type has its mapping inactive
- WHEN staff open the Woo coverage view
- THEN the KPI shows the correct percentage, the inactive type is listed as unmapped with a link to settings, and the undecorated publication is listed with a rectify link

#### Scenario: Coverage distinguishes uninstalled sibling types

- GIVEN the `regeling` mapping seed while the Regeling schema is not installed
- WHEN the coverage view renders
- THEN `regeling` appears as "type not installed", not as a compliance gap

---

### Requirement: REQ-WOO-006 Admin mapping UI

The decidesk admin settings MUST gain a Woo/DiWoo section where admins manage: the per-type `WooCategorieMapping` rows (categorie URI + label from the TOOI waardelijst, active flag), the bestuursorgaan TOOI identifier for the instance (with per-governance-body override), and the optional OpenConnector push Source slug. All configuration MUST be stored as OpenRegister objects or app config — no bespoke tables and no secrets in schemas (ADR-064: any Source credentials live in OpenConnector). The section MUST be rendered through Nextcloud's settings framework only (never registered as an in-app vue-router route), MUST use `IInitialState`/`loadState` for server data, and MUST meet WCAG 2.1 AA with Dutch and English strings (statutory Dutch terms kept, English gloss).

**Nextcloud OCP interface:** `OCP\Settings\ISettings`, `OCP\AppFramework\Services\IInitialState`

#### Scenario: Admin activates a mapping

- GIVEN an admin in the decidesk admin settings Woo section
- WHEN they set the informatiecategorie for `toezegging` and mark it active
- THEN subsequent toezegging publications carry that TOOI URI in their `diwoo` block and the coverage report no longer lists the type as unmapped

#### Scenario: Bestuursorgaan configured once, used everywhere

- GIVEN the admin sets the bestuursorgaan TOOI id to `gm0344`
- WHEN any object is published with a `diwoo` block
- THEN `diwoo.bestuursorgaan` is `gm0344` unless a per-body override applies

#### Scenario: Settings surface is not a public route

@e2e exclude static convention — enforced by the admin-router hydra gate
- WHEN the admin-router gate scans the frontend router
- THEN the Woo settings component is not registered as a vue-router route

## Non-Functional Requirements

- **Performance:** Woo-index generation MUST paginate (sitemap protocol) and answer a page in under 2 seconds at 10,000 publications; the decorator adds no extra OR round-trips beyond one mapping lookup per publish (mappings cacheable per request).
- **Security:** the public endpoint serves only already-public references/metadata under the same predicate as OR's anonymous surface; no NC UIDs; push credentials live in OpenConnector (ADR-064).
- **Accessibility:** coverage widget and admin section meet WCAG 2.1 AA.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); statutory Dutch terms (informatiecategorie, openbaarmakingsdatum, bestuursorgaan) are kept in both locales with an English gloss.

## Acceptance Criteria

- [ ] Fragment 58 imports on a clean instance: `woo-categorie-mapping` schema + seeds with real TOOI waardelijst URIs
- [ ] Publishing an eligible object with an active mapping yields a payload with a complete `diwoo` block; without a mapping, publication still succeeds and the gap is counted
- [ ] Public-publication regression suites pass unchanged against the decorated builders
- [ ] Unauthenticated Woo-index fetch lists exactly the DiWoo-decorated, currently-public publications; withdrawn items disappear
- [ ] Push delivery works with a configured Source and degrades honestly without OpenConnector
- [ ] Coverage KPI + per-type/per-object gap lists render from declarative aggregations; admin mapping UI round-trips all configuration

## Notes

- Strictly ADDED-only: this spec decorates public-publication payloads; the eligibility-gates requirement of public-publication is owned there and extended by sibling changes (toezeggingen-ingekomen-stukken, vragenuur-interpellatie) — this change never touches it.
- Woo *verzoeken* (passieve openbaarmaking) are out of scope; the Woo-openbaarmakingsbesluit document for such decisions is covered by p2-minutes-and-decisions-core-t1 REQ-PPD-002.
- Type names coordinate with siblings: `Motie` (motie-amendement-administratie), `Toezegging` (toezeggingen-ingekomen-stukken), `Raadsinformatiebrief` (raadsinformatiebrieven, fragment 51), `Regeling` (verordeningenregister); besluitenlijst is the generated decision-list document of p2-minutes-and-decisions-core-t1.
