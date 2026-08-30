# Proposal: Decision relations (supersedes / amends / repeals chains)

## Why

A decision register is only trustworthy if it answers the question every secretary, member, and auditor actually asks: **"is this decision still in force, and if not, what replaced it?"** Decidesk's decision-management spec covers the lifecycle of a single decision (draft → … → enacted → archived) but nothing models relations BETWEEN decisions — no supersedes, no amends, no repeals. FEATURE-REEVALUATION-2026-06-11 flags this as EXPECTED-GAP #4: "FEATURES.md #8 (V1) and core to legislative use; decision-management spec covers lifecycle but not cross-decision relations. Low-cost via OR object relations; needs only a spec requirement + UI affordance."

Without it, decidesk silently lies: a 2024 decision that was repealed in 2026 still renders as `enacted` with nothing pointing at its repeal. Every incumbent in this category (Notubiz, iBabs, Decos) models besluit relations, Akoma Ntoso (already a cited standard in the spec) has first-class `passiveModifications`/`activeModifications` for exactly this, and the in-force question is the core of legislative consolidation.

## What Changes

- **Typed decision-to-decision relations** stored as OpenRegister object relations on the `Decision` schema: `supersedes`, `amends`, `repeals`, `implements`, and `refersTo`. The inverse view (superseded-by, amended-by, repealed-by, implemented-by, referenced-by) is derived by querying the OR relations — never stored twice.
- **Effective status derivation**: a decision targeted by an enacted `supersedes` or `repeals` relation gets a derived effective status (`superseded` / `repealed`) layered over its lifecycle status — the lifecycle state machine is NOT extended; the audit trail and statutory record stay intact while the register stops presenting dead decisions as in force.
- **Relation integrity rules**: only enacted/decided decisions can exert legal effect (`supersedes`/`amends`/`repeals` activate when the source decision is decided/enacted); self-references and cycles in the effect-bearing relation types are rejected; relations are recorded in the immutable audit trails of both decisions.
- **UI affordances**: a Related decisions sidebar tab (delta on `relation-tab-ui` — peer relations, not parent-child CRUD), an effective-status banner with chain navigation on the decision detail view ("Superseded by [Budget 2027]"), and an in-force filter (`in force` / `superseded` / `repealed`) on the decision list.
- **Publication hook**: when the `public-publication` capability is configured, published decision payloads carry relation metadata (ORI/Akoma Ntoso modification references) and withdrawing nothing — relation changes after publication surface through the existing rectify flow.

## Capabilities

### New Capabilities

_None — this is a deepening of existing capabilities, not a new surface._

### Modified Capabilities

- `decision-management`: ADDED requirements — typed decision relations, effective-status derivation, relation integrity validation, in-force filtering, and relation visibility in the detail view and audit trail.
- `relation-tab-ui`: ADDED requirement — a peer-relation tab pattern (typed link to an existing object of the same schema, add/remove, navigate) alongside the existing parent-owned child CRUD tabs.

## Impact

- **Schemas**: `Decision` gains the five relation properties (OR object-relation typed, arrays of decision references) via a declarative schema version bump. No new entity schemas; no app-local join tables — relations are OpenRegister object relations.
- **Storage / RBAC / notifications**: all from OpenRegister. Creating/removing an effect-bearing relation requires the same governance-body authority as decision transitions (OR RBAC). One ADR-031 notification rule notifies the body when a decision is superseded/repealed (update trigger on the target's derived status).
- **Backend**: a small `DecisionRelationService` (integrity validation: status preconditions, self/cycle rejection; effective-status derivation; audit entries on both ends). Relation CRUD itself stays on the OR object API per ADR-022; only the validation/derivation logic is app code (server-side validation hook, not a pass-through controller).
- **Frontend**: Related-decisions tab, effective-status banner + chain navigation, in-force list filter. All reads/writes via `useObjectStore`.
- **Standards**: Akoma Ntoso `activeModifications`/`passiveModifications`, ORI `Besluit` relations, schema.org `replacer`/`replacee` — all already cited standards in the spec, now actually mapped.
- **Out of scope**: consolidation text rendering (showing the amended text merged — follow-up), cross-register relations to external decisions, automatic relation inference, relations on motions/amendments (already owned by motion-amendment).
