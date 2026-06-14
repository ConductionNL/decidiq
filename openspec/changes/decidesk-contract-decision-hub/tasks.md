# Tasks — Decidesk contract-decision + sign-off hub

## Phase 0: Deduplication Check (ADR-012)

Document what already exists so this change EXTENDS the hub rather than rebuilding the Decision model.

- [x] **Decision supertype already exists.** `decision-management` spec + ADR-005 define `Decision`
  as the universal supertype with `decisionType` discriminator (already includes the `contract`
  value), declarative `lifecycle` (draft→proposed→deliberating→voting→decided→enacted→archived),
  `outcome`, and a hash-chained audit log. → **REUSED verbatim.** This change adds NO new
  decision/approval entity.
- [x] **Decision route/stages already exist.** `decision-route` spec defines `DecisionStage`
  (sequence/stageType/status/outcome/method), polymorphic `Person`/`GovernanceBody` assignment, and
  the ambtelijk→politiek bridge. → **REUSED** for the approval path (single-stage sign-off or
  multi-stage governance route).
- [x] **Signature-as-a-method already exists.** `decision-methods` spec defines
  `method ∈ {manual, vote, signature, chair-register, advice}`; `method=signature` is resolved by
  `EIDASSignatureService` (`lib/Service/`), available to ANY decision in ANY mode (ADR-006). →
  **REUSED**; this change only retargets the document-signature *transport* onto docudesk (contract #2).
- [x] **eIDAS service already exists.** `lib/Service/EIDASSignatureService.php` composes the e-sign
  call (currently via openconnector's `e-sign` Source) and exposes the `resolveSignatureStage()`
  seam. → **MODIFIED** to add the docudesk `signingRequest` path; openconnector kept as fallback.
- [x] **Public API foundation already exists.** `p4-integration` (`REQ-API-001..003`) provides the
  versioned `/api/v1/` REST surface, pagination, error envelope and auth posture. → **EXTENDED** with
  the three integration endpoints (same conventions), NOT a new API stack.
- [x] **Confirmed ABSENT (the genuine net-new surface):** no external **subject-reference / provenance**
  fields on `Decision` (verified: schema props carry no `sourceApp`/`subjectRegister`/`subjectId`/
  `outcomeCallbackUrl`); no **create-decision-from-external-app** endpoint; no **outcome callback /
  subscribe** surface; no **docudesk** reference anywhere in decidesk; no integration-registry (ADR-019)
  consumer surface. These are what this change adds.
- [x] **No overlap with OR ApprovalChain.** OpenRegister's generic `ApprovalChainPanel`/
  `ApprovalStepList` is the lightweight in-object sign-off; decidesk Decision is the richer governance
  path. This change POSITIONS them (REQ-DCDH-006) and adds no code to OR.
- [x] **Conclusion:** dedup clean. Net-new = additive `Decision` fields + 3 integration endpoints +
  docudesk signing transport + positioning note. No Decision-model rebuild, no parallel entity (ADR-005/006).

## Phase 1: Schema delta — subject reference, provenance, callback (ADR-031, ADR-037)

- [ ] Add additive, nullable fields to the `Decision` schema in the decidesk register fragment:
  `sourceApp`, `subjectRegister`, `subjectSchema`, `subjectId`, `subjectLabel`, `outcomeCallbackUrl`,
  `externalReference` (REQ-DCDH-001).
- [ ] Add `decisionType` enum values `contract-renewal`, `report-adoption` (additive; `contract`
  already present) (REQ-DCDH-001).
- [ ] Bump the `Decision` schema `version` (additive change, no migration) and confirm existing
  decisions still validate (no required field added).
- [ ] Declare the provenance block render rule (read-only "raised by" block on the detail relations
  tab, progressive disclosure ADR-004 Rule 2) — schema metadata, no PHP.

## Phase 2: Integration surface — create / query / subscribe (ADR-019, ADR-022)

- [ ] Add `lib/Controller/IntegrationController.php` with `POST /api/v1/decisions`
  (create-decision-with-subject, idempotent on the provenance tuple + `externalReference`) (REQ-DCDH-002).
- [ ] Add `GET /api/v1/decisions/{id}/outcome` returning the outcome envelope (REQ-DCDH-003).
- [ ] Add `POST /api/v1/decisions/{id}/subscriptions` to register an outcome callback against a
  registry-known consumer (REQ-DCDH-004).
- [ ] Register routes in `appinfo/routes.php`; each method declares its auth attribute
  (`#[NoAdminRequired]` etc.) and a per-object/admin guard (route-auth + no-admin-IDOR gates).
- [ ] Add `lib/Service/DecisionIntegrationService.php` — assembles the outcome envelope from existing
  `Decision`/`DecisionStage`/`Minutes`/signer data and derives `status`/`signed`/`signers`
  declaratively; dispatches the registry callback. NO ObjectService CRUD wrapper (ADR-022).
- [ ] Validate the callback URL against the registry consumer entry (anti-SSRF); reject arbitrary URLs.

## Phase 3: Signature method delegates to docudesk (contract #2, ADR-019)

- [ ] In `lib/Service/EIDASSignatureService.php`, add the docudesk path: compose a docudesk
  `signingRequest` from the `method=signature` stage's `signedDocument` + resolved signatories, via
  the ADR-019 registry; store the returned signing reference on the stage (REQ-DCDH-005).
- [ ] On docudesk completion, resolve the signature stage via the existing `resolveSignatureStage()`
  seam (`outcome=adopted` + `decidedAt` + link signed document) — unchanged behaviour, new transport.
- [ ] Keep openconnector's `e-sign` Source as the fallback when docudesk is absent; select the
  provider via the registry. Fail CLOSED if neither is available (no silent "signed") (REQ-DCDH-005).
- [ ] Surface `signingReference` / `signedAt` / `signers` in the outcome envelope (REQ-DCDH-003).

## Phase 4: Positioning + boundary

- [ ] Document the decidesk-Decision vs OR-ApprovalChain choice in the decision-hub spec
  (REQ-DCDH-006); add no code to OR.
- [ ] Confirm the boundary in the spec: decidesk emits the outcome and stops; consumers
  (shillinq GL post, procest ZGW advance) own the side effects (REQ-DCDH-007).

## Phase 5: Notifications & docs

- [ ] Declare the outcome notification + registry callback dispatch via `x-openregister-notifications`
  (ADR-031), avoiding imperative leaf dispatch.
- [ ] Update the decidesk integration/API docs to list decidesk as the fleet contract-decision +
  sign-off hub and document the three endpoints + outcome envelope for consumer apps.

## Phase 6: Tests

- [ ] PHPUnit: `DecisionIntegrationService` outcome-envelope derivation (approved/rejected/withdrawn,
  signed/unsigned, signers from `Minutes.signedBy`).
- [ ] PHPUnit/Newman: create-decision idempotency on the provenance tuple; callback dispatched only to
  registry consumers; signature method fails closed when no signing provider is available.
- [ ] Newman: `/api/v1/decisions` create + `/api/v1/decisions/{id}/outcome` query against a live register.
- [ ] e2e: the "raised by" provenance block renders on the Decision detail relations tab.
