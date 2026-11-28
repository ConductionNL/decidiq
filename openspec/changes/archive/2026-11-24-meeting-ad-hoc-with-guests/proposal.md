---
kind: code
depends_on: []
---

# Proposal: meeting-ad-hoc-with-guests

## Summary

Any signed-in user can create a meeting, but only administrators can change it afterwards, and invitees come only from existing participant records. This change lets the organiser of an ad hoc meeting keep editing it and invite guests from outside by email.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### pla-20, let any user set up an ad hoc meeting with its own agenda and papers and invite people from outside the organisation

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `featurePage`, originUrl https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/mijn_vergaderingen/.

Matrix evidence, verbatim:

> lib/Settings/decidesk_register.json:62 register authorization lets any authenticated user create objects (create: authenticated) while update and delete are for decidiq-administrators; Meetings index (/meetings) create form, agenda and Documents widgets on MeetingDetail; src/dialogs/MeetingParticipantAddDialog.vue:23 picks participants from existing participant records only, no invite for people outside the organisation

Matrix note, verbatim:

> Any signed-in user can create a meeting, but the register lets only administrators change it afterwards, and invitees come from the existing participant list, not from outside. Whether OpenRegister lets the creator edit their own meeting was not checked.

Competitor cells rated `yes`, verbatim:

- go-raadsinformatie: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/mijn_vergaderingen/ states everyone in the organisation can create a meeting with agenda items and documents and invite external participants

## Why

Core area (planning), with a featurePage demand (GO. Mijn Vergaderingen).

## What is built today

- Meeting create for any authenticated user; agenda, participants and documents widgets.

## What changes

1. A meeting records its organiser; the organiser may update and delete their own ad hoc meeting (authorization rule on owner).
2. The participant dialog gets Invite a guest by email: a Participant with the email and no Nextcloud account.
3. Guests receive the invitation email with the date and agenda, and a share link to the meeting papers.

## Out of scope

- Guest voting.
