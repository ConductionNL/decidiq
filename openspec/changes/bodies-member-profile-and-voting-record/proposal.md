---
kind: code
---

# Proposal: bodies-member-profile-and-voting-record

## Summary

Nobody can open a member's page in decidiq. The data for one mostly exists: a person has a photo and a biography, a membership has a party and a role, and outside positions are declared. This change adds a profile page per person with their photo, party, committees, portfolio, outside positions and voting record, links every member list to it, and publishes each member's voting record for residents through decidiq's public ORI API, under a rule the body sets.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### bod-05, give every member a profile page with party, photo, committees and portfolio

Own rating `no`, built.state `none`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> Searched: no manifest page for schema 'person' (src/manifest.json, src/manifest.d/*); Person schema at lib/Settings/decidesk_register.json:183 has image/biography but no page renders it; members tab rows (src/components/tabs/GovernanceBodyMembersTab.vue) have no row link; 'portfolio' appears nowhere in schemas or src. Only the deprecated Participant has a detail page (src/manifest.json:751, /participants/:id, no menu entry) with party but no photo/committees/portfolio

Matrix note, verbatim:

> The data for a profile partly exists (Person.image, biography; Membership.party), but no page shows a member profile. There is no committees overview per person and no portfolio field.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.kennisbank.notubiz.nl/handleidingen/raadplegen-politiek-portret states each member profile shows party, contact information, nevenfuncties, standpunten, recent speaking moments and voting behaviour; https://www.kennisbank.notubiz.nl/kennisclips/navigeren-door-het-politiek-portret shows photo, name, functie in raad or commissies
- ibabs: https://support.ibabs.com/docs/publieke-profielen.md states public profiles show fractie, functie, contact details, personal text, social media and custom fields such as Mijn onderwerpen; https://utrecht.bestuurlijkeinformatie.nl/People shows members per party

Lane decision: `build`. Reason, verbatim: "Core area (bodies) and two competitors rated yes (notubiz, ibabs); archived 2026-05-11-p4-collaboration specified participant profiles with photo and party and none is built."

### pub-14, publish each member's voting record for citizens to see

Own rating `no`, built.state `none`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Controller/VotingBehaviourController.php:106 getStats() is #[NoAdminRequired], own participant or admin only, no src caller; lib/Service/OriPublicationService.php:232 buildJsonLd() pushes totals only; lib/Controller/OriController.php:70 'votes' resource filters lifecycle=published but the vote schema (decidesk_register.json Vote) has no lifecycle field

Matrix note, verbatim:

> No citizen-facing per-member voting record. Whether the public ORI /votes resource returns anything depends on how OpenRegister treats a filter on a field the vote schema lacks; not settled by reading.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.notubiz.nl/onze-diensten/gekoppelde-modules states Politiek Portret lets residents see the voting behaviour of council members; https://www.kennisbank.notubiz.nl/kennisclips/navigeren-door-het-politiek-portret lists every vote per member
- ibabs: https://utrecht.bestuurlijkeinformatie.nl/People/Details shows public Stemgedrag per member; https://support.ibabs.com/docs/live-stemmen-door-deelnemers.md states results per person under Wie is wie
- go-raadsinformatie: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._stemgedrag/ states vote results are shown in the personal profiles of council members on the website

Lane decision: `build`. Reason, verbatim: "Three competitors rated yes (notubiz, ibabs, go-raadsinformatie). Archived member-voting-behaviour-tracking keeps statistics private to the member and chair, and motie-amendement-administratie cannot be applied (parallel Motion schema), so nothing publishes a member's record. Clustered with bod-05 on the member profile."

## Why

A council's residents want to know who represents them and how they voted. Three of the five products rated here show it on a public profile. decidiq already publishes council data through a public ORI API that Open Raadsinformatie and portals read, but that API returns no votes at all: it filters `/votes` and `/voteevents` on a `lifecycle` field the vote and voting round schemas do not have. Inside the app, a clerk or member cannot even open a colleague's page.

## What changes

1. A profile page per person, `/people/:id`: photo, name, biography, the memberships with body, role, party and portfolio, the declared outside positions, and the voting record.
2. `Membership.portfolio`: the subjects a member holds in that body, such as an alderman's "Finance" and "Housing".
3. Every member list links to the profile: the members widget of a body and the deprecated participant page.
4. A body decides whether its members' votes are public, with a new `GovernanceBody.publishVotingRecords`. It defaults to off, and legislative bodies are seeded with it on.
5. The public ORI API returns a vote when it is public: its round is closed, not secret and not anonymised, its decision is published, and its body publishes voting records. Each vote names its voter as an ORI person, so any portal can show a member's record. `/voteevents` follows the same rule without the per-member part.

## Out of scope

- The public web page itself. Portals for people without an account are portaliq's (ADR-046). The `portal-contribution` design already records that a public list cannot be expressed in the per-subject portal reader and belongs on a public read API with publication filters. This change delivers that API; the page that renders it is to be specified in portaliq.
- Speaking time and positions taken (notubiz "standpunten"). Different data, not asked by these rows.
- The private statistics of `member-voting-behaviour-tracking`, which stay as they are.

## Supersedes

- The participant profile part of archived change `2026-05-11-p4-collaboration` (`participant-identification`), which was never built.
- The public voting record part of open change `motie-amendement-administratie` (`stemming-administratie`), which proposes its own Motion schema beside the universal decision and cannot be applied.

## Risks

- Publishing a vote is publishing personal data. The rule is off by default, set per body, and only covers rounds that were not secret; the vote of a council member in a public meeting is public by law, a vote of a private board is not.
- Votes point at participants, not persons. The record uses a read-only lookup from participant to person (Nextcloud user id first, then email) and never creates a person, unlike the migration crosswalk.
- `/votes` starting to return data is a visible change for any harvester. It is the intended change: today it returns nothing for anyone.
