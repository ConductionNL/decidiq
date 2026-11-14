---
kind: code
depends_on: []
---

# Proposal: bodies-shared-body-participations

## Summary

A joint body can record the organisations that take part in it, but only through object lists nobody has verified can add a row. This change gives the shared body a Participating organisations widget where the secretary adds, edits and ends a participation (seats, voting weight, accession and exit date), and shows per organisation how many of its seats are filled by members sitting on its behalf.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### bod-13, share one body between several organisations, such as a joint arrangement of municipalities

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Settings/register.d/56-shared-governance-bodies.json BodyParticipation (sharedBody, participant, seats, votingWeight, accessionDate, exitDate) and Membership.onBehalfOf; bodyType 'shared-body' at lib/Settings/decidesk_register.json:553; src/manifest.json:482-483 body-participating-orgs and body-shared-participations object-lists on GovernanceBodyDetail; no BodyParticipation index/detail page

Matrix note, verbatim:

> A joint body and the organisations taking part in it (seats, weight, accession and exit) can be recorded and shown on the body page. It is modelling inside one installation: the participating organisations do not get their own access to the shared body, and there is no BodyParticipation page of its own, so adding rows depends on the object-list's add-in-context default, which I did not verify.

No competitor cell is rated `yes`.

## Why

bod-13 sits in the core area (bodies). The schema exists; the screen to maintain it does not.

## What is built today

- BodyParticipation schema and Membership.onBehalfOf (register.d/56).
- Object lists 'Participating organisations' and 'Shared-body participations' on GovernanceBodyDetail.

## What changes

1. A widget on GovernanceBodyDetail for bodies of type shared-body with Add, Edit and End participation (exitDate today) through a dialog under src/dialogs/.
2. Each row shows seats and filled seats (active memberships with onBehalfOf that organisation).
3. The add-member dialog on a shared body asks on behalf of which participating organisation.

## Out of scope

- Separate access for each participating organisation to the shared body (a security design of its own).
