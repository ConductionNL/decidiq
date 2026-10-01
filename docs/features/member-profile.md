# Member profile and voting record

Every person in decidiq has a profile page. It shows who they are, which
bodies they sit in and for which party, the subjects they hold, the outside
positions they declared, and how they voted in open votes.

## Open a profile

- On a body's page, the **Members** widget links each name to the member's
  profile.
- On a participant's page, the **Profile** widget offers **Open profile** when
  the participant belongs to a person. decidiq matches a participant to a person
  by Nextcloud account first and email address second.

The profile lives at `/people/<id>` in decidiq.

## What the profile shows

- **About**: name, biography and email.
- **Memberships**: the photo, or the initials when there is no photo, then the
  current memberships with body, role, party and portfolio. Earlier memberships
  are folded away under **Earlier memberships**.
- **Voting record**: the member's votes in closed rounds that were not secret,
  newest first, with the date, the decision, the member's vote, the result and
  the party the member belonged to on that date.
- **Outside positions**: the ancillary positions the member declared, with
  whether they are paid.

Secret rounds and anonymised votes never appear in the voting record. Reading
a profile never creates a person or a participant.

## A portfolio

A portfolio belongs to a membership, not to a person. An alderman holds
"Finance" and "Housing" in the executive board and nothing in the council. To
set it, open the membership and fill in **Portfolio**, one subject per entry.

## Publishing votes by name

A body has the setting **Publish voting records**. It is off by default. A
council, whose votes are public by law, turns it on; a supervisory board leaves
it off. The setting decides what the public ORI API may publish; the profile
page inside decidiq shows the voting record to every signed-in user who may
read the person, either way.

The public ORI API applies one rule to votes. A vote is published only when its
round is closed and not secret, its decision is published, it has a value, and
its body publishes voting records. `GET /apps/decidiq/api/ori/v1/votes` then
lists each such vote with its voter, option (`yes`, `no` or `abstain`), vote
event and the voter's party at the time; add `?voter=<personId>` to get one
member's votes. `GET /apps/decidiq/api/ori/v1/voteevents` lists closed rounds on
published decisions with their totals and never a member's own vote. Any other
vote or round answers 404 by id. To check a council, turn on **Publish voting
records** for its body and open `/apps/decidiq/api/ori/v1/votes` in a private
browser window.

## For developers

The voting record is read from `GET /apps/decidiq/api/people/<personId>/voting-record`.
It answers `personId` and `votes`, a list of rows with `vote`, `date`,
`decision`, `choice`, `result`, `party` and `body`. A person the caller may not
read answers 404, and a request without a signed-in user answers 401.
