# Board portal — admin runbook

> Audience: Nextcloud administrators and corporate secretaries operating
> the Decidiq board portal.
>
> Scope: install / upgrade, schema bootstrap, role-based access, eIDAS
> integration, multilingual queue ops, regulator export, observability,
> backup, troubleshooting.

## 1. Install / upgrade

1. Install **decidesk** from the app store or place the directory under
   `custom_apps/decidiq/` and run `occ app:enable decidiq`.
2. **Run upgrade** — `occ upgrade` triggers the
   `OCA\Decidiq\Repair\InitializeSettings` repair step, which calls
   `ConfigurationService::importFromApp('decidesk')`. That import seeds
   the nine board-portal schemas (`board`, `board-member`,
   `board-meeting`, `resolution`, `board-vote`, `board-minutes`,
   `conflict-of-interest`, `board-material`, `board-audit-log-entry`)
   and their default rows into OpenRegister.
3. Verify in `openregister` → register `decidesk` that the nine schemas
   are present. If they are not, re-run `occ repair --include-expensive`.

Background jobs registered by `appinfo/info.xml`:

- `OCA\Decidiq\BackgroundJob\TranslationQueueJob` — hourly, processes
  queued multilingual reconciliation entries.

If neither shows up under `oc_jobs`, re-run `occ upgrade`.

## 2. Granting access

Decidiq uses Nextcloud's standard auth: any signed-in user can hit the
controllers, but the OpenRegister object API enforces per-object RBAC.
A user only sees a `Board` row when they (a) created it, (b) are the
admin, or (c) are listed as a `BoardMember` of that board (the OR
register-config grants read on `board` to any user UID present in the
`personKoppeling` of a non-expired `board-member` row for that board).

To onboard a new board member:

1. Sign in as the board's chairman or as a Nextcloud admin.
2. Open the `Boards` view → the relevant board → **Invite member**.
3. Pick role + independence-status + supply the member's Nextcloud UID
   in `personKoppeling`.

Admin-gated endpoints (require a member of the `admin` group):

- `POST /api/regulator-exports` + `GET /api/regulator-exports` +
  `GET /api/regulator-exports/{id}` (download).
- `POST /api/multilingual/queue` + `GET /api/multilingual/queue` +
  `POST /api/multilingual/queue/process`.
- `GET /api/audit-log` + `GET /api/audit-log/export`.

## 3. Configuring quorum + notice rules

Quorum requirements live on the `Board` itself (`quorumRule`,
`quorumThreshold`, `minimumNoticeHours`) and are evaluated by
`QuorumVerificationService` at the moment a resolution's vote is opened.

To change them: go to the board detail page → **Settings** → adjust
`quorumRule` (`absolute-majority`, `simple-majority`,
`two-thirds`, `custom`) and `quorumThreshold` for the custom case.

`minimumNoticeHours` is used by `BoardMeetingService::schedule` to flag
short-notice meetings; the lifecycle still allows them but the audit
entry carries an `under-notice` marker for regulator review.

## 4. eIDAS QES integration

The portal supports **Qualified Electronic Signatures** for minutes and
written resolutions via `openconnector` → an external eIDAS QTSP.

Configuration:

1. Install + enable `openconnector`.
2. Configure an eIDAS-QTSP source (e.g. Connective / Itsme / DigiD HSM).
3. Decidiq's `EIDASSignatureService` will auto-discover the source on
   startup. To pin a specific source, set the appconfig key
   `decidesk:eidas_source_id` to the openconnector source UUID.
4. Validate the integration with **Validate certificate** (a POST to
   `/api/eidas/validate-cert` with the member's cert PEM). The response
   includes the EU Trusted List match result.

The QES flow:

1. **Initiate** — `POST /api/minutes/{id}/eidas/initiate` queues the
   signing job.
2. **Verify** — `POST /api/minutes/{id}/eidas/verify` polls the QTSP.
3. **Finalize** — `POST /api/minutes/{id}/eidas/finalize` stores the
   signed PDF + the QTSP attestation.

Verify EU Trusted List freshness with
`occ decidiq:eidas:tl-status` (currently logged at INFO; expose to a
controller surface as part of the
`decidesk-board-portal-v1` umbrella).

## 5. Multilingual minutes reconciliation

`MultilingualReconciliationService` keeps board-minutes translations in
sync with their source. The hourly `TranslationQueueJob` pulls entries
in status `queued` and calls the registered `ITranslationAdapter`.

By default a no-op `LogTranslationAdapter` is registered (entries stay
`queued`). To enable real translation:

1. Register a non-default adapter through DI (e.g. an openconnector-backed
   adapter) by overriding the `ITranslationAdapter` binding in your
   custom-app's `Application::register()`.
2. Or trigger manual processing: `POST /api/multilingual/queue/process`
   with `{ "maxEntries": 50 }`.

The queue summary (`GET /api/multilingual/queue`) shows counts per
status (`queued`, `processing`, `complete`, `failed`).

## 6. Regulator exports

Three scopes (`resolutions`, `minutes`, `audit-log`) and two formats
(`pdf`, `csv`). The output is streamed as an attachment and persisted to
the `regulator-export` schema with its SHA-256 checksum — you can
re-emit a previously generated export idempotently via
`GET /api/regulator-exports/{id}` (the download endpoint).

CSV exports are produced by the built-in `RegulatorExportService` and
require zero external dependencies. PDFs use a self-contained PDF-1.4
renderer; for richer reports route through docudesk by setting
appconfig `decidesk:export_format_provider=docudesk`.

Every export is mirrored to the hash-chained audit log
(`AuditLogService::append(actor:..., action: 'regulator-export', ...)`).

## 7. Audit log verification

The audit log is **append-only + hash-chained**. To verify integrity:

1. Pull a range — `GET /api/audit-log?since=2026-01-01&until=2026-06-30`.
2. Verify a single entry — `GET /api/audit-log/{id}/verify` recomputes
   `sha256(prevHash || canonicalPayload)` and compares to the stored
   `hash` field.
3. Export — `GET /api/audit-log/export?format=csv` for
   regulator-submissable form.

Tampering is caught at any point in the chain: an inconsistency in entry
N invalidates every entry N+1..end (each entry binds to its predecessor's
hash).

## 8. CalDAV bridge ops

`BoardMeetingCalDavBridge` subscribes to `ObjectCreatedEvent` /
`ObjectUpdatedEvent` from OpenRegister and forwards `board-meeting`
rows to `BoardCalDavSyncService`. The bridge swallows sync failures so
OR persistence never blocks on a calendar hiccup.

To check whether a meeting actually landed in a calendar:

```bash
docker exec nextcloud occ user:info <chairman-uid>
# Then inspect the calendar via DAV — VEVENT carries:
# X-DECIDESK-MEETING-ID, X-DECIDESK-LIFECYCLE, X-DECIDESK-BOARD
```

If the chairman has **no** writable CalDAV calendar, the sync service
falls back to writing the ICS blob to `caldavIcsBlob` on the
`board-meeting` row, so nothing is lost.

## 9. Observability

Standard NC logs cover service failures
(`docker exec nextcloud tail -f data/nextcloud.log`). Key log lines:

- `Decidiq: BoardService::create failed` — the OR `saveObject` raised.
- `Decidiq: failed to stamp noticeSentDate; transition retained` — the
  lifecycle transition succeeded but the `noticeSentDate` patch did not.
- `Decidiq: TranslationQueueJob processed N entries` — queue progress.
- `Decidiq: failed to record vote` — `BoardVoteService::cast` error.

The `regulator-export` records include a `processedAtMs` field for
latency tracking.

## 10. Backup + restore

Boards, members, meetings, resolutions, votes, materials, audit-log
entries and minutes all live in OpenRegister-managed tables, so a
standard NC database backup covers the full board portal data set. The
register-config + schemas live in `oc_appconfig` (under
`decidesk:*` keys) and are recreated by the repair step on a fresh
install.

To rebuild the schemas without losing data:

```bash
docker exec nextcloud occ maintenance:repair --include-expensive
# Re-runs InitializeSettings; idempotent on existing schemas.
```

## 11. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Board portal sidebar entries missing | Manifest fragment not picked up | Bump `appinfo/info.xml <version>` and reload |
| `403 Administrator role required` on `/api/regulator-exports` | Caller is not in NC `admin` group | Add to admin group or use a different account |
| `422 Unknown vote enum` | Mistyped vote on cast | Use one of `in-favor`/`against`/`abstain`/`absent`/`recused-due-to-conflict` |
| `422 Quorum not met` on `open-vote` | Meeting not `in-session` or attendance < quorum | Open the meeting + record attendance first |
| CalDAV sync silently no-op | No writable calendar for the actor | Create / share a writable calendar for the chairman |
| Translation queue stuck in `queued` | Default `LogTranslationAdapter` registered | Override `ITranslationAdapter` binding |
| `nldesign` theme not applied | App not theming-aware | Verify `nldesign` is enabled and refresh the browser cache |

For any issue not covered here, file an issue in
`Conduction/decidesk` with the relevant `data/nextcloud.log` excerpt
and the failing request as `curl --verbose`.
