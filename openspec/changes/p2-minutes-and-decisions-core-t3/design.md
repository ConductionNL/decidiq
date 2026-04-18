## Context

The `p2-minutes-and-decisions` change established foundational CRUD operations and data schemas for Minutes and Decision entities. Minutes and Decisions exist as data records, but governance bodies require operational workflows around them: decisions must be captured live during meetings, approved through formal governance procedures, published for public accountability (ORI standard), and converted into actionable follow-ups.

Core T3 builds these operational workflows on top of the existing data foundation. The change is constrained by four prior ADRs:

- **CalDAV-first storage** (ADR-002): ActionItems are VTODOs in Nextcloud Tasks — NOT OpenRegister objects. No sync layer.
- **Popolo primary standard** (ADR-001): No separate Decision entity. Decisions are outcomes of Motions (`lifecycle: adopted` + `decisionText`, `decisionDate`, `isPublished`, `publishedAt`, `legalBasis` fields on the Motion object).
- **ORI compatibility** (ADR-003): Dutch municipalities expect Minutes (as Reports) and decisions via `/api/ori/v1/reports` and `/api/ori/v1/motions`.
- **OpenRegister platform** (company ADR): Workflow engine, notification, search, audit, and export capabilities are provided and MUST be reused.

**Current state:** Minutes and Motion schemas defined in `lib/Settings/decidesk_register.json`. No approval workflow, no ORI publication, no real-time capture, no action item extraction. Schema fields `lifecycle`, `signedBy`, `version` on Minutes and `decisionText`, `decisionDate`, `isPublished`, `publishedAt`, `legalBasis` on Motion are present in ADR-000 but not yet operationally wired.

**Stakeholders affected:**
- Council clerks and board secretaries — authoring and approving minutes
- Governance body chairs and secretaries — digital signature for minutes approval
- Citizens and institutional investors — public decision access via ORI API
- IT administrators — ORI endpoint configuration
- Management teams and corporate boards — action item tracking from meeting decisions

**Depends on:** `p2-meeting-management` (Meeting/CalDAV wrapper), `p2-motion-and-voting` (Motion, VotingRound), `p2-agenda-management` (AgendaItem).

---

## Goals / Non-Goals

**Goals:**
- Real-time Minutes and Decision capture during active meetings with debounced auto-save and optimistic locking
- Formal Minutes approval lifecycle (draft → submitted → approved → published) using platform workflow engine with role-based guards
- Digital signature recording for chair and secretary via `AuditTrailService` (legal acknowledgement trail)
- Automatic ActionItem extraction from approved Minutes content using configurable regex/keyword patterns — creates CalDAV VTODOs via `CalDavService`
- ORI-compatible `/api/ori/v1/reports` and `/api/ori/v1/motions` endpoints exposing published Minutes and adopted Motions (decision outcome fields)
- Decision discovery: full-text search, faceted filtering, and CSV/JSON/PDF export across governance bodies
- Foundation for decision notifications: event emission on state transitions, per-user/body notification preferences
- Seed data for Minutes and Motion (with decision outcome) entities in `lib/Settings/decidesk_register.json` to support automated testing and QA

**Non-Goals:**
- Full ORI national harvesting protocol push-integration with Open State Foundation crawler (separate project)
- AI/ML-based minutes summarization or automated decision text generation (non-deterministic; out of scope)
- Video/audio recording integration or AV-RIS indexing (separate project)
- Speech entity implementation (deferred to later phase per ADR-000)
- PKI/eIDAS qualified electronic signatures (deferred; current approach uses audit trail acknowledgement)
- Multi-language minutes content (English via i18n keys for UI; Minutes content text is user-authored)

---

## Decisions

### 1. ActionItem storage: CalDAV VTODO (not OpenRegister)

**Decision:** ActionItems created by action item automation are stored as CalDAV VTODOs in Nextcloud Tasks with `X-DECIDESK-MOTION-UID` and `X-DECIDESK-MEETING-UID` extended properties. There is no OpenRegister schema for ActionItem.

**Rationale:** ADR-002 mandates CalDAV-first storage for meetings and action items. ActionItems as VTODOs appear natively in the Nextcloud Tasks app, sync to any CalDAV client, and require zero integration code. Creating a parallel OpenRegister schema would reintroduce the sync layer that ADR-002 explicitly eliminates.

**Consequence for seed data:** ActionItem seed objects in `decidesk_register.json` are NOT applicable. ActionItems are CalDAV objects, not OpenRegister objects. Seed data is limited to Minutes and Motion entities.

**Alternative considered:** Store ActionItems in OpenRegister for richer relational queries. Rejected: contradicts ADR-002.

---

### 2. Decision as Motion outcome (no separate Decision entity)

**Decision:** Published decisions are represented by Motion objects with `lifecycle: adopted`, `isPublished: true`, and populated `decisionText`, `decisionDate`, `publishedAt`, `legalBasis` fields. The deprecated `Decision` entity (ADR-000) is not re-introduced.

**Rationale:** ADR-000 explicitly deprecates the Decision entity: "Decision is now the outcome of a Motion. This follows the Popolo standard which has no separate Decision class." Creating a Decision entity would duplicate data, violate ADR-001 (Popolo), and require a synchronization concern.

**ORI mapping:** The ORI endpoint `/api/ori/v1/motions` serves adopted Motions as ORI Motion objects with decision fields. The endpoint `/api/ori/v1/reports` serves published Minutes as ORI Report objects. Neither requires a new entity.

**Alternative considered:** Separate Decision entity linked to Motion via relation. Rejected: violates ADR-000 and ADR-001.

---

### 3. Minutes approval via platform workflow engine

**Decision:** Minutes lifecycle transitions (draft → submitted → approved → published) are implemented using OpenRegister's `WorkflowEngineController` / `WorkflowEngineRegistry`. Role-based transition guards enforce that only authorized users (chair, secretary, governance body authority) can advance each state.

**Rationale:** Per company ADR: "Task & Workflow Management: use TasksController + WorkflowEngineController. NO custom task/workflow systems." A custom PHP state machine would duplicate platform capability and introduce inconsistency.

**Workflow definition:** Stored as OpenRegister metadata on the Minutes schema. Each governance domain (legislative, association, corporate, operational, citizen) MAY configure a domain-specific workflow template via the governance body's `workflowTemplate` field.

**Alternative considered:** Custom PHP service with explicit state transition methods. Rejected: duplicates platform capability.

---

### 4. Digital signatures via audit trail acknowledgement

**Decision:** Digital signatures (chair + secretary) for Minutes approval are recorded by:
1. The `signedBy` array on the Minutes object storing the UIDs of authorized signers.
2. The OpenRegister `AuditTrailService` automatically capturing who made each lifecycle transition and when.

Together these provide a legally defensible record of who acknowledged the Minutes and when, without requiring PKI infrastructure.

**Rationale:** OpenRegister's audit trail is automatic and tamper-evident. The `signedBy` array captures intended signers; the audit trail captures actual actors. This satisfies Dutch governance requirements for meeting minutes authentication (Gemeentewet art. 23) at the "acknowledgement" level of assurance.

**Limitation (documented in UI):** This is a digital acknowledgement trail, not an eIDAS qualified electronic signature. Governance bodies requiring Level of Assurance "High" (e.g. notarial acts) must use a separate PKI integration (deferred).

**Alternative considered:** Integrate with DigiD or PKIoverheid for qualified signatures. Deferred to a future phase.

---

### 5. Action item extraction: pattern-based backend service

**Decision:** `ActionItemExtractor` PHP service parses the Minutes `content` field using configurable regex/keyword patterns stored in governance body settings, then creates CalDAV VTODOs via `CalDavService` with `X-DECIDESK-MOTION-UID` and `X-DECIDESK-MEETING-UID` set where applicable.

**Pattern format:** JSON array of named patterns per governance body, stored via `IAppConfig`. Example: `[{ "name": "actiepunt", "pattern": "ACTIEPUNT:\\s*(.+?)(?=ACTIEPUNT|BESLUIT|$)" }]`.

**Trigger:** Extraction runs automatically when Minutes transition to `approved` lifecycle state. Results are created as VTODOs in a "DecideDesk — Actiepunten" CalDAV calendar.

**Rationale:** Deterministic pattern matching is auditable, configurable, and has no external dependencies. Municipal meeting minutes in the Netherlands follow predictable patterns (ACTIEPUNT, BESLUIT, "zal worden uitgevoerd door").

**Alternative considered:** AI/ML extraction via `ChatService`. Deferred: non-deterministic, requires LLM availability, out of scope for Core T3.

---

### 6. ORI endpoint as read-only serialization layer

**Decision:** `/api/ori/v1/reports` is a public, read-only endpoint serializing Minutes objects with `lifecycle: published` to ORI Report format. `/api/ori/v1/motions` exposes adopted Motions with decision fields. A dedicated `OriSerializer` PHP service handles all field mapping. No ORI data is stored separately.

**Rationale:** ADR-003 specifies "the endpoint is a thin read-only serialization layer, not a separate data store." Internal storage is Popolo-aligned; ORI is the Dutch municipal output format. `OriSerializer` is isolated, enabling ORI standard evolution without touching the data model.

**Public access:** Per ADR-002 and `#[PublicPage] #[NoCSRFRequired]` annotation pattern. Published Minutes and adopted Motions are public governance records.

**Alternative considered:** Store data in ORI format natively. Rejected: ORI is Dutch-specific; international users would be burdened.

---

## Reuse Analysis

The following OpenRegister and platform services are leveraged (per ADR-012):

| Service | Usage in this change |
|---|---|
| `ObjectService` | CRUD for Minutes, Motion (decision fields); auto-save endpoint |
| `WorkflowEngineController` / `WorkflowEngineRegistry` | Minutes lifecycle state machine (draft → published) |
| `AuditTrailService` | Digital signature audit proof; change tracking on Minutes approval |
| `NotificationService` | Decision publication notifications (Nextcloud notification API) |
| `IndexService` + `CnFacetSidebar` | Decision full-text search and faceted filtering |
| `SearchTrailService` | Decision search analytics (popular terms) |
| `ExportService` + `CnMassExportDialog` | CSV/JSON/PDF export of decision lists |
| `CnObjectSidebar` → `CnAuditTrailTab` | Minutes revision history and audit UI |
| `CnTimelineStages` | Minutes lifecycle workflow progression visualization |
| `CnFormDialog` / `CnAdvancedFormDialog` | Minutes editing form, decision capture fields |
| `createObjectStore` with `auditTrails`, `files`, `lifecycle` plugins | Pinia stores for Minutes and Motion entities |
| `CalDavService` | ActionItem VTODO creation with X-DECIDESK-* extended properties |
| `CnIndexPage` + `useListView` | Decision discovery list view with search and filter |
| `CnDetailPage` + `useDetailView` | Minutes detail view with approval workflow |

**No new platform services are required.** All capabilities map to existing platform functionality.

**Deduplication findings:** No overlap with existing Decidesk capabilities (`p2-meeting-management`, `p2-motion-and-voting`, `p2-agenda-management`). The ORI endpoint paths defined in ADR-003 (`/api/ori/v1/reports`, `/api/ori/v1/motions`) are not yet implemented by any prior change.

---

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Concurrent real-time editing of Minutes by multiple clerks | `ObjectService.lockObject()` before editing session; debounced auto-save (500ms) with last-write-wins; display "locked by [user]" notice to secondary editors |
| CalDAV VTODO links break if parent Motion is deleted | Soft-delete Motions: lifecycle transitions to `withdrawn` rather than physical deletion; warn clerk before deletion if `X-DECIDESK-MOTION-UID` VTODOs reference the Motion |
| ORI field mapping drift as ORI standard evolves | `OriSerializer` is an isolated class with inline ORI spec version comment; field mappings documented as constants |
| `signedBy` audit trail does not constitute eIDAS qualified signature | Explicitly documented in approval UI tooltip and docs: "digitale akkoordverklaring — geen gekwalificeerde elektronische handtekening" |
| Extraction pattern false positives creating spurious ActionItems | Preview mode: show extracted items before creating VTODOs; allow manual deletion after extraction; configurable per-body patterns |
| Large Minutes content (multi-hour sessions, 20+ agenda items) | OpenRegister stores content as JSON string with no practical field length limit; validate in QA with large seed content |
| ORI endpoint performance under high load (all published Minutes) | Pagination (`_page` + `_limit`) mandatory; indexed `lifecycle` and `isPublished` fields via OpenRegister; no N+1 queries in serializer |

---

## Migration Plan

### Steps

1. **Schema update (non-breaking add):** Add optional fields `lifecycle` (default: `draft`), `signedBy` (default: `[]`), `version` (default: `1`) to Minutes schema in `decidesk_register.json`. Add `isPublished` (default: `false`), `publishedAt`, `legalBasis`, `decisionText`, `decisionDate` to Motion schema. All new fields are optional — adding optional properties is non-breaking per ADR.

2. **Repair step:** Add `IRepairStep` implementation `MigrateMinutesDecisionFieldsRepairStep` that:
   - Finds all existing Minutes objects without `lifecycle` → sets `lifecycle: draft`, `version: 1`
   - Finds all existing Motion objects with `lifecycle: adopted` and no `isPublished` field → sets `isPublished: false`
   - Uses `ObjectService::findObjects()` with `_rbac: false` and `_multitenancy: false` for idempotent migration

3. **Schema import update:** Bump version in `decidesk_register.json` to trigger re-import via `ConfigurationService::importFromApp()` idempotency logic.

4. **CalDAV calendar provisioning:** On app activation, ensure a "DecideDesk — Actiepunten" CalDAV calendar exists per governance body via `CalDavService`.

### Rollback

New optional fields are forward-compatible. Rollback: revert schema definitions; existing objects retain unknown fields but platform ignores them (schema-tolerant). The repair step is idempotent — safe to re-run.

---

## Open Questions

1. **ORI harvesting push protocol:** Should `/api/ori/v1/reports` support the ORI webhook push-harvest protocol used by the Open State Foundation crawler? Currently scoped as passive pull-only — confirm with stakeholders before shipping.

2. **Qualified e-signatures:** Which governance domains require eIDAS Level of Assurance "High" for minutes signatures? Water boards and provincial states have stricter authentication requirements. If any domain requires qualified signatures, a PKI integration change must be scoped separately.

3. **ActionItem extraction: English-language patterns:** Municipal minutes are Dutch, but corporate board minutes may be in English. Should extraction patterns support per-body language configuration? Currently assumed Dutch-language only.

4. **Minutes content storage:** Should large Minutes content (>100KB) be stored as a file attachment via `FileService` rather than inline in the OpenRegister object's `content` field? No known limit hit yet, but architectural preference should be confirmed.

---

## Seed Data

All seed objects follow the `@self` envelope format for `lib/Settings/decidesk_register.json` under `components.objects[]`. Objects are loaded via `ConfigurationService::importFromApp()` on install and are idempotent (matched by `slug`).

**Note:** ActionItem seed data is NOT included — ActionItems are CalDAV VTODOs (ADR-002), not OpenRegister objects. There is no ActionItem schema in `decidesk_register.json`.

---

### Minutes — 5 seed objects

**1. Gepubliceerde raadsnotulen — gemeente Westerbork**
```json
{
  "@self": {
    "register": "decidesk",
    "schema": "minutes",
    "slug": "minutes-westerbork-raad-20260115"
  },
  "title": "Notulen raadsvergadering gemeente Westerbork — 15 januari 2026",
  "lifecycle": "published",
  "content": "De vergadering wordt geopend om 19:30 door voorzitter dhr. J. Hendriks. Aanwezig: 17 raadsleden, quorum aanwezig. Agendapunt 3: Bestemmingsplan Centrum 2026. Na uitgebreide beraadslaging wordt stemming uitgesteld tot 20 januari conform SO-motie van VVD-fractie. BESLUIT d.d. 20-01-2026: De raad stelt het bestemmingsplan Centrum Westerbork 2026 vast met 14 stemmen voor en 3 stemmen tegen. ACTIEPUNT: griffier publiceert besluit uiterlijk 22 januari 2026 op gemeentewebsite. Rondvraag: geen. Sluiting 21:45 door voorzitter.",
  "approvedAt": "2026-01-22T10:15:00Z",
  "signedBy": ["uid-hendriks-voorzitter", "uid-smit-griffier"],
  "version": 3
}
```

**2. Goedgekeurde commissienotulen — gemeente Emmen**
```json
{
  "@self": {
    "register": "decidesk",
    "schema": "minutes",
    "slug": "minutes-emmen-commissie-ruimte-20260222"
  },
  "title": "Notulen commissievergadering Ruimte & Wonen — gemeente Emmen — 22 februari 2026",
  "lifecycle": "approved",
  "content": "Opening om 14:00 door commissievoorzitter mevr. A. Veldhuis. Afwezig met kennisgeving: dhr. T. de Graaf (GroenLinks). Agendapunt 2: Conceptomgevingsvisie Emmen 2035. Inspreker: mevr. P. Koster (bewonersgroep Bargeres). Commissie is positief over de ingezette koers maar verzoekt aanvulling op paragraaf 4.2 (mobiliteit). ACTIEPUNT: dhr. G. de Boer (PvdA) brengt schriftelijke inbreng in vóór 7 maart 2026. Sluiting 16:30.",
  "approvedAt": "2026-02-28T09:30:00Z",
  "signedBy": ["uid-veldhuis-voorzitter"],
  "version": 1
}
```

**3. Ter beoordeling — Waterschap Vechtstromen**
```json
{
  "@self": {
    "register": "decidesk",
    "schema": "minutes",
    "slug": "minutes-vechtstromen-ab-20260308"
  },
  "title": "Notulen Algemeen Bestuur Waterschap Vechtstromen — 8 maart 2026",
  "lifecycle": "submitted",
  "content": "Vergadering geopend door dijkgraaf drs. R. van Loon om 10:00. Aanwezig: 30 van 30 leden. Agendapunt 5: Uitvoeringsprogramma dijkversterking Vecht 2026-2028. Technische toelichting door hoofd Waterveiligheid. Financiering: totaalbudget €4.200.000 ten laste van investeringsreserve. BESLUIT: het AB stemt in met het uitvoeringsprogramma dijkversterking Vecht 2026-2028 (unaniem). ACTIEPUNT: programmamanager Wilbrink stelt contracten aan voor aannemersselectie, deadline 1 mei 2026. Sluiting 12:45.",
  "version": 2
}
```

**4. Conceptnotulen ALV — VvE De Lindeboom**
```json
{
  "@self": {
    "register": "decidesk",
    "schema": "minutes",
    "slug": "minutes-vve-lindeboom-alv-20260419"
  },
  "title": "Conceptnotulen ALV VvE De Lindeboom — 19 april 2026",
  "lifecycle": "draft",
  "content": "CONCEPT — ter vaststelling op volgende vergadering. Aanwezig: 28 van 44 eigenaren (quorum aanwezig). Voorzitter: dhr. K. Jansen (Bredestraat 12). Agendapunt 3: Onderhoudsfonds 2026. Besloten: bijdrage verhogen van €45 naar €60 per maand per appartement (meerderheid 21-7). ACTIEPUNT: penningmeester informeert hypotheekverstrekkers uiterlijk 1 juni. Rondvraag: klacht over rookoverlast trappenhuis — voorzitter bespreekt intern met beheerder. Sluiting 21:15.",
  "version": 1
}
```

**5. Conceptnotulen MT-vergadering — gemeente Assen**
```json
{
  "@self": {
    "register": "decidesk",
    "schema": "minutes",
    "slug": "minutes-assen-mt-20260503"
  },
  "title": "Notulen MT-vergadering gemeente Assen — 3 mei 2026",
  "lifecycle": "draft",
  "content": "Aanwezig: gemeentesecretaris T. Brouwer, directeur Sociale Zaken mevr. L. Haisma, directeur Ruimte dhr. F. van Dam, directeur Bedrijfsvoering mevr. C. Postma. Agendapunt 2: IT-vervanging kernsystemen. Businesscase nog onvoldoende onderbouwd. BESLUIT: besluit uitgesteld tot MT-vergadering 17 mei 2026. ACTIEPUNT: dhr. Van Dam levert herziene businesscase aan vóór 14 mei. Agendapunt 3: Reorganisatie afdeling HR. ACTIEPUNT: mevr. Postma stuurt conceptplan vóór 17 mei ter bespreking. Sluiting 14:30.",
  "version": 1
}
```

---

### Motion (with decision outcome fields) — 4 seed objects

**1. Aangenomen bestemmingsplanmotion — gemeente Westerbork**
```json
{
  "@self": {
    "register": "decidesk",
    "schema": "motion",
    "slug": "motion-westerbork-bestemmingsplan-centrum-2026"
  },
  "title": "Vaststelling bestemmingsplan Centrum Westerbork 2026",
  "text": "De raad van de gemeente Westerbork stelt het bestemmingsplan Centrum Westerbork 2026 vast, zoals opgenomen in het raadsvoorstel d.d. 5 januari 2026 met bijbehorende plankaart (planidentificatie NL.IMRO.1952.BPCentrum2026-VA01).",
  "motionType": "motion",
  "proposer": "College van B&W",
  "lifecycle": "adopted",
  "submittedAt": "2026-01-05T00:00:00Z",
  "requirement": "simple-majority",
  "decisionText": "De raad stelt het bestemmingsplan Centrum Westerbork 2026 vast conform planidentificatie NL.IMRO.1952.BPCentrum2026-VA01.",
  "decisionDate": "2026-01-20T20:15:00Z",
  "isPublished": true,
  "publishedAt": "2026-01-22T10:30:00Z",
  "legalBasis": "Wet ruimtelijke ordening artikel 3.1"
}
```

**2. Aangenomen begrotingswijziging — Waterschap Vechtstromen**
```json
{
  "@self": {
    "register": "decidesk",
    "schema": "motion",
    "slug": "motion-vechtstromen-dijkversterking-2026"
  },
  "title": "Uitvoeringsprogramma dijkversterking Vecht 2026-2028",
  "text": "Het Algemeen Bestuur stemt in met het uitvoeringsprogramma dijkversterking Vecht 2026-2028 en machtigt het Dagelijks Bestuur tot het aangaan van verplichtingen tot een maximum van €4.200.000 ten laste van de investeringsreserve waterveiligheid.",
  "motionType": "motion",
  "proposer": "Dagelijks Bestuur",
  "lifecycle": "adopted",
  "submittedAt": "2026-02-15T00:00:00Z",
  "requirement": "simple-majority",
  "decisionText": "Het AB stelt het uitvoeringsprogramma dijkversterking Vecht 2026-2028 vast en machtigt het DB tot besteding van €4.200.000.",
  "decisionDate": "2026-03-08T11:30:00Z",
  "isPublished": true,
  "publishedAt": "2026-03-10T09:00:00Z",
  "legalBasis": "Waterschapswet artikel 77"
}
```

**3. Verworpen motie sociale woningbouw — gemeente Emmen**
```json
{
  "@self": {
    "register": "decidesk",
    "schema": "motion",
    "slug": "motion-emmen-sociale-woningbouw-2026"
  },
  "title": "Motie: versnelling sociale woningbouw Emmerhout",
  "text": "De raad verzoekt het college van B&W om in de Omgevingsvisie Emmen 2035 een aparte paragraaf op te nemen over de versnelling van sociale woningbouw in de wijk Emmerhout, met concrete doelstellingen voor 2028.",
  "motionType": "motion",
  "proposer": "PvdA-fractie",
  "coSigners": ["SP-fractie", "GroenLinks-fractie"],
  "lifecycle": "rejected",
  "submittedAt": "2026-02-22T14:00:00Z",
  "requirement": "simple-majority",
  "isPublished": false
}
```

**4. Aangenomen jaarplan VvE — VvE De Lindeboom**
```json
{
  "@self": {
    "register": "decidesk",
    "schema": "motion",
    "slug": "motion-vve-lindeboom-onderhoudsfonds-2026"
  },
  "title": "Verhoging onderhoudsbijdrage VvE De Lindeboom 2026",
  "text": "De ALV besluit de maandelijkse bijdrage aan het onderhoudsfonds te verhogen van €45 naar €60 per appartement per maand met ingang van 1 juli 2026, conform het meerjarenonderhoudsplan opgesteld door Vastgoedbeheer Havinga B.V.",
  "motionType": "motion",
  "proposer": "Bestuur VvE De Lindeboom",
  "lifecycle": "adopted",
  "submittedAt": "2026-04-01T00:00:00Z",
  "requirement": "simple-majority",
  "decisionText": "De bijdrage aan het onderhoudsfonds wordt verhoogd van €45 naar €60 per appartement per maand met ingang van 1 juli 2026.",
  "decisionDate": "2026-04-19T20:45:00Z",
  "isPublished": false
}
```
