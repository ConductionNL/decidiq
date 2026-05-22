# Design — NOTUBIZ en iBabs Griffie-Koppeling (Bidirectional Sync)

## Context

The decidesk platform aims to disintermediate municipal political decision-making by exposing vendor-controlled data (NOTUBIZ/iBabs) through an open data model. The sync layer bridges proprietary systems and open standards without requiring immediate vendor exit — incremental migration over 2–5 years.

**Current state:**

- Decidesk schemas (`Meeting`, `AgendaItem`, `Person`, `Motion`, `Vote`, `Decision`, etc.) are defined in ADR-000 and map to Popolo standard.
- No external sync layer exists today.
- OpenRegister provides `ObjectService`, `RelationService`, `AuditTrailService`, file attachment, schema management, and webhook support.
- OpenConnector provides app hosting, cron scheduling, background job runner, and webhook receiver framework.

**Stakeholders:**

- **Decidesk team** — owns the canonical data model; sync populates it.
- **OpenConnector team** — hosts the adapters; owns sync orchestration, job runner, webhook dispatch.
- **OpenRegister team** — provides platform services (ObjectService, audit trails, permissions).
- **Griffies** (primary users) — stay in NOTUBIZ/iBabs for official workflows; get real-time open-data mirror.
- **Raadsleden** — use decidesk for better UX; changes in decidesk don't disrupt official workflow.

## Goals / Non-Goals

**Goals:**

1. Ship full bidirectional sync: pull all governance objects from NOTUBIZ/iBabs every 15 min (cron) or on webhook (iBabs); push decidesk changes to provider for writable fields.
2. Detect and surface conflicts when same field edited locally + externally since last sync, requiring manual resolution.
3. Sync per-fraction role data (fractievoorzitter, portefeuilles, woordvoerderschappen) with succession handling.
4. Sync voting with full per-person breakdown (hoofdelijke stemming, fractie-stemming).
5. Stream large documents (50MB+) to object storage, not memory.
6. Provide observability: SyncJob dashboard, open-conflict queue, alert thresholds, per-provider health.

**Non-Goals:**

- Automatic conflict merge (too risky for legal documents). Manual resolution required.
- Companion (LIAS) adapter — small market, defer until concrete demand.
- Bulk STOP/TPOD XML export for official publications (separate docudesk spec).
- Historical backfill beyond 2 years (performance validation first).
- NLP-based privacy content detection (separate initiative).
- Province/waterboard schema extensions (separate per-org-type validation).

## Decisions

### D1: Polymorph ExternalIdentifier over per-type join tables

**Choice:** Single `ExternalIdentifier` register with `localObjectType` enum + `localObjectId` UUID, plus `provider`, `externalId`, `externalUrl`, `externalEtag`.

**Alternatives considered:**

- Per-type join tables (ExternalMeetingId, ExternalPersonId, etc.) — matches traditional SQL join pattern but becomes unmaintainable with 12+ decidesk object types.
- Inline vendor fields directly in decidesk schemas (e.g., `meeting.notubizId`, `meeting.ibabsId`) — tight coupling, hard to add third provider.

**Why polymorph:** Single index structure `(localObjectType, localObjectId, provider)` covers all 12 types; unique constraint `(provider, externalId)` prevents duplicate imports; clean separation of concern (sync metadata lives in sync register, not domain schemas).

### D2: Last-writer-wins with explicit conflict detection, no automatic merge

**Choice:** Detect simultaneous edits via `lastModifiedLocal` + `lastModifiedExternal` timestamps. On conflict, create `SyncConflict` record; human (griffier) resolves via UI.

**Alternatives considered:**

- Automatic CRDT merge on text fields (e.g., `motie.text`) — unsafe for legal documents; auto-merge can produce juridically incorrect content.
- Always prefer external (provider-of-truth) — loses decidesk-user edits; violates data integrity.
- Per-field conflict strategy (auto-merge for safe fields like `location`, manual for risky like `motie.text`) — complex config, fragile.

**Why last-writer-wins + conflict flag:** Motie text, besluit formulering, etc. are legal documents; automatic merge is a liability. For low-risk fields (`location`, `email`), conflict rate is low and manual resolution is acceptable. Griffier gets a clear UI showing both values + who changed when.

### D3: Pull-based default, push-back opt-in per organization

**Choice:** Sync direction is per `(organization, provider, objecttype)`. Defaults: read-only (pull). Push must be explicitly enabled and configured with writable-field allowlist.

**Alternatives considered:**

- Always bidirectional (push by default) — risks corrupting official agenda if decidesk has bugs.
- Separate "read-only" and "sync-back" instances — duplicate infrastructure; confusing for users.

**Why opt-in push:** Griffie is the authority for official workflow. Decidesk is initially a mirror. Push introduces risk (bad data back to vendor); enable only when org is confident. Writable-field allowlist prevents accidental write to read-only provider fields (e.g., `Stemming.uitslag` in NOTUBIZ is never writable).

### D4: Schemas in decidesk-sync register, not domain registers

**Choice:** `ExternalIdentifier`, `SyncJob`, `SyncConflict`, `FractieRol` live in a separate `decidesk-sync` register. Domain schemas (`Meeting`, `Person`, etc.) stay in `decidesk` register but add FK relations to ExternalIdentifier.

**Alternatives considered:**

- Embed sync metadata directly in domain schemas (`meeting.externalId`, `meeting.externalEtag`) — pollutes domain model; makes sync optional/optional.
- Separate app per adapter (notubiz-app, ibabs-app) — prevents unified sync engine.

**Why separate register:** Sync is a technical layer, not a domain concern. Domain schemas remain clean. Separation of concerns: openconnector owns sync; decidesk owns governance model. FK relations make it clear when an object is sync-bound.

### D5: Webhook-first for iBabs, cron-fallback for NOTUBIZ

**Choice:** iBabs publishes webhooks (Notifications module) → primary trigger. NOTUBIZ has no webhooks (as of 2026) → cron every 15 min with `?modified-since={lastSyncedAt}` query.

**Alternatives considered:**

- Cron for both (simpler implementation, less real-time) — misses iBabs webhook advantage.
- Webhook + cron for both (redundancy) — extra load; hard to de-dupe if both fire simultaneously.

**Why webhook-first:** iBabs offers real-time notifications; use them to reduce latency. NOTUBIZ cron is acceptable (15 min is reasonable for governance data). If webhook fails, cron is a fallback (eventual consistency).

### D6: Streaming documents to object storage, never loading full file in memory

**Choice:** When importing a `Vergaderstuk` (document) > 1MB, stream directly to `ObjectService::saveFile()` or openregister's file storage backend. Never `file_get_contents()` or load into memory.

**Alternatives considered:**

- Inline all documents up to 50MB (what we see in practice) — memory pressure; can crash PHP worker.
- Async job queue for document imports — adds latency; complicates error handling.

**Why streaming:** Dutch municipalities have documents 20–200MB (scans of thick agendas). Streaming is the safe default. ObjectService's file backend (likely S3 or similar) handles the burden.

### D7: Per-organization configuration via admin UI, no env vars or code

**Choice:** Each organization (gemeente) configures its NOTUBIZ/iBabs connection via an OpenRegister admin page: API key, endpoint URL, writable-field allowlist, sync direction, cron schedule, webhook secret.

**Alternatives considered:**

- Env vars (NOTUBIZ_API_KEY, IBABS_ENDPOINT, etc.) — doesn't scale to 300+ gemeenten; not multi-tenant.
- Config files — hard to rotate credentials; no audit trail.

**Why admin UI:** Multi-tenant. Credentials stored encrypted in database (openregister's secret storage). Each org controls its own sync settings. Changes are auditable.

### D8: FractieRol includes succession handling (no in-place updates)

**Choice:** When a fractievoorzitter changes, the old `FractieRol` gets `actiefTot = today`; a new `FractieRol` is created with `actiefVan = today`. Old role stays in history for audit/analysis.

**Alternatives considered:**

- In-place update (change `persoon` FK on the old role) — loses historical record; breaks references in Stemmingen that point to the old fractievoorzitter.
- Delete + create — no audit trail; hard to reconstruct who was in role when.

**Why immutable + new record:** Auditable; fractievoorzitter name is a fact at voting time. If role changes retroactively, old stemmingen should still reference the person-who-was-chair-then.

## Reuse Analysis

| Code Path | Source | Reuse Strategy |
|---|---|---|
| Schema management | `openregister/lib/Service/SchemaService.php` | Register definitions loaded at app init; no custom schema engine. |
| Object CRUD | `openregister/lib/Service/ObjectService.php` | `saveObject()`, `findAll()` for all 12 domain types + 4 sync types. |
| Relation management | `openregister/lib/Service/RelationService.php` | ExternalIdentifier FK relations use standard relation system. |
| File attachment | `openregister/lib/Service/FileService.php` | `saveFile()` for document streaming; no custom upload handler. |
| Audit trail | `openregister/lib/Service/AuditTrailService.php` | All mutations logged automatically; no custom audit code. |
| Webhook dispatch | `openregister/lib/Service/WebhookService.php` (OR) + `openconnector` framework | iBabs webhooks routed to connector; NOTUBIZ cron via native job runner. |
| Job scheduling | `openconnector` job runner | Cron jobs (NOTUBIZ), background sync reconciliation, conflict expiry checks. |
| Permission checks | `openregister/lib/Service/AuthorizationService.php` | Push-back only if user has write permission on the domain object. |
| Multi-tenancy | `openregister` built-in | Organization scoping automatic; no custom tenant isolation code. |

No custom CRUD, REST API, or permission system. All leverages platform.

## Seed Data

**Seed data required:** This change introduces 4 new schemas (`ExternalIdentifier`, `SyncJob`, `SyncConflict`, `FractieRol`) and modifies domain schemas. Per ADR-001 (data-layer), we MUST include 3–5 realistic seed objects per schema in `lib/Settings/decidesk_sync_register.json`.

**Seed objects:**

### ExternalIdentifier (3 examples)

```json
{
  "@self": {
    "register": "decidesk-sync",
    "schema": "ExternalIdentifier",
    "slug": "ext-meeting-notubiz-1"
  },
  "localObjectType": "Meeting",
  "localObjectId": "uuid-meeting-1",
  "provider": "notubiz",
  "externalId": "MEETING_12345",
  "externalUrl": "https://notubiz.nl/meetings/12345",
  "externalEtag": "e9f5a0c5e2b1a3d4f6c8e9g2h4",
  "lastSyncedAt": "2026-05-22T10:30:00Z",
  "syncDirection": "bidirectional",
  "lastModifiedLocal": "2026-05-22T09:00:00Z",
  "lastModifiedExternal": "2026-05-22T08:45:00Z"
}
```

### SyncJob (3 examples)

```json
{
  "@self": {
    "register": "decidesk-sync",
    "schema": "SyncJob",
    "slug": "syncjob-notubiz-2026-05-22-10"
  },
  "provider": "notubiz",
  "direction": "pull",
  "scope": "incremental",
  "targetObjectType": "Meeting",
  "startedAt": "2026-05-22T10:00:00Z",
  "finishedAt": "2026-05-22T10:03:45Z",
  "status": "success",
  "objectsProcessed": 12,
  "objectsCreated": 2,
  "objectsUpdated": 8,
  "objectsSkipped": 2,
  "objectsConflicted": 0,
  "errorLog": null,
  "triggeredBy": "cron"
}
```

### SyncConflict (2 examples)

```json
{
  "@self": {
    "register": "decidesk-sync",
    "schema": "SyncConflict",
    "slug": "conflict-meeting-location-1"
  },
  "externalIdentifier": "uuid-ext-meeting-1",
  "field": "location",
  "localValue": "Raadszaal",
  "externalValue": "Burgerzaal",
  "localChangedAt": "2026-05-22T10:35:00Z",
  "externalChangedAt": "2026-05-22T10:30:00Z",
  "localChangedBy": "user-griffier-1",
  "detectedAt": "2026-05-22T10:45:00Z",
  "status": "open",
  "resolvedBy": null,
  "resolution": null
}
```

### FractieRol (3 examples)

```json
{
  "@self": {
    "register": "decidesk-sync",
    "schema": "FractieRol",
    "slug": "fractierole-groenlinks-voorzitter-2026"
  },
  "fractie": "uuid-fractie-groenlinks",
  "persoon": "uuid-person-marieke",
  "rol": "fractievoorzitter",
  "portefeuille": ["klimaat", "duurzaamheid"],
  "actiefVan": "2026-01-01",
  "actiefTot": null
}
```

(More examples in actual `decidesk_sync_register.json` with varied persons, roles, and providers.)

## Deduplication Check

**Result: No overlap found.**

Checked `openspec/specs/` and `openregister/lib/Service/`:

- **ObjectService** (existing) — generic CRUD for all schemas. Sync uses it; no custom model.
- **RelationService** (existing) — manages FK relations. Sync uses for ExternalIdentifier; no custom relation engine.
- **AuditTrailService** (existing) — automatic change tracking. No custom audit code needed.
- **WebhookService** (existing) — dispatch and retry. iBabs webhooks routed via this; no custom webhook handler.
- **FileService** (existing) — upload, download, streaming. Document import uses this; no custom file logic.
- **ConflictResolution** — NOT in openregister. Custom conflict detection + resolution UI required for sync domain.
- **SyncJob tracking** — NOT in openregister. Custom SyncJob + status monitoring required.

**Custom capabilities required:**

1. Sync adapters (NOTUBIZ + iBabs API clients) — domain-specific field mapping.
2. Conflict detection engine (CRDT-style lastModified comparison) — sync-specific business logic.
3. SyncJob orchestration (push/pull sequencing, rate-limit respect) — openconnector job runner hosting.
4. Admin dashboard + conflict-resolution UI — observability layer.

No duplication; all new code is justified.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| **R1 — Vendor API contract breakage.** NOTUBIZ/iBabs can introduce breaking changes with short notice (API versioning, endpoint removal, field removal, format changes). | Daily contract tests against sandbox endpoints; alert if test fails; feature flags per provider version in adapter code; ADR-specific version pinning in openconnector config. |
| **R2 — Performance at large gemeenten.** Amsterdam: 45 raadsleden, 18 commissies/maand, 3–4 meetings/week → 10K+ sync events/day; 1GB+ of documents/month. | Per-organization sharding of sync jobs (each gemeente's sync runs on dedicated pool); rate-limit awareness (token-bucket per provider); bulk endpoints preferred over per-object GETs; document streaming not memory-loaded. |
| **R3 — Conflict explosion during dual-editing.** Griffier edits meeting in NOTUBIZ while decidesk-user edits same meeting in decidesk → conflicts pile up; griffier's queue becomes unmanageable. | UI field-locking hints ("this field is being edited in NOTUBIZ now"); SSE push of external changes for reactive UI; conflict-quota alert when >10 conflicts/week per org. |
| **R4 — Push-back corrupts official workflow.** Bad data pushed back to NOTUBIZ (due to decidesk bug or misconfiguration) alters the official agenda, damaging griffie credibility. | Push default OFF per org; per-(org, provider, objecttype, field) granular enable switch; dry-run mode (mock pushes logged for 7 days before actual write); push limited to non-governance-critical fields (titles, descriptions); never touch `Stemming.uitslag` or `Besluit.tekst` without explicit org policy. |
| **R5 — LLM spoofing persons in chat companion.** Future AI Chat integration: LLM invents a realistic-sounding name for person creating action item → collides with real raadslid, causing confusion. | Action-item creation by chat uses explicit person UUID lookup (not free-text name input); no free-text person creation in sync; schema validation prevents invalid person FK. |
| **R6 — Sync latency masking real-time changes.** Griffier makes change at 10:00 in NOTUBIZ; cron at 10:15 pulls it; user in decidesk doesn't see it until 10:15 → appears stale. | 15-min pull frequency is acceptable for governance data. If iBabs, webhook reduces to 60s. For NOTUBIZ, document roadmap item: webhook support. UI shows "last synced at X" timestamp. |

## Migration Plan

**Forward path:**

1. Add `decidesk_sync_register.json` with 4 schemas + seed data to openconnector.
2. Add `ExternalIdentifier` FK relation to decidesk domain schemas in `decidesk_register.json` (backwards-compatible; no data migration).
3. Implement NOTUBIZ adapter as new openconnector app.
4. Implement iBabs adapter as new openconnector app.
5. Implement SyncService orchestration in openconnector/lib/Service/SyncService.
6. Implement conflict detection engine (CrdtService).
7. Implement admin dashboard + conflict-resolution UI.
8. Deploy to shared dev/staging; pilot with 1–2 gemeenten (NOTUBIZ or iBabs, not both yet).
9. Gather feedback; iterate.
10. Expand to production.

**No breaking changes to decidesk.** Domain schemas are only extended with optional `externalIdentifier` FK; existing objects unaffected.

**Rollback:** Disable adapters in openconnector config; sync stops. All data remains (no deletion). Can re-enable later.

**Compatibility:** Requires openregister 1.0+, openconnector 2.0+.

