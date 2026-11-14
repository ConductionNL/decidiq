---
kind: code
---

# Proposal: bodies-substitute-mandate-swap

## Summary

In a committee or a joint body a member who has to leave is replaced by their substitute for the rest of the meeting. The substitute takes the member's seat, votes for the same party and counts once towards the quorum. decidiq can record that someone is an acting holder of a position, but it cannot swap a member for a substitute during a meeting. This change lets the chair or secretary do that swap on the live meeting page, and makes voting, quorum and the seat plan follow it until the swap is ended.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### bod-18, swap a member's mandate with a substitute during a meeting, moving their groups and seat number

Own rating `no`, built.state `none`.

Demand row: origin `competitor`, originUrl https://github.com/OpenSlides/OpenSlides/blob/4.3.4/CHANGELOG.md#L20.

Matrix evidence, verbatim:

> grep -rniE 'substitute|plaatsvervang|seatNumber|replaceMember|swapMandate' in the register files and src: only approval-route substitutes (lib/Settings/register.d/81-resolve-a-manager-and-declare-silence.json:57); PositionHold.holdType (lib/Settings/register.d/70-configurable-types.json:514) can record an 'acting' hold, but nothing swaps a mandate during a meeting or moves groups and seat

Matrix note, verbatim:

> A substitute can be recorded as an acting holder of a position, but there is no action that swaps a member for a substitute during a meeting and carries over their groups and seat.

Competitor cells rated `yes`, verbatim:

- openslides: source read at 4.3.4, not driven: openslides-client/client/src/app/site/pages/meetings/pages/participants/pages/participant-list/components/participant-list/participant-list.component.ts:537 switchParticipants exchanges group_ids and number between two participants via the Swap mandates dialog (participant-switch-dialog.component.html:1).

Lane decision: `build`. Reason, verbatim: "Core area (bodies) with one competitor rated yes (openslides). The only other spec naming substitutes, commissievergaderingen, proposes its own entities and tables and cannot be applied."

## Why

Substitution is ordinary in Dutch public bodies. A committee member is replaced by a fraction colleague, and each member of the general board of a joint arrangement under the Wet gemeenschappelijke regelingen (Wgr) often has a designated substitute. Today a clerk in decidiq can only change the member's attendance to absent and add the substitute as an extra person. The substitute then has no party, no seat and no voting weight, and the quorum count drops by one while the seat is in fact filled.

## What changes

1. A participant can carry a seat number.
2. A new record, the mandate substitution, says who left which seat in which meeting, who took it, when, and who recorded it. It copies the seat, party, role and voting weight of the member who left, so the record keeps its meaning if the member's data changes later.
3. The chair or secretary of the meeting starts a substitution from a "Seats" panel on the live meeting page, and ends it there when the member returns.
4. While a substitution is active in a meeting: the member who left cannot vote in that meeting's rounds, the substitute can, the quorum counts the seat once, and a voting group that names the member resolves to the substitute.
5. No substitution starts or ends while a vote is open in the meeting.

## Out of scope

- Enforcing a list of designated substitutes per member. `PositionHold.holdType` can already record an acting hold (`70-configurable-types.json:514`); checking it at swap time is a follow-up once bodies record their substitutes that way.
- Substituting the chair or the secretary. The vice-chair takes over presiding, which is not a mandate swap.
- A standing substitution across meetings. Every substitution belongs to one meeting.

## Supersedes

The substitution part of open change `commissievergaderingen` (its `Presentielijst.plaatsvervangen-door`, `recordSubstitution()` and `POST /api/meetings/{id}/absences/{lid}/substitute`, tasks 1.9 and following). That change proposes its own committee entities beside the universal ones, which ADR-006 and the retirement of the board portal ruled out, so its substitution part cannot be built as written. This change specifies it on the universal meeting and participant.

## Risks

- `participant` is a deprecated shim for Person and Membership, but votes, quorum and presets all read participants today. This change follows those readers. When the shim retires, the substitution record moves with them; it names people through participants only.
- The live meeting page reads participants for the meeting with a client-side filter on a relation the participant schema does not declare (`src/views/LiveMeeting.vue:330` to `:336`, and its own note at `:566`). The seats panel reads the server's list instead (see design).
