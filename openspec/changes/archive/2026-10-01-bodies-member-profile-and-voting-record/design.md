# Design: bodies-member-profile-and-voting-record

Kind: code. Two properties in a register fragment, one manifest page with two custom widgets, one link, one read endpoint, and a publication rule in the ORI controller. The JSON part is small beside the code.

Read at development `4d7430ff`.

## What is there today

### The profile data

- `Person` (`lib/Settings/decidesk_register.json:183`) has `name`, `givenName`, `familyName`, `gender`, `image` (a photo URL, `:232`), `biography` (`:237`), `email`, `nationality` and references to its memberships and contact details. `nextcloudUserId` comes from `lib/Settings/register.d/67-model-debt-cleanup.json:35`.
- `Membership` (`decidesk_register.json:334`) has `role`, `label`, `startDate`, `endDate`, `votingWeight`, `party`, `independenceStatus`, `external`, `otherPositions`, and references to `person`, `governanceBody` and `post`. There is no portfolio.
- `AncillaryPosition` (`lib/Settings/register.d/81-integrity-disclosures-in-plain-words.json`) is a declared outside position of a person, with a lifecycle `reported`, `public`, `internal`, `ended` and a `publicationDate` that makes a public one anonymously readable.
- No manifest page renders a person. The members widget of a body, `GovernanceBodyMembersTab` (`src/components/tabs/GovernanceBodyMembersTab.vue`), reads memberships and their persons (`:245` to `:256`), shows name, role and party (`:193` to `:195`), and offers "Change role" and "Remove from body" (`:200` onwards), but no link. The only person-like page is the deprecated `ParticipantDetail` (`src/manifest.json:751`).

### The voting record

- A vote relates to its round and, on a round that is not secret, to a `participant` (`lib/Service/VoteBallotFactory.php:151`). Closing with anonymisation sets each vote's `value` to null (`lib/Service/VotingRoundCloser.php:462`).
- `VotingBehaviourService::getStats()` (`lib/Service/VotingBehaviourService.php:157`) sums one participant's votes over a body's closed rounds. `VotingBehaviourController::getStats()` (`lib/Controller/VotingBehaviourController.php:91`) lets only that participant or an admin read it, and no page calls it.
- Votes point at participants; persons are reached through `Participant.nextcloudUserId` and `Person.nextcloudUserId`, or by email. `ParticipantToPersonMembershipResolver` does that match but creates a person when none matches, which is right for a migration and wrong for a read.

### The public API

- `OriController` (`lib/Controller/OriController.php`) serves `/api/ori/v1/{resource}` as `#[PublicPage]` (`index()` at `:183`, `show()` at `:377`). `persons` and `memberships` are public reference data with no gate (`NO_LIFECYCLE_GATE`, `:103`). Motions and amendments are gated on `isPublished: public` (`buildFilters()`, `:237`). Every other resource, including `voteevents` and `votes`, is filtered on `lifecycle: published`, and `narrowToPublicVisibility()` (`:339`) refuses an object without that value. `voting-round` and `vote` have no `lifecycle` property, so both resources return nothing and every item is refused.
- `Decision.isPublished` is set to `public` by the publication flow only.

## Decisions

### D1. The profile is a manifest detail page

A new page `PersonDetail` at `/people/:id` in a manifest fragment `src/manifest.d/member-profile.json`, schema `person`:

- `person-data`: a data widget with `image`, `name`, `biography` and `email`, with `image` rendered as the photo.
- `person-memberships`: a custom widget `PersonMembershipsTab` listing the person's memberships, current first: body (linked), role, party, portfolio, start and end. Past memberships are collapsed under "Earlier memberships".
- `person-outside-positions`: an object list of `ancillary-position` filtered on `person`, showing organisation, role and whether it is paid.
- `person-voting-record`: a custom widget `PersonVotingRecordTab` over the read in D3.

`GovernanceBodyMembersTab` gets a name link to `/people/{personId}`, and `ParticipantDetail` gets "Open profile" when its participant resolves to a person.

### D2. Portfolio lives on the membership

`Membership.portfolio`: array of strings. An alderman holds "Finance" in the executive board and nothing in the council, so the portfolio belongs to the membership, not the person.

### D3. One read for a person's record

`GET /api/people/{personId}/voting-record`, route `votingRecord#forPerson`, `#[NoAdminRequired]`. A new `VotingRecordService` finds the person's participants with a read-only lookup (`PersonParticipantLookup`: Nextcloud user id first, then email; never creates), collects their votes that have a value in closed, not secret rounds, and returns per vote: date, decision title and link, the member's choice, the round result, and the member's party at the time from the membership. Secret rounds and anonymised votes are never included. Any signed-in user may read it, because a named vote in a round that was not secret is known to everyone who was in the room.

### D4. The body decides whether votes are public

`GovernanceBody.publishVotingRecords`: boolean, default `false`. A council's votes are public by law, a supervisory board's are not, and decidiq serves both. The example sets seed it `true` on the legislative bodies.

### D5. The public API publishes a vote when the rule says so

The ORI controller gets a publication rule for `votes` and `voteevents` that replaces the `lifecycle: published` filter those two resources cannot meet:

- `voteevents`: a round is public when it is closed and its subject decision has `isPublished: public`.
- `votes`: a vote is public when its round is public as above, the round is not secret, the vote has a value, and the round's body has `publishVotingRecords: true`.

`index()` and `show()` apply the same rule, so an item the collection omits is never served by id; this keeps the invariant `narrowToPublicVisibility()` states. `votes` accepts `?voter={personId}`. A public vote serializes as an ORI `Vote` with `voter` (the ORI person id), `option` (`yes`, `no`, `abstain`), `vote_event` and `group` (the party). This is PHP by design: ADR-031 names ORI adapters as code apps write.

Before changing anything, the build task calls `/api/ori/v1/persons`, `/votes` and `/voteevents` anonymously on a seeded instance and records the counts in the PR, so the change is measured against what the API returns today rather than against what the code seems to say.

## Declarative or imperative

- Declared in a new fragment `lib/Settings/register.d/NN-member-profile.json` (next free number at build time): `Membership.portfolio` and `GovernanceBody.publishVotingRecords`, each with a `title` and a description.
- The page and its object list are manifest (ADR-024). The two custom widgets exist because a person's votes are two relations away (vote, participant, person) and memberships need the body name joined in, which no declared widget expresses.
- The record read and the ORI rule are imperative. The first joins three schemas through a lookup that ADR-031 has no extension for; the second is an external publication adapter, which ADR-031 lists as app code.

## Seed data

In `lib/Settings/profiles/municipality.json`:

- a new body `college-van-bw-amsterdam` (`bodyType: executive-board`) and a membership `m-femke-college-amsterdam` for Femke Halsema in it, with `portfolio: ["Public order and safety", "Communication"]`; `m-marie-amsterdam` (Marie Janssen, D66, council member) stays without a portfolio, so the page shows both cases;
- `gemeenteraad-amsterdam` gets `publishVotingRecords: true`;
- one public `ancillary-position` for Marie Janssen: board member of a local housing foundation, unpaid.

In `lib/Settings/profiles/corporate.json`, `raad-van-commissarissen-acme-bv` keeps the default `false`, so the example sets show both answers.

## Risks

- The ORI API may return nothing anonymously for reasons outside this controller (OpenRegister RBAC for anonymous reads, or a filter shape). The measurement above is the control: if `persons` also returns nothing anonymously today, that is a separate defect and the PR says so instead of claiming the votes are published.
- A member who changed party keeps their party at the time on each vote, because the record reads the membership that was active on the vote's date.
