# Design: document-accessibility-check

## Architecture Overview

Decidiq stays a thin client: all new persistence is OpenRegister objects (`AccessibilityScanReport` in the decidesk register) plus `IAppConfig` keys — no tables, no migrations. The moving parts:

```
upload (OR FileService attach, agenda builder / Files tab)
   └─ scan-on-upload enabled? → queue DocumentAccessibilityScanJob (QueuedJob)
        └─ DocumentAccessibilityScanService
             ├─ FileService: read file bytes (size-capped)
             ├─ smalot/pdfparser: catalog/StructTreeRoot/MarkInfo, /Lang,
             │   Info+XMP title, Outlines, per-page text extraction, XObjects,
             │   Figure struct-elem /Alt where present
             └─ ObjectService: upsert AccessibilityScanReport (per file, latest wins)

publish (two existing chokepoints, gate consulted server-side)
   ├─ AgendaService::publishAgenda()          — meeting agenda to participants/public
   └─ PublicationService (+EligibilityService) — public-publication payload path
        └─ AccessibilityGate (method on scan service):
             mode off  → no-op
             mode warn → refuse unless request carries acknowledgement
             mode block→ refuse on any `fail` report unless override{reason}
             not-scanned attachments → synchronous scan first (capped)
             override → recorded on PublicationRecord / source audit trail

reporting (declarative)
   └─ x-openregister-aggregations on AccessibilityScanReport
        → per-body/period pass/warnings/fail/not-scanned counts + override count
        → report view + CSV export (ExportService pattern)
```

The scanner runs entirely locally; no document content leaves the instance (unlike PDF *generation*, which optionally delegates to Docudesk's `PdfService`, scanning must work on every install because it is a compliance gate).

## Declarative-vs-imperative decision (ADR-031)

- **Imperative (justified exception): PDF parsing and check evaluation.** Reading a PDF's object graph (structure tree, `/Lang`, outlines, text layer, XObjects) is content-level file analysis — it cannot be expressed as OR declarative dialects, which operate on JSON object properties. `DocumentAccessibilityScanService` is the single imperative unit, analogous to the existing imperative exceptions (`MinutesDocumentService` PDF generation, `TranscriptionService`). Its *output* is a plain OR object so everything downstream is declarative.
- **Declarative: everything downstream.**
  - Per-body/period status aggregation → `x-openregister-aggregations` on the `AccessibilityScanReport` schema (counts grouped by `status`, filtered by `governanceBody` + `scannedAt` range), consumed by the report view and any dashboard widget. No imperative counting service.
  - Notifications (e.g. "scan completed with failures" to the uploader) → `x-openregister-notifications` on `AccessibilityScanReport` (created trigger, condition `status == fail`). No imperative dispatch (notification-dialect gate 18).
  - Report status field is a plain enum, not a lifecycle — reports are immutable snapshots, superseded by newer reports, so no `x-openregister-lifecycle` (canonical `initial` key not needed here at all).
- **Gate wiring is imperative but minimal**: a pre-condition check inside the two existing publish paths; it reads pre-computed report objects and app config. Putting a publication gate in a declarative dialect would require an OR-side "conditional write refusal across related files" dialect that does not exist; per ADR-031 this stays a thin server-side guard in decidiq.

## API Design

No new controllers for CRUD (frontend reads `AccessibilityScanReport` objects via the OR object API / `useObjectStore`, per ADR-022 — redundant-controller gate). New/changed endpoints:

### `POST /api/accessibility/scan`
On-demand scan of one attachment (also used by the "scan now" badge action and publish-time sync scan is internal).
**Request:**
```json
{ "sourceObject": "00000000-0000-0000-0000-000000000000", "fileId": 123 }
```
**Response:** `200` with the created/updated report object (status, findings). `413`-style error payload when over the size cap (report saved as `not-scanned`). Auth: `#[NoAdminRequired]` + per-object authorization via OR RBAC on the source object (no-admin-idor gate).

### Existing publish endpoints (agenda publish, publication create) — request extension
**Request additions:**
```json
{ "accessibilityAcknowledged": true, "accessibilityOverride": { "reason": "…" } }
```
**Response on refusal:** `409` with `{ "error": "accessibility-gate", "mode": "block", "documents": [ { "fileId": 123, "fileName": "raadsvoorstel.pdf", "status": "fail", "findings": [ … ] } ] }` so the UI can render the dialog from the server verdict (gate is server-side truth).

### Admin settings
Reuse the existing `SettingsController`/`SettingsService` load/save path; two new `IAppConfig` keys: `accessibility_enforcement_mode` (`off|warn|block`, default `warn`), `accessibility_scan_on_upload` (`1|0`, default `1`). Plus internal constant size cap (25 MB) and long-doc threshold (20 pages) — constants first, promoted to settings only if field demand appears.

## Database Changes

None. All persistence is OR objects + `IAppConfig` (ADR-022; thin-client architecture).

## Nextcloud Integration

- Controllers: `AccessibilityController` (single scan endpoint); existing `AgendaController` / publication controllers pass the acknowledgement/override fields through.
- Services: new `DocumentAccessibilityScanService` (parse + checks + report upsert + gate evaluation); `AgendaService::publishAgenda()` and `PublicationService`/`PublicationEligibilityService` call the gate; `SettingsService` new keys.
- Background jobs: `DocumentAccessibilityScanJob` (`OCP\BackgroundJob\QueuedJob`), queued from the upload path when scan-on-upload is on (same pattern as `TranscriptionJob`).
- Mappers/Entities: none (OR objects via `ObjectService`, files via OR `FileService`, both container-resolved as in `MeetingFolderService`).
- Events/Hooks: none new; notifications are declarative rules on the report schema.

## Security Considerations

- **Untrusted file parsing**: PDFs are attacker-controllable input. Parser wrapped in catch-all → `not-scanned` + `parse-failure` finding; hard size cap before reading bytes; scan runs in background job where possible. Fail-closed: a scan error is never `pass`.
- **Gate is server-side**: enforcement evaluated in the service layer on every publish request, independent of UI state (mirrors `public-publication` eligibility gates). Override requires a non-empty reason; actor/timestamp recorded on `PublicationRecord` (audit trail for agenda-only path).
- **Authorization**: scan endpoint and override use OR per-object RBAC / existing governance-body authority checks — no new app-local authorization service (ADR-051, x-decidesk-rbac-scopes convention). Override permitted to the same actors who may publish.
- **No data egress**: scanning is local; no external service, no document upload anywhere.
- Routes declare auth posture attributes (route-auth gate); no `#[PublicPage]` anywhere in this change.

## NL Design System

- Badges: NC-standard components with CSS variables (`--color-success`, `--color-warning`, `--color-error`) — text + colour, never colour alone (beware nldesign `--color-error` foreground inversion, nldesign#40).
- Gate dialog: own file under `src/dialogs/` (modal-isolation gate), `NcDialog`-based, keyboard operable per `accessibility-baseline`.
- All strings through `t('decidiq', …)`, Dutch + English (ADR-005); i18n keys in English.

## File Structure

```
lib/
  Controller/AccessibilityController.php        (new)
  Service/DocumentAccessibilityScanService.php  (new: checks, gate, report upsert)
  BackgroundJob/DocumentAccessibilityScanJob.php(new)
  Service/AgendaService.php                     (gate call in publishAgenda)
  Service/PublicationService.php                (gate call in payload path)
  Service/SettingsService.php                   (2 config keys)
  Settings/decidesk_register.json               (AccessibilityScanReport schema,
                                                 aggregations, notifications, seeds;
                                                 PublicationRecord override fields)
appinfo/routes.php                              (scan route)
src/
  dialogs/AccessibilityGateDialog.vue           (new)
  components/AccessibilityBadge.vue             (new, used in attachment lists)
  views/AccessibilityReport.vue                 (new, per-body/period report + CSV)
  (admin settings view: 2 new fields)
```

## Seed Data

New schema introduced ⇒ seeds required (ADR-016), shipped as `x-openregister-seeds` on the schema in `lib/Settings/decidesk_register.json` (schema ref by slug, not PascalCase). `governanceBody`/`sourceObject` seed values reference existing seeded objects by slug-resolvable UUIDs at import time; nil UUID shown here as placeholder.

### Schema: `accessibility-scan-report`
| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | `scan-raadsvoorstel-begroting` | `scan-bijlage-kaart-centrum` | `scan-notulen-rvc-signed` | `scan-inspraaknotitie-docx` |
| fileName | `raadsvoorstel-begroting-2026.pdf` | `bestemmingsplan-kaart.pdf` | `notulen-rvc-ondertekend.pdf` | `inspraaknotitie.docx` |
| fileId | `1001` | `1002` | `1003` | `1004` |
| sourceObject | (seeded AgendaItem UUID) | (seeded AgendaItem UUID) | (seeded DigitalDocument `notulen-rvc-investering-acme-signed`) | (seeded AgendaItem UUID) |
| governanceBody | (seeded GovernanceBody UUID) | (same body) | (seeded RvC body UUID) | (same as obj 1) |
| status | `pass` | `fail` | `warnings` | `not-scanned` |
| findings | `[]` | `[{code:"no-text-layer",severity:"error",evidence:"0 chars/14 pages"},{code:"not-tagged",severity:"error"}]` | `[{code:"no-bookmarks",severity:"warning",evidence:"36 pages, no outline"},{code:"no-title",severity:"warning"}]` | `[{code:"unsupported-format",severity:"info"}]` |
| scannerVersion | `1.0.0` | `1.0.0` | `1.0.0` | `1.0.0` |
| scannedAt | `2026-06-02T09:15:00Z` | `2026-06-02T09:16:00Z` | `2026-06-10T14:30:00Z` | `2026-06-12T08:00:00Z` |

`@self` envelope per object: `{ "register": "decidiq", "schema": "accessibility-scan-report", "slug": "…" }`.

**Related items per object:**
- Files: seed PDFs are not shipped as binaries; reports reference fileName/fileId so badges, the report view, and aggregations are demonstrable on install. The e2e fixture set adds one real tiny tagged PDF and one scanned-image PDF for live scan tests.
- Notes/Tasks/Contacts: none.

**Modified schema `publication-record`** (additive properties, no new seeds required beyond one updated existing seed showing an override): `accessibilityOverride: { reason, actor, overriddenAt, reports[] }` — one existing PublicationRecord seed gains an example override with reason "Brondocument niet meer beschikbaar; publicatie wettelijk termijngebonden".

## Trade-offs

- **`smalot/pdfparser` (pure PHP) over `verapdf`/poppler binaries or an ExApp**: no binary/sidecar dependency keeps the gate working on every NC install; the cost is heuristic depth (no full PDF/UA validation) — accepted and honestly labelled. Alternative veraPDF-based certified validation could later become an optional ExApp without changing the report schema.
- **Reports as separate OR objects over properties on `DigitalDocument`**: attachments are NC files via OR `FileService`, not `DigitalDocument` objects — there is no object to hang properties on for most attachments. A dedicated report schema covers both cases, keeps scan history superseding clean, and gives declarative aggregation a natural target.
- **Gate at the two service chokepoints over an OR-side write guard**: OR has no dialect for cross-file publication preconditions; the decidiq services are already the single publish paths (`publishAgenda`, publication payload construction), mirroring how eligibility gates are enforced today.
- **Default `warn` over `block`**: non-breaking on upgrade; municipalities opt into `block` consciously. `off` preserves exact legacy behaviour as config-level rollback.

## Migration Plan

1. Ship schema + seeds (register import via existing repair-step path), services, job, UI; defaults `warn` + scan-on-upload on.
2. Existing attachments simply show `not-scanned` — no backfill (bulk legacy scanning is an explicit follow-up change).
3. Rollback: set mode `off` + toggle off (behavioural rollback, no deploy); code revert leaves inert additive OR data.

## Open Questions

- Whether the long-document bookmark threshold (20 pages) and size cap (25 MB) should be admin-tunable from day one — provisional: constants.
- Whether `warnings`-status documents should also require acknowledgement in `block` mode — provisional: only `fail` gates; warnings are informational everywhere.
