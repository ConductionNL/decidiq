---
kind: code
depends_on: [voting-chair-close-and-amendment-rounds]
---

# Proposal: voting-ranked-preference-ballot

## Summary

A board choosing between three candidates or three variants of a plan often asks members to rank them. decidiq lists `ranked-choice` as a voting method and does nothing with it. This change lets a chair open a round with a list of options, lets each member rank all of them, counts the ballots with a Borda count, and shows the ranking with its points.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### vot-07, rank options or candidates in order of preference

Own rating `no`, built.state `none`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> 'ranked-choice' appears only in the VotingRound.votingMethod enum (decidesk_register.json); VotingRoundPanel.vue open dialog offers for-against-abstain/show-of-hands/weighted only; grep ranked in lib/Service: only BudgetVotingService ranking proposals by votesFor

Matrix note, verbatim:

> Ranked voting is an enum value with no implementation.

No competitor cell is rated `yes`. notubiz, ibabs, go-raadsinformatie and diligent-boards are `unknown` ("ranked voting not described"); openslides is `no` ("pollmethod only Y, YN, YNA and N with max_votes_per_option (line 60) for cumulative votes; grep for rank in poll.yml, option.yml and vote.yml returns nothing").

Lane decision: `build`. Reason, verbatim: "Archived 2026-05-11-p2-motion-and-voting-other-t1 specified a ranked-choice ballot (REQ-PRF-001, REQ-PRF-002); ranked-choice is only an enum value today. Treated as build per the rule."

## Why

The capability was specified in May, archived with its tasks unticked, and its spec `openspec/specs/preferential-ballot/spec.md` was marked `status: done`. Nothing of it exists: no rank input, no Borda count, no results table. The enum value invites a chair to pick a method the app cannot run. Either the method works or the enum value goes; the decision rule says build, so this change specifies it against the code as it stands.

The old spec also no longer fits the code. It stores the candidates as a free text note, writes a JSON string into `Vote.value`, whose enum allows only for, against and abstain, and writes the winner's name into `VotingRound.result`, whose enum allows only adopted, rejected, tied and invalid. Every one of those writes would be refused by schema validation. This change modifies those requirements so they describe fields that can hold the data.

## What changes

1. A ranked round carries its options as structured data: a key, a label and optionally a Person.
2. A ranked vote carries the member's full ordering in its own field. `Vote.value` gets one more enum value, `ranked`, so the required field stays valid.
3. The cast endpoint accepts an ordering for a ranked round and refuses a partial or duplicated one.
4. Closing a ranked round runs a Borda count and stores the points per option, the winning option, and `adopted` or `tied` as the result.
5. The panel offers the method, an option editor, a keyboard-operable ranking ballot and a ranking table.

## Out of scope

- Other counting methods, such as instant runoff or single transferable vote. The ballot shape (a full ordering) supports them later without a data change.
- Voting on an appointment decision's own candidates. Appointment decisions have no voting surface today (`DecisionVotingTab` is read only). When one is added, it can fill the options from `Decision.candidates`, which has the same person-or-name shape.
- Publishing a ranked result to ORI in a ranked form. ORI publication keeps publishing the result, as it does for every round.
- Citizen ranked votes (`CitizenVote`), which belong to `participation-citizen-advisory-vote-on-motions`.

## Supersedes

- The preferential ballot part of archived change `2026-05-11-p2-motion-and-voting-other-t1` (its tasks 5.1 to 5.3 and 9.1 to 9.4, never ticked). This change replaces those tasks.
- The requirements REQ-PRF-001 to REQ-PRF-005 in `openspec/specs/preferential-ballot/spec.md`, which this change modifies. That spec's `status: done` is not true today; it should read `planned` until this change is built.

## Depends on

`voting-chair-close-and-amendment-rounds`, for the permissions answer that decides who sees the open dialog and the close control.

## Risks

- A Borda count rewards broad acceptability over first preferences. That is a property of the method, and the results table shows the points, so a body can see why an option won.
- Ranking many options is tedious. The editor caps a round at 20 options.
- A tie at the top has no automatic winner. The round stores `tied` and names the tied options, and the existing revote rule can open one more round.
