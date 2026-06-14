# Design: popolo-decision-makers

## Context

ADR-001 adopts Popolo as decidesk's primary data standard and mandates separating
identity (`Person`) from organisational relationship (`Membership`), with `Post` for
formal positions and `ContactDetail` for typed multi-value contacts. The shipped
register (`lib/Settings/decidesk_register.json`) never implemented these: people are
modelled by the flat `Participant` schema (`displayName`, `role`, `party`, `email`,
`votingWeight` all on one object) and corporate boards add a parallel `BoardMember`
person-like schema. ADR-006 forbids that parallel-entity pattern ("one schema per
concept") and names this change as the one that folds `board-member` into Person +
Membership. ADR-003's ORI `/persons` and `/memberships` endpoints currently serialize
`Participant` rows, not real Popolo objects.

Constraints:
- Config-first per ADR-031 — relations, lifecycle, aggregations are declarative
  `x-openregister-*` in the register, NOT new Service classes.
- decidesk owns no DB tables; OpenRegister stores objects and resolves relations.
- The `BoardMember`/`board-*` schema *deletion* belongs to the sibling change
  `retire-board-portal` (C3); this change only re-expresses the data + coordinates.
- `Participant` is referenced widely (quorum aggregation, vote-casting, several specs),
  so it is retained as a deprecated compatibility shim, not deleted.

## Mixed-spec rationale (ADR-032)

ADR-032 (kind frontmatter) distinguishes config-only changes from code changes. This
change is **mixed**: the bulk is config (four new schemas + declarative relations +
re-seeded demo data in `decidesk_register.json`), but it also includes a code edit —
retargeting `OriController::RESOURCE_MAP` so `persons`/`memberships` map to
`person`/`membership`. Per the C1 (`unify-decision-supertype`) precedent, a mixed
change with a small, tightly-coupled code edit is delivered as **one change under
supervised local apply** rather than split, because the ORI retarget is meaningless
without the schemas and the schemas are incomplete (ORI still serializes Participant)
without the retarget. `kind: code` is set in the proposal frontmatter so the supervisor
runs the code-path gates.

## Goals / Non-Goals

**Goals**
- Implement the four Popolo person/org schemas exactly per ADR-000 field sets.
- Separate identity (Person) from org-relationship (Membership) per ADR-001 §2.
- Fold `BoardMember` demo data into Person + Membership (mode=corp) per ADR-006.
- Re-seed a realistic council/board/association demo across all four schemas.
- Retarget ORI `/persons` + `/memberships` to the real schemas, contract-preserving.

**Non-Goals**
- Deleting `Participant` or `BoardMember` schemas (retain / C3 respectively).
- New Vue views or nav for the Popolo entities (`ia-six-item-nav` + downstream UI).
- The `Speech` Popolo class (ADR-000 later phase).
- A production data migration (seeded demo data → re-seed based).

## Architecture Overview

```
decidesk_register.json  components.schemas
  Person ──< Membership >── GovernanceBody          (org:Membership join)
    │            │
    │            └────── Post ── GovernanceBody       (formal position)
    └──< ContactDetail >── GovernanceBody             (typed contacts)

  Participant (DEPRECATED shim, retained)            ← quorum/vote-casting still read it
  BoardMember (owned by retire-board-portal C3)      ← data re-expressed as Person+Membership here
```

OpenRegister resolves the relations declared in `x-openregister-relations`. The ORI
controller projects `person`/`membership` objects to Popolo JSON-LD at the boundary.

## Decisions

### Decision 1: Add Person/Membership/Post/ContactDetail as four new schemas

Mirror ADR-000 field sets verbatim. Follow the existing `Participant`/`GovernanceBody`
schema shape: `slug`, `icon`, `version: 0.1.0`, `title`, `description`, `type: object`,
`x-openregister` block (`schemaType`, `active`, `hardDelete: false`, `searchable`),
`required`, `properties`, `x-openregister-relations`, `x-openregister-seeds`.

- **Person** — `schemaType: foaf:Person`; properties `name` (required), `familyName`,
  `givenName`, `gender`, `birthDate` (date), `image`, `biography`, `email` (convenience).
- **Membership** — `schemaType: org:Membership`; properties `role` (required enum:
  chair, vice-chair, secretary, treasurer, member, observer, guest), `label`,
  `startDate`/`endDate` (date-time), `votingWeight` (number, default 1), `party`
  (Popolo `on_behalf_of`); relations Person/GovernanceBody/Post (all many-to-one).
- **Post** — `schemaType: org:Post`; properties `label` (required), `role` (enum:
  chair, vice-chair, secretary, treasurer, member), `startDate`/`endDate`; relation
  GovernanceBody (many-to-one).
- **ContactDetail** — `schemaType: popolo:ContactDetail`; properties `type` (required
  enum: email, phone, fax, cell, address, url), `value` (required), `label`, `note`,
  `validFrom`/`validUntil`; relations Person/GovernanceBody (many-to-one).

_Alternative considered:_ extend `Participant` with the missing fields instead of adding
Person+Membership. Rejected — it perpetuates the identity/relationship conflation
ADR-001 §2 explicitly calls out as incorrect and can't model one-person-in-two-bodies
without duplicating identity.

### Decision 2: Retain `Participant` as a deprecated shim

`Participant` is read by the `Meeting` quorum aggregation (`totalParticipantCount`,
`presentParticipantCount`), `VotingService::resolveParticipantUuid()`, and the
`participant-crud`/`meeting-attendees`/`voting-system` specs. Deleting it now exceeds
the config-first scope and would break quorum/vote-casting. Decision: annotate its
`description` as deprecated, point new seeds at Person + Membership, and defer removal.

_Alternative considered:_ delete `Participant` now and migrate every reference. Rejected
as out-of-scope ripple (see Open Questions); tracked as a deferred question.

### Decision 3: Re-express BoardMember data, defer schema deletion to C3

ADR-006 maps `board-member` → Person + Membership (mode=corp labels). This change adds
the corporate board members as Person + Membership seeds (Membership `label` carries the
corp vocabulary, e.g. "Voorzitter RvC", `role` maps to the Popolo enum). The
`BoardMember` schema itself is deleted by `retire-board-portal` (C3), which runs after
C2 so the data already lives as Person + Membership when the schema is removed.

### Decision 4: Retarget ORI resource map, contract-preserving

Change `OriController::RESOURCE_MAP['persons']` from `participant` to `person` and
`['memberships']` from `participant` to `membership`. `ORI_TYPE_MAP` already says
`Person`/`Membership`. The list path uses the C1-fixed pattern
`findAll(['limit' => 100, 'filters' => ['register' => 'decidesk', 'schema' => $schema, ...]])`
where register/schema live **inside** `filters`. No new code paths; `serializeOri()`
maps `name` and gates `email` to Organization types, so Person/Membership serialize
cleanly. Endpoint paths and JSON-LD envelope are unchanged.

## Declarative-vs-imperative (ADR-031)

All structure is declarative in `decidesk_register.json`:
- **Relations** — `x-openregister-relations` on Membership (→ Person, GovernanceBody,
  Post), Post (→ GovernanceBody), ContactDetail (→ Person, GovernanceBody). No relation
  Service classes.
- **Lifecycle** — Memberships use the built-in `startDate`/`endDate` validity window
  (active when `endDate` is null or future) per the `person-and-membership` spec; this
  is data, not an imperative state machine.
- **Aggregations** — member counts for quorum are declarative aggregations on `Meeting`
  (the existing `quorum-schema-declaration` pattern), not imperative counting code.
- **Seeds** — demo data lives in `x-openregister-seeds`, imported via the register
  repair step; no seeding Service.

The single imperative edit is the ORI `RESOURCE_MAP` retarget (a constant-array change),
justified in the Mixed-spec rationale above.

## participant → Person + Membership migration mapping

A flat `Participant` decomposes into one `Person` (identity) + one `Membership`
(org-relationship), linked by the Membership → Person relation:

| Participant field      | Goes to                          | Notes |
|------------------------|----------------------------------|-------|
| `displayName`          | Person `name`                    | also split into `givenName`/`familyName` where known |
| `email`                | Person `email` (+ ContactDetail) | convenience field on Person; full contact as ContactDetail type=email |
| `role`                 | Membership `role`                | same enum, plus `treasurer` |
| `party`                | Membership `party`               | Popolo `on_behalf_of` |
| `votingWeight`         | Membership `votingWeight`        | default 1 |
| `joinedAt`             | Membership `startDate`           | |
| `leftAt`              | Membership `endDate`             | null = active |
| `governanceBody` (rel) | Membership → GovernanceBody (rel)| relation moves from Person to Membership |
| `nextcloudUserId`      | _stays on Participant shim_      | vote-casting resolver still reads it; out-of-scope to move |
| `attendanceStatus`/`participantType` | _stays on Participant shim_ | per-meeting attendance, not membership (ADR-001 §, CalDAV PARTSTAT) |

One Person + N Memberships models a person sitting in multiple bodies (the case
`Participant` could not represent without duplicating identity).

## board-member coordination note (with C3 retire-board-portal)

`retire-board-portal` (C3) deletes ALL `board-*` schemas including `BoardMember`. This
change (C2) does **not** delete `BoardMember`; it re-expresses the BoardMember demo
objects as Person + Membership seeds with mode=corp labels so that when C3 removes the
schema, the corporate decision-maker data already lives on the unified Popolo model.
Ordering: **C2 before C3.** Field mapping for the re-expression:

| BoardMember field        | Goes to (Person/Membership) |
|--------------------------|------------------------------|
| (slug person name)       | Person `name`/`givenName`/`familyName` |
| `board` (→ Board rel)    | Membership → GovernanceBody (the corp body) |
| `role` (chairman/...)    | Membership `role` (chair/vice-chair/member) + `label` for corp wording |
| `appointmentDate`        | Membership `startDate` |
| `termEndDate`            | Membership `endDate` |
| `nationality`/`nevenfuncties`/`independenceStatus` | dropped from the unified seed (corp-disclosure metadata; if needed later, modelled as Person fields or notes — out of scope here) |

## Nextcloud Integration
- Controllers: `lib/Controller/OriController.php` (RESOURCE_MAP retarget only).
- Services: none new (config-first per ADR-031).
- Mappers/Entities: none — OpenRegister owns storage.
- Events/Hooks: none.

## Security Considerations

ORI `/persons` and `/memberships` are `#[PublicPage] #[NoCSRFRequired]` anonymous
endpoints. `serializeOri()` only surfaces `email` for Organization-typed resources, so
Person email is **not** leaked on the public ORI Person serialization (the convenience
`email` on Person stays internal; public contact exposure would be an explicit
ContactDetail decision in a later change). The list endpoint already filters to
published/visible objects. No new auth surface is introduced.

## File Structure
```
lib/
  Settings/
    decidesk_register.json   # + Person, Membership, Post, ContactDetail schemas + seeds; Participant deprecated; BoardMember data re-expressed
  Controller/
    OriController.php         # RESOURCE_MAP persons→person, memberships→membership
```

## Seed Data

Realistic Popolo demo across general orgs (reusing existing GovernanceBody slugs:
`gemeenteraad-amsterdam`, `rvc-waterschap-amstel`, `ledenraad-vng`,
`directieteam-gemeente-utrecht`). Reuses existing person names where sensible.

### Schema: `person`
| Field      | Object 1            | Object 2          | Object 3        | Object 4            | Object 5          |
|------------|---------------------|-------------------|-----------------|---------------------|-------------------|
| slug       | femke-halsema       | marie-janssen     | jan-de-vries    | janneke-de-bruin    | mark-van-den-berg |
| name       | Femke Halsema       | Marie Janssen     | Jan de Vries    | Janneke de Bruin    | Mark van den Berg |
| givenName  | Femke               | Marie             | Jan             | Janneke             | Mark              |
| familyName | Halsema             | Janssen           | de Vries        | de Bruin            | van den Berg      |
| gender     | female              | female            | male            | female              | male              |
| email      | f.halsema@amsterdam.nl | m.janssen@amsterdam.nl | j.devries@waterschap.nl | j.debruin@acme.example | m.vandenberg@acme.example |
| biography  | Burgemeester        | Raadslid          | Secretaris      | Voorzitter RvC      | Voorzitter RvB    |

### Schema: `membership`
| Field         | Object 1 (council chair) | Object 2 (council member) | Object 3 (council secretary) | Object 4 (corp board chair) | Object 5 (corp exec) | Object 6 (assoc member) |
|---------------|--------------------------|---------------------------|------------------------------|------------------------------|----------------------|--------------------------|
| slug          | m-femke-amsterdam        | m-marie-amsterdam         | m-jan-amstel                 | m-janneke-rvc                | m-mark-rvb           | m-marie-vng              |
| role          | chair                    | member                    | secretary                    | chair                        | member               | member                   |
| label         | Voorzitter raad          | Raadslid                  | Raadsgriffier                | Voorzitter RvC               | Lid RvB              | Lid ledenraad            |
| party         | GroenLinks               | D66                       | —                            | —                            | —                    | —                        |
| votingWeight  | 1                        | 1                         | 0                            | 1                            | 1                    | 1                        |
| startDate     | 2018-05-30               | 2022-03-16                | 2021-01-15                   | 2022-04-01                   | 2020-01-01           | 2024-01-01               |
| endDate       | null                     | null                      | null                         | 2026-04-01                   | 2025-12-31           | null                     |
| → person      | femke-halsema            | marie-janssen             | jan-de-vries                 | janneke-de-bruin             | mark-van-den-berg    | marie-janssen            |
| → governanceBody | gemeenteraad-amsterdam | gemeenteraad-amsterdam   | rvc-waterschap-amstel        | rvc-waterschap-amstel        | rvc-waterschap-amstel| ledenraad-vng            |
| → post        | post-voorzitter-amsterdam| —                         | post-griffier-amsterdam      | post-voorzitter-rvc          | —                    | —                        |

### Schema: `post`
| Field          | Object 1                  | Object 2                 | Object 3              |
|----------------|---------------------------|--------------------------|-----------------------|
| slug           | post-voorzitter-amsterdam | post-griffier-amsterdam  | post-voorzitter-rvc   |
| label          | Voorzitter gemeenteraad   | Raadsgriffier            | Voorzitter RvC        |
| role           | chair                     | secretary                | chair                 |
| startDate      | 2018-05-30                | 2021-01-15               | 2022-04-01            |
| → governanceBody | gemeenteraad-amsterdam  | gemeenteraad-amsterdam   | rvc-waterschap-amstel |

### Schema: `contact-detail`
| Field      | Object 1                | Object 2          | Object 3              |
|------------|-------------------------|-------------------|-----------------------|
| slug       | cd-femke-email          | cd-femke-phone    | cd-amsterdam-address  |
| type       | email                   | phone             | address               |
| value      | f.halsema@amsterdam.nl  | +31 20 624 1111   | Amstel 1, Amsterdam   |
| label      | Werk                    | Bestuurssecretariaat | Stadhuis           |
| → person   | femke-halsema           | femke-halsema     | —                     |
| → governanceBody | —                 | —                 | gemeenteraad-amsterdam|

**Related items per object:**
- Files: none required (Person `image` may reference an avatar URL).
- Notes: Membership records may carry a free-text note for term context.
- Tasks: none.
- Contacts: ContactDetail objects link to Person/GovernanceBody via relations above.

## Risks / Trade-offs

- [ORI Person/Membership output regression for external consumers] → endpoint paths and
  JSON-LD envelope unchanged; validate `/api/ori/v1/persons` + `/memberships` return
  non-empty Popolo items before archiving.
- [Deprecated Participant lingers and drifts] → annotated deprecated; new seeds use
  Person + Membership; full removal tracked as a deferred question + migration note.
- [BoardMember re-expression overlaps with C3] → additive seeds + coordination note;
  C2 runs before C3 so no orphaned references.

## Migration Plan

See `migration.md`. Summary: additive schema add + re-seed; ORI resource retarget;
`Participant` retained deprecated. Rollback = revert the single commit.

## Open Questions

- Delete or retain `Participant`? Provisional: **retain, deprecated** (quorum +
  vote-casting + several specs depend on it). Full removal deferred — see
  DEFERRED_QUESTIONS.
- Should public ORI Person serialization expose any ContactDetail? Provisional: **no**
  — keep email internal; public contact exposure is a later, explicit decision.
