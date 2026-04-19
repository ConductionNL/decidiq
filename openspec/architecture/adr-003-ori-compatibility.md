# ADR-003: ORI Compatibility Endpoint

**Status:** accepted
**Date:** 2026-04-16

## Context

Open Raadsinformatie (ORI) is the Dutch open data standard for municipal council
information, maintained by VNG Realisatie and Open State Foundation. 265 of 345 Dutch
municipalities publish council data via ORI. The standard is based on Popolo with
Dutch-specific extensions (AgendaItem, Amendment, Report, Committee).

DecideDesk follows Popolo as its primary data standard (ADR-001). Since ORI is a
superset of Popolo, compatibility is straightforward.

## Decision

DecideDesk exposes an **ORI-compatible REST API endpoint** as an addition to its
standard API. The core architecture follows Popolo (international); ORI is a Dutch
municipal output format.

### Endpoint structure

```
/api/ori/v1/organizations       → GovernanceBody as ORI Organization
/api/ori/v1/persons             → Person as ORI Person
/api/ori/v1/memberships         → Membership as ORI Membership
/api/ori/v1/events              → Meeting (from CalDAV) as ORI Event/Meeting
/api/ori/v1/agendaitems         → AgendaItem as ORI AgendaItem
/api/ori/v1/motions             → Motion as ORI Motion
/api/ori/v1/amendments          → Amendment as ORI Amendment
/api/ori/v1/voteevents          → VotingRound as ORI VoteEvent
/api/ori/v1/votes               → Vote as ORI Vote
/api/ori/v1/reports             → Minutes as ORI Report
```

### Entity mapping

| DecideDesk Entity | ORI/Popolo Class | Key Differences |
|---|---|---|
| GovernanceBody | Organization / Committee | `bodyType` → `classification` |
| Person | Person | Direct mapping |
| Membership | Membership | Direct mapping |
| Meeting (CalDAV) | Meeting / Event | Read from CalDAV, map X-properties |
| AgendaItem | AgendaItem | `orderNumber` → `position` |
| Motion | Motion | `lifecycle` → `status`, `proposer` → `creator` |
| Amendment | Amendment | `amends` relation to parent Motion |
| VotingRound | VoteEvent | Counts expanded to separate Count objects |
| Vote | Vote | `value` → `option` |
| Minutes | Report | Direct mapping |

### What the ORI endpoint does NOT do

- It does not change the internal data model — Popolo is the source of truth
- It does not store data in ORI format — it serializes on read
- It does not implement the full ORI harvesting protocol — that requires a
  separate adapter (e.g. for Open State Foundation's crawler)

## Consequences

- Dutch municipalities can consume DecideDesk data via the standard ORI API
- DecideDesk appears in ORI-compatible tooling and dashboards
- The endpoint is a thin read-only serialization layer, not a separate data store
- International users ignore the ORI endpoint and use the standard Popolo-aligned API
- Future: ORI harvesting adapter can push data to the national ORI aggregator
