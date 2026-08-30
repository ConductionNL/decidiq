# Design: verordeningenregister

## Architecture Overview

Thin-client per ADR-022: three new OpenRegister schemas (`regeling`, `regeling-versie`, `regeling-export-package`) live in the ADR-037 register fragment `lib/Settings/register.d/53-verordeningenregister.json` (number 53 is assigned to this change; 40–52 and 54–65 belong to siblings). The frontend queries OR directly through the shared object stores; decidiq's backend adds only two narrow services plus a small controller surface:

- **RegelingConsolidationService** — pure read-side computation: "which RegelingVersie of Regeling R is in force on date X" (REQ-VOR-004), plus the activation-ordering guard used before sealing a version. Exposed as a service method (callable by other capabilities, e.g. commissievergaderingen's future REQ-CVG-013 wiring) and via one GET endpoint for the UI date picker.
- **RegelingExportService** (+ `LogRegelingExportService` fallback) — builds the STOP/TPOD export package (imperative document-generation exception) and delivers it through an OpenConnector Source resolved lazily by slug (external-integration exception), mirroring `TransferPackageService`/`ArchiveConnectorService` from records-management-archiving and the `EIDASSignatureService`/`LogEIDASSignatureService` pair.

Versioning model follows Akoma Ntoso FRBR: `Regeling` is the work, `RegelingVersie` the expression. Every expression traces to the Decision (wijzigingsbesluit) that enacted it — reusing the existing Decision supertype (decision-management) and sitting naturally next to decision-evolution-and-cascade's relation links. The consolidated text is an attached document via OR's file abstraction (as Meeting attachments already do), not text authored in decidiq.

The public page reuses public-publication's conventions: server-side eligibility (only `in-werking` regelingen with sealed current versions), exposure through OR's published-predicate RBAC surface, anonymous access to the derived listing and consolidated-text downloads.

## Decisions

### Declarative-vs-imperative decision (ADR-031)

Default is declarative via `x-openregister-{lifecycle,relations,notifications,calculations}` in the register fragment. Imperative services only for justified exceptions:

| Concern | Mode | Justification |
|---|---|---|
| Regeling state machine (`in-voorbereiding → vastgesteld → in-werking → vervallen`) and RegelingVersie state machine (`concept → vastgesteld → in-werking → vervangen \| vervallen`) | **Declarative** `x-openregister-lifecycle` (canonical keys `field`/`initial`/`states`/`terminal`/`transitions` — never `initialState`/`states`-only/`default`, which OR silently ignores) | Pure guarded transition maps |
| Version immutability once `in-werking` (REQ-VOR-003) | **Declarative** — lifecycle state + OR immutable-after-state enforcement on the sealed state; no app-side write interceptor | The seal is a state property, exactly the pattern REQ-CVG-013 expects to reference |
| Regeling ↔ versions, versie ↔ wijzigingsbesluit (Decision), regeling ↔ vaststellend orgaan (GovernanceBody), versie ↔ export package | **Declarative** `x-openregister-relations` | UUID reference relations |
| Inwerkingtreding-approaching-without-published-text warnings (14d / 3d, REQ-VOR-007) | **Declarative** `x-openregister-notifications` (ADR-031 dialect; gate-18 forbids imperative dispatch) | Trigger-condition notifications on object fields |
| Current-version convenience fields on Regeling (current versienummer, current inwerkingtreding) | **Declarative** `x-openregister-calculations` where derivable from related versions | Feeds list page without N+1 |
| Regelingen index/detail pages, public page section | **Declarative** manifest fragment `src/manifest.d/verordeningenregister.json` (ADR-037; schema refs by **slug** — `regeling`, `regeling-versie` — never PascalCase) | Rendering is manifest-driven |
| **RegelingConsolidationService** | Imperative | Date-parameterised resolution over a version chain ("geldend op X") exceeds the declarative dialects: it is a query with an input, not a stored derivation; also hosts the activation-ordering guard |
| **RegelingExportService** (+ Log fallback) | Imperative | Document-generation exception (STOP/TPOD XML build + structural validation) and external-integration exception (lazy OpenConnector Source lookup, delivery, ack + CVDR-id capture) |

### Other key decisions

- **Corrections are new versions, never edits.** A rectificatie is legally a new decision producing a new expression; modelling it as an edit would break both the audit chain and every external immutable reference. *Alternative considered:* admin-only unseal — rejected, destroys the REQ-CVG-013 guarantee.
- **In-force ordering enforced at activation** (a new version's inwerkingtreding must be after the latest sealed one), so resolution is a simple deterministic max-≤-date scan. *Alternative:* allow overlaps and resolve with tie-break rules — rejected, retroactive regulation is the rare exception and is handled as a rectification chain, not silent overlap.
- **Consolidated text = attached document** (PDF/ODT via OR file abstraction), not structured text. STOP/TPOD XML wraps metadata + the text artefact. *Alternative:* Akoma Ntoso structured authoring — out of scope (no drafting UI); the existing amendment-diff machinery stays for proposal texts.
- **decidiq never talks to DROP directly.** The export package is handed to an OpenConnector Source (`drop-bekendmakingen` slug convention, configurable); absence degrades to a downloadable package for manual DROP submission with a truthful UI notice. Mirrors records-management-archiving REQ-RMA-005 exactly.
- **CVDR identifier is stored, never minted** — captured on the Regeling after the national register assigns it (manually or from the delivery acknowledgement when the Source returns it).

## API Design

### `GET /api/regelingen/{id}/in-force?date=YYYY-MM-DD`
**Response:**
```json
{ "regeling": "<uuid>", "date": "2025-12-15", "version": { "id": "<uuid>", "versienummer": 2, "inwerkingtreding": "2025-06-01" } }
```
`version` is `null` when no version is in force. Reads via consolidation service; anonymous-safe for published regelingen.

### `POST /api/regeling-export-packages` — build; body `{ "versieIds": ["<uuid>"] }`
### `POST /api/regeling-export-packages/{id}/deliver` — deliver via OpenConnector; 409 when not `ready`
### `GET  /api/regeling-export-packages/{id}/download` — zip (STOP/TPOD XML + consolidated texts)
### `POST /api/regelingen/{id}/cvdr` — record the assigned CVDR identifier; body `{ "cvdrIdentifier": "CVDR..." }`

All CRUD on regelingen/versies goes directly to OR's object API from the frontend (no pass-through controllers, gate hydra-gate-redundant-controller).

## Database Changes

None. Decidiq owns no tables (ADR-022); all storage is OR objects plus OR-managed file attachments.

## Nextcloud Integration

- Controllers: `RegelingController` (in-force resolution, CVDR capture), `RegelingExportController` (build/deliver/download) — every method with explicit auth attributes (route-auth gate); public in-force endpoint `#[PublicPage]` behind published-predicate eligibility.
- Services: `RegelingConsolidationService`, `RegelingExportService`, `LogRegelingExportService` (registered as fallback when OpenConnector is absent, DI pattern of `LogEIDASSignatureService`).
- Mappers/Entities: none (thin client).
- Events/Hooks: none new; lifecycle and notifications are OR-declarative.

## Security Considerations

- Public page exposes only derived, eligibility-gated data via OR published-predicate RBAC; `concept` versions and internal annotations are structurally excluded from the payload (REQ-VOR-005), enforced server-side.
- Immutability of sealed versions enforced by OR lifecycle state, independent of UI (REQ-VOR-003) — no client-trusted checks.
- Export build/deliver/CVDR-capture endpoints require governance-body staff authority via OR RBAC scopes (ADR-051; no app-local AuthorizationService); per-object guards on every `#[NoAdminRequired]` method (no-admin-idor gate).
- Connector resolver must not fail open: absence of OpenConnector yields the Log fallback which refuses delivery honestly, never a silent skip (unsafe-auth-resolver family lesson).
- Input validation on `date` query parameter (strict ISO-8601) and CVDR identifier format.

## NL Design System

Standard NC components via nc-vue; CSS variables only (no hardcoded colors); `NcSelect` filters with `inputLabel`; version timeline keyboard-navigable, WCAG 2.1 AA. Public page uses the existing public-publication page chrome.

## File Structure

```
lib/
  Controller/RegelingController.php
  Controller/RegelingExportController.php
  Service/RegelingConsolidationService.php
  Service/RegelingExportService.php
  Service/LogRegelingExportService.php
  Settings/register.d/53-verordeningenregister.json
src/
  manifest.d/verordeningenregister.json
  views/regelingen/ (list, detail incl. version timeline, public register page)
appinfo/routes.php (new entries)
tests/unit/Service/RegelingConsolidationServiceTest.php
tests/unit/Service/RegelingExportServiceTest.php
```

## Seed Data

Seed objects ship in the `x-openregister.seedData` block of `53-verordeningenregister.json` (pattern: `43-process-config-v1.json`) and link to existing seed decisions/bodies from `decidesk_register.json` (e.g. governance body `gemeenteraad-amsterdam`).

### Schema: `regeling`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | afvalstoffenverordening-amsterdam | beleidsregel-terrassen-amsterdam | huishoudelijk-reglement-vng |
| type | verordening | beleidsregel | reglement |
| citeertitel | Afvalstoffenverordening Amsterdam | Beleidsregel terrassen Amsterdam 2025 | Huishoudelijk reglement ledenraad VNG |
| wettelijkeGrondslag | ["Gemeentewet art. 149", "Wet milieubeheer art. 10.23"] | ["APV Amsterdam art. 3.17"] | ["Statuten VNG art. 12"] |
| vaststellendOrgaan | gemeenteraad-amsterdam | gemeenteraad-amsterdam | ledenraad-vng |
| status | in-werking | in-werking | vastgesteld |
| cvdrIdentifier | CVDR641871 | (empty) | (empty) |

### Schema: `regeling-versie`
| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | afvalstoffen-v1 | afvalstoffen-v2 | terrassen-v1 | hr-vng-v1 |
| regeling | afvalstoffenverordening-amsterdam | afvalstoffenverordening-amsterdam | beleidsregel-terrassen-amsterdam | huishoudelijk-reglement-vng |
| versienummer | 1 | 2 | 1 | 1 |
| vastgesteldDoor | (seed decision: vaststelling afvalstoffenverordening) | (seed decision: wijzigingsbesluit afval 2025) | (seed decision: vaststelling beleidsregel terrassen) | (seed decision: vaststelling HR VNG) |
| inwerkingtreding | 2024-01-01 | 2025-06-01 | 2025-03-01 | 2026-09-01 |
| status | vervangen | in-werking | in-werking | concept |

Two additional seed decisions (the wijzigingsbesluiten) are added to the fragment's seed block so every version's `vastgesteldDoor` resolves. One `regeling-export-package` seed in state `ready` demonstrates the honest-degradation download path.

**Related items per object:**
- Files: consolidated-text placeholder PDF per sealed versie (attached via OR file abstraction)
- Notes: —
- Tasks: —
- Contacts: —

## Risks / Trade-offs

- [STOP/TPOD package rejected by DROP] → structural validation before `ready`, stored errors, retryable delivery, manual-download fallback; DROP remains the authoritative acceptance check.
- [Immutability blocks a genuine correction] → rectification is a new version traced to its own decision; sealed content never mutates.
- [In-force ambiguity] → activation-ordering guard makes resolution a deterministic scan; retroactivity handled as rectification chains.
- [Public page leaks a draft] → structural exclusion of `concept` versions in the payload builder + published-predicate RBAC; covered by a negative test.

## Migration Plan

Fragment merges at register import (ADR-037) — additive only, no existing schema touched. Deploy: ship fragment + services + pages, re-run register import. Rollback: remove fragment/services/routes/pages and re-import; existing regeling objects remain inert in OR.

## Open Questions

None blocking (deferred: CVDR-id auto-capture from Source acknowledgement payload shape; provincial/waterschap CVDR variants).
