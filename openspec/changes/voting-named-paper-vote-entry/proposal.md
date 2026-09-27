---
kind: code
depends_on: [voting-chair-close-and-amendment-rounds]
---

# Proposal: voting-named-paper-vote-entry

## Summary

When a council votes by roll call or on paper, the clerk writes down each member's choice. decidiq can only take the totals afterwards: for, against, abstain. This change lets the chair or secretary record each named member's choice on the round, per member or per party at once, and computes the totals from those records.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### vot-21, record a named vote held on paper, entering each member's choice afterwards

Own rating `partial`, built.state `built`.

Demand row: origin `featureRequest`, originUrl https://github.com/OpenSlides/OpenSlides/issues/6591. The openslides cell quotes it: "Open request https://github.com/OpenSlides/OpenSlides/issues/6591 asks for analog nominal voting."

Matrix evidence, verbatim:

> src/components/VotingRoundPanel.vue:50 'Show of hands' voting method, :859 posts totals to /api/voting-rounds/{id}/tally -> lib/Controller/VotingController.php:327 tally() (chair or secretary) saves only votesFor, votesAgainst and votesAbstain via saveShowOfHandsTally; no endpoint records a choice per named member afterwards

Matrix note, verbatim:

> A vote held outside the system can be entered afterwards, but only as totals for, against and abstain. Each member's choice cannot be recorded by name.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/stemregistratie-door-de-agendabeheerder.md states the agenda manager enters votes afterwards for all participants, per fractie or per participant

Lane decision: `build`. Reason, verbatim: "Partial and built with featureRequest demand (OpenSlides#6591) and one competitor rated yes (ibabs): a paper vote can be entered as totals only. Entering each named member's choice is specified."

## Why

A roll call vote (hoofdelijke stemming) is the vote where each member's choice is on record by name. Gemeentewet article 32 lets any council member ask for an oral vote, and associations and boards hold the same kind of vote on paper. The griffier or secretary writes the names down as the vote happens. Today the only way into decidiq is three numbers, so the record of who voted how lives on paper next to the app. It also cannot reach a member's voting record, which `bodies-member-profile-and-voting-record` publishes.

## What changes

1. A voting method `roll-call`: a round whose votes are recorded by the chair or secretary, not cast by the members.
2. A write endpoint that takes a list of named choices for an open roll-call round and stores one vote per member. Re-entering the list corrects it instead of doubling it.
3. Each recorded vote says it was recorded, by whom, and when the vote was held, so a recorded vote never reads like one the member cast.
4. An entry sheet on the voting round panel: one row per member of the meeting, with a control that sets a whole party at once.
5. Closing a roll-call round computes the totals from the recorded votes through the existing tally path.

## Out of scope

- Correcting a closed round. That is `vote-review-and-recount` (REQ-VRR-001), which already exists.
- Publishing each member's choice to residents. That is `bodies-member-profile-and-voting-record` (row pub-14).
- Weighted roll calls. A recorded vote carries weight 1, as a cast vote does today (`VoteBallotFactory.php`), and the weighted method stays as it is.
- Scanning paper ballots.

## Supersedes

The per-member roll call part of open change `motie-amendement-administratie` (its `stemming-administratie` capability, "hoofdelijke stemming per raadslid met fractie-snapshot"). That change proposes its own Motion schema beside the universal decision and cannot be applied as written.

## Builds on

- `vote-casting` REQ-VCT-003 (show-of-hands totals entered by the chair). That stays for votes where names are not recorded.
- `voting-chair-close-and-amendment-rounds`, for the per-meeting role check on tally entry (REQ-VCR-003) and the permissions answer the panel reads (REQ-VCR-002). This change depends on it.
- `decision-methods`, which says a vote sub-variant is a `VotingRound.votingMethod` value and never a new stage method. `roll-call` follows that rule.

## Risks

- A clerk could overwrite a member's own electronic vote. The endpoint refuses to touch a vote the member cast and names that member in the refusal.
- A named record of a secret ballot defeats the secret. The endpoint refuses on secret rounds.
- A typo in the member list. The endpoint accepts only active participants of the round's meeting and rejects the whole list, with the unknown ids, if one is wrong, so a half-saved sheet never exists.
