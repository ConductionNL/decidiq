# Design: governing-documents-register

## Architecture Overview

Thin-client per ADR-022: two new OpenRegister schemas (`governing-document`, `governing-document-versie`) live in the ADR-037 register fragment `lib/Settings/register.d/55-governing-documents-register.json` (number 55 is assigned to this change; 40–54 and 56–65 belong to siblings). The same fragment carries the additive optional `citesGoverningDocuments` property on the existing `decision` schema — the fragment-located additive pattern proven by decidesk-contract-decision-hub REQ-DCDH-001. The frontend queries OR directly through the shared object stores; decidesk's backend adds one narrow service plus a minimal controller surface:

- **GoverningDocumentConsolidationService** — pure read-side computation: "which GoverningDocumentVersie of document D is in force on date X" (REQ-GDR-004), plus the activation-ordering guard used before sealing a version. Exposed as a service method (callable by other capabilities — urgent-decision-procedure and vve-alv-pack resolve their references through it) and via one GET endpoint for the UI date control. Same pattern as verordeningenregister's `RegelingConsolidationService`.

Versioning model follows Akoma Ntoso FRBR: `GoverningDocument` is the work, `GoverningDocumentVersie` the expression — deliberately mirroring the verordeningenregister `RegelingVersie` conventions (versienummer, vastgesteldDoor, inwerkingtreding, vervaldatum, toelichting, seal-on-effective, correction-as-new-version) so staff learn one versioning model. Every amendment expression traces to the Decision that enacted it (besluit tot statutenwijziging, vaststellingsbesluit, ALV-besluit), reusing the existing Decision supertype and sitting naturally next to decision-evolution-and-cascade's relation links. The consolidated text is an attached document via OR's file abstraction (FileService), not text authored in decidesk.

**Boundary with verordeningenregister:** that sibling owns public-law regelingen subject to bekendmaking (CVDR identifiers, DROP/STOP-TPOD export via OpenConnector). This register owns the organisation's *own* private-law/internal constitutive documents and deliberately carries **no** CVDR field, no export package, no connector delivery. There is no `GoverningDocumentExportService` — that absence is the boundary.

## Decisions

### Declarative-vs-imperative decision (ADR-031)

Default is declarative via `x-openregister-{lifecycle,relations,notifications,calculations}` in the register fragment. Imperative code only for justified exceptions:

| Concern | Mode | Justification |
|---|---|---|
| GoverningDocument state machine (`geldend → vervallen`) and GoverningDocumentVersie state machine (`concept → vastgesteld → in-werking → vervangen \| vervallen`) | **Declarative** `x-openregister-lifecycle` (canonical keys `field`/`initial`/`states`/`terminal`/`transitions` — never `initialState`/`default`, which OR silently ignores) | Pure guarded transition maps |
| Version immutability once `in-werking` (REQ-GDR-003) | **Declarative** — lifecycle state + OR immutable-after-state enforcement on the sealed state; no app-side write interceptor | The seal is a state property — same posture as verordeningenregister REQ-VOR-003 |
| Document ↔ versions, versie ↔ enacting Decision, document ↔ GovernanceBody, decision ↔ cited documents | **Declarative** `x-openregister-relations` | UUID reference relations |
| New-effective-version notification (REQ-GDR-008) | **Declarative** `x-openregister-notifications` (ADR-031 dialect; gate-18 forbids imperative dispatch); verified keys only, recipients `kind:object-acl` + `kind:groups` | Trigger-condition notification on the version's lifecycle field |
| Current-version convenience fields on GoverningDocument (current versienummer, current inwerkingtreding) | **Declarative** `x-openregister-calculations` where derivable from related versions | Feeds the list page without N+1 |
| Public exposure of `isPublic` documents (REQ-GDR-007) | **Declarative** — `isPublic` predicate on the live object + OR published-predicate RBAC surface; never a modification of public-publication's eligibility-gates requirement | Predicate-on-live-object pattern (same posture as `isPublished` beside the lifecycle) |
| List/detail pages, version timeline, citing-decisions card | **Declarative** manifest fragment `src/manifest.d/governing-documents-register.json` (ADR-037; schema refs by **slug** — `governing-document`, `governing-document-versie` — never PascalCase) | Rendering is manifest-driven |
| **GoverningDocumentConsolidationService** | Imperative | Date-parameterised resolution over a version chain ("geldend op X") exceeds the declarative dialects: it is a query with an input, not a stored derivation; also hosts the activation-ordering guard and the conditional trace rule (amendment versions require `vastgesteldDoor`, constitutive first versions do not — a conditional requiredness JSON Schema alone cannot express across sibling objects) |

### Other key decisions

- **Corrections are new versions, never edits.** A rectification is legally a new decision producing a new expression; editing a sealed version would break the audit chain and every external immutable reference (REQ-GDR-005 consumers). *Alternative considered:* admin-only unseal — rejected, destroys the guarantee the sibling changes rely on.
- **`vastgesteldDoor` is conditionally required.** Amendment versions (a sealed version already exists) MUST trace to their enacting Decision; the constitutive first version MAY omit it because founding deeds (oprichtingsakte, splitsingsakte) predate decidesk and fabricating placeholder Decisions would poison decision analytics. Notarial metadata documents the origin instead. *Alternative:* always-required like RegelingVersie — rejected for this domain; municipalities enact regelingen through decisions, but statuten/splitsingsaktes are constituted by notarial deed.
- **Notarial-deed metadata is plain fields** (`aktedatum` date, `notaris` string). No KNB/notary-system integration, no deed-document validation — decidesk records what the organisation knows. `notaris` is stripped from any public payload (PII convention).
- **One canonical reference shape** `{document, versie?, artikel?}` for every consumer. Omitted `versie` means "resolve the version in force at reading time" via the consolidation service; a pinned `versie` gives the immutable-content guarantee. Kept deliberately simple (object ref + optional article string) so urgent-decision-procedure and vve-alv-pack can adopt it without coupling to this change's internals.
- **In-force ordering enforced at activation** (a new version's inwerkingtreding must be after the latest sealed one), so resolution is a deterministic max-≤-date scan — identical rule to verordeningenregister REQ-VOR-004.
- **No publication machinery.** `isPublic` exposure rides OR's published-predicate RBAC surface following public-publication's conventions; this change adds no publication payload builder beyond the derived public view and never modifies public-publication's eligibility-gates requirement (sibling-collision rule).

## API Design

### `GET /api/governing-documents/{id}/in-force?date=YYYY-MM-DD`
**Response:**
```json
{ "document": "<uuid>", "date": "2021-06-15", "version": { "id": "<uuid>", "versienummer": 1, "inwerkingtreding": "1990-05-01" } }
```
`version` is `null` when no version is in force. Reads via the consolidation service; anonymous-safe only for `isPublic` documents (published-predicate eligibility), authenticated members otherwise. Strict ISO-8601 validation on `date`.

All CRUD on documents/versions and the decision citation edits go directly to OR's object API from the frontend (no pass-through controllers, hydra-gate-redundant-controller).

## Database Changes

None. Decidesk owns no tables (ADR-022); all storage is OR objects plus OR-managed file attachments for consolidated texts.

## Nextcloud Integration

- Controllers: `GoverningDocumentController` (in-force resolution only) — explicit auth attributes on every method (route-auth gate); the in-force endpoint is `#[NoAdminRequired]` with a per-object read guard, additionally reachable anonymously only through the published-predicate surface for `isPublic` documents.
- Services: `GoverningDocumentConsolidationService` (registered via DI; no fallback pair needed — there is no external integration in this change).
- Mappers/Entities: none (thin client).
- Events/Hooks: none new; lifecycle, relations, and notifications are OR-declarative.

## Security Considerations

- Internal by default: documents and versions readable via OR RBAC member scopes (ADR-022/ADR-051; no app-local AuthorizationService).
- Public exposure is opt-in per document (`isPublic`), enforced server-side through OR published-predicate RBAC; `concept` versions, `toelichting`, and the `notaris` name are structurally excluded from the public payload (REQ-GDR-007) — covered by a negative test.
- Immutability of sealed versions enforced by OR lifecycle state, independent of UI (REQ-GDR-003) — no client-trusted checks.
- Per-object guards on every `#[NoAdminRequired]` controller method (no-admin-idor gate); input validation on the `date` query parameter.
- The citation field is assistive metadata: it grants no read access to the cited document (a member without access to an internal directiestatuut sees the citation label, not the content).

## NL Design System

Standard NC components via nc-vue; CSS variables only (no hardcoded colors); `NcSelect` filters with `inputLabel`; version timeline keyboard-navigable, WCAG 2.1 AA.

## File Structure

```
lib/
  Controller/GoverningDocumentController.php
  Service/GoverningDocumentConsolidationService.php
  Settings/register.d/55-governing-documents-register.json
src/
  manifest.d/governing-documents-register.json
  views/governingdocuments/ (list, detail incl. version timeline + citing decisions)
appinfo/routes.php (new entry)
tests/unit/Service/GoverningDocumentConsolidationServiceTest.php
```

## Seed Data

Seed objects ship in the `x-openregister.seedData` block of `55-governing-documents-register.json` (pattern: `43-process-config-v1.json`) and link to existing seed bodies from `decidesk_register.json` (`ledenraad-vng`, `gemeenteraad-amsterdam`). One new seed GovernanceBody is added for the VvE scenario, plus two seed Decisions (the enacting besluiten) so every amendment version's `vastgesteldDoor` resolves.

### Schema: `governance-body` (one addition)
| Field | Object 1 |
|-------|----------|
| slug | vve-parkstaete |
| name | VvE Parkstaete |
| bodyType | association |
| workflowTemplate | association |

### Schema: `governing-document`
| Field | Object 1 (statuten van een vereniging) | Object 2 (reglement van orde van een gemeenteraad) | Object 3 (VvE splitsingsakte) |
|-------|----------|----------|----------|
| slug | statuten-vng | reglement-van-orde-raad-amsterdam | splitsingsakte-vve-parkstaete |
| type | statuten | reglement-van-orde | splitsingsakte |
| citeertitel | Statuten Vereniging van Nederlandse Gemeenten | Reglement van orde gemeenteraad Amsterdam | Splitsingsakte VvE Parkstaete |
| governingBody | ledenraad-vng | gemeenteraad-amsterdam | vve-parkstaete |
| status | geldend | geldend | geldend |
| isPublic | true | false | false |

### Schema: `governing-document-versie`
| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | statuten-vng-v1 | statuten-vng-v2 | rvo-amsterdam-v1 | splitsingsakte-parkstaete-v1 |
| document | statuten-vng | statuten-vng | reglement-van-orde-raad-amsterdam | splitsingsakte-vve-parkstaete |
| versienummer | 1 | 2 | 1 | 1 |
| vastgesteldDoor | (empty — constitutive) | besluit-statutenwijziging-vng-2021 | besluit-vaststelling-rvo-amsterdam | (empty — constitutive) |
| inwerkingtreding | 1990-05-01 | 2022-01-01 | 2023-03-01 | 2005-06-01 |
| aktedatum | 1990-04-12 | 2021-12-15 | (empty) | 2005-05-20 |
| notaris | mr. C. van Dam | mr. A. de Wit | (empty) | mr. B. Jansen |
| status | vervangen | in-werking | in-werking | in-werking |

Two seed Decisions are added to the fragment's seed block: `besluit-statutenwijziging-vng-2021` (deciding body ledenraad-vng — the besluit tot statutenwijziging enacting statuten v2) and `besluit-vaststelling-rvo-amsterdam` (gemeenteraad-amsterdam). The statutenwijziging decision additionally carries a `citesGoverningDocuments` entry (`document: statuten-vng`, `artikel: "art. 20"` — the amendment clause) so the citation UI is demonstrable on install. The statuten chain v1(`vervangen`) → v2(`in-werking`) demonstrates in-force resolution and immutability; the two constitutive versions demonstrate the notarial-deed path without a decision link; `statuten-vng` with `isPublic=true` demonstrates the public predicate.

**Related items per object:**
- Files: consolidated-text placeholder PDF per sealed versie (attached via OR file abstraction)
- Notes: —
- Tasks: —
- Contacts: —

## Risks / Trade-offs

- [Domain overlap with verordeningenregister (`reglement`/`statuut-extern` Regeling types, its `huishoudelijk-reglement-vng` seed)] → boundary stated normatively (public-law + bekendmaking vs private-law/internal); the possible migration of that seed is a deferred question for the sibling, not silently changed here.
- [Immutability blocks a genuine correction] → correction is a new version traced to its own decision; sealed content never mutates.
- [Constitutive versions without a Decision weaken the trace] → conditional rule is precise and server-enforced; only the first version may omit the link, and notarial metadata/toelichting documents the origin.
- [Public predicate leaks internal data] → derived public view structurally excludes `concept` versions, `toelichting`, and `notaris`; server-side eligibility requires a sealed current version; negative tests.
- [OR immutable-after-state enforcement may not cover file-attachment replacement] → verify during implementation; if OR seals only object properties, add a spec-noted server-side guard on the consolidated-text reference (never client-trusted).

## Migration Plan

Fragment merges at register import (ADR-037) — additive only; the `decision` property is optional and nullable, so existing decisions stay valid. Deploy: ship fragment + service + pages, re-run register import. Rollback: remove fragment/service/route/pages and re-import; existing governing-document objects remain inert in OR.

## Open Questions

None blocking (deferred: migration of the verordeningenregister `huishoudelijk-reglement-vng` seed; whether the public view should also expose historical sealed versions; OR immutable-after-state coverage of file attachments).
