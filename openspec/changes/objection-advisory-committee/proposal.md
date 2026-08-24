---
kind: code
---

# Proposal: objection-advisory-committee

## Summary

Make a Dutch **bezwaaradviescommissie** (Awb 7:13 objection advisory committee) expressible as a decidiq `GovernanceBody`, so dossiq can stop owning a parallel committee schema. Four additive fields close the gap measured against dossiq's `bezwaaradviescommissie`: `active` on the body, `quorum` as a NUMBER, `jurisdiction`, and `external` on `Membership`. Plus the one thing none of them fixes on its own — a **write seam**, because decidiq's cross-app API is read-only today and a migration cannot POST a body through it.

## Motivation

dossiq (`ConductionNL/dossiq`) carries `bezwaaradviescommissie`: name, type (BAC/VKK/VTH), domain, jurisdiction, chair, members[], secretary, quorum, term dates, active. It is a governance body in everything but where it lives, and decidiq already owns governance bodies — 17 seeded, with `Membership` and `Post` for the roster and `bodyType: advisory-body` already in the enum.

The obstacle is not architectural, it is **four missing fields and one missing verb**, all measured against the live schemas on 2026-08-24 rather than assumed:

| dossiq field | decidiq today | verdict |
|---|---|---|
| `active` (bool) | — | ❌ **blocking**. `x-openregister.active` is schema-level, not per object. |
| `quorum` (int, min 2) | `quorumRule` (string, e.g. `majority`) | ❌ **blocking**. A method name cannot express Awb 7:13's minimum member count. |
| `jurisdiction` (string) | — | ❌ missing |
| `members[].external` (bool) | `Membership.independenceStatus` | ❌ **blocking**. A DIFFERENT axis: MCCG board independence, not Awb 7:13(2)'s "not employed by the administrative organ". |
| `type` BAC/VKK/VTH | `bodyType: advisory-body` | ⚠️ lossy — three legal regimes collapse to one bucket |
| `name`, `domain`, `chair`, `secretary`, `members[]`, term dates | `name`, `domain`, `Membership.role`, `Membership`, `termStart`/`termEnd` | ✅ already expressible |

`active` deserves singling out: it is **the one field dossiq's live code actually reads**. `AdvisoryCommitteeService` throws "Committee is archived and cannot accept new bezwaaren" on it. Without it the archive gate cannot move, and a migration that dropped it would silently start routing objections to disbanded committees.

## Affected Projects

- [x] Project: `decidiq` — this change. Additive schema fields on `GovernanceBody` and `Membership`, a `governanceBody` write path on the cross-app API, seed data, manifest surfacing, tests.
- [ ] Project: `dossiq` — a FOLLOW-UP change, not this one. It migrates its committees onto decidiq bodies and retires its schema. It cannot start until this lands, which is the whole reason this is separate.

## Scope

### In Scope

1. **`GovernanceBody.active`** (boolean, default `true`). Whether the body may still be assigned new work. Distinct from `termEnd`: a body can be within its term and suspended, or past its term and still finishing open cases.
2. **`GovernanceBody.quorum`** (integer, minimum 2). The minimum number of members required for a valid sitting, as a NUMBER. `quorumRule` stays and keeps its meaning — the two answer different questions ("how is a quorum calculated" vs "how many people"), and Awb 7:13 asks the second.
3. **`GovernanceBody.jurisdiction`** (string). The territorial or subject-matter competence.
4. **`GovernanceBody.statutoryBasis`** (string, optional). Replaces the lossy BAC/VKK/VTH collapse with something that generalises: the legal instrument the body is constituted under (`Awb 7:13`, `Wabo`, a local verordening). A Dutch tripartite enum would not survive contact with the non-Dutch bodies decidiq already models.
5. **`Membership.external`** (boolean, default `false`). Whether this member sits from outside the administrative organ. Awb 7:13(2) requires the chair of an objection advisory committee not to be employed by it, and `independenceStatus` cannot carry that — it encodes corporate-governance independence, a different question with a different answer set.
6. **A write seam.** `ApiController` exposes `governance-bodies` read-only (`GET /api/v1/{resource}` and `/{id}`). This change adds create/update for `governance-body` behind the existing scope mechanism, with a `governance-bodies:write` scope. Without it there is no supported way for another app to place a body here, and the migration would have to reach into decidiq's register directly — which ADR-022 and ADR-066 both forbid.
7. **Seed + surface**: one seeded bezwaaradviescommissie demonstrating the fields, and the new fields shown on `GovernanceBodyDetail`.

### Out of Scope

- **Migrating dossiq's committees.** That is dossiq's change, after this lands. It is a fan-out (each `members[].uid` needs a `Person` and a `Membership`), and doing it here would put dossiq's data model in decidiq's repo.
- **`bacAdviceRequest`.** The advice REQUEST is a separate object with its own lifecycle, and `advisory-opinion-workflow` already models the general shape (`Adviesaanvraag` / `Advies`). Whether Awb objection advice reuses that or needs its own is a question for the dossiq-side change, once bodies are shared.
- **Retiring anything in dossiq.** Nothing here removes a capability from another app.
- **A BAC/VKK/VTH enum.** Deliberately declined in favour of `statutoryBasis` — see In Scope 4.

## Risks

- **Two quorum fields invite confusion.** Mitigated by making the descriptions state plainly that `quorumRule` is the calculation method and `quorum` the member count, and by not defaulting `quorum` — an unset value means "not specified", never "0".
- **A write seam widens the API surface.** It is scoped, authorised the same way the read side is, and limited to `governance-body`; it does not open every resource for writing.
- **`external` and `independenceStatus` look similar.** Both descriptions name the other and say what it is not. This is the `display-vs-stored` failure mode in a new place: two fields that read alike and mean different things.
