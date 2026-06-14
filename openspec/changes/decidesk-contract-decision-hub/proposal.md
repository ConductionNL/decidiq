# Proposal: decidesk-contract-decision-hub

kind: integration-surface (formalizes decidesk as the fleet decision/sign-off hub) — cites
ADR-005 (Decision as the universal supertype), ADR-006 (mode-adaptation; signature-as-a-method),
hydra ADR-019 (integration-registry), hydra ADR-022 (apps-consume-or-abstractions), and
hydra ADR-031 (schema-declarative-business-logic). Counterpart of the consumer-side changes
`procest-delegate-contract-decision` (procest) and `shillinq-delegate-signing` (shillinq).

## Summary

Decidesk already is the company's canonical decision/approval/signing authority: ADR-005 makes
`Decision` the single universal supertype discriminated by `decisionType` (which already includes
a `contract` value), ADR-006 promotes eIDAS signing to a pluggable **decision method**
(`method=signature`) available to *any* decision in *any* organisation mode, and the
`decision-route` / `decision-methods` capabilities already model multi-stage routes resolved by
vote / chair-register / advice / signature. What is **missing** is the *integration surface* that
lets other fleet apps — finance (shillinq) and case-management (procest) — **delegate** their
contract-approval, contract-renewal and report-sign-off flows to decidesk instead of
reimplementing approval state machines and signing trails locally.

This change formalizes decidesk as the fleet **contract-decision + sign-off hub**. It adds, on top
of the existing Decision model (no rebuild), the generic delegation contract that consuming apps
use through the ADR-019 integration registry:

1. A consuming app raises a **Decision** of an appropriate `decisionType` (e.g. `contract`,
   `contract-renewal`, `report-adoption`/sign-off) carrying a **subject reference** back to the
   originating object (which app, which register/schema, which object id), and later receives the
   **outcome** (approved / rejected / signed + signer identity + decided/signed timestamp + a
   stable decision reference).
2. **Signature-as-a-method**: where a stage requires a document signature, decidesk drives it and
   **delegates the actual document e-signature to docudesk** (per cross-app contract #2), composing
   docudesk's `signingRequest` and storing the returned signing reference on the signature stage —
   it does not implement its own e-signature engine.
3. A defined **integration surface** — create-decision (with subject reference), subscribe-to-outcome
   / outcome callback, and query-decision-status — kept generic so *any* app and *any* `decisionType`
   can consume it. This is the delegation target.
4. An explicit **positioning** of decidesk's Decision against OpenRegister's generic
   `ApprovalChainPanel` / `ApprovalStepList`: the OR approval framework is the lightweight,
   in-place sign-off path for a single object; the decidesk Decision is the richer **governance**
   path (typed route across bodies, quorum, methods, eIDAS, ORI/Popolo publication). This change
   positions them — it does not duplicate or remove the OR framework.

**Boundary (explicit):** decidesk owns the **decision / approval / signing**, NOT the downstream
side effects. Consuming the outcome is the **consumer app's** responsibility — shillinq posts the
GL / advances its bookkeeping lifecycle, procest advances its ZGW case. decidesk emits the outcome
and stops at the boundary.

**Depends on:**
- decidesk `decision-management` (ADR-005 — Decision supertype + `decisionType` incl. `contract`) — **built**.
- decidesk `decision-route` (DecisionStage, polymorphic decision-maker, ambtelijk→politiek bridge) — **built**.
- decidesk `decision-methods` (`method=signature` resolved by eIDAS; `EIDASSignatureService`) — **built**.
- hydra ADR-019 integration-registry (the cross-app transport) — shared.
- docudesk signing capability (`signingRequest` / `signingSession`) — the document e-signature engine this hub composes (cross-app contract #2).
- Consumer counterparts `procest-delegate-contract-decision` and `shillinq-delegate-signing` (they call this surface; this change does not implement their side effects).

## Deduplication rationale (ADR-012)

This change **extends/formalizes** decidesk's existing role; it does NOT rebuild the Decision model.
Phase 0 (see `tasks.md`) documents that the Decision supertype, `decisionType=contract`, decision
routes/stages, and `method=signature` (eIDAS) **already exist and are reused verbatim**. The only
*new* schema surface is a small set of additive fields on the existing `Decision` schema for the
external subject reference + outcome callback + source-app provenance, plus the integration-contract
endpoints. No new Decision / approval / signing entity is introduced (that would violate ADR-005 §
"one schema per concept" and ADR-006 § "Forbidden: a new schema that duplicates an existing
concept"). The document e-signature itself is delegated to docudesk, not re-implemented (contract #2).
