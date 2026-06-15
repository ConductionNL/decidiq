# decidesk-contract-decision-hub Specification

## Purpose
TBD - created by archiving change decidesk-contract-decision-hub. Update Purpose after archive.
## Requirements
### Requirement: REQ-DCDH-001 — Subject reference and provenance on a Decision

The system SHALL extend the existing `Decision` schema with additive, nullable properties that link a
Decision back to the originating object in a consuming app, without breaking existing Decisions:
`sourceApp` (the app that raised it), `subjectRegister` + `subjectSchema` + `subjectId` (the
OpenRegister coordinates of the originating object), `subjectLabel` (human display label),
`outcomeCallbackUrl` (optional registry callback target), and `externalReference` (the consumer's own
reference for idempotency/linking). The `decisionType` enum SHALL additively gain `contract-renewal`
and `report-adoption` (the existing `contract` value is reused). No new Decision/approval/signing
entity SHALL be introduced (ADR-005/006); these are schema metadata only (ADR-031), fragment-located
per ADR-037, with no required field added so existing decisions stay valid.

#### Scenario: A finance app raises a contract decision with a subject reference

- GIVEN shillinq holds a `Contract` object that requires board approval
- WHEN shillinq raises a decidesk Decision with `decisionType=contract`, `sourceApp=shillinq`,
  `subjectRegister=shillinq`, `subjectSchema=Contract`, `subjectId=<uuid>`, and a `subjectLabel`
- THEN the Decision is stored with those provenance fields populated
- AND the decidesk Decision detail shows a read-only "raised by" provenance block resolving to that
  originating object

#### Scenario: Existing decisions remain valid after the additive delta

- GIVEN Decisions created before this change with none of the new fields set
- WHEN the updated `Decision` schema version is imported
- THEN those Decisions validate unchanged (all new fields are nullable, no required field added)

#### Scenario: Report sign-off uses report-adoption

- GIVEN a consumer raises a Decision to adopt/sign off a report (e.g. an ACM report)
- WHEN it sets `decisionType=report-adoption`
- THEN the value is accepted by the enum and the Decision behaves as any other typed Decision

---

### Requirement: REQ-DCDH-002 — Generic create-decision integration endpoint

The system SHALL expose `POST /index.php/apps/decidesk/api/v1/decisions` so any fleet app can raise a
Decision of any `decisionType`, supplying the REQ-DCDH-001 subject reference and provenance. The
endpoint SHALL follow the `p4-integration` conventions (auth posture declared via a Nextcloud
attribute, consistent error envelope). The endpoint SHALL be **idempotent** on the tuple
`(sourceApp, subjectRegister, subjectSchema, subjectId, externalReference)` — a repeated raise for the
same subject returns the existing Decision rather than creating a duplicate. The endpoint SHALL NOT be
a pass-through wrapper of OpenRegister's ObjectService (ADR-022); it composes the Decision (incl. an
optional route/method) and returns its id.

#### Scenario: Create a contract decision from procest

- GIVEN procest manages a ZGW case for a contract award
- WHEN procest POSTs to `/api/v1/decisions` with `decisionType=contract` and the case as the subject
- THEN a Decision is created in the decidesk register with the provenance fields set
- AND the response returns the stable `decisionId`

#### Scenario: Idempotent re-raise returns the same decision

- GIVEN a Decision already exists for `(shillinq, shillinq, Contract, <uuid>, REF-42)`
- WHEN shillinq POSTs the same subject tuple again
- THEN the existing `decisionId` is returned and no duplicate Decision is created

#### Scenario: Unauthenticated create is rejected

- WHEN a request to `/api/v1/decisions` carries no valid Nextcloud session/registry credential
- THEN the response is HTTP 401 with the standard error envelope and no Decision is created

---

### Requirement: REQ-DCDH-003 — Queryable outcome envelope

The system SHALL expose `GET /index.php/apps/decidesk/api/v1/decisions/{id}/outcome` returning a
stable outcome envelope so a consumer can poll the result of a delegated decision. The envelope SHALL
contain `decisionId`, `decisionType`, a `status` derived from the existing `Decision.lifecycle` +
`Decision.outcome` (`approved` when lifecycle is `decided`/`enacted` with an adopting outcome,
`rejected` for a rejecting outcome, `withdrawn` for the withdrawn lifecycle, otherwise `pending`),
`decidedAt`, `signed` (true when a `method=signature` stage is resolved), `signingReference`,
`signedAt`, a `signers` array (resolved from `Minutes.signedBy` / docudesk signer records), and the
echoed subject reference + `externalReference`. `status` SHALL be DERIVED, introducing no new state
machine (ADR-031).

#### Scenario: Approved + signed contract reports a complete outcome

- GIVEN a `decisionType=contract` Decision whose route is decided with `outcome=adopted` and whose
  `method=signature` stage is resolved
- WHEN a consumer GETs `/api/v1/decisions/{id}/outcome`
- THEN the envelope reports `status=approved`, `signed=true`, a non-null `signingReference`, `signedAt`,
  and the `signers` array

#### Scenario: Rejected decision reports a rejected outcome

- GIVEN a Decision whose route concluded with a rejecting outcome
- WHEN the outcome is queried
- THEN the envelope reports `status=rejected` and `signed=false`

#### Scenario: Pending decision reports a pending outcome

- GIVEN a Decision still in an in-progress lifecycle
- WHEN the outcome is queried
- THEN the envelope reports `status=pending` with no `decidedAt`

---

### Requirement: REQ-DCDH-004 — Subscribe to outcome via registry callback

The system SHALL expose `POST /index.php/apps/decidesk/api/v1/decisions/{id}/subscriptions` so a
consumer can register an outcome callback (push delivery), complementing polling (REQ-DCDH-003). When
the Decision reaches a terminal outcome, the system SHALL dispatch the outcome envelope to the
registered callback via the ADR-019 integration registry. The callback target SHALL be validated
against the registry's known consumer entry — arbitrary URLs SHALL be rejected (anti-SSRF). The
callback dispatch SHALL be declared via `x-openregister-notifications` where possible (ADR-031),
avoiding imperative leaf-app dispatch.

#### Scenario: Outcome is pushed to a registered consumer on terminal status

- GIVEN shillinq has registered a callback for a `decisionType=contract` Decision
- WHEN that Decision becomes `decided` with `outcome=adopted`
- THEN decidesk dispatches the outcome envelope to shillinq's registry callback

#### Scenario: Callback to a non-registry URL is rejected

- WHEN a subscription request supplies a callback URL not matching any registry consumer entry
- THEN the subscription is rejected and no callback is ever dispatched to that URL

---

### Requirement: REQ-DCDH-005 — Signature method delegates document signature to docudesk

For a Decision stage with `method=signature`, the system SHALL delegate the actual document
e-signature to **docudesk** (the canonical document e-signature owner, cross-app contract #2) by
composing a docudesk `signingRequest` from the stage's `signedDocument` (DigitalDocument) and the
resolved signatories, via the ADR-019 integration registry, and SHALL store the returned signing
reference on the signature stage. When docudesk reports the signing complete, the existing
`EIDASSignatureService` stage-resolution seam (`resolveSignatureStage()`) SHALL resolve the stage
(`outcome=adopted` + `decidedAt` + link the signed document) — unchanged behaviour, new transport.
The system SHALL retain openconnector's `e-sign` Source as a fallback provider when docudesk is
absent, selecting the provider via the registry. The signature method SHALL fail **closed** (the
stage stays unresolved) when no signing provider is available — it SHALL NOT silently mark a document
signed. decidesk SHALL NOT implement its own document e-signature engine.

#### Scenario: A signature stage composes a docudesk signing request

- GIVEN a `method=signature` stage on a contract Decision with a `signedDocument` and signatories
- WHEN the signature method runs with docudesk available
- THEN decidesk composes a docudesk `signingRequest` via the registry and stores the returned signing
  reference on the stage

#### Scenario: Completed docudesk signing resolves the stage

- GIVEN docudesk reports the `signingRequest` complete
- WHEN `EIDASSignatureService.resolveSignatureStage()` runs
- THEN the signature stage is set `outcome=adopted` with `decidedAt`, the signed document is linked,
  and the outcome envelope reports `signed=true` with the signing reference

#### Scenario: No signing provider fails closed

@e2e exclude backend integration contract — no-provider failure path is covered by PHPUnit, not a UI flow

- GIVEN neither docudesk nor the openconnector e-sign Source is available
- WHEN a `method=signature` stage is run
- THEN the stage stays unresolved and the document is NOT marked signed

---

### Requirement: REQ-DCDH-006 — Position decidesk Decision vs OpenRegister ApprovalChain

The system SHALL document, and consumers SHALL choose between, two approval paths without
duplication: OpenRegister's generic `ApprovalChainPanel`/`ApprovalStepList` is the lightweight,
in-place, single-object sign-off (no governance route, no bodies/quorum, no eIDAS, no ORI/Popolo
publication); the decidesk Decision is the richer governance path (typed multi-stage route across
Persons/GovernanceBodies, quorum + chair rules, pluggable methods incl. eIDAS signature via docudesk,
audit hash-chain, ORI/Popolo/OpenCatalogi publication). A consumer SHALL use the decidesk hub when it
needs a governance decision, a document signature, or a cross-body route; otherwise it MAY stay on the
OR ApprovalChain. This change SHALL add no code to OpenRegister and SHALL NOT remove or wrap the OR
approval framework.

#### Scenario: A cross-body contract approval routes to the decidesk hub

- GIVEN a contract approval that must pass an ambtelijk preparing body then a politiek deciding body
  and be signed
- WHEN the consumer chooses an approval path
- THEN it raises a decidesk Decision (route + `method=signature`) rather than the OR ApprovalChain

#### Scenario: A trivial single-object sign-off stays on the OR ApprovalChain

- GIVEN a simple "needs one approval" on a single object with no governance/signing requirement
- WHEN the consumer chooses an approval path
- THEN it MAY use the OR ApprovalChain and need not raise a decidesk Decision

---

### Requirement: REQ-DCDH-007 — Own the decision only, not downstream side effects

The system SHALL emit the decision outcome (via REQ-DCDH-003 query and REQ-DCDH-004 callback) and
SHALL stop at that boundary. decidesk SHALL NOT post to a finance ledger, SHALL NOT advance a ZGW
case, and SHALL NOT mutate any consuming app's lifecycle. Consuming the outcome is the consumer app's
responsibility — shillinq posts the GL / advances its bookkeeping lifecycle, procest advances its ZGW
case — owned by `shillinq-delegate-signing` and `procest-delegate-contract-decision` respectively.

#### Scenario: decidesk does not post the consumer's side effects

- GIVEN a `decisionType=contract` Decision becomes `decided` with `outcome=adopted`
- WHEN the outcome is emitted to shillinq
- THEN decidesk performs no GL posting and no case transition itself; shillinq's delegate change
  consumes the outcome and posts the GL

#### Scenario: Side-effect ownership is documented for both consumers

- GIVEN the hub is consumed by both shillinq and procest
- WHEN a contract decision concludes
- THEN decidesk emits the same outcome envelope to each, and each consumer (shillinq GL post, procest
  ZGW advance) owns its own downstream action

