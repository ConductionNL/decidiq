---
kind: code
depends_on: [decidesk-base]
status: superseded
superseded-by: organisation-facet-composition
---

> **SUPERSEDED (2026-08-19).** This draft pre-dates ADR-006 (one universal
> `GovernanceBody` schema — no parallel per-domain schema families) and
> proposes exactly the parallel schema set ADR-006 forbids: `PolitiekePartij`,
> `Kandidatenlijst`, `Fractie`, `FractieLidmaatschap`, `SchriftelijkeVraag`,
> `FractieOndersteuning`. The replacing mechanism is `organisation-facet-composition`
> (in progress as of 2026-08-19 — see its status-note and
> `openspec/specs/governance-bodies/spec.md`'s own status-note, which names
> this draft explicitly): a faction is an ordinary `GovernanceBody` with
> `bodyType: "faction"` and a new `parentBody` self-reference, not a
> separate schema. This file is retained for its domain research (fraction
> splits/merges/succession, written questions, funding transparency) but its
> **New Entities**, **New Capabilities**, and **Impact** sections below MUST
> NOT be implemented as written — any future work on this domain should
> start from `organisation-facet-composition`'s `GovernanceBody`-based model
> instead.

# Decidesk — Fractievoorzitter en Fractie Koppeling

## Why

In Dutch municipal governance, the **fractie** (political group of elected council members) is the central unit of political coordination — not the individual raadslid (council member) and not the landelijke partij (national party). A fractie has a chair who claims speaking rights on behalf of the group, reserved question time in council meetings, administrative support from the griffie (council secretariat), annual funding for education and research, and dedicated representation in the seniorenconvent (senior council where meeting agendas are coordinated).

However, fractions are **not static**. Council members split off to form single-member fractions, return to the original fraction, merge with other fractions, or leave the council entirely when a successor from the party list takes the seat. On average, 2–4 such fraction changes occur per term; in politically turbulent periods, 6 or more — enough to drive any secretariat to despair with manual administration and enough to corrupt any historical analysis of voting patterns.

**Current state:** DecideDesk models council meetings, voting, and motions — but lacks a first-class fraction register. Without it, researchers cannot answer "which raadsleden switched factions during this term?", journalists cannot track splinter groups, and griffiers must maintain parallel spreadsheets to administrate fraction changes.

**This change delivers:** A production-grade fraction register that captures all this dynamics without losing history. Per fraction: who the chair is, who the members are, which party and candidate list it represents, how much question time it has, and what support it receives. Per raadslid: complete faction-membership history including splits and switches, so historical voting patterns are always shown with the faction active at the time of the vote.

## What Changes

### New Entities (5)

- **PolitiekePartij** — juridical entity (national or local political party, or party alliance)
- **Kandidatenlijst** — temporary alliance per election (a list of candidates for that cycle)
- **Fractie** — elected political group per term (changes through splits, merges, succession)
- **FractieLidmaatschap** — membership relation with history (one raadslid → one fractie over time)
- **SchriftelijkeVraag** — written question to the college submitted via a faction
- **FractieOndersteuning** — yearly funding and accountability for each faction

### New Capabilities

- **Fraction lifecycle:** create fractions after elections, handle splits, merges, and succession
- **Member tracking:** attach raadsleden to fractions with role (member, chair, secretary) and history
- **Voting snapshots:** every vote is tagged with "which faction was this raadslid in at vote time"
- **Written questions:** schriftelijke vragen routed through fractions with deadline tracking
- **Funding & transparency:** track yearly fraction allocation, spending, and public accountability per Wfpp
- **Public portal:** `/raad/fracties` shows current composition, chair, members, and visible history

### Modified Capabilities

- **Raadslid** — gains explicit faction-membership relations (via FractieLidmaatschap, not inline)
- **Stemgedrag** (voting) — every vote now carries a `fractieSnapshot` field so historical votes show the correct faction
- **Motie / Amendement** — can now reference a proposing fractie (in addition to proposing raadslid)
- **Commissiezetels** (commission seats) — reallocated via D'Hondt when faction composition changes

### Not in Scope

- Frontend UI for fraction management (griffier admin panel)
- Integration with Kiesraad election-result imports (wiring happens in later change)
- Schriftelijke vraag answering workflow (web form for college responses)
- Fractievergoeding budget execution (spend-tracking via docudesk)
- Fractie-portaal (internal coordination workspace)

## Impact

**Code:**

- 5 new OpenRegister schemas (PolitiekePartij, Kandidatenlijst, Fractie, FractieLidmaatschap, SchriftelijkeVraag, FractieOndersteuning)
- REST API CRUD for all 6 entities with full audit trails, filtering, and bulk export
- Computed fields: `fractieAtDatum()` (which faction was a raadslid in on a given date) for vote snapshots
- Migration: all current Raadsleden get a FractieLidmaatschap entry in the active raadsperiode

**Dependencies:**

- Hard dependency on decidesk-base (provides Raadslid, Raadsperiode, Gemeente schemas)
- Soft dependency on moties-en-amendementen (Motie/Amendement will reference fractie; exists independently)
- Soft dependency on commissies (Commission seat reallocation via D'Hondt; triggers on fraction change)

**Standards Compliance:**

- Gemeentewet artikels 7, 12, 33, 36b (council composition, conflict of interest, member rights, incompatibility)
- Kieswet (seat allocation D'Hondt, succession on interim departure)
- Wet financiering politieke partijen (party transparency, gifts disclosure)
- OWMS v4 (public portal metadata)
- TOOI/Bestuursorgaan (standardized governance body reference)
- AVG (personal data of raadsleden is public but privacy-safe)

## Stakeholders

- **Griffie / Griffier** — input: registers fraction changes, runs migrations after elections, administrates member changes. Needs clear UI to prevent errors.
- **Raadsleden** — input: report splits, mergers, chair changes. Output: see own faction history, coordinated via fractie-portaal.
- **Burgers / Onderzoekers** — output: public portal showing faction composition, voting history, funding accountability.
- **Journalisten** — output: track faction dynamics, publish on splits and mergers.
- **Politicologen** — output: download historical faction data for analysis (CSV/JSON export).

## Success Criteria

1. All 15 requirements in context-brief.md can be met from this data model without workarounds
2. Historical voting data can be re-queried with the correct faction per raadslid per vote
3. Public portal `/raad/fracties` shows current composition and visible history of changes
4. A griffier can register a split/merge/return in under 3 clicks after training
5. Schriftelijke vragen route through fractions with deadline tracking and public publication
6. Written question archive exports per Wfpp with open-data compliance
