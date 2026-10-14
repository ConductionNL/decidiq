---
kind: code
depends_on: []
---

# Proposal: participation-citizen-advisory-vote-on-motions

## Summary

Some councils and associations want to hear residents or members before they vote on a motion. This change lets the griffie open a motion for an advisory vote by residents through the portal, count one vote per verified resident, close it, and show the result on the motion page beside the council's own vote, clearly marked as advisory.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### par-08, let citizens cast an advisory vote on a motion

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "The schema has a motion reference, but nothing lets a citizen vote on a motion."

No demand row (origin `code`).

No competitor rated yes.

## Why

The rule for this pass builds a row that an archived change covered and that is still `none`. Archived `2026-05-11-p3-citizen-participation` specified exactly this: "CitizenVote entity: records individual citizen votes on motions/amendments" (`proposal.md:35`), `citizenVotingAllowed` on the motion (`proposal.md:63`), and cast and results endpoints (`tasks.md:20-22`), none ticked. What survived is data without a path: `Decision.citizenVotingAllowed` and `citizenVotingMethod` (folded from the retired Motion schema) and `CitizenVote.motionId`, while the only writer of citizen votes is `AdvisoryVoteService::applyAdvisoryTally()` for budget proposals (`lib/Service/AdvisoryVoteService.php:97`).

Residents have no Nextcloud account, so under ADR-046 they vote through portaliq, using the create-action contract decidiq already fills for reactions and budget proposals (`lib/Portal/PortalContributionProvider.php:308` `citizenActions()`).

## What changes

1. A motion gets `citizenVotingStatus` (`not-open`, `open`, `closed`) next to the existing `citizenVotingAllowed`.
2. The griffie opens and closes the advisory vote from the motion page. Opening needs `citizenVotingAllowed` and a motion that is published.
3. decidiq's portal contribution gets a create action Give your advice on this motion (voor, tegen, onthoud) that portaliq offers only while the motion's advisory vote is open, and only to residents signed in at trust level substantial (DigiD or an equivalent), so a vote is one person's.
4. decidiq refuses a second vote by the same resident on the same motion.
5. The motion page shows the advisory result (voor, tegen, onthoud) beside the council's vote, labelled "Advisory vote by residents, not binding". After closing, residents read the totals through the portal.

## Out of scope

- Ranked or weighted citizen voting. `citizenVotingMethod` keeps its enum, and this change supports `simple` only; opening a motion with another method is refused.
- Anonymous referenda without identification.
- Binding votes. The council's own vote stays the decision.

## Risks

- One resident, one vote needs a stable identity. The action requires trust level substantial and scopes the vote to the portal's subject reference, and the duplicate check runs on that reference.
- An advisory tally could be read as the outcome. The motion page shows it in its own widget with the not-binding label, and nothing in the statutory tally reads citizen votes, exactly as the budget proposals already keep them apart.
