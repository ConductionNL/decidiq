# Design: Public publication via OpenCatalogi

## Context

Three facts shape this design:

1. **The fields already exist but mean nothing.** `Decision.isPublished`/`publishedAt` and `Meeting.isPublic` shipped with the schemas but no spec, no writer, and no reader. This change gives them owners instead of inventing parallel state.
2. **The public route is already decided.** The `citizen-participation` change (same re-evaluation cycle) established decidesk's publication posture: derived summary objects are made anonymously readable via the OpenRegister RBAC published-predicate (public-group `authorization.read` matching `publicatiedatum <= $now`) and are routed into a configured OpenCatalogi catalog; the app serves **no** anonymous pages or read endpoints. This change reuses that posture verbatim — one public story, not two.
3. **Confidentiality has a stronger owner.** The in-flight `board-meeting-resolutions` change builds an entire confidentiality model (access-level enums, regulator scoping, watermarking). Publication must be structurally unable to leak that material.

## Goals / Non-goals

- **Goal:** WOO-grade publication of the three core outputs — decisions, agendas, minutes — with eligibility gates, PII-stripped derived payloads, withdraw/rectify, and full audit.
- **Goal:** ORI-compatible payload shape so raadsinformatie consumers can use the OpenCatalogi/OR public API without a bespoke harvester.
- **Goal:** spec-governed semantics for the dormant `isPublished`/`publishedAt`/`isPublic` fields.
- **Non-goal:** an ORI/OAI-PMH harvester endpoint (OpenConnector follow-up; the payloads are shaped for it).
- **Non-goal:** publishing citizen-participation results (owned by `citizen-participation`), board-governance material (excluded by design), or any auto-publication without an explicit staff action.
- **Non-goal:** an app-local public portal — explicitly the anti-pattern this change exists to avoid.

## Decisions

### D1 — Publish derived payloads, never the live objects

Publication NEVER sets the publication predicate on the live `Decision`/`Meeting`/`Minutes` objects. A `PublicationPayloadService` builds a **derived publication object** per publish action, and only that derived object receives `publicatiedatum`:

- **Decision**: title, decision text, outcome, decisionDate, legalBasis, body name, vote **totals** (for/against/abstain) — never individual votes or voter identities.
- **Agenda**: meeting title/date/location/body plus the ordered agenda items — with items marked confidential (and their document references) stripped.
- **Minutes**: the approved minutes content version, attendance rendered per the body's policy (counts or names-of-role-holders, configured per body), with confidential sections stripped.

Rationale: live objects keep evolving (amendments, follow-ups, soft-deletes); a published government statement must be a stable, versioned artifact. This also makes the PII-strip a construction step rather than a filter that can regress. A `PublicationRecord` object links source → payload version → catalog publication for audit and withdraw/rectify bookkeeping.

### D2 — Eligibility is a hard server-side gate

Publishable iff:

- `Decision`: status `decided` or `enacted` (the formal outcome exists; Awb 3:40 — a decision has no external force before publication, but it must exist before it can be published).
- Agenda: parent `Meeting.isPublic === true` AND convocation sent (the meeting-management lifecycle already tracks notice) — public agendas before notice would leak planning.
- `Minutes`: lifecycle `approved` (the resolution-minutes approval workflow is the source of truth; draft minutes are never public).

**Never publishable, rejected structurally** (type-level deny-list in the service, not per-object flags): `BoardMeeting`, `BoardMinutes`, `BoardMaterial`, `BoardVote`, `ConflictOfInterest`, `BoardAuditLogEntry`, `Vote`, `VotingRound`, and any `Resolution` whose board carries a confidentiality classification. The deny-list lives in one place and the payload service refuses to construct payloads for those types.

### D3 — `isPublished`/`publishedAt` are flow-owned, `Meeting.isPublic` is staff intent

- `Decision.isPublished`/`publishedAt` become **derived outputs of the publication flow**: set on publish, cleared on withdraw, never accepted from client writes (delta on decision-management; enforcement is a server-side guard on the update path, mirroring how `submissionCount` is derived in citizen-participation).
- `Meeting.isPublic` stays a **staff-set intent flag** (it expresses "this body meets in public", Gemeentewet 23) and is an eligibility input, not a publication state.

### D4 — ORI alignment as payload shape, not a new protocol

Every spec already cites OpenRaadsinformatie mappings (`Besluit`, `Vergadering`, `AgendaPunt`, `Verslag`). The publication payloads carry these as structured fields (`oriType`, ORI property names alongside the schema.org ones), so a consumer reading the OpenCatalogi public API or the OR published-predicate surface gets ORI-compatible records without decidesk running a harvester. The OAI-PMH/ORI feed itself is an OpenConnector follow-up — decidesk's job ends at correctly-shaped published data.

### D5 — Withdraw and rectify, never silent edit

- **Withdraw**: staff action with a mandatory reason; sets `depublicatiedatum` on the payload (removing it from the public-group RBAC surface), retracts/depublishes the OpenCatalogi publication, flips `isPublished` back, records actor+reason+timestamp on the `PublicationRecord` and in the decision audit trail. The payload object is soft-retained (audit), not hard-deleted.
- **Rectify**: publishes a NEW payload version that references the version it rectifies (`rectifiesVersion`); the old version is withdrawn in the same transaction. Published payloads are themselves immutable — corrections are visible as corrections (WOO art. 3.7 spirit; same immutability stance as the decision audit trail).

### D6 — OpenCatalogi routing with the citizen-participation degradation contract

Per governance body, admins configure a target catalog. On publish: set `publicatiedatum` on the payload via the normal OR object API (a regular field write on a register-owned object — the public-group `authorization.read` rule on the PublicationPayload schema then makes it anonymously readable while `publicatiedatum <= $now`), then create the publication in the target catalog when OpenCatalogi is installed and configured. Absent OpenCatalogi: predicate still set, catalog step skipped, staff-visible warning — byte-for-byte the same contract as citizen-participation D4 so the two features degrade identically. (Note: `@self.published` is deprecated and removed from OpenRegister; the live model is the RBAC `publicatiedatum` predicate. The earlier "magic-mapper cannot set the predicate" concern was a misdiagnosis — decidesk payloads are register-owned objects on the normal RBAC save path, so the magic-mapper limitation never applied.)

### D7 — Storage, RBAC, notifications: OpenRegister only

`PublicationRecord` lives in the decidesk register; publish/withdraw authority is OR per-object RBAC on the governance body's staff roles (same roles that drive lifecycle transitions elsewhere); notifications are one declarative ADR-031 rule on `PublicationRecord` (`created` → notify body members; `updated` covers withdraw). No imperative dispatch.

## Risks

- **Published-predicate model.** RESOLVED: anonymous visibility uses the OpenRegister RBAC published-predicate — the PublicationPayload schema declares an `authorization.read` rule granting the public group read access while `publicatiedatum <= $now`, and publish sets `publicatiedatum` (a normal field). The earlier "magic-mapper gap" framing was a misdiagnosis: decidesk payloads are register-owned objects on the normal RBAC save path, never magic-mapped, and `@self.published` is deprecated/removed. No app-local public page is needed or used.
- **PII leakage through payload construction bugs.** Mitigation: payloads are built field-by-field allow-list style (D1), never object-copy-minus-fields; Newman asserts the published payload shape negatively (no voter IDs, no member UIDs beyond role-holder names where policy allows).
- **Confidential agenda items leaking into published agendas.** Mitigation: strip-by-default — items publish only when explicitly marked public-eligible; the deny-list test publishes a mixed agenda and asserts absence.
- **Catalog drift** (publication exists in OpenCatalogi but withdraw failed remotely). Mitigation: `PublicationRecord` stores the catalog publication reference; withdraw retries and surfaces failure to staff instead of pretending success.
- **Legal nuance per body type** (municipal WOO vs. association privacy). Mitigation: per-body attendance-rendering policy and per-type publication policy are admin configuration, not hardcoded municipal assumptions.
