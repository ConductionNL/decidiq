# Design: records-management-archiving

## Architecture Overview

Decidesk stays a thin client on OpenRegister (ADR-022): all new entities (ArchivalDossier, RetentionRule, TransferPackage, DestructionList) are OR schemas in the decidesk register; there are no app tables. Retention lands in OR's object-level `_retention` column and MDTO-relevant system metadata comes from OR's `_tmlo`/audit metadata — decidesk writes both only through the OR object API and never reimplements storage.

```
Meeting/Decision/Minutes/Vote/DigitalDocument  (existing schemas)
        │  assembled into
        ▼
ArchivalDossier (forming → closed → transferred | destroyed)
        │ RetentionRule (Selectielijst 2020) resolves disposition → OR `_retention`
        ├─ V (bewaren)  → TransferPackage (building → ready → delivering → delivered|failed)
        │                   └─ MDTO-XML sidecars + documents → OpenConnector Source → DMS/zaaksysteem/e-depot
        └─ B (vernietigen) → DestructionList (proposed → authorized → executed|rejected)
                              └─ OR retention-aware deleteObject → vernietigingsverklaring (permanent)
Compliance dashboard = x-openregister-aggregations + manifest.d widgets (no reporting backend)
```

Schemas + declarative dialects ship as an ADR-037 register fragment `lib/Settings/register.d/44-records-management-archiving.json` (merged into the effective `decidesk_register.json` at load by `SettingsService`), so concurrent changes never conflict. Additive `mdto` and `securityClassification` properties on the existing Minutes/Decision/Meeting/DigitalDocument schemas are the one edit made directly in `lib/Settings/decidesk_register.json` (fragments merge whole schemas; property additions to existing schemas belong in the canonical file).

## Decisions

### Declarative-vs-imperative decision (ADR-031)

Default is declarative via `x-openregister-{lifecycle,aggregations,calculations,notifications,relations,widgets}` in the register JSON (`lib/Settings/decidesk_register.json` + its ADR-037 fragment). Imperative service classes only for justified exceptions:

| Concern | Mode | Justification |
|---|---|---|
| Dossier / package / destruction-list state machines | **Declarative** `x-openregister-lifecycle` (canonical keys: `field`, `initial`, `states`, `terminal`, `transitions` — the exact dialect Decision already uses; never `initialState`/`default`, which OR silently ignores) | Pure guarded transition maps |
| Compliance counters (dossiers per state, overdue transfers, unassigned retention, gaps, pending destructions) | **Declarative** `x-openregister-aggregations` (pattern: Meeting's Participant/ActionItem counters) | Count metrics with filters; no bespoke reporting backend |
| Resolved disposition date, dossier max-member-classification | **Declarative** `x-openregister-calculations` | Derivable from own + related fields |
| Transfer-deadline approaching, destruction-authorization requested/granted, delivery failed | **Declarative** `x-openregister-notifications` (ADR-031 dialect; gate-18 forbids imperative dispatch) | Trigger-condition notifications |
| Dossier ↔ members, dossier ↔ rule/package/list links | **Declarative** `x-openregister-relations` | UUID reference relations |
| Dashboard widgets, dossier index/detail pages | **Declarative** manifest v2 fragment `src/manifest.d/records-management.json` (ADR-037; schema refs by **slug**, e.g. `archival-dossier`, never PascalCase) | Rendering is manifest-driven |
| **TransferPackageService** | Imperative | Document generation exception: builds MDTO-XML sidecars, validates against the MDTO schema, bundles documents |
| **ArchiveConnectorService** (+ `LogArchiveConnectorService` fallback) | Imperative | External-integration exception: lazy OpenConnector Source lookup, delivery, ack handling — mirror of `EIDASSignatureService`/`LogEIDASSignatureService` |
| **DestructionService** + `RetentionSweepJob` | Imperative | Scheduled bulk work exception: enumerating due dossiers, executing authorized deletions via OR `deleteObject`, generating the verklaring; pattern: `TranscriptRetentionJob` |
| Dossier assembly + completeness check | Imperative (`ArchivalDossierService`) | Cross-schema enumeration (minutes/decisions/votes/attachments per meeting) and override-gated close exceed the declarative dialects; assembly is a staff-triggered composite write |

### Other key decisions

- **MDTO-XML first** (National Archives reference serialization). Mapping is a data table inside TransferPackageService so MDTO-JSON can be added without redesign. *Alternative considered:* MDTO-JSON only — rejected, e-depot intake pipelines predominantly accept XML today.
- **Item-level MDTO derived at package-build time** from existing object properties + OR metadata; member schemas get only an optional `mdto` override. *Alternative:* mandatory MDTO blocks on all 37 schemas — rejected, would stall every existing workflow on archival metadata nobody has yet.
- **Destruction = OR retention-aware soft delete**, never a decidesk-side hard purge; OR remains the deletion authority and its audit trail records the act. Verklaring is the permanent legal artefact.
- **Separation of duties enforced server-side** on DestructionList (`proposedBy !== authorizer`), using OR RBAC scopes for records-management authority (pattern: `x-decidesk-rbac-scopes`); no app-local AuthorizationService (ADR-051).
- **Verklaring rendering** reuses the minutes document pattern: Docudesk PDF opportunistic, markdown canonical fallback, honest response when Docudesk is absent.
- **Classification deny integration**: `PublicationEligibilityService` gains one additional structural check (classification > `openbaar` refused) — extending the existing deny-list rather than a new surface.

## API Design

Frontend CRUD goes through OR's object API via `useObjectStore` (no redundant pass-through controllers — hydra gate `redundant-controller`). App endpoints exist only where imperative services act:

### `POST /api/dossiers/{id}/assemble` — (re)enumerate members for a forming dossier
### `POST /api/dossiers/{id}/close` — completeness check; body: `{ "overrideReason": "..." }` optional
### `POST /api/transfer-packages` — build package; body: `{ "dossierIds": ["<uuid>"] }`
### `POST /api/transfer-packages/{id}/deliver` — deliver via OpenConnector; 409 when not `ready`
### `GET  /api/transfer-packages/{id}/download` — zip (documents + MDTO sidecars)
### `POST /api/destruction-lists/{id}/authorize` — second-person authorization; 403 for proposer
### `POST /api/destruction-lists/{id}/execute` — execute authorized list, returns verklaring reference

All endpoints `#[NoAdminRequired]` + `#[NoCSRFRequired]` where API-consumed, with per-object authority guards in the method body (records-management scope), registered in `appinfo/routes.php` (gates: route-auth, semantic-auth, route-reachability, no-admin-idor).

## Database Changes

None. All entities are OpenRegister objects; retention uses OR's existing `_retention` object column. No Nextcloud migrations.

## Nextcloud Integration

- Controllers: `ArchivalDossierController`, `TransferPackageController`, `DestructionController` (imperative actions only)
- Services: `ArchivalDossierService`, `TransferPackageService`, `ArchiveConnectorService` (+ interface + log fallback), `DestructionService`
- Background jobs: `RetentionSweepJob` (`OCP\BackgroundJob\TimedJob`, daily — flags due dispositions and approaching transfer deadlines; never destroys anything itself)
- DI: lazy OpenConnector lookup via `ContainerInterface` (pattern: `EIDASSignatureService`); registrations in `AppInfo\Application`
- Events/Hooks: none imperative — notifications are declarative (gate-18)

## Security Considerations

- **Destruction is the highest-risk operation in the app**: frozen enumeration at proposal time, server-side separation of duties (proposer ≠ authorizer, both scope-checked via OR RBAC), execution touches only enumerated UUIDs, everything audit-trailed, verklaring permanent.
- Classification labels gate the public-publication payload builder structurally (before eligibility); transfer packages record the highest classification and MDTO `beperkingGebruik`.
- Download endpoint checks records-management authority per package (no IDOR); package zips contain no NC UIDs beyond what MDTO actor fields require (function + TOOI org id, not personal contact data).
- Input validation on all imperative endpoints; no secrets stored in schemas (ADR-064 — the archive Source credentials live in OpenConnector).

## NL Design System

Standard NC components through the manifest renderer; nldesign CSS variables only (no hardcoded colors); classification badges use semantic tokens; WCAG 2.1 AA on dossier pages, dashboard widgets, and the destruction authorization dialog (own file under `src/dialogs/`, modal-isolation gate).

## File Structure

```
lib/
  Controller/ArchivalDossierController.php     (new)
  Controller/TransferPackageController.php     (new)
  Controller/DestructionController.php         (new)
  Service/ArchivalDossierService.php           (new)
  Service/TransferPackageService.php           (new)
  Service/IArchiveConnectorService.php         (new)
  Service/ArchiveConnectorService.php          (new)
  Service/LogArchiveConnectorService.php       (new)
  Service/DestructionService.php               (new)
  Service/PublicationEligibilityService.php    (modified — classification deny)
  BackgroundJob/RetentionSweepJob.php          (new)
  Settings/register.d/44-records-management-archiving.json  (new — 4 schemas + dialects + seeds)
  Settings/decidesk_register.json              (modified — additive mdto/securityClassification props)
  AppInfo/Application.php                      (modified — DI + job registration)
appinfo/routes.php                             (modified)
src/manifest.d/records-management.json         (new — pages, menu, dashboard widgets)
src/dialogs/DestructionAuthorizeDialog.vue     (new)
tests/Unit/Service/{TransferPackageServiceTest,DestructionServiceTest,ArchivalDossierServiceTest}.php (new)
tests/integration/decidesk-records-management.postman_collection.json (new)
```

## Seed Data

Seeds ship as `x-openregister-seeds` inside the register fragment (convention: `43-process-config-v1.json`), realistic for a Dutch municipality and neutral enough for other governance domains. All cross-references use seed slugs; example UUIDs are the nil placeholder `00000000-0000-0000-0000-000000000000`.

### Schema: `retention-rule`
| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| @self.slug | selectielijst-2020-cat-2-1 | selectielijst-2020-cat-3-1 | selectielijst-2020-cat-19-1 | selectielijst-2020-cat-11-1 |
| name | Raadsbesluiten en -verslagen | Verordeningen en beleidsregels | Ingetrokken voorstellen | Organisatie-interne verantwoording |
| selectielijstCategory | 2.1 | 3.1 | 19.1 | 11.1 |
| waardering | V (bewaren) | V (bewaren) | B (vernietigen) | B (vernietigen) |
| retentionYears | — (permanent, overbrenging ≤ 10 jaar) | — | 5 | 10 |
| triggerEvent | dossier-closed | dossier-closed | dossier-closed | end-of-council-term |
| builtIn | true | true | true | true |

### Schema: `archival-dossier`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| @self.slug | dossier-raad-2026-04-10 | dossier-verordening-parkeren-2025 | dossier-mt-overleg-2016-q1 |
| title | Raadsvergadering 10 april 2026 | Besluitroute Parkeerverordening 2025 | MT-overleg Q1 2016 |
| lifecycle | forming | closed | closed |
| governanceBody | Gemeenteraad Utrecht | Gemeenteraad Utrecht | Managementteam Dienstverlening |
| period | 2026-04-10 | 2025-02-01 – 2025-06-19 | 2016-01-01 – 2016-03-31 |
| retentionRule | selectielijst-2020-cat-2-1 | selectielijst-2020-cat-3-1 | selectielijst-2020-cat-11-1 |
| members | minutes + 2 decisions + 2 voting rounds + 3 attachments | 1 decision (verordening) + amendments + votes | minutes + action list |
| securityClassification | openbaar | openbaar | intern |
| mdto.archiefvormer | gm0344 (TOOI) | gm0344 (TOOI) | gm0344 (TOOI) |
| dispositionDate | — (V) | — (V) | 2026-03-31 (B, due) |

### Schema: `transfer-package`
| Field | Object 1 | Object 2 |
|-------|----------|----------|
| @self.slug | overbrenging-2026-001 | overbrenging-2026-002 |
| state | delivered | ready |
| dossiers | dossier-verordening-parkeren-2025 | dossier-raad-2026-04-10 |
| mdtoSerialization | MDTO-XML | MDTO-XML |
| validationResult | passed | passed |
| deliveredTo / ack | Het Utrechts Archief e-depot, ack `<ACK_REF>` | — (awaiting delivery) |
| highestClassification | openbaar | openbaar |

### Schema: `destruction-list`
| Field | Object 1 | Object 2 |
|-------|----------|----------|
| @self.slug | vernietiging-2026-q3 | vernietiging-2025-q4 |
| state | proposed | executed |
| dossiers | dossier-mt-overleg-2016-q1 | (historical example dossier, 12 objects) |
| proposedBy | griffie-medewerker (seed user) | griffie-medewerker |
| authorizedBy | — (awaiting second person) | gemeentearchivaris, 2025-11-02 |
| verklaring | — | vernietigingsverklaring-2025-q4 (permanent, PDF + markdown) |

**Related items per object:**
- Files: seed minutes/decision documents referenced as dossier members; the executed destruction list links its rendered vernietigingsverklaring document.
- Notes: the `forming` dossier carries a completeness note ("besluitenlijst nog niet vastgesteld").
- Tasks: none (action items are Deck-leaf domain).
- Contacts: none (MDTO actor fields carry TOOI org ids, not personal contacts).

## Risks / Trade-offs

- [Wrongful destruction] → frozen enumeration, second-person authorization, OR soft delete, permanent verklaring (proposal Risk 1).
- [MDTO rejection by receiving archive] → build-time XSD validation blocks delivery; per-target quirks live in OpenConnector config (proposal Risk 2).
- [`_retention` semantics drift vs OR] → single write path through the OR object API; dashboard surfaces mismatches; verified against a live OR instance, not fakes (proposal Risk 3).
- [Declarative aggregation limits] → if a completeness counter proves inexpressible in the aggregation dialect, it degrades to a calculated field on the dossier — never a bespoke reporting backend.
- [Lifecycle dialect drift] → fragment uses the exact canonical keys Decision already uses; register-import verified on a clean instance (known fleet defect class: non-canonical keys are silently ignored).

## Migration Plan

Purely additive: new fragment + additive properties import via the existing register bootstrap; no data migration, no NC migration. Rollback = revert the PR and re-import; existing objects untouched. Already-delivered packages and executed destructions are legally irreversible by design.

## Open Questions

Carried from the proposal: MDTO-XML vs MDTO-JSON for the pilot target (provisional: XML); Selectielijst rules editable per municipality (provisional: editable seeds, `builtIn: true`).
