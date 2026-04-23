# ADR-001: Popolo as Primary Data Standard

**Status:** accepted
**Date:** 2026-04-16

## Context

DecideDesk models governance concepts (people, organizations, motions, votes, meetings)
that are common across parliaments, councils, boards, and assemblies worldwide. Multiple
standards exist for representing this data:

- **Popolo** (popoloproject.com) — international open data standard for political/governance
  information, used by projects like EveryPolitician, OpenAustralia, and as the foundation
  for the Dutch ORI standard
- **Schema.org** — general-purpose vocabulary, too broad for governance specifics
- **Akoma Ntoso** — OASIS standard for legislative documents (complementary, not competing)
- **Custom schemas** — app-specific, non-interoperable

## Decision

DecideDesk adopts **Popolo as its primary data standard**. Every entity in the data model
either maps directly to a Popolo class or is explicitly documented as an extension.

### Popolo classes implemented

| Popolo Class | DecideDesk Entity | Storage |
|---|---|---|
| Person | Person | OpenRegister |
| Organization | GovernanceBody | OpenRegister |
| Membership | Membership | OpenRegister |
| Post | Post | OpenRegister |
| ContactDetail | ContactDetail | OpenRegister |
| Motion | Motion | OpenRegister |
| VoteEvent | VotingRound | OpenRegister |
| Vote | Vote | OpenRegister |
| Count | (fields on VotingRound) | OpenRegister |
| Event | Meeting | CalDAV VEVENT |
| Area | Area | OpenRegister |
| Speech | Speech | OpenRegister |

### Extensions beyond Popolo

These entities are not in Popolo but are needed for governance workflows:

| Entity | Source | Rationale |
|---|---|---|
| AgendaItem | ORI standard | Structured agenda with ordering, types, durations |
| Amendment | ORI standard | Subclass of Motion with `amends` relation |
| Minutes (Report) | ORI standard | Official meeting record |
| ActionItem | Custom | Follow-up tasks from adopted motions |

### Key design choices

1. **No separate Decision entity.** Popolo has no Decision class. A decision is the
   outcome of a Motion (lifecycle: adopted/rejected + decisionText fields). This avoids
   redundant entities and matches how ORI and Popolo model outcomes.

2. **Person + Membership separation.** Popolo separates identity (Person) from
   organizational relationships (Membership). One person can be a member of multiple
   bodies with different roles. The previous Participant entity merged these incorrectly.

3. **Post for formal positions.** Popolo Post represents positions (Chair, Secretary)
   that exist independently of who fills them. This enables vacancy tracking and
   succession planning.

4. **Property naming follows Popolo conventions** in the API layer, with camelCase
   variants in PHP/JavaScript code. The ADR-000 data model documents both.

## Consequences

- ORI API output is a thin serialization of existing entities, not a complex mapping
- Data is interoperable with 265+ Dutch municipalities using ORI (which is Popolo-based)
- International governance projects can consume DecideDesk data without custom adapters
- New Popolo classes (e.g. future standards additions) can be adopted incrementally
- Speech entity deferred to later phase — placeholder in data model, not yet implemented
