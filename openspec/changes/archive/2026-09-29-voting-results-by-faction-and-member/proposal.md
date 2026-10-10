---
kind: code
depends_on: []
---

# Proposal: voting-results-by-faction-and-member

## Summary

The votes widgets show round totals only: the voter column reads a field ballots never carry, and there is no breakdown per faction. This change shows each round's result per faction and per member on motion, meeting and decision pages.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### vot-03, show the result of a vote per faction and per member

Own rating `no`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> src/components/tabs/MotionVotesTab.vue:101 'Voter' column reads row.caster (:207 casterDisplayName), but ballots carry no caster property: lib/Service/VoteBallotFactory.php voteRelations() stores the participant only in a relations array, so the column renders a dash; no grouping by Participant.party in MotionVotesTab/MeetingVotesTab/DecisionVotingTab

Matrix note, verbatim:

> Round totals are shown. There is no per-faction breakdown, and the per-member voter column reads a field the ballot never writes.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.kennisbank.notubiz.nl/handleidingen/digitaal-stemmen states after closing the voting behaviour per party and of individual members is shown; https://www.notubiz.nl/onze-diensten/videotulen pie charts per fractie and raadslid
- ibabs: https://support.ibabs.com/docs/stemregistratie-door-de-agendabeheerder.md registers votes per fractie or per participant; https://support.ibabs.com/docs/inrichting-2.md groups per party to show results per group and per individual
- go-raadsinformatie: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._stemgedrag/ states results show per agenda item and in the personal profiles of members, and https://www.gemeenteoplossingen.nl/blogs/ondersteuning_stemproces_vanuit_huis/ states results are published on the profile of fractie and raadslid
- openslides: source read and driven at 4.3.4 on 2026-09-26 (lab-decidiq-openslides): after poll.stop and poll.publish the poll page showed Yes 2 (66.667 %), No 1, Abstain 0 and a single-votes list, and the projector slide listed each member with their ballot. Source: openslides-client/client/src/app/site/pages/meetings/pages/motions/pages/motion-polls/components/motion-poll-detail/motion-poll-detail.component.html:62 votes table per member for named polls, filterable by structure level (faction) in openslides-client/client/src/app/site/pages/meetings/modules/poll/services/votes-filter.service.ts:19, with a filtered votes chart (modules/poll/components/poll-filtered-votes-chart).

## Why

Rated yes by four competitors.

## What is built today

- Round totals on MotionVotesTab, MeetingVotesTab and DecisionVotingTab.
- Open ballots link the participant in a relations array (VoteBallotFactory::voteRelations()).

## What changes

1. The votes widget reads the voter from the ballot's participant relation, resolved to the person's name.
2. A per-faction table: for each faction the for, against and abstain counts, from the voter's membership faction (or party).
3. Secret rounds keep showing totals only.

## Out of scope

- Recording named votes by the clerk (voting-named-paper-vote-entry).
