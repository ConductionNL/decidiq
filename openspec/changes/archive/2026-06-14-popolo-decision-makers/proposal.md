---
kind: code
---

# Proposal: popolo-decision-makers

## Summary

Build the Popolo "decision makers" model that ADR-001 specifies but that was never implemented. The shipped data model still represents people with the flat `Participant` schema (identity, role, party, votingWeight all merged into one object) and a third person-like entity `BoardMember` for corporate boards. This change introduces the four Popolo person/org-relationship classes — `Person`, `Membership`, `Post`, `ContactDetail` — in `lib/Settings/decidesk_register.json`, separates identity (Person) from organisational relationship (Membership) per ADR-001 §2, re-expresses corporate board members as Person + Membership (mode=corp labels) per ADR-006, re-seeds a realistic council/board/association demo across the four schemas, and retargets the ORI `/persons` and `/memberships` endpoints from the `Participant` schema to the real `Person`/`Membership` schemas so ORI serializes genuine Popolo objects.

## Motivation

ADR-001 adopts Popolo as decidesk's primary data standard and design-choice #2 mandates "Person + Membership separation — the previous Participant entity merged these incorrectly." ADR-006 makes "one schema per concept" binding at the data layer and explicitly states "the Popolo decision-maker model becomes the single person/org model, with `board-member` folded into Person + Membership (Cycle 1, change `popolo-decision-makers`)." Today the model contradicts both: `Participant` conflates identity with org-relationship (so one person in two bodies needs two Participant rows with duplicated identity), `BoardMember` is a parallel person-like entity for corporate boards (the exact drift ADR-006 forbids), and ADR-003's ORI `/persons` + `/memberships` endpoints serialize `Participant` rows rather than real Popolo Persons/Memberships. This is C2 of the decidesk semantic-cleanup programme (Cycle 1) and the prerequisite person/org foundation that `retire-board-portal` (C3) and `ia-six-item-nav` build on.

## Affected Projects

- [ ] Project: `decidesk` — adds `Person`, `Membership`, `Post`, `ContactDetail` Popolo schemas to `lib/Settings/decidesk_register.json` with declarative relations + seeds; deprecates the flat `Participant` schema (retained as a compatibility shim); re-expresses `BoardMember` demo data as Person + Membership seeds (the `BoardMember`/board-* schema deletion is owned by `retire-board-portal`); retargets the ORI `/persons` and `/memberships` resource map from `participant` to `person`/`membership`.

## Scope

### In Scope

- Add four Popolo schemas to `decidesk_register.json` `components.schemas`, mirroring the exact field sets in ADR-000: `Person` (foaf/popolo:Person identity), `Membership` (org:Membership person↔org relationship), `Post` (org:Post formal position), `ContactDetail` (popolo:ContactDetail typed multi-value contacts).
- Declare relations with `x-openregister-relations` following the existing `Participant`/`BoardMember` pattern (Membership → Person/GovernanceBody/Post; Post → GovernanceBody; ContactDetail → Person/GovernanceBody).
- Re-seed realistic demo data across general orgs: council members, corporate board members, and association members as Person + Membership pairs; Posts (chair/secretary/treasurer); ContactDetails. Reuse existing names (femke-halsema etc.) where sensible.
- Re-express the shipped `BoardMember` demo objects as Person + Membership seeds with mode=corp labels (data only — schema deletion is C3's job).
- Mark the `Participant` schema deprecated (retained as a compatibility shim because many specs and the quorum aggregation reference it; deletion deferred — see Open Questions).
- Retarget `OriController::RESOURCE_MAP` so `persons` → `person` and `memberships` → `membership`, following the C1 `findAll(['filters' => ['register' => .., 'schema' => ..]])` config-array pattern.

### Out of Scope

- Deletion of the `BoardMember` / `Board` / other `board-*` schemas — owned by the sibling change `retire-board-portal` (C3); this change only re-expresses the *data* and adds a coordination note.
- Deletion of the `Participant` schema and migration of every spec/view/aggregation that references it — deferred; `Participant` is kept deprecated for now (see Open Questions).
- New Vue views for Person/Membership/Post/ContactDetail and nav restructuring — owned by `ia-six-item-nav` and downstream UI changes.
- The `Speech` Popolo class (ADR-000 marks it a later phase).
- A data-preserving production migration — the shipped person data is seeded demo data, so migration is re-seed-based.

## Approach

Config-first per ADR-031: add the four schemas + their declarative relations + seeds to `decidesk_register.json`, re-seed `Participant`/`BoardMember` demo objects as Person + Membership, mark `Participant` deprecated, then retarget the ORI resource map. Relations, lifecycle, and aggregations stay declarative `x-openregister-*` in the register — no new Service classes. The only code change is the `OriController::RESOURCE_MAP` retarget (`persons`/`memberships` → `person`/`membership`), which follows the C1-fixed `findAll` config-array pattern where register/schema live inside `filters`. The Popolo mapping stays a thin projection at the ORI serialization boundary per ADR-001/ADR-003.

## New Dependencies

None.

## Impact

- **Schemas** (`lib/Settings/decidesk_register.json`): four new schemas (`Person`, `Membership`, `Post`, `ContactDetail`) with `x-openregister-relations` and `x-openregister-seeds`; `Participant` schema description annotated as deprecated; `BoardMember` demo data re-expressed as Person + Membership seeds.
- **ORI serializer** (`lib/Controller/OriController.php`): `RESOURCE_MAP['persons']` and `['memberships']` retargeted from `participant` to `person`/`membership`; `ORI_TYPE_MAP` unchanged (`Person`/`Membership`); endpoint paths and response envelope unchanged.
- **Standards**: ORI `/api/ori/v1/persons` and `/memberships` now serialize real Popolo Person/Membership objects; the endpoint contract (JSON-LD shape) is preserved.

## Cross-Project Dependencies

None at the data layer — OpenRegister stores the objects and resolves the declarative relations. ORI consumers (Dutch municipalities) read `/api/ori/v1/persons` + `/memberships`; their contract is preserved (same paths, same JSON-LD envelope, richer Popolo content).

## Capabilities

### New Capabilities

_None — this implements existing capabilities with the real Popolo schemas; no new public surface._

### Modified Capabilities

- `person-and-membership`: ADDED requirements — the `Person`, `Membership`, and `Post` Popolo schemas now exist in the register with declarative relations and seeds (the capability previously described Membership-based attendance against the flat Participant model).
- `governance-bodies`: ADDED requirements — `ContactDetail` and `Post` are introduced as governance-body-linked Popolo entities; `Person` + `Membership` replace `Participant` as the canonical decision-maker model.
- `participant-crud`: MODIFIED — the `Participant` schema is deprecated in favour of `Person` + `Membership`; CRUD requirements redirect new data to the Popolo schemas while the shim is retained.
- `ori-api`: MODIFIED — the ORI `/persons` and `/memberships` resources serialize real `person`/`membership` objects instead of `participant` objects; endpoint paths and response shapes unchanged.

## Risks

### Risk 1: ORI /persons and /memberships output regression for external consumers

**Severity:** Medium — **Mitigation:** The ORI endpoint paths and JSON-LD envelope (`@context`/`@type`/`id`/`name`) are unchanged; only the source schema changes. `serializeOri()` already maps `name`/`title` and gates email to Organization-typed resources, so Person serialization stays well-formed. Validate `/api/ori/v1/persons` and `/memberships` return non-empty Popolo items against the re-seeded data before archiving (see test-plan).

### Risk 2: Retaining a deprecated Participant schema causes confusion / drift

**Severity:** Medium — **Mitigation:** `Participant` is annotated deprecated in its description; all new seeds use Person + Membership; the deprecation and the deletion-vs-retain decision are recorded in design.md and migration.md, and full removal is tracked as a deferred question so it is not silently dropped.

### Risk 3: BoardMember data re-expression overlaps with retire-board-portal (C3)

**Severity:** Low — **Mitigation:** This change adds the Person + Membership corporate seeds and a coordination note; C3 owns the actual `board-*` schema deletion. The seeds are additive, so ordering C2-before-C3 leaves no orphaned references (C3 removes the BoardMember schema after its data already lives as Person + Membership).

## Rollback Strategy

Revert the `decidesk_register.json` schema additions/seeds and the `OriController::RESOURCE_MAP` retarget (single commit). Because the change is additive (new schemas + new seeds) and only re-points two ORI resource slugs, reverting restores the prior Participant-backed behaviour without data loss — the seeded objects are demo data and are recreated on the next register import.

## Open Questions

- **Delete or retain `Participant` now?** Provisional decision: **retain, deprecated.** `Participant` is referenced by the quorum aggregation on `Meeting` (`totalParticipantCount`/`presentParticipantCount`), the `participant-crud`/`meeting-attendees`/`voting-system` specs, and `VotingService::resolveParticipantUuid()`. Deleting it now would ripple across many specs and the quorum/vote-casting code, exceeding this change's config-first scope. Full removal + migration of those references is deferred to a follow-up change. Recorded in DEFERRED_QUESTIONS.
