# Design: voting-ranked-preference-ballot

Kind: code. Three properties and two enum values in a register fragment, one pure counting class, one branch each in cast, tally and open, and three Vue components.

Read at development `4d7430ff`.

## What is there today

- `VotingRound.votingMethod` allows `ranked-choice` (`lib/Settings/decidesk_register.json:1359`). Nothing reads that value: the open dialog offers three other methods (`src/components/VotingRoundPanel.vue:46` to `:55`), and a request that sends `ranked-choice` opens an ordinary round.
- `VotingRound.result` allows `adopted`, `rejected`, `tied`, `invalid` (`decidesk_register.json:1394`).
- `Vote.value` is required and allows `for`, `against`, `abstain` (`decidesk_register.json:1640`, `required: [value, castAt]`).
- `VotingController::cast()` (`lib/Controller/VotingController.php:134`) refuses any other value (`:160`). `VoteCastingService::castVote()` (`lib/Service/VoteCastingService.php:127`) and `VoteBallotFactory::buildVote()` (`lib/Service/VoteBallotFactory.php:77`) write one vote per member per round; on a secret round the vote carries no participant relation (`voteRelations()`, `:151`).
- Closing counts votes in `VotingRoundResults::tally()` (`lib/Service/VotingRoundResults.php:124`) and computes the result with `VotingResultCalculator::compute()`. `VotingRoundCloser::transitionSubject()` (`lib/Service/VotingRoundCloser.php:200`) moves the subject to `decided` for `adopted` or `rejected`, and leaves it for `tied`.
- The open request is parsed by `VotingOpenRequestParser::parse()` (`lib/Service/VotingOpenRequestParser.php:47` rule enums, `:58` subject types) and the round is built by `VotingRoundPreflight::buildRoundPayload()` (`lib/Service/VotingRoundPreflight.php:233`). The tie-break rules are `rejected`, `chair-decides` and `revote` (`lib/Service/VotingService.php:71`).
- The only other ranking in `lib/` is `BudgetVotingService`, which orders budget proposals by their for votes. It has nothing to reuse here.

## Decisions

### D1. Options are data on the round

`VotingRound.options`: array of objects with `key` (string, unique in the round), `label` (string) and `person` (uuid, `$ref` Person, nullable). Two to twenty entries. They are set when the round opens and never change afterwards, because a ballot that ranks a list is only meaningful against that list.

The open request gains `options`. `VotingOpenRequestParser` refuses a ranked round with fewer than two options, more than twenty, or duplicate keys, and refuses `options` on any other method. `buildRoundPayload()` copies them onto the round.

A ranked round refuses `tieBreakRule: chair-decides`, because `chairCastingVote` holds for or against and cannot name an option. `rejected` (the tie stands) and `revote` remain.

### D2. The ballot has its own field

`Vote.ranking`: array of option keys, first preference first. `Vote.value` gains the enum value `ranked`, so a ranked vote satisfies the required field without pretending to be for or against. The existing tally ignores `ranked` values (`countVotes()` only adds `for`, `against` and `abstain`), so no other round changes meaning.

`cast()` accepts `{ "ranking": [...] }` for a round whose method is `ranked-choice` and sets `value: ranked`. It refuses a ranking that does not contain every option key exactly once. Casting again replaces the ballot, through the same slug as any vote.

### D3. Counting is a pure class

`lib/Service/BordaCount.php`, no dependencies. With N options, first place earns N minus 1 points and last place 0. It returns the points per option, the ranks, and either one winning key or the tied keys. Being pure, it is tested with plain arrays, and the tally only has to hand it the ballots.

`VotingRoundResults::tally()` branches on `ranked-choice`: it collects `ranking` from the round's votes, calls `BordaCount`, and stores on the round `rankingResult` (array of `key`, `label`, `points`, `rank`), `winningOption` (the key, empty on a tie) and `result`: `adopted` with one winner, `tied` when two or more options share the top score, `invalid` with no ballots. `votesFor`, `votesAgainst` and `votesAbstain` stay empty on a ranked round.

The close path is unchanged: `adopted` moves the subject to `decided` with outcome `adopted`, and `tied` leaves it for a revote.

### D4. The screens

- The open dialog lists "Ranked preference (Borda count)" and, when it is picked, an option editor: add, rename, reorder and remove, and pick a Person for an option.
- `src/components/RankedBallot.vue`: the options with move up and move down buttons on every row, operable by keyboard alone (ADR-059). Drag is an extra, never the only way. Submit is disabled until every option has a place.
- `src/components/RankedResultsCard.vue`: a table of rank, option and points on a closed round, with the winner marked "Elected" or the tied options marked "Tied".
- On a secret round the card shows the totals only. Ballots of a secret round carry no participant relation already (`voteRelations()`), so there is nothing per member to hide.

## Declarative or imperative

- `VotingRound.options`, `VotingRound.rankingResult`, `VotingRound.winningOption`, `Vote.ranking` and the enum values `Vote.value: ranked` are declared in a new register fragment `lib/Settings/register.d/NN-ranked-preference-ballot.json` (next free number at build time). Fragments union enum lists (see `81-resolve-a-manager-and-declare-silence.json`).
- The count is imperative. An `x-openregister-aggregations` block sums or counts one field; a Borda count weights a position inside an array per ballot and then ranks, which no declared aggregation expresses. It lives beside the existing tally, which is already the home of every round's result rules.

## Seed data

In `lib/Settings/profiles/association.json` (`x-openregister.seedData.objects`), one closed ranked round and three ballots:

- `voting-round` `stemming-voorkeur-clubhuis`: `votingMethod: ranked-choice`, `isSecret: false`, options `renoveren` ("Renovate the clubhouse"), `nieuwbouw` ("Build a new clubhouse"), `huren` ("Rent a hall"); `rankingResult` renoveren 5 points, nieuwbouw 3, huren 1; `winningOption: renoveren`; `result: adopted`.
- three `vote` objects on it with `value: ranked` and rankings `[renoveren, nieuwbouw, huren]`, `[nieuwbouw, renoveren, huren]` and `[renoveren, huren, nieuwbouw]`.

With three options a first place is worth 2 points: renoveren 2 + 1 + 2 = 5, nieuwbouw 1 + 2 + 0 = 3, huren 0 + 0 + 1 = 1. The seed test recomputes them with `BordaCount` so a wrong seed fails.

## Risks

- A round opened with options that later turn out wrong cannot be edited. The chair closes it as invalid and opens a new one. Editing options under cast ballots would silently change what those ballots mean.
- `Vote.value: ranked` is visible to any consumer that switches on value. `MotionVotesTab` and `DecisionVotingTab` show the value as text; the task that adds the ballot also teaches both tabs to show the ranking instead.
