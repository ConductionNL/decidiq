# Design — decidesk contract-decision + sign-off hub

## Goal

Turn decidesk's already-built universal Decision model into a **consumable delegation target** for
finance/case apps, through the ADR-019 integration registry, without rebuilding any of the Decision
model. The change is almost entirely an *interface* + a *small additive schema delta*; the decision
engine, routes, methods and eIDAS wiring already exist.

## What already exists (reused verbatim — see Phase 0)

| Existing capability | Reused for |
|---|---|
| `decision-management` — `Decision` supertype, `decisionType` enum incl. `contract`, declarative `lifecycle` (draft→…→decided→enacted), `outcome`, hash-chained audit | The thing a consumer raises and the outcome it reads back |
| `decision-route` — `DecisionStage`, polymorphic `Person`/`GovernanceBody` assignee, ambtelijk→politiek bridge | The approval *path* (one-stage for a simple sign-off, multi-stage for a governance route) |
| `decision-methods` — `method ∈ {manual, vote, signature, chair-register, advice}`; `method=signature` resolved by `EIDASSignatureService` | The "this contract must be signed" stage |
| `EIDASSignatureService` (`lib/Service/`) — composes the e-signature call, resolves the signature stage on completion | The signing engine seam (retargeted onto docudesk — see below) |
| `p4-integration` — versioned public REST API `/api/v1/`, pagination, error envelope, auth posture | The API foundation the new endpoints extend (same conventions) |

## Key decisions

### D1 — Generic delegation, discriminated by `decisionType` (not per-consumer endpoints)

There is exactly **one** create-decision integration endpoint. A consumer picks the `decisionType`
(`contract`, `contract-renewal`, `report-adoption`, `appointment`, `policy`, `management-point`, …)
and supplies a subject reference. We do NOT add `contract-approval` / `sign-off` siblings to the
Decision model — `decisionType=contract` (ADR-005) already covers contract decisions; renewal and
report-adoption are added as additive enum values, not new schemas. This keeps the hub usable by any
app for any decision, exactly the ADR-005 universal framing.

### D2 — Additive subject-reference + provenance + callback fields on `Decision` (ADR-031, ADR-037)

The current `Decision` schema (`lib/Settings/decidesk_register.json`) has no field that points back
to the originating object in another app. We add a small declarative block of **additive, nullable**
properties so existing decisions stay valid:

| Field | Type | Purpose |
|---|---|---|
| `sourceApp` | string (enum-ish: `shillinq`, `procest`, …, free for new consumers) | Which app raised the decision (provenance / routing of the callback). |
| `subjectRegister` | string | OR register of the originating object (e.g. `shillinq`, `procest`). |
| `subjectSchema` | string | OR schema of the originating object (e.g. `Contract`, `ACMReport`, ZGW `zaak`). |
| `subjectId` | string (uuid) | The originating object's id — the back-reference. |
| `subjectLabel` | string | Human label for display in the decidesk list/detail (e.g. "Lease contract 2027 — pand X"). |
| `outcomeCallbackUrl` | string (nullable) | Optional registry callback target for push delivery of the outcome (consumers may also poll). |
| `externalReference` | string (nullable) | Consumer's own reference for idempotency / their ledger linking. |

These are schema metadata only — no PHP. They render on the Decision detail "relations" tab as the
"raised by" provenance block (progressive disclosure, ADR-004 Rule 2), and the existing typed
relations (`supersedes`/`amends`/…) are untouched.

### D3 — Outcome shape (the contract the consumer reads)

When a Decision reaches a terminal lifecycle (`decided`/`enacted`, or `withdrawn`/rejected), the hub
exposes a stable **outcome envelope**:

```
{
  "decisionId":      "<uuid>",           // stable decidesk reference
  "decisionType":    "contract",
  "status":          "approved|rejected|withdrawn",   // derived from lifecycle+outcome
  "decidedAt":       "<iso8601>",
  "signed":          true|false,         // any method=signature stage resolved
  "signingReference":"<docudesk signingRequest id|null>",
  "signedAt":        "<iso8601|null>",
  "signers":         [ { "personId": "...", "name": "...", "signedAt": "..." } ],  // from Minutes.signedBy / docudesk signerRecords
  "subjectRegister": "...","subjectSchema":"...","subjectId":"...",
  "externalReference":"..."
}
```

`status` is **derived** from the existing `Decision.lifecycle` + `Decision.outcome` — no new state
machine. `approved` = lifecycle `decided`/`enacted` with `outcome=adopted`; `rejected` = a rejecting
outcome; `withdrawn` = the withdrawn lifecycle.

### D4 — Signature method delegates the document e-signature to docudesk (contract #2)

Today `EIDASSignatureService` composes the e-sign call via **openconnector's `e-sign` Source**
(`ESIGN_SOURCE_SLUG`). Cross-app contract #2 makes **docudesk** the canonical document e-signature
owner (`signingRequest`/`signingSession`/`signerRecord`/`signingAuditEntry`). This change defines the
seam so a `method=signature` stage **composes docudesk's `signingRequest`** through the ADR-019
integration registry:

- decidesk builds a docudesk `signingRequest` from the stage's `signedDocument` (the DigitalDocument)
  + the resolved signatories (`Minutes.signedBy` / stage assignees), and hands docudesk the document
  and signing mode/level.
- docudesk runs the actual eIDAS signing session and returns a **signing reference**
  (`signingRequest` id) + status; decidesk stores that reference on the signature stage and surfaces
  it in the outcome envelope (D3 `signingReference`).
- When docudesk reports the signature complete, the existing `EIDASSignatureService`
  stage-resolution seam (`resolveSignatureStage()`) sets the stage `outcome=adopted` + `decidedAt`
  and links the signed document — *unchanged behaviour, new transport.*

decidesk does **not** implement signing UI/engine; it **orchestrates** docudesk for the document
signature and keeps ownership only of the *decision* that the signature resolves. (The
openconnector path remains as a fallback Source where docudesk is absent; docudesk is preferred when
present — same `IEIDASSignatureService` contract, provider selected by the registry.)

### D5 — Positioning vs OpenRegister `ApprovalChainPanel` (not duplicated)

OR ships a generic `ApprovalChainPanel`/`ApprovalStepList` for lightweight in-object sign-off. We do
**not** remove or wrap it. The positioning rule:

- **OR ApprovalChain** — quick, in-place, single-object "needs N approvals" with no governance route,
  no body/quorum semantics, no eIDAS, no ORI/Popolo publication. Good default for trivial approvals.
- **decidesk Decision** — the richer governance path: typed multi-stage route across Persons/Bodies,
  quorum + chair rules, pluggable methods incl. eIDAS signature via docudesk, audit hash-chain, and
  ORI/Popolo/OpenCatalogi publication. Used when the approval is a *governance decision*, when it
  must be signed, or when it crosses bodies (ambtelijk→politiek).

A consumer chooses the hub when it needs governance/signing; it stays on OR ApprovalChain otherwise.
This change documents the choice; it adds no code to OR.

### D6 — Boundary: outcome consumption is the consumer's job

decidesk emits the outcome (callback + queryable status) and **stops**. It does not post to a GL,
does not advance a ZGW case, does not flip a finance lifecycle. shillinq's `shillinq-delegate-signing`
and procest's `procest-delegate-contract-decision` own those side effects. This keeps decidesk a
clean delegation target with no knowledge of finance/case semantics.

## Files to add / modify

- **`lib/Settings/decidesk_register.json`** — additive nullable fields on the `Decision` schema
  (D2) + additive `decisionType` enum values `contract-renewal`, `report-adoption` (D1). Schema
  `version` bump (additive, ADR-031). Per ADR-037 the fragment lives where the register fragments
  live for decidesk.
- **`lib/Controller/IntegrationController.php`** (new) — three endpoints under `/api/v1/`:
  `POST /api/v1/decisions` (create-decision-with-subject), `GET /api/v1/decisions/{id}/outcome`
  (query outcome envelope), `POST /api/v1/decisions/{id}/subscriptions` (register an outcome
  callback). Reuses the `p4-integration` pagination/error/auth conventions. Each method declares its
  auth posture (ADR — route-auth gate) and guards the object (no-admin-IDOR gate).
- **`lib/Service/DecisionIntegrationService.php`** (new, thin) — composes the outcome envelope from
  the existing Decision + DecisionStage + Minutes/signer data and resolves `status`/`signed`/`signers`
  declaratively from existing fields. NOT an ObjectService wrapper (ADR-022) — it only assembles the
  cross-app envelope and dispatches the registry callback; CRUD stays on OR's object surface.
- **`lib/Service/EIDASSignatureService.php`** (modify) — add the docudesk `signingRequest`
  composition path (D4) behind the existing `IEIDASSignatureService` contract; openconnector remains
  the fallback Source.
- **`lib/Notification/` / `x-openregister-notifications`** — declarative outcome notification +
  the registry callback dispatch (ADR-031), no imperative dispatch in a leaf where avoidable.
- Detail UI: the Decision detail "relations" tab shows the "raised by" provenance block (D2) — a
  read-only addition to the existing detail view.

## Alternatives considered

- **Per-consumer endpoints (`/api/v1/contract-decisions`, `/api/v1/sign-offs`)** — rejected; violates
  the ADR-005 universal model and would fork the hub per consumer. One generic create endpoint
  discriminated by `decisionType` instead.
- **A new `ContractDecision` / `SignOff` schema** — rejected; ADR-005/006 forbid a parallel entity for
  a concept the Decision supertype already covers. Additive fields on `Decision` instead.
- **decidesk implementing the e-signature itself** — rejected; contract #2 makes docudesk the
  document e-signature owner. decidesk composes docudesk's `signingRequest`.
- **decidesk performing the GL post / ZGW advance** — rejected; D6 boundary. Side effects belong to
  the consumer apps.

## Migration / rollout

- Schema change is **additive + nullable** → existing Decisions stay valid; no data migration needed.
  The additive `decisionType` enum values do not affect existing objects.
- No existing decidesk object is moved or dropped; if any backfill of provenance on pre-existing
  externally-raised decisions is ever needed it is declared as a `lib/Repair/*` step (none required
  at introduction, since the field is new).
- Rollout is consumer-driven: the surface ships first (decidesk), then shillinq/procest land their
  delegate changes against it. The endpoints are versioned (`/api/v1/`) so the contract is stable.

## Risks

- **Registry availability** — if the ADR-019 registry or docudesk is briefly absent, the signature
  method must fail *closed* (stage stays unresolved), never silently mark a contract signed
  (unsafe-auth-resolver gate). The openconnector fallback Source covers the no-docudesk case
  explicitly, not by silent skip.
- **Callback trust** — outcome callbacks are dispatched only to registry-registered consumers, not to
  arbitrary URLs, to avoid SSRF; `outcomeCallbackUrl` is validated against the registry entry.
- **Idempotency** — `externalReference` lets a consumer de-duplicate a re-raised decision; the create
  endpoint is idempotent on `(sourceApp, subjectRegister, subjectSchema, subjectId, externalReference)`.
