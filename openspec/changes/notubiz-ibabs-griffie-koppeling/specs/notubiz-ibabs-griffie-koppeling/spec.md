---
status: draft
---

# Spec: NOTUBIZ en iBabs Griffie-Koppeling (Bidirectional Sync)

## Purpose

Decidiq synchronizes governance data bidirectionally with NOTUBIZ (±60% of Dutch municipalities) and iBabs (Gemeente Oplossingen, ±35%) to create an open-data mirror of official decision-making without requiring immediate vendor exit. This spec captures the sync adapter contracts, data-mapping rules, conflict-detection engine, and observability requirements.

## ADDED Requirements

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: sync-pull-incremental                                       -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

### Requirement: REQ-NIK-001 — Full initial import per provider

The system SHALL support a full historical import of all governance objects for a given time range when a new provider is connected or a new calendar year begins.

#### Scenario: Full NOTUBIZ history import
- **GIVEN** a gemeente with a NOTUBIZ account and 2 years of existing meeting history
- **WHEN** an admin runs `sync full --provider notubiz --from 2024-01-01`
- **THEN** the system MUST import all Vergaderingen, Agendapunten, Vergaderstukken, Stemmingen, and Besluiten from that date within 24 hours, paginated with rate-limit respect (NOTUBIZ: 60 requests/min)

#### Scenario: Duplicate detection across providers
- **GIVEN** a gemeente later adds iBabs for some commissies (while NOTUBIZ covers others)
- **WHEN** iBabs sync runs
- **THEN** the system MUST deduplicate by `(Vergadering.datum + Vergadering.naam)` and link both ExternalIdentifiers to the same decidiq Meeting (no duplicates created)

#### Scenario: Large document streaming
- **GIVEN** a Vergaderstuk is 200MB
- **WHEN** it is imported
- **THEN** the system MUST stream the file to object-storage (not load in memory); create Vergaderstuk record with `grootte`, `sha256`, `mimeType` metadata

---

### Requirement: REQ-NIK-002 — Incremental pull every 15 minutes

The system SHALL execute a delta-pull every 15 minutes (cron default) or via webhook when the provider supports it.

#### Scenario: NOTUBIZ delta-pull
- **GIVEN** NOTUBIZ does not support webhooks (as of 2026)
- **WHEN** cron fires every 15 minutes
- **THEN** the system MUST execute `?modified-since={lastSyncedAt}` query on all relevant endpoints; create a SyncJob with `status: success, objectsProcessed: <count>`

#### Scenario: iBabs webhook-triggered pull
- **GIVEN** iBabs Notifications module publishes a `meeting.updated` webhook
- **WHEN** the webhook is received
- **THEN** the system MUST pull the updated Meeting + agenda + decisions within 60 seconds

#### Scenario: Zero-change sync
- **GIVEN** a delta-pull finds 0 changes
- **WHEN** it completes
- **THEN** the system MUST record SyncJob with `status: success, objectsProcessed: 0`; MUST NOT emit events

---

### Requirement: REQ-NIK-003 — Push decidiq changes back to provider

Modifications to synchronized objects in decidiq MUST be pushed back to the provider for writable fields.

#### Scenario: Agendapunt title push
- **GIVEN** a griffier changes a Agendapunt title in decidiq
- **WHEN** the change is saved
- **THEN** within 30 seconds, the system MUST push the change to the provider via `PATCH /agenda-items/{id}` (NOTUBIZ) or `agendaItems.update` (iBabs)

#### Scenario: Provider rejects push
- **GIVEN** the provider rejects a push (e.g., meeting is closed)
- **WHEN** the rejection arrives
- **THEN** the system MUST create a SyncConflict with `resolution: provider-rejected`; mark the local change as "not-synchronized"

#### Scenario: Read-only field push skip
- **GIVEN** a field is not writable in the provider (e.g., `Stemming.uitslag` in NOTUBIZ)
- **WHEN** a decidiq change attempts a push on that field
- **THEN** the system MUST skip the push, log a warning, and keep the local change as "local-only"

---

### Requirement: REQ-NIK-004 — Conflict detection for simultaneous edits

When the same field is modified locally and externally since the last sync, a SyncConflict MUST be created.

#### Scenario: Simultaneous meeting location edit
- **GIVEN** a Vergadering with `lastSyncedAt: 10:00`
- **GIVEN** at 10:30, a griffier changes location to "Burgerzaal" in NOTUBIZ
- **GIVEN** at 10:35, a decidiq-user changes location to "Raadszaal" in decidiq
- **WHEN** the 10:45 sync runs
- **THEN** a SyncConflict MUST be created with `status: open`, showing both values, who changed, and when

#### Scenario: Manual conflict resolution
- **GIVEN** a SyncConflict with `status: open`
- **WHEN** a griffier opens the conflict-resolution UI
- **THEN** the UI MUST display both values, allow selection (local/external/merged), log the choice in `resolvedBy` / `resolution`

#### Scenario: Conflict escalation alert
- **GIVEN** a SyncConflict remains `open` for 7 days
- **WHEN** the health-check cron runs
- **THEN** a notification MUST be sent to the griffier and decidiq-admin; the affected object MUST show a "in-conflict" badge in decidiq UI

---

### Requirement: REQ-NIK-005 — Per-fraction roles with succession handling

FractieRol objects MUST be synchronized to track role assignments, portefeuilles, and woordvoerderschappen with proper succession.

#### Scenario: Import fraction roles from NOTUBIZ
- **GIVEN** NOTUBIZ provides a member list with role fields (fractievoorzitter, etc.)
- **WHEN** the import runs
- **THEN** a FractieRol MUST be created per (Fractie, Persoon) pair with `actiefVan = current calendar year`

#### Scenario: Fraction chair succession
- **GIVEN** a fractievoorzitter is replaced in NOTUBIZ
- **WHEN** the update is synced
- **THEN** the old FractieRol MUST receive `actiefTot = today`; a new FractieRol MUST be created with `actiefVan = today` and the new person; existing Stemmingen MUST continue referencing the old person-role combination

#### Scenario: Portefeuille handling for iBabs (no explicit portefeuilles)
- **GIVEN** iBabs does not model portefeuilles
- **WHEN** iBabs FractieRol is imported
- **THEN** the `portefeuille` field MUST remain empty; the griffier MUST be able to manually fill it in the UI without sync overwriting it

---

### Requirement: REQ-NIK-006 — Stemming (voting) with full per-person breakdown

Stemmingen MUST include individual per-person votes, not just totals.

#### Scenario: Import hoofdelijke (roll-call) voting
- **GIVEN** a roll-call vote on a Motion
- **WHEN** imported from NOTUBIZ
- **THEN** one StemUitgebracht (Vote) object MUST exist per present raadslid with `stem ∈ {voor, tegen, onthouden, afwezig, niet-deelgenomen}`

#### Scenario: Import fractie-stemming (group vote)
- **GIVEN** a group vote (fractie, not per-person)
- **WHEN** imported
- **THEN** one Vote per fractie MUST be created with `aantal` and `stem` fields; individual members MUST be automatically marked as "verondersteld conform fractielijn" unless explicitly overridden

#### Scenario: Stemming annulment with audit
- **GIVEN** a vote is annulled due to procedure error
- **WHEN** the provider registers the annulment
- **THEN** the Stemming MUST get `status: geannulled`; an audit link to the annulment MUST be added; the record MUST NOT be deleted

---

### Requirement: REQ-NIK-007 — Motions, amendments, written questions with version control

Moties, Amendementen, and SchriftelijkeVragen MUST be synchronized with full version history.

#### Scenario: Motion amendment import
- **GIVEN** Motion M2024-15 is submitted, then amended (version 2)
- **WHEN** both are imported
- **THEN** two MotieVersie records MUST exist linked to the same Motie; the UI MUST show the latest version by default with a "earlier versions" link

#### Scenario: Written question answer
- **GIVEN** a SchriftelijkeVraag is answered by a wethouder 6 weeks later
- **WHEN** the answer is published
- **THEN** the SchriftelijkeVraag MUST receive `antwoord` field + `status: beantwoord` + `aantalDagen: 42` (calculated)

#### Scenario: Amendment withdrawal
- **GIVEN** an amendment is withdrawn before voting
- **WHEN** that action is recorded in the provider
- **THEN** the Amendement MUST get `status: ingetrokken`; the relation to the Agendapunt MUST remain for traceability

---

### Requirement: REQ-NIK-008 — Aanwezigheid (attendance) tracking per meeting

Attendance data MUST be kept synchronized with the griffie's administration.

#### Scenario: Attendance list import
- **GIVEN** NOTUBIZ provides `/meetings/{id}/attendance` data
- **WHEN** imported
- **THEN** one Aanwezigheid record per raadslid MUST be created with `status ∈ {aanwezig, afwezig-gemeld, afwezig-zonder-bericht, deels-aanwezig}` and optional `vervangenDoor` (proxy) reference

#### Scenario: Late arrival tracking
- **GIVEN** a raadslid arrives 30 minutes into the meeting and iBabs records tap-in time
- **WHEN** synced
- **THEN** the Aanwezigheid MUST receive `aanwezigVanaf` timestamp (2026-05-22T14:30:00Z)

#### Scenario: Post-publication attendance correction alert
- **GIVEN** attendance is updated after the decision list is published
- **WHEN** the update is received
- **THEN** a notification MUST be sent to the griffier for verification (publication-after-correction can damage credibility)

---

### Requirement: REQ-NIK-009 — Vergaderstukken (documents) with metadata

PDF and attachment documents MUST be synchronized with complete provider metadata.

#### Scenario: Large document import with streaming
- **GIVEN** a 50MB Vergaderstuk at Agendapunt 7
- **WHEN** imported
- **THEN** the file MUST be streamed to object-storage; a Vergaderstuk record MUST be created with `titel`, `bestandsnaam`, `mimeType`, `grootte`, `sha256`, `vertrouwelijkheidsniveau`, linked to Agendapunt

#### Scenario: Confidential document access control
- **GIVEN** a document is marked `vertrouwelijk` or `geheim` by the griffie
- **WHEN** imported
- **THEN** the file MUST NOT be publicly accessible; access-ACL MUST restrict to raadsleden + griffie only

#### Scenario: Document versioning
- **GIVEN** a document is replaced with a corrected version after the meeting
- **WHEN** imported
- **THEN** the old version MUST be retained as VergaderstukVersie; the active version MUST be the latest

---

### Requirement: REQ-NIK-010 — Observability: sync dashboard and alerting

The system MUST provide an operational dashboard and alert thresholds for sync health.

#### Scenario: Sync status dashboard
- **GIVEN** an admin opens `/admin/sync-status`
- **WHEN** the page loads
- **THEN** it MUST display per-provider:
  - Last 24h SyncJob list with status/counts
  - Number of open SyncConflicts
  - Average sync latency
  - Quota usage (rate-limit %)

#### Scenario: Failed sync alerting
- **GIVEN** three consecutive SyncJobs fail
- **WHEN** the third fails
- **THEN** a PagerDuty/Slack/e-mail alert MUST be sent to the configured on-call

#### Scenario: Conflict queue health
- **GIVEN** the number of open SyncConflicts exceeds 25
- **WHEN** the health-check runs
- **THEN** the overall connector-status MUST become `degraded`; this MUST be visible in mydash and the admin panel

---

