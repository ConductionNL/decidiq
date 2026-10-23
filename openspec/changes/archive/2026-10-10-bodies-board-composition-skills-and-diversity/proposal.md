---
kind: code
---

# Proposal: bodies-board-composition-skills-and-diversity

## Summary

A supervisory board is expected to know what expertise it needs, which of its members bring it, where it falls short, and how diverse it is. decidiq keeps none of that beyond a person's gender and a member's independence. This change lets a body list the competences it needs, lets each member's competences be recorded, shows a skills matrix with the gaps, and shows the body's composition figures against the targets it set itself.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### bod-11, see the board's skills matrix and which expertise is missing

Own rating `no`, built.state `none`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> Searched 'skill', 'expertise', 'competenc' in schemas, src and services: no skills/expertise schema or matrix. Only hit: a seeded self-evaluation question 'What skills or perspectives is the board missing?' in lib/Settings/profiles/corporate.json:1123

Matrix note, verbatim:

> No skills or expertise data is kept per member, so no matrix or gap view exists. The self-evaluation can ask about missing skills as a survey question.

No competitor cell is rated `yes`. notubiz, ibabs, go-raadsinformatie and diligent-boards are `unknown`; diligent-boards notes that "the April rating cited a Skills Matrix; today only the separate Director Network add-on keeps a personal expertise profile (https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-add-ons/director-network/editing-your-profile.htm), no board skills matrix is documented". openslides is `no`.

Lane decision: `build`. Reason, verbatim: "Core area (bodies); no competitor rated yes and no demand."

### bod-12, track diversity figures for the board

Own rating `no`, built.state `none`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> Searched 'diversity', 'gender' in lib/Service, src: Person.gender exists (lib/Settings/decidesk_register.json:183 Person) and seed data fills it, but nothing aggregates it; GovernanceReportingService computes independenceRatio only and no frontend calls /api/governance-reports (grep under src/: none)

Matrix note, verbatim:

> No diversity figures. Gender sits on the Person record and an independence ratio exists in an API-only governance report.

No competitor cell is rated `yes`. openslides is `partial`: "the mandate check page (routed at participants-routing.module.ts:62) breaks present and total members per group down by gender with percentages ... Gender figures only, no wider diversity tracking." diligent-boards is `unknown` ("https://www.diligent.com/solutions/board-diversity describes peer benchmarking of diversity policies and a candidate network").

Lane decision: `build`. Reason, verbatim: "Core area (bodies); one competitor partial and no demand. Clustered with bod-11 on the board composition view."

## Why

The Dutch Corporate Governance Code asks a supervisory board to draw up a profile of its size and composition, including the expertise it needs and its diversity, and to report on it. The Wet ingroeiquotum en streefcijfers (2022) requires large companies to set targets for the balance of men and women on their boards and to report progress, and sets a quota for the supervisory boards of listed companies. Housing corporations, healthcare boards and pension funds work with the same kind of profile. A board using decidiq keeps that profile in a spreadsheet next to the app today.

The rows are core area rows without competitor demand, so this change stays small: one list per body, one record per member competence, and figures computed from data the app already holds.

## What changes

1. A body lists the competences it needs, each with how many members should have it.
2. A member, or the secretary for them, records a competence against their membership with a level: basic, experienced or expert. The chair or secretary confirms it.
3. A "Composition" widget on the body's page shows the skills matrix (members against competences) and marks each competence that fewer members hold than the body asked for.
4. The same widget shows the body's current composition by gender, age band, nationality, independence and whether members come from outside, each with a "not recorded" count.
5. A body can set its own targets, for example "at least one third women". The figures show whether each target is met.

## Out of scope

- Benchmarking against other organisations (Diligent's peer benchmarking) and candidate search.
- Ethnic or cultural background. It is a special category of personal data under the GDPR and needs a lawful basis a board app cannot assume.
- Filing the figures with a regulator. The governance report can pick them up later.

## Builds on

- `Person.gender`, `Person.birthDate`, `Person.nationality`, `Membership.independenceStatus` and `Membership.external`, all present today.
- The board self-evaluation (`board-self-evaluation`), whose seeded question about missing skills can now be answered with the matrix beside it.

## Risks

- On a board of five, a figure identifies people. The widget shows only data its viewer can already read on each person, and shows counts, never a list of names per value.
- A self-recorded competence is a claim. The level stays marked "unconfirmed" until the chair or secretary confirms it, and the gap count uses confirmed competences only.
