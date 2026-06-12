# Board portal — compliance guide

> Audience: corporate secretaries, compliance officers, internal audit, and the
> regulators (DNB, AFM, ACM, local supervisory bodies) of organisations that
> operate the Decidesk board portal.
>
> Scope: how Decidesk's shipped feature surface maps onto the **Dutch Corporate
> Governance Code (MCCG, December 2022 revision)**, the **eIDAS Regulation
> (EU 910/2014)** and its successor **eIDAS 2 (EU 2024/1183)**, the
> **regulator-export obligations** that follow from sector law, and the
> **minutes signature process**.
>
> This document is a *configuration / control mapping*. It is not a legal
> opinion — operators remain responsible for the regulatory determinations
> that apply to their organisation.

---

## 1. MCCG alignment

The Dutch Corporate Governance Code revision of December 2022 (MCCG-2022) sets
out the conduct expectations for boards of listed and large unlisted Dutch
entities ("rvc" + "rvb" + audit/remuneration/nomination committees). The
mapping below identifies, for every MCCG principle that has a *systems*
component, the Decidesk feature that operationalises it.

| MCCG principle | Decidesk feature | Concrete code surface |
| --- | --- | --- |
| **2.7** — independence of supervisory directors | `BoardMember.independenceStatus` + Independence ratio trend on `BoardDashboard` | `src/manifest.d/board-portal.json#independence-ratio-trend` (8-quarter window) |
| **2.7.2 / 2.7.3** — declaration of conflicts of interest | `ConflictOfInterestController::declare` + `recordAction` | `POST /api/conflicts`, `PUT /api/conflicts/{id}/action`; service: `lib/Service/ConflictOfInterestService.php` |
| **2.7.4** — recusal from deliberation + vote when conflicted | `ResolutionLifecycleGuard::canCastVote()` (refuses cast when an active conflict marks the caller as `recused-from-vote`) | `lib/Lifecycle/ResolutionLifecycleGuard.php` |
| **2.3** — composition + diversity reporting | `GovernanceReportingService::generateAnnualReport()` includes board-composition counters | `lib/Service/GovernanceReportingService.php` |
| **3.4** — minutes of meetings (kept available to the body of the company) | `Minutes` schema (`signedAt`, `signedBy`, `hash`) + minutes-signature flow (§3) | `EIDASSignatureController::initiate / verify / finalize`; `lib/Service/WrittenResolutionService.php` for written-procedure resolutions |
| **3.4.2** — adopting resolutions outside meetings (written procedure) | `WrittenResolutionService::initiate / collectSignature / finalize` | `lib/Service/WrittenResolutionService.php` |
| **5.1** — internal audit + audit committee access | Audit-log query + verify + export endpoints | `GET /api/audit-log`, `GET /api/audit-log/{id}/verify`, `GET /api/audit-log/export`; `lib/Service/AuditLogService.php` (hash-chain) |
| **5.2** — external auditor receives + retains supporting records | `RegulatorExportService::generate` (`exportFormat: pdf|csv`) | `POST /api/regulator-exports`; `lib/Service/RegulatorExportService.php` |

The MCCG controls that are *people / governance / policy* (e.g. the chair's
duty to set the agenda) are not in scope for this systems guide; they are
addressed in the user guide `docs/Features/board-portal.md`.

---

## 2. eIDAS compliance — qualified electronic signatures

Decidesk uses the openconnector e-sign adapter to drive **QES (Qualified
Electronic Signature)** flows per eIDAS Article 25(2). QES is the only
signature level that carries the legal equivalence of a hand-written
signature across all EU member states, and it is the level the board-portal
spec requires for adopted minutes and signed written resolutions.

### 2.1 Signature levels recognised

| Level | Decidesk handling |
| --- | --- |
| **SES** — simple electronic signature | Rejected for minutes + written-procedure resolutions; logged as failure |
| **AdES** — advanced electronic signature | Rejected for minutes + written-procedure resolutions; logged as failure |
| **AdES-QC** — advanced with qualified certificate | Rejected for minutes + written-procedure resolutions; logged as failure |
| **QES** — qualified electronic signature | Accepted; verified end-to-end (§2.2) |

The level test is enforced by `lib/Lifecycle/QesGuardTest.php` (and the
underlying guard at `lib/Lifecycle/QesGuard.php`). A signature that does not
carry a `qualified=true` flag in the openconnector callback is rejected by
the controller — the lifecycle transition does not proceed.

### 2.2 QES verification chain

The verification logic lives in `lib/Service/EIDASSignatureService.php`:

1. **Initiate**: `POST /api/minutes/{minutesId}/eidas/initiate` — Decidesk
   creates a signature-bag record, persists the minutes-hash to be signed,
   and delegates to openconnector's e-sign provider to obtain a redirect URL
   for the signer.
2. **Sign + verify**: the signer completes the signing flow at the provider;
   `POST /api/minutes/{minutesId}/eidas/verify` re-fetches the signature
   status and validates the certificate against the **EU Trusted List
   (LOTL)**, the qualification flag, and the OCSP / CRL revocation chain.
3. **Finalize**: `POST /api/minutes/{minutesId}/eidas/finalize` mirrors the
   signature artefact onto the minutes record (`signedAt`, `signedBy`,
   `certificateThumbprint`, `qesLevel`) and appends an immutable audit-log
   entry (`AuditLogService::logSignatureAdded()`).

All three steps are mirrored to the audit log; tampering with any of the
mirrored records breaks the hash chain (§4).

### 2.3 eIDAS 2 readiness

eIDAS 2 (EU 2024/1183) introduces the **European Digital Identity Wallet
(EUDIW)** and brings remote QES into mainstream use. The
`EIDASSignatureController` surface is provider-agnostic — it only depends on
the openconnector `e-sign` interface contract — so EUDIW provider rollout
becomes an openconnector configuration change rather than a Decidesk code
change.

---

## 3. Audit-trail export for regulators

### 3.1 What is exported

`POST /api/regulator-exports` (admin only — `RegulatorExportController` is
gated by `IGroupManager::isAdmin`) generates a self-contained bundle of:

- every **Resolution** in scope (by board + date range), including amend
  history, voting tally, and signed-minutes references;
- every **BoardMinutes** record in scope, including signature artefacts
  (`signedAt`, `signedBy`, `certificateThumbprint`, `qesLevel`);
- every **AuditLog** entry in scope, including the linked-list hash chain so
  the regulator can independently verify integrity (§4).

### 3.2 Formats

- **PDF/A** — self-contained PDF 1.4 skeleton with the docudesk leaf as the
  optional formatter; sha256 of the binary is appended to the audit log.
- **CSV** — flat row-oriented dump (one file per record type) when the
  regulator prefers tabular analysis (audit, ACM data-room workflows).

### 3.3 Persistence + reproducibility

Every generated export persists a `regulator-export` record (sha256, record
count, requesting user, generation timestamp). `GET /api/regulator-exports`
lists them; `GET /api/regulator-exports/{id}` deterministically re-renders
the same bytes — the regulator can therefore verify months later that the
export they received is bit-identical to the one Decidesk has on record.

### 3.4 Sector-specific obligations

| Regulator | Typical demand | Decidesk fit |
| --- | --- | --- |
| **DNB** (banking / insurance prudential supervisor) | Board minutes + audit-committee records on demand, in PDF/A | `regulatorExport.generate` with `format=pdf` |
| **AFM** (financial markets conduct supervisor) | Decisions + voting tallies + conflict declarations, CSV friendly | `regulatorExport.generate` with `format=csv` |
| **ACM** (competition + consumer authority) | Decision history of the board organ for a specific market | `regulatorExport.generate` scoped to a date range |
| **NZa** (healthcare provider supervision) | Decision file for governance investigations | `regulatorExport.generate` scoped by board |

Operators add the regulator's identifier to the export request payload (`scope.regulator`)
so the audit-log mirror records which regulator received the bundle.

---

## 4. Audit-trail immutability

The audit-trail integrity guarantee is the spine of every regulatory claim
the board portal makes. It is documented in detail in
`docs/Technical/board-portal-architecture.md` §5; the salient compliance
properties are:

- **Append-only** — `AuditLogService::append()` is the only write path;
  there is no UPDATE / DELETE statement against `oc_openregister_table_*_board-audit-log-entry`.
- **Hash-chained** — every entry carries `previousHash`, recomputed in
  application code on every append; `GET /api/audit-log/{id}/verify` walks
  the chain and reports `checked` vs `tampered`.
- **Independently verifiable** — the regulator-export bundle (§3) includes
  the chain so the regulator can re-verify outside the Decidesk instance.

Tampering tests are exercised by `tests/Unit/Service/AuditLogServiceTest.php`
(append + verify + tamper-detection) and
`tests/Unit/Controller/AuditLogControllerTest.php`.

---

## 5. Minutes signature process

The end-to-end minutes flow is:

1. **Draft** — secretary creates the minutes record (`Minutes.lifecycle = draft`).
2. **Review** — chair reviews; redline-by-comment via the OR per-object
   comment surface (the Notulen review tab in the user guide).
3. **Approve** — chair approves; `Minutes.lifecycle` transitions to `approved`.
4. **Sign** — secretary calls `POST /api/minutes/{minutesId}/eidas/initiate`;
   the chair completes the QES signing at the provider; the system
   `verify`s, then `finalize`s.
5. **Distribute** — minutes are visible to the board roster at the
   `Minutes.accessLevel` granted (see admin runbook §3 for access levels).
6. **Archive** — when the board meeting concludes its decision file (e.g.
   yearly), the secretary triggers a `regulator-export` for the audit
   committee + external auditor.

Each transition is mirrored to the audit log; the signature artefacts are
the legally binding record under eIDAS Art. 25(2) (§2).

---

## 6. Compliance review checklist

A pragmatic check the corporate secretary can walk before every board cycle:

- [ ] Independence ratio on `BoardDashboard` is ≥ the threshold the bylaws require.
- [ ] Every BoardMember with an active `ConflictOfInterest` has a recorded `actionTaken`.
- [ ] Every BoardMeeting has minutes in `signed` lifecycle within the bylaws-defined deadline.
- [ ] Every adopted resolution has a tallied vote record (`boardVote#tally`).
- [ ] Every written-procedure resolution has unanimous QES signatures
      (`WrittenResolutionService::finalize` refuses to finalize otherwise).
- [ ] Audit-log verification (`auditLog#verify`) of the last 200 entries returns `checked`.
- [ ] Last regulator-export bundle is on file with the audit committee.

This list is the input to the independent security audit (see §10.10 of the
board-meeting-resolutions tasks file).

An **internal partial security review** of the same surfaces (audit-trail
immutability, RBAC, eIDAS QES integration) was authored in W33 and lives
at `docs/security/board-portal-internal-security-review.md`. The internal
review is NOT a substitute for the external audit — it exists to document
the security posture while the external auditor engagement is still
pending. §10.10 stays `[~]` until the external auditor's findings letter
lands at `docs/compliance/audit-letters/`.
