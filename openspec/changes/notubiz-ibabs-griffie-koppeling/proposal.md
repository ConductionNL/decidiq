---
kind: openconnector
depends_on: []
---

# NOTUBIZ en iBabs Griffie-Koppeling (Bidirectional Sync)

## Why

Vrijwel alle 342 Nederlandse gemeenten gebruiken één van twee griffie-systemen voor het beheer van politieke besluitvorming: NOTUBIZ (±60% van gemeenten) of iBabs (Gemeente Oplossingen, ±35%). Beide zijn proprietary-SaaS met gesloten datamodellen; gemeenten betalen jaarlijks zes-cijferige bedragen voor licenties. Exit-kosten zijn enorm: alle agenda's, besluiten, moties en stemmingen zitten vast in vendor-formaten.

Decidesk wil het democratisch besluitvormingsproces openen via een open datamodel (gebaseerd op Popolo, OASIS Akoma Ntoso, en de Nederlandse STOP/TPOD-standaard). Gemeenten kunnen echter niet van de ene op de andere dag overstappen. De realistische aanpak is decidesk naast NOTUBIZ of iBabs te draaien als open laag, met bidirectional sync:

1. **Griffie blijft in haar workflow.** Haar bestaande tool is normatief voor officiële processen.
2. **Raadsleden krijgen beter UX.** Via decidesk: betere consultatie- en participatie-interface.
3. **Open data direct beschikbaar.** Voor burgers, hergebruik via mydash dashboards, opencatalogi inzichten, docudesk PDF-publicatie.
4. **Exit-optie zonder data-verlies.** Over 2–5 jaar kan de gemeente NOTUBIZ/iBabs uitfaseren.

Deze spec definieert de bidirectional synchronisatie: lezen van alle vergadering-gerelateerde objecten (agenda, vergaderstukken, aanwezigheid, stemming, besluiten, moties, amendementen, schriftelijke vragen, fracties), schrijven terug van decidesk-gegenereerde inzichten (samenvattingen, transcript-links, participatie-signalen), en conflict-resolutie bij gelijktijdige bewerkingen.

## What Changes

**NEW openconnector apps/modules:**

- **NOTUBIZ adapter** — leest via REST/JSON API, schrijft via REST/JSON API (OAuth2). Endpoints: `/meetings`, `/agenda-items`, `/documents`, `/voting`, `/decisions`, `/motions`, `/amendments`, `/questions`, `/people`, `/parties`, `/attendance`. Rate-limit: 60 req/min per tenant.
- **iBabs adapter** — leest via SOAP + modern JSON-REST, schrijft via JSON-REST. Endpoints via iBabs API portal (modules: Public Data, Meeting Items, Voting, Documents). Webhooks via Notifications module.
- **Sync engine** — polling (cron) + webhooks; conflict detection; state machine voor SyncJob tracking.

**NEW schemas in OpenRegister (decidesk-sync register):**

- `ExternalIdentifier` — polymorph koppeling tussen decidesk-object en vendor-id. Velden: `localObjectType`, `localObjectId`, `provider`, `externalId`, `externalUrl`, `externalEtag`, `lastSyncedAt`, `syncDirection`, `lastModifiedLocal`, `lastModifiedExternal`.
- `SyncJob` — uitvoeringslog. Velden: `provider`, `direction`, `scope`, `targetObjectType`, `startedAt`, `finishedAt`, `status`, `objectsProcessed`, `objectsCreated`, `objectsUpdated`, `objectsSkipped`, `objectsConflicted`, `errorLog`, `triggeredBy`.
- `SyncConflict` — onopgeloste gelijktijdige bewerkingen. Velden: `externalIdentifier`, `field` (JSONPath), `localValue`, `externalValue`, `localChangedAt`, `externalChangedAt`, `localChangedBy`, `detectedAt`, `status`, `resolvedBy`, `resolution`.
- `FractieRol` — per fractie de rolverdeling. Velden: `fractie`, `persoon`, `rol` (enum: fractievoorzitter, plaatsvervangend-fractievoorzitter, secretaris, woordvoerder, gewoon-lid), `portefeuille` (array), `actiefVan`, `actiefTot`.

**MODIFIED decidesk schemas:**

- `Vergadering` (Meeting), `Agendapunt` (AgendaItem), `Vergaderstuk` (DigitalDocument), `Aanwezigheid` (new; tracks attendance), `Stemming` (VotingRound + Vote per raadslid), `Besluit` (Decision), `Motie` (Motion), `Amendement` (Amendment), `SchriftelijkeVraag` (new; written questions), `Persoon` (Person), `Fractie` (GovernanceBody), `Rol` (Membership/Post) — alle gevuld via sync.

**New capabilities:**

- **sync-pull-incremental** — delta-pull elke 15 min via cron (`?modified-since={lastSyncedAt}`); webhook-triggering voor iBabs.
- **sync-push** — wijzigingen in decidesk-velden teruschrijven naar provider (per `(org, provider, objecttype)` configureerbaar).
- **sync-conflict-detection** — CRDT-style tracking van `lastModifiedLocal` + `lastModifiedExternal`; SyncConflict voor gelijktijdige edits.
- **sync-fraction-roles** — FractieRol synchronisatie inclusief woordvoerderschappen, portefeuilles.
- **sync-voting-detail** — per-persoon stemming (hoofdelijk + fractie-stemming).
- **sync-document-streaming** — grote PDF's streamen naar object-storage.
- **sync-observability** — admin dashboard met SyncJob statistieken, alert-thresholds, conflict-queue.

## Capabilities

### New Capabilities

1. **sync-pull-incremental** — Incremental pull every 15 minutes, respecting provider rate limits.
2. **sync-push** — Push decidesk changes back to provider (writable fields only, configurable per org/provider/object-type).
3. **sync-conflict-detection** — Detect simultaneous edits; create SyncConflict for manual resolution.
4. **sync-fraction-roles** — Sync FractieRol with role titles, portefeuilles, succession handling.
5. **sync-voting-detail** — Full per-person voting with hoofdelijke voting breakdown.
6. **sync-document-streaming** — Stream large documents to object storage.
7. **sync-observability** — SyncJob dashboard, alert thresholds, conflict queue UI.

### Modified Capabilities

None. This change is purely additive in openconnector; decidesk schema extensions are additive.

## Impact

**Code locations:**

- `openconnector/apps/notubiz-adapter/` — new app (REST client, polling logic, field mapping).
- `openconnector/apps/ibabs-adapter/` — new app (SOAP + REST client, webhook receiver, field mapping).
- `openconnector/lib/Service/SyncService/` — generic sync orchestration (push, pull, conflict resolution).
- `openconnector/lib/Service/CrdtService/` — conflict detection engine.
- `openconnector/lib/Controller/SyncStatusController.php` — admin dashboard.
- `openconnector/lib/Settings/decidesk_sync_register.json` — schema definitions for `ExternalIdentifier`, `SyncJob`, `SyncConflict`, `FractieRol` + seed data.
- `decidesk/lib/Settings/decidesk_register.json` — extend `Meeting`, `AgendaItem`, `Person`, etc. with sync metadata (fk to ExternalIdentifier).

**Dependencies:**

- `openregister` — provides `ObjectService`, `RelationService`, `AuditTrailService`, schema management.
- `openconnector` — provides app hosting, job runner, webhook framework.
- NOTUBIZ API (sandbox for dev/test).
- iBabs API (sandbox for dev/test).

**Reused (no changes):**

- `decidesk` data model — schemas unchanged; sync fills them.
- `openregister` CRUD, audit trails, file attachment, permission framework.
- `openconnector` job scheduling, webhook dispatch, app lifecycle.

**Out of scope (future iterations):**

- Companion (LIAS) adapter — small market, separate spec.
- STOP/TPOD XML export for official publications — separate spec via docudesk.
- Historical backfill beyond 2 years.
- Advanced conflict merge (text diffing) — v2.
- Privacy-gevoelige content auto-detection (NLP) — separate initiative.
- Province/waterboard schema extensions — separate validation per org type.

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| **Vendor API changes break sync.** NOTUBIZ/iBabs can introduce breaking changes. | Contract tests run daily against sandbox; alert on test failure before prod rollout. |
| **Performance degradation at large gemeenten.** Amsterdam: 45 raadsleden, 18 commissies/maand → 10K+ events/dag. | Per-org sharding; rate-limit awareness (token bucket); prefer bulk endpoints. |
| **Conflict explosion during simultaneous edits.** Griffier + decidesk-user edit same field → conflict queue explodes. | Per-veld UI locking hints; SSE reactive updates; conflict-quota alert (>10/week). |
| **Push-back corrupts griffie workflow.** Bad data pushed to NOTUBIZ damages official agenda. | Push default off; per-(org, provider, objecttype, field) granular control; dry-run mode. |
| **LLM-generated names spoofing vendor persons.** Chat companion creates action items with realistic-looking names that collide with real persons. | UUID-based person refs (not free-text names); create-action-item requires explicit person lookup. |

