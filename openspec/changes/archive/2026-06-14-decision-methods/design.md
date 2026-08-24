# Design: Decision methods

## Context

ADR-005 made `Decision` the universal supertype and its target diagram sketches a resolution layer: `method ∈ vote | secret-vote | chair-registers | sign | advice`. ADR-006 promotes eIDAS signing from a corporate-only feature to a **decision method available to ANY decision regardless of mode**. ADR-031 mandates declarative-first behaviour (`x-openregister-*` in the register, not Service classes). C4 (`decision-route-and-stages`) shipped the `DecisionStage` with a `method` enum **placeholder** (`manual` / `vote` / `sign` / `chair-register` / `advice`) and declarative route-progress on Decision, but nothing connects a stage to the mechanism that resolves it. C3 retargeted `EIDASSignatureService` onto the unified `minutes` / `decision` entities and left a `// TODO Cycle 2` note: "the proper signature decision method … lands in Cycle 2 `decision-methods`". This change is that Cycle 2.

This change models, per `DecisionStage.method`, the **resolution mechanism** and derives the stage `outcome` from it.

## Method → mechanism → outcome map

| `method` | Resolution mechanism | Stage relation(s) | Outcome derivation | Sub-variants |
|---|---|---|---|---|
| `vote` | `VotingRound` (+ `Vote`s) tallies the ballot | `votingRound` (→ VotingRound) | **Declarative** — stage `outcome` derives from `VotingRound.result` (`adopted`/`rejected`/`tied`/`invalid` → mapped) | `isSecret` (anonymous/secret ballot) + `votingMethod` on the VotingRound |
| `chair-register` | The chair records the outcome directly, no ballot | `registeredBy` (→ Person) | **Directly set** — chair sets `outcome` + `decidedAt`; `registeredBy` records who | — |
| `signature` | eIDAS signing (chair + secretary) on a signed document | `signedDocument` (→ DigitalDocument); reuses `Minutes.signedBy` | **Directly set** — `EIDASSignatureService` sets `outcome=adopted` + `decidedAt` when signatures complete | QES via openconnector e-sign |
| `advice` | Non-binding advisory outcome (e.g. raadscommissie advises) | none (no mechanism object) | **Directly set** — `outcome` ∈ {`advised`, `deferred`} set directly | — |
| `manual` | Default fallback — outcome set directly, no mechanism | none | **Directly set** — `outcome` set directly | — |

## Design decisions

### D1 — Coarse method enum + VotingRound config for vote sub-variants (rename `sign`→`signature`)

**Decision:** keep the **coarse** `DecisionStage.method` enum — `manual` / `vote` / `signature` / `chair-register` / `advice` — and express the vote sub-variants (anonymous/secret ballot, personal/general/roll-call/show-of-hands) via the EXISTING `VotingRound.isSecret` (boolean) and `VotingRound.votingMethod` (enum) fields, NOT by expanding the method enum into `open-vote`/`secret-vote`/`general-vote`/… Rename the placeholder value `sign` → `signature` for clarity.

**Rationale:** the product vision's "personal vote, anonymous vote, general vote" are all *votes resolved by a VotingRound* — they differ only in ballot configuration, which `VotingRound` already models (`isSecret`, `votingMethod`, `voteThreshold`, `abstentionHandling`, …). Expanding the method enum would **duplicate** that configuration in two places (on the stage AND on the round) and guarantee drift — exactly the failure ADR-006 forbids ("storing X and X' as different shapes guarantees they drift"). A coarse method tells the UI *which mechanism* to render; the VotingRound tells it *how the ballot is configured*. The `sign`→`signature` rename aligns the value with ADR-006's prose ("a decision method (`signature`)") and reads correctly next to the noun "signature method". **Chosen: coarse enum + VotingRound config; rename `sign`→`signature`.**

### D2 — Stage→mechanism relations, one per method that needs an object

**Decision:** add three optional typed relations to `DecisionStage`:
- `votingRound` (→ VotingRound, many-to-one, optional) — the round that resolves a `method=vote` stage.
- `registeredBy` (→ Person, many-to-one, optional) — the chair who recorded the outcome of a `method=chair-register` stage.
- `signedDocument` (→ DigitalDocument, many-to-one, optional) — the signed document produced for a `method=signature` stage (signatories are read from the related `Minutes.signedBy`).

A **declarative validation note** records the integrity rule: the mechanism relation required by the stage's `method` MUST be present (`vote`⇒`votingRound`, `chair-register`⇒`registeredBy`, `signature`⇒`signedDocument`); `advice`/`manual` require no mechanism relation.

**Rationale:** OpenRegister relations are typed per target schema, so each mechanism gets its own optional relation rather than one polymorphic "mechanism" pointer (the same reasoning as C4's D2 for the polymorphic assignee). Keeping them optional preserves C4's "a stage with no mechanism is valid" property for `manual`/`advice`. Reusing `VotingRound`, `Person`, `DigitalDocument` honours ADR-006 (one schema per concept — no new vote/signature schemas). **Chosen: three optional typed relations + a declarative validation note.**

### D3 — Signature is modelled by a signedDocument + Minutes.signedBy, not a new schema

**Decision:** a `method=signature` stage references a `signedDocument` (DigitalDocument) and reads its signatories from the existing `Minutes.signedBy`; no new "Signature" record schema is added. `EIDASSignatureService` already owns the QES workflow (`initializeSigningRequest`, `verifySignature`, `finalizeMinutes`, `validateCertificateChain`) against minutes/decision.

**Rationale:** ADR-006 explicitly retired the parallel board-* entity set and folded board-material into DigitalDocument and signing into "a decision method available to any decision". Adding a standalone Signature schema would re-introduce a parallel entity for something `Minutes.signedBy` + `DigitalDocument` already capture. The signed artefact is a document; who signed it is `Minutes.signedBy`; the stage points at the document. **Chosen: reuse `signedDocument` (DigitalDocument) + `Minutes.signedBy`; no new schema.** (See DEFERRED_QUESTIONS — a dedicated per-signature record may be warranted if multi-party signature *status* per signatory must be tracked on the stage itself.)

### D4 — Outcome derivation: vote is DECLARATIVE; chair-register/advice/manual/signature are DIRECTLY-SET

**Decision:**
- For `method=vote`, the stage `outcome` is **declarative** — an `x-openregister-calculations` entry on `DecisionStage` maps the linked `VotingRound.result` to the stage outcome (`adopted`→`adopted`, `rejected`→`rejected`, `tied`→`rejected` unless a tie-break applies, `invalid`→no outcome). One source of truth: the round.
- For `method=chair-register`, `method=advice`, and `method=manual`, `outcome` is **set directly** by the actor (chair / advisory body / owner) — no calculation.
- For `method=signature`, `outcome` is set by `EIDASSignatureService` when signing completes (the service writes `outcome=adopted` + `decidedAt`); this is imperative because it is driven by an external signing callback, not a register-resident field.

**Rationale:** ADR-031 prefers declarative derivation where a register-resident field (`VotingRound.result`) is the single source of truth — vote outcome qualifies, mirroring the existing `Decision.currentStage`/`routeComplete` calculations from C4. Chair-register/advice/manual have no upstream register field to derive from (the human *is* the source), so a calculation would have nothing to compute. Signature outcome is driven by an asynchronous external eIDAS result, which a static register calculation cannot observe — so the service writes it. **Chosen: vote declarative; the rest directly-set (signature directly-set by the service).**

### D5 — eIDAS method wiring: C5 MODELS it (config) + a thin code touch on EIDASSignatureService

**Decision:** C5 models signature-as-method via config (the `signedDocument` relation, the `signature` enum value, reuse of `Minutes.signedBy`) AND adds a thin code touch on `EIDASSignatureService` so the service can **resolve a `DecisionStage` of `method=signature`** — i.e. when `finalizeMinutes` completes, locate the related signature stage, link the `signedDocument`, and set the stage `outcome=adopted` + `decidedAt`. This replaces the `// TODO Cycle 2` note in the service with the actual stage wiring.

**Rationale:** the prompt's recommendation and ADR-006's framing both say signing is a real decision method, not just config — leaving it config-only would mean a `method=signature` stage never reaches a resolved outcome (the placeholder problem C5 exists to fix). The retargeted service already has the QES plumbing and a `// TODO Cycle 2` anchor; the minimal honest fix is to make `finalizeMinutes` (or a thin new `resolveSignatureStage`) write the stage outcome. **Code scope is one service method touched** (no new controller, no new transport). This is why the change is `kind: code` — see Mixed-spec rationale.

## Mixed-spec rationale (kind: code)

This change is predominantly register configuration (ADR-031: relations, enum rename, VotingRound retarget, the vote-outcome calculation, seeds) but **touches one PHP method** on `EIDASSignatureService` (D5) to resolve a `method=signature` stage. Per ADR-032, any PHP touch makes the change `kind: code`. Following the Cycle-1 / C4 precedent of supervised local apply, this is delivered as **ONE change** rather than split config/code, because the code touch is meaningless without the config (the `signedDocument` relation + `signature` enum value it writes to) and the config is incomplete without it (a `method=signature` stage would never resolve). The vast majority of the surface is declarative; the lone imperative seam is the externally-driven signing callback that no register calculation can observe (D4).

> If keeping the change config-only is preferred, the alternative is to ship the config (relations + `signature` enum + VotingRound retarget + vote calculation + seeds) as `kind: config` and defer the `EIDASSignatureService` stage-wiring to a follow-up `kind: code` change — leaving a `method=signature` stage resolvable only by a manual outcome set until then. This design RECOMMENDS the single `kind: code` change so the signature method is honestly resolvable on delivery; the code scope is small and bounded (one method).

## Seed data

Extend the C4 route seeds so each method is exercised end-to-end. Existing C4 DecisionStage seed slugs are reused; the `method` value `sign`→`signature` is renamed and mechanism relations + supporting objects are added.

**Municipal route — `besluit-begroting-2027` (college → raadscommissie → gemeenteraad):**
- `stage-begroting-2027-1-prep` · `method=manual` · directieteam · outcome `adopted` (directly set) — unchanged from C4.
- `stage-begroting-2027-2-adv` · **`method=advice`** · raadscommissie (assignedPerson chair) · outcome `advised` (directly set) — changed from C4 `manual` to exercise the advice method.
- `stage-begroting-2027-3-dec` · **`method=vote`** · gemeenteraad · `votingRound` → a new seeded `VotingRound` (`result=adopted`, `votingMethod=for-against-abstain`, `isSecret=false`, 28-3-2) → stage `outcome=adopted` **derived** from the round.

**Corporate route — `besluit-investering-acme` (MT → RvB → RvC):**
- `stage-investering-acme-1-prep` · **`method=chair-register`** · MT body · `registeredBy` → a seeded `Person` (the MT chair) · outcome `adopted` (directly set) — changed from C4 `manual` to exercise chair-register.
- `stage-investering-acme-2-dec` · `method=vote` · executive board · `votingRound` → a new seeded secret `VotingRound` (`isSecret=true`, `result=adopted`) → stage `outcome=adopted` derived — demonstrates the anonymous/secret sub-variant via VotingRound config (D1).
- `stage-investering-acme-3-rat` · **`method=signature`** (renamed from `sign`) · RvC · `signedDocument` → a seeded `DigitalDocument` (signed minutes) · `outcome` reached via `EIDASSignatureService` (seed sets `outcome=adopted` + `decidedAt` to represent a completed signing).

The seeds prove all five methods across the municipal and corporate domains and exercise the secret-ballot sub-variant via `VotingRound.isSecret`.

## Declarative vs. imperative (ADR-031)

| Concern | Declarative (register) | Imperative (Service) |
|---|---|---|
| `method` enum + mechanism relations on DecisionStage | ✅ schema config | — |
| Vote-outcome derivation (`outcome` from `VotingRound.result`) | ✅ `x-openregister-calculations` on DecisionStage | — |
| Vote sub-variants (secret/personal/general) | ✅ `VotingRound.isSecret` + `votingMethod` | — |
| "required mechanism relation present for the method" integrity | ✅ schema validation note | — |
| Chair-register / advice / manual outcome | ✅ directly-set register field (human is the source) | — |
| **Signature stage resolution** (link signedDocument, set outcome on signing) | — | ⚠️ `EIDASSignatureService` (externally-driven eIDAS callback — D4/D5) |
| Rendering each method's mechanism + resolve actions | — | deferred to C6 (UI) |

Everything except the single signature-stage-resolution seam is declarative. That seam is imperative only because it is driven by an asynchronous external signing result no register calculation can observe.

## Relationship to C4 and C6

C4 modelled the route and the `method` placeholder; C5 (this change) makes each method real and derives the stage outcome; C6 builds the route/stage UI (per-stage resolve buttons, live vote projection, signature request flow). C5 touches no UI. The Decision's overall lifecycle auto-advance stays out of scope (a stage resolution may nudge it in C6).

## DEFERRED_QUESTIONS

- **method-enum-shape** — RESOLVED (D1): coarse enum `manual`/`vote`/`signature`/`chair-register`/`advice` + VotingRound config for vote sub-variants; `sign`→`signature` renamed. OPEN only if a future product need requires the *stage list* to filter by sub-variant (secret vs open) without joining the VotingRound — then a derived read-only sub-variant calculation on the stage (not an enum expansion) would be added. Recorded for C6.
- **eIDAS-code-scope** — RESOLVED (D5): C5 models signature-as-method (config) AND adds one thin `EIDASSignatureService` method to resolve a `method=signature` stage, making the change `kind: code`. OPEN: whether the resolution lives on `finalizeMinutes` directly or a new `resolveSignatureStage(stageId, documentId)` seam — the spec mandates the behaviour, not the exact method name; the apply step picks the cleaner seam. (Config-only fallback documented in the Mixed-spec rationale if the code touch must be deferred.)
- **signature-reference-model** — RESOLVED (D3) for the common case: reuse `signedDocument` (DigitalDocument) + `Minutes.signedBy`; no new schema. OPEN: if multi-party signing must track per-signatory *status* (pending/signed/declined) ON the stage rather than as a document/minutes property, a dedicated Signature record (one per signatory, related to the stage) would be warranted — a follow-up change, not C5.
