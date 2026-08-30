# Tasks: Decision relations

## 1. Schema (decidesk_register.json)
- [ ] 1.1 Add the five relation properties to `Decision` (`supersedes`, `amends`, `repeals`, `implements`, `refersTo` — arrays of OR object references to decisions) via a declarative schema version bump (optional properties; backwards compatible).
- [ ] 1.2 Add the ADR-031 `x-openregister-notifications` rule notifying the governance body when a decision becomes superseded/repealed (update trigger driven by the derivation service).
- [ ] 1.3 Document the standards mapping in the schema descriptions (Akoma Ntoso active/passive modifications, ORI `Besluit` relations, schema.org `replacer`/`replacee`).
- [ ] 1.4 Verify the dialect gate still passes after the edits.

## 2. Backend (lib/)
- [ ] 2.1 `DecisionRelationService`: write-time validation (self-reference rejection for all types, bounded cycle walk over the effect-bearing subgraph with a clear conflicting-decision error, same-register target check) hooked into the decision save path — relation CRUD itself stays on the OR object API per ADR-022 (no pass-through controllers; run the redundant-controller gate).
- [ ] 2.2 Authority enforcement: effect-bearing relation writes require governance-body transition authority via OR RBAC; informational relations require decision write access (per-object guard, no bare `#[NoAdminRequired]` — no-admin-idor gate).
- [ ] 2.3 Effective-status derivation in the same service: `repealed` > `superseded` > lifecycle status, computed at read time from live relations (effect only while the source is `decided`/`enacted`); expose as a derived field on decision reads; reuse from the public-publication payload builder when present.
- [ ] 2.4 Audit-trail entries on BOTH decisions for every relation add/remove (actor, timestamp, type, counterpart) — immutable, same mechanism as transition entries.
- [ ] 2.5 Drive the notification rule: emit the derived-status update the ADR-031 rule consumes when an enactment flips targets to superseded/repealed (declarative dispatch only, no imperative notifications).

## 3. Frontend
- [ ] 3.1 Peer-relation "Related decisions" sidebar tab per REQ-RTU-002: outgoing + derived incoming groups, add via NcSelect (with `inputLabel`) object search + relation-type selector, remove with `CnDeleteDialog`, navigation on row activation, inline server validation errors; dialogs in `src/modals/` (modal-isolation gate); empty-`objectId` short-circuit; reusable component so future peer-typed relations adopt it.
- [ ] 3.2 Effective-status banner on the decision detail view (effecting decision + date + navigation) with the lifecycle badge always visible.
- [ ] 3.3 In-force filter (`in force` / `superseded` / `repealed`) on the decision list over the derived status.
- [ ] 3.4 i18n keys in English source, nl + en translations; all reads/writes via `useObjectStore`.

## 4. Tests + verification
- [ ] 4.1 PHPUnit: validation matrix (self-reference, cycle incl. multi-hop, cross-register target), authority split (effect-bearing vs. informational), derivation precedence (repealed > superseded > lifecycle), draft-exerts-no-effect, enactment flip, dual audit entries.
- [ ] 4.2 Vitest: peer-relation tab component (grouping, read-only incoming, empty-objectId guard, inline error rendering).
- [ ] 4.3 Newman (`tests/integration/`): 403 on effect-bearing relation write without authority (IDOR), validation 4xx contract, derived inverse query shape, published-payload relation fields when public-publication is configured.
- [ ] 4.4 Playwright (UI only): add a `supersedes` relation via the tab; enact the superseding decision and see the banner + chain navigation on the target; in-force filter excludes superseded/repealed; cycle error shown inline in the dialog. Annotate for gate-19 (backend/API excludes already inline in the spec deltas).
- [ ] 4.5 Run hydra gates (notification-dialect, no-admin-idor, redundant-controller, route-auth/reachability, spec-coverage with `@spec` tags, e2e-coverage) and `composer check:strict`; fix anything pre-existing the touched files surface.
- [ ] 4.6 Live verify against the dev container with a three-decision chain (A superseded by B, B repealed by C); bump `appinfo/info.xml` version (immutable-cache bust).

## 5. Docs + follow-ups
- [ ] 5.1 Update docs: the in-force story (lifecycle vs. effective status), relation types and their legal meaning per body type.
- [ ] 5.2 File follow-up issues: consolidation text rendering for `amends` chains; rectify-prompt integration when a published decision is later superseded (coordinates with publish-decisions-via-opencatalogi); peer-relation tab adoption for other schemas.
