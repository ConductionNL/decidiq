# Design: records-management-archiving

## What OpenRegister already provides

Verified first-hand against openregister at commit `ebedbdd5a`. Everything below is **consumed**, never reimplemented (ADR-022). This section exists because the first draft of this change was written believing none of it existed.

| Capability | OpenRegister file | Notes |
|---|---|---|
| Retention resolution | `lib/Service/RetentionService.php::applyArchivalMetadata()` (L137) | Reads `Schema::getArchive()`; looks up the Selectielijst entry by `archive.classificatie`; writes `archiefnominatie`, `archiefstatus`, `classificatie`, `bewaartermijn`, `selectielijstBron`, `archiefactiedatum` into the persisted `retention` field. Invoked from `lib/Service/Object/SaveObject.php` (L3300). |
| Archiefactiedatum calculation | `lib/Service/Archival/ArchiefactiedatumCalculator.php` | ZGW afleidingswijzen: `afgehandeld`, `eigenschap`, `termijn` (L53-55). |
| Selectielijst storage | `lib/Db/SelectionList.php` + `SelectionListMapper.php` | Fields: `category`, `retentionYears`, `action`, `description`, `schemaOverrides`, `organisation`. |
| Destruction | `lib/Service/Archival/DestructionService.php` | Dual approval with a distinct-approver check (L366-382), legal-hold re-check before each delete (L552), certificate generation (L618-676). |
| Legal holds | `lib/Service/Archival/LegalHoldService.php` | `hasActiveHold()` consulted at DestructionService L228/L552/L700. |
| Destruction jobs | `lib/BackgroundJob/{DestructionCheckJob,DestructionExecutionJob}.php` | Daily sweep + execution. Decidiq needs no sweep job of its own. |
| MDTO serialization | `lib/Service/Edepot/MdtoXmlGenerator.php::generate()` (L99) | Emits `naam`, `toelichting`, `waardering`, `bewaartermijn`, `informatiecategorie` (from `retention.classificatie`, L272-273), `omvang`, `bestandsformaat`. |
| SIP packaging | `lib/Service/Edepot/SipPackageBuilder.php::build()` (L108) | `zip` or `bagit` (RFC 8493), METS + PREMIS namespaces (L67/L72), size splitting via `splitIntoBatches` (L126). |
| Transfer lists | `lib/Service/Edepot/TransferListService.php` | `createTransferList(array $objects)` (L134), approve/reject/exclude, archivist notification. |
| Transfer execution | `lib/Service/Edepot/EdepotTransferService.php` | `executeTransfer(array $transferList, TransportInterface $transport)` (L111); `lib/BackgroundJob/TransferExecutionJob.php`. |
| Transports | `lib/Service/Edepot/Transport/{OpenConnectorTransport,RestApiTransport,SftpTransport}.php` | OpenConnector is reached through OR, not by decidiq. |
| Confidentiality ordinal | `lib/Service/ZaaktypeAuthorizationService.php::VERTROUWELIJKHEIDAANDUIDING_LEVELS` (L61-70) | 8 ordered ZGW levels; order drives OR's clearance decisions. |
| Routes | `appinfo/routes.php` L1169-1183 | `/api/archival/destruction-lists/{id}/approve|reject`, `/api/archival/legal-holds`, `/api/archival/certificates` (L1177), `/api/settings/edepot`, `/api/transfers`. |

### Two naming traps this design must not repeat

1. **`_retention` is not the Archiefwet field.** `ObjectEntity::$archivalRetention` (`lib/Db/ObjectEntity.php` L531, surfaced as `_retention` at L1001) is a **transient, not-persisted, read-only** render-layer view from the `x-openregister-archival` TTL mechanism, shape `{effectiveRetention, matchedRule, expiresAt}`. It cannot be written. The persisted Archiefwet block is `retention` (`addType('retention', 'json')`, L713); the MDTO field is `tmlo` (L714). **`_tmlo` does not exist.**
2. **`x-openregister-archival` is TTL/log-rotation, not Archiefwet.** `ArchivalAnnotationValidator` allows only `{default, rules}` / `{condition, retention, reason}` (L58/L65) and auto-deletes on expiry without approval — it cannot express waardering B/V, a Selectielijst category, archiefactiedatum, afleidingswijze, or a bewaren→overbrengen route. Do not target it for Archiefwet work.

## Architecture Overview

Decidiq contributes exactly one thing OR lacks: the **aggregate**. (`openspec/specs/document-zaakdossier/spec.md` in OR is a `status: redirect` stub owned by Procest; OR has no dossier concept.) Everything downstream of the dossier is an OR call.

```
Meeting/Decision/Minutes/Vote/DigitalDocument  (existing decidiq schemas)
        │  assembled by ArchivalDossierService  ← the only new decidiq service
        ▼
ArchivalDossier (forming → closed → transferred | destroyed)   ← the only new decidiq schema
        │
        │ decidiq sets schema archive.classificatie; OR's RetentionService::applyArchivalMetadata()
        │ resolves nominatie/bewaartermijn/archiefactiedatum into the PERSISTED `retention` field
        │
        ├─ bewaren  → OR TransferListService.createTransferList(member UUIDs)
        │               └─ OR EdepotTransferService + SipPackageBuilder + Transport/OpenConnectorTransport
        │                    → dossier reflects `transferred`
        └─ vernietigen → OR destruction list + /api/archival/destruction-lists/{id}/approve
                          └─ OR DestructionCheckJob / DestructionExecutionJob (dual approval, legal holds)
                               → dossier reflects `destroyed`
                               → OR certificate `verklaring_van_vernietiging` (GET /api/archival/certificates)
                                    └─ decidiq renders it (Docudesk PDF, markdown fallback), retains permanently
Compliance dashboard = x-openregister-aggregations over OR's `retention.archiefactiedatum`/`.archiefstatus`
                       + manifest.d widgets (no reporting backend; OR's dashboard is spec-only)
```

The schema + declarative dialects ship as an ADR-037 register fragment `lib/Settings/register.d/44-records-management-archiving.json` (merged into the effective `decidesk_register.json` at load by `SettingsService`). The additive `securityClassification` property and the `archive` config on existing Minutes/Decision/Meeting/DigitalDocument schemas are edited directly in `lib/Settings/decidesk_register.json` (fragments merge whole schemas; property additions to existing schemas belong in the canonical file).

## Decisions

### Declarative-vs-imperative decision (ADR-031)

Default is declarative via `x-openregister-{lifecycle,aggregations,calculations,notifications,relations}` in the register JSON. After the consumption rewrite, exactly **one** imperative service remains — the three former services (TransferPackageService, ArchiveConnectorService, DestructionService) are gone because OR owns those concerns.

| Concern | Mode | Justification |
|---|---|---|
| Dossier state machine | **Declarative** `x-openregister-lifecycle` (canonical keys: `field`, `initial`, `states`, `terminal`, `transitions` — the exact dialect Decision already uses; never `initialState`/`default`, which OR silently ignores) | Pure guarded transition map |
| Compliance counters (dossiers per state, overdue transfers, unresolved retention, gaps, pending destructions) | **Declarative** `x-openregister-aggregations` over OR's `retention.*` (pattern: Meeting's Participant/ActionItem counters) | Count metrics with filters; no reporting backend |
| Dossier max-member-classification | **Declarative** `x-openregister-calculations` | Derivable from related fields |
| Transfer-deadline approaching | **Declarative** `x-openregister-notifications` (ADR-031 dialect; gate-18 forbids imperative dispatch) | Trigger-condition notification |
| Dossier ↔ members, dossier ↔ OR transfer/destruction list links | **Declarative** `x-openregister-relations` | UUID reference relations |
| Dashboard widgets, dossier index/detail pages | **Declarative** manifest v2 fragment `src/manifest.d/records-management.json` (ADR-037; schema refs by **slug**, e.g. `archival-dossier`, never PascalCase) | Rendering is manifest-driven |
| Retention resolution, MDTO, packaging, transfer, destruction, certificate | **Consumed from OpenRegister** | Already shipped upstream (ADR-022) — not decidiq's to declare or implement |
| **ArchivalDossierService** | Imperative | Cross-schema enumeration (minutes/decisions/votes/attachments per meeting), override-gated close, and the OR transfer/destruction-list hand-off exceed the declarative dialects; assembly is a staff-triggered composite write |

### Other key decisions

- **Verklaring = render OR's certificate, do not compose one.** OR's `DestructionService::generateCertificate()` already emits the legally-relevant record (approvers, counts, objectsBySchema, objectsBySelectielijst, complianceStatement citing Archiefwet/Archiefbesluit, `immutable: true`). Decidiq fetches it from `/api/archival/certificates` and renders it via the established minutes document pattern (Docudesk PDF opportunistic, markdown canonical fallback). *Alternative:* decidiq composes its own verklaring — rejected, it would be a second legal artefact contradicting OR's.
- **No decidiq MDTO mapping.** Populate `tmlo`/`retention`; OR's `MdtoXmlGenerator` serializes. *Alternative:* a decidiq mapping table — rejected, it duplicates OR's serializer and would drift from it.
- **Retention is OR's, addressed by schema config.** Decidiq declares `archive.classificatie` on archivable schemas and ships `SelectionList` seeds; OR resolves. Decidiq computes no dates. *Alternative:* a decidiq `RetentionRule` schema — rejected, it duplicates `SelectionList`.
- **Destruction is OR's, including separation of duties.** OR's `DestructionService` compares the first and second approver and pops an invalid second approval (L366-382) — decidiq MUST NOT add a parallel check or claim OR lacks one. Note also that OR's execution calls `DeleteObject::delete(..., permanent: true)` after a legal-hold re-check: it is a **permanent delete gated by OR's pre-flight**, not a "retention-aware soft delete" (`DeleteObject::deleteObject()` has no retention gate). Do not describe it as one.
- **Classification aligns to OR's ordinal.** Decidiq's four values are a documented strict subset of `VERTROUWELIJKHEIDAANDUIDING_LEVELS`, order preserved, with a mapping table. `beperkingGebruik` in MDTO is an OR follow-up — OR emits no such element and owns MDTO serialization.
- **Classification deny integration**: `PublicationEligibilityService` gains one structural check (classification > `openbaar` refused) — extending the existing deny-list rather than a new surface.

## API Design

Frontend CRUD goes through OR's object API via `useObjectStore` (no redundant pass-through controllers — hydra gate `redundant-controller`). Transfer and destruction are driven against **OR's** endpoints (`/api/transfers`, `/api/archival/destruction-lists/{id}/approve`, `/api/archival/certificates`), so decidiq adds only the two dossier actions OR cannot serve:

### `POST /api/dossiers/{id}/assemble` — (re)enumerate members for a forming dossier
### `POST /api/dossiers/{id}/close` — completeness check; body: `{ "overrideReason": "..." }` optional

Both `#[NoAdminRequired]` with per-object authority guards in the method body, registered in `appinfo/routes.php` (gates: route-auth, semantic-auth, route-reachability, no-admin-idor). **Deleted from the previous design:** `POST /api/transfer-packages`, `/api/transfer-packages/{id}/deliver`, `/api/transfer-packages/{id}/download`, `/api/destruction-lists/{id}/authorize`, `/api/destruction-lists/{id}/execute` — all OR's.

## Database Changes

None. `ArchivalDossier` is an OpenRegister object; retention lives in OR's existing persisted `retention` field. No Nextcloud migrations, no app tables.

## Nextcloud Integration

- Controllers: `ArchivalDossierController` (assemble, close)
- Services: `ArchivalDossierService`; `PublicationEligibilityService` (modified — classification deny)
- Background jobs: **none** — OR's `DestructionCheckJob` sweeps due dispositions and `TransferExecutionJob` runs transfers
- DI: OR services resolved through the existing OR service layer; registrations in `AppInfo\Application`
- Events/Hooks: none imperative — notifications are declarative (gate-18)

## Security Considerations

- **Destruction is not decidiq's to execute**: OR freezes the approved enumeration, enforces dual approval, re-checks legal holds before each delete, and emits the immutable certificate. Decidiq's only security-relevant contribution is the frozen dossier member list — a wrong UUID in a dossier is the realistic failure mode, so close freezes membership and assembly is audit-trailed.
- Classification labels gate the public-publication payload builder structurally (before eligibility).
- Dossier endpoints check records-management authority per object (no IDOR); dossier member enumeration exposes no data the caller cannot already read.
- No secrets in schemas (ADR-064) — e-depot/transport credentials live in OR's e-depot settings and OpenConnector, never decidiq.

## NL Design System

Standard NC components through the manifest renderer; nldesign CSS variables only (no hardcoded colors); classification badges use semantic tokens; WCAG 2.1 AA on dossier pages and dashboard widgets.

## File Structure

```
lib/
  Controller/ArchivalDossierController.php     (new — assemble, close)
  Service/ArchivalDossierService.php           (new — the only new service)
  Service/PublicationEligibilityService.php    (modified — classification deny)
  Settings/register.d/44-records-management-archiving.json  (new — 1 schema + dialects + seeds)
  Settings/decidesk_register.json              (modified — additive securityClassification + archive config)
  AppInfo/Application.php                      (modified — DI)
appinfo/routes.php                             (modified — 2 routes)
src/manifest.d/records-management.json         (new — pages, menu, dashboard widgets)
tests/Unit/Service/ArchivalDossierServiceTest.php (new)
tests/integration/decidiq-records-management.postman_collection.json (new)
```

**Deleted vs the pre-rewrite design:** `TransferPackageController`, `DestructionController`, `TransferPackageService`, `IArchiveConnectorService`, `ArchiveConnectorService`, `LogArchiveConnectorService`, `DestructionService`, `BackgroundJob/RetentionSweepJob.php`, `src/dialogs/DestructionAuthorizeDialog.vue` — every one of them duplicated an OpenRegister capability.

## Seed Data

Seeds ship as `x-openregister-seeds` inside the register fragment (convention: `43-process-config-v1.json`), realistic for a Dutch municipality and neutral enough for other governance domains. All cross-references use seed slugs; example UUIDs are the nil placeholder `00000000-0000-0000-0000-000000000000`.

**Selectielijst rules are seeded as OpenRegister `SelectionList` objects — not as a decidiq schema.** Fields follow OR's entity (`category`, `retentionYears`, `action`, `description`, `schemaOverrides`, `organisation`); decidiq's archivable schemas reference them through `archive.classificatie`, and OR resolves the rest.

### OpenRegister entity: `SelectionList` (seeded, editable)
| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| category | 2.1 | 3.1 | 19.1 | 11.1 |
| description | Raadsbesluiten en -verslagen | Verordeningen en beleidsregels | Ingetrokken voorstellen | Organisatie-interne verantwoording |
| action | bewaren | bewaren | vernietigen | vernietigen |
| retentionYears | — (permanent; overbrenging ≤ 10 jaar) | — | 5 | 10 |
| schemaOverrides | — | — | — | — |
| organisation | gm0344 | gm0344 | gm0344 | gm0344 |

### Schema: `archival-dossier` (the one new decidiq schema)
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| @self.slug | dossier-raad-2026-04-10 | dossier-verordening-parkeren-2025 | dossier-mt-overleg-2016-q1 |
| title | Raadsvergadering 10 april 2026 | Besluitroute Parkeerverordening 2025 | MT-overleg Q1 2016 |
| lifecycle | forming | closed | closed |
| governanceBody | Gemeenteraad Utrecht | Gemeenteraad Utrecht | Managementteam Dienstverlening |
| period | 2026-04-10 | 2025-02-01 – 2025-06-19 | 2016-01-01 – 2016-03-31 |
| members | minutes + 2 decisions + 2 voting rounds + 3 attachments | 1 decision (verordening) + amendments + votes | minutes + action list |
| securityClassification | openbaar | openbaar | intern |
| tmlo.archiefvormer | gm0344 (TOOI) | gm0344 (TOOI) | gm0344 (TOOI) |
| *OR-resolved* `retention.classificatie` | 2.1 | 3.1 | 11.1 |
| *OR-resolved* `retention.archiefnominatie` | bewaren | bewaren | vernietigen |
| *OR-resolved* `retention.archiefactiedatum` | — (bewaren) | — (bewaren) | 2026-03-31 (due) |

Rows marked *OR-resolved* are **not authored in the seed** — `RetentionService::applyArchivalMetadata()` writes them on save from the schema's `archive.classificatie` and the matching `SelectionList` row. They are shown here so the expected post-import state is verifiable.

**Related items per object:**
- Files: seed minutes/decision documents referenced as dossier members.
- Notes: the `forming` dossier carries a completeness note ("besluitenlijst nog niet vastgesteld").
- Tasks: none (action items are Deck-leaf domain).
- Contacts: none (MDTO actor fields carry TOOI org ids, not personal contacts).
- Transfer/destruction lists and certificates are **not seeded** — they are OR-owned objects created by OR's own services; seeding fakes of them would misrepresent OR state.

## Risks / Trade-offs

- [Duplicating OR] → the risk this rewrite closes: every OR-shipped concern is out of scope by name, with file citations in the table above (proposal Risk 2).
- [Wrongful destruction] → OR owns freeze, dual approval, legal-hold re-check, certificate; decidiq's exposure is the dossier member list, frozen on close (proposal Risk 1).
- [Wrong OR field targeted] → `retention`/`tmlo`, never `_retention`/`_tmlo`; verified against a live OR instance, not fakes (proposal Risk 3).
- [Declarative aggregation limits] → if a completeness counter proves inexpressible in the aggregation dialect, it degrades to a calculated field on the dossier — never a bespoke reporting backend.
- [Lifecycle dialect drift] → fragment uses the exact canonical keys Decision already uses; register-import verified on a clean instance (known fleet defect class: non-canonical keys are silently ignored).
- [OR follow-ups not landed] → `beperkingGebruik` and a first-class council-term trigger are OR's; decidiq ships without them rather than building local workarounds that would later have to be unwound.

## Migration Plan

Purely additive: new fragment + additive properties import via the existing register bootstrap; no data migration, no NC migration. Rollback = revert the PR and re-import; existing objects untouched. Transfers and destructions already executed by OR are OR-owned and legally irreversible by design.

## Open Questions

Carried from the proposal: `end-of-council-term` via OR's `eigenschap` afleidingswijze vs a first-class OR trigger (provisional: `eigenschap` + OR follow-up); `SelectionList` seeds editable per municipality (provisional: editable, consistent with the entity's `organisation` field).
