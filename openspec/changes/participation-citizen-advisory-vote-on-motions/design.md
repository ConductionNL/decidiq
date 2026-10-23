# Design: participation-citizen-advisory-vote-on-motions

Read at decidiq development `c0b2f5bb`.

## What exists

| Piece | Where |
|---|---|
| Motion fields | `lib/Settings/decidesk_register.json` `Decision.citizenVotingAllowed` (boolean, default false) and `citizenVotingMethod` (`simple`, `weighted`, `ranked`), both "folded from the retired Motion schema"; `Decision.isPublished` and the public read rule `{ group: public, match: { isPublished: public } }` |
| Citizen vote | `CitizenVote` (slug `citizen-vote`, `schema:VoteAction`): `voteValue` (voor, tegen, onthoud for simple voting), `voterId`, `motionId`, `motion`, `castAt`, `weight`; required `voteValue`, `voterId`, `castAt`; its description keeps advisory tallies apart from statutory VotingRound tallies |
| Only writer today | `lib/Service/AdvisoryVoteService.php:97` `applyAdvisoryTally()` for budget proposals (voor or tegen, duplicate check per proposal and voter) |
| Portal contribution | `lib/Portal/PortalContributionProvider.php:136` `getContribution()` for audience `citizen`; `:219` `citizenCollections()` (including `citizenVotes`, scoped on `voterId`); `:308` `citizenActions()` (create actions `createReaction`, `createBudgetProposal`, each with `scopeField`, `minTrust`, `fields`, `defaults`, `parentConstraint { field, parentSchema, statusField, statusValue }`) |
| Motion page | `src/manifest.json` `MotionDetail` (`/motions/:id`, schema decision) |
| Pre-save hooks | `lib/AppInfo/Registrar/ObjectListenerRegistrar.php` (`ObjectCreatingEvent`) |

## Approach

### Fields

Fragment `lib/Settings/register.d/92-citizen-advice-on-motions.json` adds `Decision.citizenVotingStatus`, enum `not-open`, `open`, `closed`, default `not-open`, with `x-openregister-lifecycle` on that field: `not-open` to `open`, `open` to `closed`. It also adds `Decision.citizenAdviceFor`, `citizenAdviceAgainst`, `citizenAdviceAbstain` (integers) as `x-openregister-aggregations` counts over `CitizenVote` with `motion = @self.id` and the matching `voteValue`.

### Opening and closing

`lib/Controller/CitizenAdviceController.php`, `#[NoAdminRequired]`, `POST /api/motions/{id}/citizen-advice/open` and `/close`. The caller must be in `decidiq-secretariat` or hold the chair or secretary role on the motion's meeting (`ParticipantResolver::hasRole()`). Open requires `decisionType` motion, `citizenVotingAllowed` true, `citizenVotingMethod` `simple`, and `isPublished` `public`; it refuses otherwise with the reason. Both write `citizenVotingStatus` through OpenRegister, whose lifecycle rejects any other move. The decision's own `update` rule is admin only, so the controller writes as the system after its own check, the way the other motion actions do.

### The resident's vote

`PortalContributionProvider::citizenActions()` gets:

```
id: castMotionAdvice, type: create, label: "Give your advice on this motion",
schema: citizen-vote, scopeField: voterId, minTrust: substantial,
fields: [motion, voteValue],
defaults: { castAt: <now>, weight: 1 },
parentConstraint: { field: motion, parentSchema: decision, statusField: citizenVotingStatus, statusValue: open }
```

and `citizenCollections()` gets `motionsOpenForAdvice` (schema decision, `anonymous: true`, fields title, text, submittedAt, citizenVotingStatus, and the three advice counts), filtered on `decisionType` motion and `isPublished` public, so residents can find and read the motions without signing in and see the totals once closed.

`lib/Listener/CitizenAdviceListener.php` on `ObjectCreatingEvent` for `citizen-vote` with `motion` set: copies `motion` into `motionId`, refuses a `voteValue` outside voor, tegen and onthoud, refuses when the motion's `citizenVotingStatus` is not `open` (the portal checks too, the server must not trust it), and refuses a second vote with the same `voterId` on the same motion, using the relation filter `AdvisoryVoteService` already uses.

### Motion page

`MotionDetail` gets `motion-citizen-advice`: three stat tiles over the aggregations with the caption "Advisory vote by residents, not binding", and Open and Close actions for the secretariat through the endpoint.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Status and its moves | Declarative field and `x-openregister-lifecycle` | Guarded state map |
| Counts | Declarative `x-openregister-aggregations` | Counts over related objects |
| Offering the vote to residents | Declarative portal contribution entries | ADR-046 contract |
| Opening rules, duplicate and window checks | Imperative controller and listener | Cross-object rules and the portal must not be the only guard |

## Seed data

Municipality example set: the published motion "Motie meer groen in de wijk" with `citizenVotingAllowed` true, method `simple`, status `closed`, and five citizen votes (three voor, one tegen, one onthoud) from pseudonymous voter ids, so the result widget shows on a fresh install.

## Files

- `lib/Settings/register.d/92-citizen-advice-on-motions.json`, `lib/Settings/profiles/municipality.json`
- `lib/Controller/CitizenAdviceController.php`, `appinfo/routes.php`, `lib/Listener/CitizenAdviceListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`, `lib/Portal/PortalContributionProvider.php`
- `src/manifest.json` (`MotionDetail`)
- `tests/Unit/Listener/CitizenAdviceListenerTest.php` (real `ObjectCreatingEvent`), `tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/Unit/Controller/CitizenAdviceControllerTest.php`, `tests/newman/citizen-advice-on-motions.json`
