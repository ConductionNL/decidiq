# Board portal — migration guide

> Audience: corporate secretaries and IT integrators migrating an existing
> board portal onto Decidiq.
>
> Scope: practical, schema-level migration from the three most common
> incumbent stacks — **Diligent Boards**, **Boardvantage / Nasdaq Directors
> Desk**, and **SharePoint-based ad-hoc portals** — onto the Decidiq
> `Board`, `BoardMember`, `BoardMeeting`, `Resolution`, `Vote`, `Minutes`,
> `ConflictOfInterest`, `BoardMaterial`, and `AuditLogEntry` schemas. The
> guide assumes the target Decidiq instance is already installed
> (`docs/admin/board-portal-admin.md` §1) and the legacy export is in hand.

---

## 1. Migration principles

1. **Lift on slugs, not on legacy IDs** — every Decidiq schema is keyed on
   a slug (`oc_openregister_table_<reg>_<schema>`). Pick the slug at import
   time (e.g. `rvc-strategy-q3`); do not try to preserve legacy GUIDs.
2. **Preserve the audit trail as separate `AuditLogEntry` records** — the
   board-portal audit trail is hash-chained (`docs/Technical/board-portal-architecture.md` §5).
   Imported history goes in as a single migration entry per source record
   (`source: "migration"`, `previousSystem: "diligent"`), not as forged
   contemporaneous events.
3. **Re-sign canonical artefacts where possible** — eIDAS QES does not
   carry across portals. Re-sign the last N years of board minutes on the
   Decidiq side where the relevant directors are still serving; flag the
   older artefacts as `qesLevel: "legacy"` and surface that in the
   regulator-export bundle.
4. **Migrate in waves** — boards → members → meetings → resolutions →
   materials. Earlier waves are blockers for later ones (a Resolution
   requires a BoardMeeting; a BoardMeeting requires a Board; etc.).

---

## 2. Migrating from Diligent Boards

Diligent's `Director` REST API exposes:

- `/api/v1/personas` (members),
- `/api/v1/books` (board-book = meeting + materials bundle),
- `/api/v1/resolutions` (Diligent Resolutions module).

### 2.1 Field mapping

| Diligent field | Decidiq schema.field | Notes |
| --- | --- | --- |
| `persona.id` | `BoardMember.legacyId` (custom) | retained for cross-reference |
| `persona.fullName` | `BoardMember.name` | |
| `persona.role` | `BoardMember.role` | map `chair`, `vice-chair`, `member`, `secretary` |
| `persona.independent` | `BoardMember.independenceStatus` | `true` → `independent`, `false` → `executive` |
| `book.id` | `BoardMeeting.legacyId` (custom) | |
| `book.scheduledStart` | `BoardMeeting.scheduledStart` | ISO 8601 |
| `book.agenda[]` | `AgendaItem` records linked via `boardMeetingId` | one per Diligent agenda entry |
| `book.attachments[]` | `BoardMaterial` records | `accessLevel` defaults to `board-only` |
| `resolution.id` | `Resolution.legacyId` (custom) | |
| `resolution.text` | `Resolution.text` | |
| `resolution.adoptedAt` | `Resolution.decidedAt` | |
| `resolution.votes[]` | `BoardVote` records via `resolutionId` | one per Diligent vote row |

### 2.2 Recipe

1. Export Diligent personas + books + resolutions via the REST API; persist
   as JSON.
2. Run a 2-pass import:
   - **Pass 1** — `Board` + `BoardMember` (no dependencies);
   - **Pass 2** — `BoardMeeting` + `AgendaItem` + `BoardMaterial` +
     `Resolution` + `BoardVote` + `ConflictOfInterest` (depend on Pass 1).
3. Drive every write through the OR REST surface (`POST /apps/openregister/api/objects/decidiq/<schema>`),
   not via direct DB writes — this keeps OR's per-object RBAC + audit
   mirrors honest.
4. Verify by walking `GET /api/audit-log` for each imported record's
   `objectUuid` and asserting at least one `migration` entry exists.

### 2.3 Known gaps

- **eIDAS QES** — Diligent uses its in-platform SES; re-sign with QES on
  the Decidiq side for any minutes that are still legally live (typically
  the most recent 7 years for Dutch entities under Article 2:10 BW).
- **Diligent annotations** — board members' personal annotations on
  materials are user-private; they do not migrate. Communicate this to the
  directors *before* the cutover.

---

## 3. Migrating from Boardvantage / Nasdaq Directors Desk

Boardvantage uses a SOAP-ish export bundle (ZIP of XML + PDF). The shape:

- `Members.xml`
- `Meetings.xml`
- `Resolutions.xml`
- `Attachments/<meetingId>/<file>.pdf`

### 3.1 Field mapping (delta from §2)

| Boardvantage field | Decidiq schema.field |
| --- | --- |
| `Members/Member/IndependenceDeclaration` | `BoardMember.independenceStatus` |
| `Meetings/Meeting/QuorumRequired` | `BoardMeeting.quorumRequired` (numeric) |
| `Resolutions/Resolution/Threshold` | `Resolution.threshold` (e.g. `majority`, `two-thirds`, `unanimous`) |
| `Resolutions/Resolution/Outcome` | `Resolution.status` (`adopted`, `rejected`, `withdrawn`) |

### 3.2 Recipe

1. Unzip the Boardvantage bundle locally.
2. Parse the XML files with an XSLT transform (sample at
   `tools/migration/boardvantage-to-decidiq.xslt` — to be added per
   project) into the JSON shape Decidiq's REST surface expects.
3. Same 2-pass import as §2.2.
4. Upload the `Attachments/<meetingId>/*.pdf` set as `BoardMaterial`
   records; the binary itself is delegated to the docudesk leaf (per
   `BoardMaterialController` §4 of the architecture doc).

### 3.3 Known gaps

- **Boardvantage uses `Threshold: "simple-majority"` ambiguously** — verify
  with the corporate secretary whether that means majority of votes cast
  vs. majority of board strength, and set `Resolution.threshold` explicitly.
- **PDF annotations** — same caveat as Diligent (§2.3).

---

## 4. Migrating from SharePoint-based ad-hoc portals

SharePoint board sites are typically a Document Library + a SharePoint List
("Meetings") + an emailed-around minutes Word file. There is no canonical
export; the migration is bespoke.

### 4.1 Recipe

1. **Audit the SharePoint site** — produce a CSV with one row per past
   board meeting (`date`, `agenda items`, `attendees`, `minutes file`,
   `resolutions adopted`). The corporate secretary owns this audit; it is
   the unit of work, not the SharePoint export itself.
2. **Build a Board + roster** — manually configure the current Board and
   BoardMember rows in the Decidiq UI before any history is imported.
3. **Backfill meetings** — for each row in the audit CSV, `POST` a
   `BoardMeeting` record with `lifecycle: "archived"` and the resolutions
   as linked `Resolution` records (`status` = the row's outcome).
4. **Upload minutes** — `BoardMaterial` records pointing at the legacy
   Word/PDF files (`accessLevel: "board-only"`).
5. **Stop accepting board work outside Decidiq** — communicate the
   cutover date; from that date all new board work goes through the
   Decidiq UI.

### 4.2 Known gaps

- **No structured vote records** — SharePoint sites typically do not have
  per-director vote tallies. Backfill the resolutions as `Resolution` rows
  with a single `BoardVote` per director-of-record marked `voteMethod:
  "imported"` and `voteValue: "abstain"` if unknown. Surface the gap in
  the next regulator-export bundle.
- **No conflict declarations** — start the conflict-of-interest register
  cleanly from the cutover date.

---

## 5. Post-migration validation

For every migration path:

1. Run `GET /api/audit-log/{id}/verify` over the head of the audit log;
   assert `checked: true`.
2. Run `POST /api/regulator-exports` over the imported range and audit the
   sha256 against a sample of the source bundle.
3. Walk the `BoardDashboard` KPIs (`board-meetings-this-quarter`,
   `resolutions-this-quarter`, `attendance-current-quarter`,
   `conflicts-active`) and confirm they match the secretary's expected
   numbers for the current quarter.
4. Have the chair sign one fresh test minutes record QES end-to-end
   (`docs/compliance/board-portal-compliance.md` §5) and confirm the
   signature appears on the minutes record and in the audit log.

If any of those four checks fail, do **not** declare the migration done.
