# Migration: popolo-decision-makers

## Current State

`lib/Settings/decidesk_register.json` `components.schemas` contains:
- `Participant` (flat `foaf:Person`): identity + role + party + email + votingWeight +
  joinedAt/leftAt + governanceBody relation, all on one object. Seeded with 5 demo
  people (femke-halsema, jan-de-vries, marie-janssen, pieter-bakker, ans-rutten).
- `BoardMember` (parallel corporate person-like schema): board relation + role +
  appointment metadata. Seeded with 10 demo board members across corporate bodies.
- No `Person`, `Membership`, `Post`, or `ContactDetail` schema.

`OriController::RESOURCE_MAP` maps `persons` → `participant` and `memberships` →
`participant`, so ORI `/persons` and `/memberships` serialize Participant rows.

Storage is OpenRegister object storage; decidesk owns no DB tables. There is no
Nextcloud DB migration class — schema and seed changes are applied by re-importing the
register via the existing repair step (`ConfigurationService::importFromApp()`).

## Target State

`components.schemas` additionally contains the four Popolo schemas:
- `Person` (`foaf:Person`) — identity only (ADR-000 field set).
- `Membership` (`org:Membership`) — Person↔GovernanceBody relationship, relations to
  Person/GovernanceBody/Post (many-to-one each).
- `Post` (`org:Post`) — formal position, relation to GovernanceBody.
- `ContactDetail` (`popolo:ContactDetail`) — typed contacts, relations to
  Person/GovernanceBody.

`Participant` is retained but its `description` is annotated **deprecated**; no new
Participant seeds are added. `BoardMember` demo data is re-expressed as Person +
Membership seeds (mode=corp labels); the `BoardMember` schema itself is left intact for
deletion by the sibling change `retire-board-portal` (C3).

`OriController::RESOURCE_MAP` maps `persons` → `person` and `memberships` →
`membership`.

## Migration Class

```
Version: N/A — no Nextcloud DB migration class
File:    lib/Settings/decidesk_register.json (register import via repair step)
Key operations:
- Add Person / Membership / Post / ContactDetail schemas to components.schemas
- Add x-openregister-relations + x-openregister-seeds for the four schemas
- Annotate Participant.description as deprecated (no new Participant seeds)
- Re-express BoardMember demo objects as Person + Membership seeds (mode=corp)
- Retarget OriController::RESOURCE_MAP persons→person, memberships→membership
```

This is a config + single-constant code change applied on register re-import; it does
not alter any database table.

## Migration Steps

1. Add the `Person` schema (ADR-000 identity fields, `x-openregister` block, seeds).
2. Add the `Post` schema (label/role/dates + GovernanceBody relation + seeds).
3. Add the `Membership` schema (role/label/dates/votingWeight/party + relations to
   Person/GovernanceBody/Post + seeds linking to the seeded Persons, Posts, and existing
   GovernanceBody slugs).
4. Add the `ContactDetail` schema (type/value/label/note/validity + relations to
   Person/GovernanceBody + seeds).
5. Annotate `Participant.description` as deprecated; do NOT add new Participant seeds.
6. Re-express the `BoardMember` demo objects as additional Person + Membership seeds
   (mode=corp labels), linked to the corporate GovernanceBody slugs. Do NOT delete the
   `BoardMember` schema (owned by C3 `retire-board-portal`).
7. Retarget `OriController::RESOURCE_MAP`: `persons` → `person`, `memberships` →
   `membership`.
8. Re-import the register (repair step) and verify the new schemas + seeds load.

## Data Impact

- **Records affected:** seeded demo data only — no production records exist (shipped
  person data is demo data). The migration is **re-seed based**: importing the register
  creates the new Person/Membership/Post/ContactDetail objects.
- **Data loss:** none. `Participant` and `BoardMember` records are not deleted by this
  change; the Participant→Person+Membership and BoardMember→Person+Membership mappings
  are applied as **new** seeds (additive), so old and new can coexist during the
  Cycle 1 transition.
- **Live data:** safe to run on a live dev instance — additive schema import + a
  two-slug ORI resource retarget; rollback restores prior behaviour.

### participant → Person + Membership mapping (re-seed)

| Participant field | Person | Membership |
|-------------------|--------|------------|
| displayName       | name (+ givenName/familyName) | — |
| email             | email + ContactDetail(type=email) | — |
| role              | — | role |
| party             | — | party |
| votingWeight      | — | votingWeight |
| joinedAt / leftAt | — | startDate / endDate |
| governanceBody    | — | → GovernanceBody relation |
| nextcloudUserId / attendanceStatus / participantType | stays on Participant shim | — |

### BoardMember → Person + Membership re-expression (C3 coordination)

| BoardMember field | Person | Membership |
|-------------------|--------|------------|
| (slug name)       | name/givenName/familyName | — |
| board (→ Board)   | — | → GovernanceBody (corp body) |
| role (chairman/…) | — | role + label (corp wording) |
| appointmentDate   | — | startDate |
| termEndDate       | — | endDate |
| nationality / nevenfuncties / independenceStatus | dropped (corp-disclosure metadata; out of scope) | — |

C2 (this change) adds these seeds; **C3 `retire-board-portal` deletes the `BoardMember`
schema afterwards** — ordering C2-before-C3 leaves no orphaned references.

### Participant retain-or-delete decision

**Retain, deprecated.** `Participant` is referenced by the `Meeting` quorum aggregation
(`totalParticipantCount`/`presentParticipantCount`), `VotingService::resolveParticipantUuid()`,
and the `participant-crud`/`meeting-attendees`/`voting-system` specs. Deleting it now
exceeds this change's config-first scope. Full removal + migration of those references is
deferred to a follow-up change (see DEFERRED_QUESTIONS).

## Rollback Procedure

Revert the single commit (schema additions + seeds + `Participant` deprecation note +
`BoardMember` re-expression seeds + `OriController::RESOURCE_MAP` retarget) and re-import
the register. Because the change is additive and only re-points two ORI resource slugs,
reverting restores the prior Participant-backed behaviour with no data loss.

## Validation

- `python3 -c "import json; json.load(open('lib/Settings/decidesk_register.json'))"` —
  register is valid JSON.
- New schemas present: `Person`, `Membership`, `Post`, `ContactDetail` in
  `components.schemas`.
- Seeds present and resolvable: Membership seeds resolve their Person/GovernanceBody/Post
  relations; ContactDetail seeds resolve Person/GovernanceBody.
- `Participant.description` contains a deprecation note; no new Participant seeds added.
- `BoardMember` schema still present (deletion deferred to C3); BoardMember people now
  also exist as Person + Membership.
- `OriController::RESOURCE_MAP['persons'] === 'person'` and `['memberships'] === 'membership'`.
- `GET /api/ori/v1/persons` and `/api/ori/v1/memberships` return non-empty JSON-LD with
  `@type` `Person`/`Membership`; Person `email` is not exposed.
