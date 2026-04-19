## Status: pr-created

## Context

Decidesk is a thin-client Nextcloud app: it owns no database tables and performs no direct SQL. All domain data is stored and retrieved via OpenRegister's ObjectService. Before any feature can be built, the 17 entities defined in ADR-000 must be registered as OpenRegister schemas inside a `decidesk` register.

The register template (`decidesk_register.json`) follows OpenAPI 3.0.0 + `x-openregister` extensions. It is imported once at install/upgrade time by a `RepairStep`. Subsequent changes to schemas go through the migration process described in ADR-001 (new migration in repair step; never modify existing ones).

## Goals / Non-Goals

**Goals:**
- Define all 17 ADR-000 entities as OpenRegister schemas with correct field types, required flags, and `x-openregister` relation declarations
- Create `decidesk_register.json` as the single source of truth for the register structure
- Provide seed data (3–5 Dutch objects per schema) for development and demo purposes
- Ensure schemas use schema.org type annotations per ADR-011

**Non-Goals:**
- Frontend views, navigation, or UI components (subsequent sprints)
- Business logic, workflow engine configuration (p2 sprints)
- Custom API endpoints (OpenRegister's built-in REST API is sufficient)
- Authentication or RBAC configuration (OpenRegister handles this)

## Decisions

### Decision 1: Single register, 17 schemas

All Decidesk entities live in one `decidesk` register. Alternative (one register per domain area) was rejected because cross-entity relations within OpenRegister are simpler when source and target share a register. The governance body separation (legislative vs. corporate etc.) is handled via the `bodyType` enum on `GovernanceBody`, not separate registers.

### Decision 2: Schema.org type mapping

Per ADR-011, each schema is annotated with its schema.org type:
- `Meeting` → `schema:Event`
- `GovernanceBody` → `schema:Organization`
- `Participant` → `schema:Person`
- `Decision`, `Minutes`, `ActionItem`, `AgendaItem`, `Amendment`, `Motion`, `Vote`, `VotingRound` → `custom:*`
- `DigitalDocument` → `schema:DigitalDocument`
- `MonetaryAmount` → `schema:MonetaryAmount`
- `Offer` → `schema:Offer`
- `Order` → `schema:Order`
- `Product` → `schema:Product`
- `Report` → `schema:Report`

### Decision 3: Lifecycle fields use OpenRegister built-in `status`

The `lifecycle` property on Meeting, Motion, Amendment, Minutes, and VotingRound maps to OpenRegister's built-in `status` field where the workflow engine controls transitions. The allowed values are stored as schema enum constraints so the platform validates them without custom code.

### Decision 4: Seed data format

Seed data uses the `@self` envelope (`register: decidesk`, `schema: <SchemaName>`, `slug: <kebab-case-id>`) as required by ADR-001. Values use Dutch municipality/association context (gemeente Westerkwartier, Waterschap Aa en Maas, Vereniging Eigen Huis).

## Risks / Trade-offs

- **Risk: Schema changes after initial import break existing objects** → Mitigation: only additive (optional) fields after initial release; breaking changes go through a repair migration.
- **Risk: OpenRegister not installed** → Mitigation: `RepairStep` checks for OpenRegister availability and logs a clear error; app remains inactive.
- **Risk: Seed data conflicts on re-import** → Mitigation: `ConfigurationService::importFromApp()` is idempotent (upsert by slug).

## Migration Plan

1. Add `lib/Settings/decidesk_register.json` with all 17 schemas and seed data
2. Add `lib/Migration/RepairStep.php` calling `ConfigurationService::importFromApp('decidesk')`
3. Enable `RepairStep` in `appinfo/info.xml`
4. On app install/upgrade, Nextcloud runs the repair step automatically
5. **Rollback**: Deleting the register via OpenRegister admin UI removes all schemas; no SQL rollback needed

## Open Questions

- Should `MonetaryAmount`, `Offer`, `Order`, and `Product` be included in the initial register or deferred to a later sprint? (Currently included per ADR-000; can be disabled via schema `x-openregister.active: false` if needed.)
- Quorum calculation method (`quorumRule` on GovernanceBody): free-text string or enum? — currently free-text; can be tightened in p3 sprint.

---

## Seed Data

### GovernanceBody (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "gemeenteraad-westerkwartier" },
    "name": "Gemeenteraad Westerkwartier",
    "bodyType": "legislative",
    "domain": "municipal",
    "quorumRule": "majority",
    "votingDefault": "for-against-abstain",
    "termStart": "2022-03-30T00:00:00Z",
    "termEnd": "2026-03-29T23:59:59Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "algemeen-bestuur-waterschap-aa-en-maas" },
    "name": "Algemeen Bestuur Waterschap Aa en Maas",
    "bodyType": "legislative",
    "domain": "water-board",
    "quorumRule": "majority",
    "votingDefault": "for-against-abstain",
    "termStart": "2023-01-01T00:00:00Z",
    "termEnd": "2026-12-31T23:59:59Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "ledenraad-vereniging-eigen-huis" },
    "name": "Ledenraad Vereniging Eigen Huis",
    "bodyType": "association",
    "domain": "association",
    "quorumRule": "two-thirds",
    "votingDefault": "for-against-abstain",
    "termStart": "2024-01-01T00:00:00Z",
    "termEnd": "2027-12-31T23:59:59Z"
  }
]
```

### Meeting (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "gemeenteraad-westerkwartier-2025-04-10" },
    "title": "Raadsvergadering 10 april 2025",
    "meetingType": "regular",
    "scheduledDate": "2025-04-10T19:30:00Z",
    "endDate": "2025-04-10T22:30:00Z",
    "location": "Raadzaal, Gemeentehuis Leek",
    "meetingMode": "in-person",
    "lifecycle": "closed",
    "quorumRequired": 19
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "commissie-ruimte-2025-05-06" },
    "title": "Commissievergadering Ruimte & Wonen 6 mei 2025",
    "meetingType": "committee",
    "scheduledDate": "2025-05-06T19:00:00Z",
    "endDate": "2025-05-06T22:00:00Z",
    "location": "https://meet.westerkwartier.nl/commissie-ruimte",
    "meetingMode": "hybrid",
    "lifecycle": "scheduled",
    "quorumRequired": 5
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "ab-waterschap-2025-06-12" },
    "title": "Vergadering Algemeen Bestuur 12 juni 2025",
    "meetingType": "regular",
    "scheduledDate": "2025-06-12T14:00:00Z",
    "endDate": "2025-06-12T17:00:00Z",
    "location": "'s-Hertogenbosch, hoofdkantoor",
    "meetingMode": "in-person",
    "lifecycle": "draft",
    "quorumRequired": 13
  }
]
```

### Participant (4 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "participant-roos-de-vries" },
    "displayName": "Roos de Vries",
    "role": "chair",
    "party": "GroenLinks",
    "email": "r.devries@westerkwartier.nl",
    "joinedAt": "2022-03-30T00:00:00Z",
    "votingWeight": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "participant-jan-bakker" },
    "displayName": "Jan Bakker",
    "role": "secretary",
    "email": "j.bakker@westerkwartier.nl",
    "joinedAt": "2022-03-30T00:00:00Z",
    "votingWeight": 0
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "participant-fatima-el-amrani" },
    "displayName": "Fatima El-Amrani",
    "role": "member",
    "party": "PvdA",
    "email": "f.elamrani@westerkwartier.nl",
    "joinedAt": "2022-03-30T00:00:00Z",
    "votingWeight": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "participant-pieter-van-dam" },
    "displayName": "Pieter van Dam",
    "role": "observer",
    "email": "p.vandam@waterschap-aaenmaas.nl",
    "joinedAt": "2024-01-15T00:00:00Z",
    "votingWeight": 0
  }
]
```

### AgendaItem (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "agendaitem-opening-2025-04-10" },
    "title": "Opening en mededelingen",
    "itemType": "informational",
    "orderNumber": 1,
    "estimatedDuration": 10,
    "isRecurring": true
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "agendaitem-begroting-2026" },
    "title": "Vaststelling Programmabegroting 2026",
    "itemType": "decision",
    "orderNumber": 5,
    "estimatedDuration": 45,
    "description": "De raad wordt gevraagd de programmabegroting 2026 vast te stellen.",
    "isRecurring": false
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "agendaitem-rondvraag-2025-04-10" },
    "title": "Rondvraag",
    "itemType": "discussion",
    "orderNumber": 99,
    "estimatedDuration": 15,
    "isRecurring": true
  }
]
```

### Motion (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-duurzaamheid-2025" },
    "title": "Motie Duurzaamheid: versnelling zonnepanelen gemeentelijke gebouwen",
    "text": "De raad verzoekt het college om voor 1 januari 2026 een plan van aanpak te presenteren voor het plaatsen van zonnepanelen op alle gemeentelijke gebouwen.",
    "motionType": "motion",
    "proposer": "Roos de Vries",
    "coSigners": ["Jan Smit", "Lisa de Jong"],
    "lifecycle": "adopted",
    "submittedAt": "2025-04-10T20:15:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "voorstel-begroting-2026" },
    "title": "Raadsvoorstel Programmabegroting 2026",
    "text": "De raad stelt de programmabegroting 2026 vast conform bijgaand begrotingsboek.",
    "motionType": "motion",
    "proposer": "College van B&W",
    "lifecycle": "voting",
    "submittedAt": "2025-04-08T09:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "amendement-begroting-post-a" },
    "title": "Amendement A: Extra budget cultuursubsidie",
    "text": "De raad besluit in de begroting 2026 een aanvullend budget van €50.000 op te nemen voor cultuursubsidies.",
    "motionType": "amendment",
    "proposer": "Fatima El-Amrani",
    "lifecycle": "rejected",
    "submittedAt": "2025-04-10T19:45:00Z"
  }
]
```

### VotingRound (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemming-motie-duurzaamheid-2025" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-10T21:00:00Z",
    "closedAt": "2025-04-10T21:05:00Z",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 28,
    "votesAgainst": 3,
    "votesAbstain": 2
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemming-begroting-2026" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-10T21:30:00Z",
    "closedAt": "2025-04-10T21:38:00Z",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 25,
    "votesAgainst": 6,
    "votesAbstain": 2
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemming-amendement-a" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-10T20:55:00Z",
    "closedAt": "2025-04-10T21:00:00Z",
    "quorumMet": true,
    "result": "rejected",
    "votesFor": 11,
    "votesAgainst": 20,
    "votesAbstain": 2
  }
]
```

### Decision (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-begroting-2026" },
    "title": "Vaststelling Programmabegroting 2026",
    "text": "De gemeenteraad van Westerkwartier stelt de programmabegroting 2026 vast.",
    "decisionDate": "2025-04-10T21:38:00Z",
    "outcome": "adopted",
    "isPublished": true,
    "publishedAt": "2025-04-11T09:00:00Z",
    "legalBasis": "Gemeentewet art. 189"
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-duurzaamheid-2025" },
    "title": "Motie Duurzaamheid aangenomen",
    "text": "De raad verzoekt het college een plan van aanpak zonnepanelen te presenteren voor 1 januari 2026.",
    "decisionDate": "2025-04-10T21:05:00Z",
    "outcome": "adopted",
    "isPublished": true,
    "publishedAt": "2025-04-11T09:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-bestemmingsplan-groningen-noord" },
    "title": "Vaststelling bestemmingsplan Groningen-Noord",
    "text": "De raad stelt het bestemmingsplan Groningen-Noord ongewijzigd vast.",
    "decisionDate": "2025-03-27T20:45:00Z",
    "outcome": "adopted",
    "isPublished": false,
    "legalBasis": "Wet ruimtelijke ordening art. 3.1"
  }
]
```

### Minutes (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-raad-2025-04-10" },
    "title": "Notulen Raadsvergadering 10 april 2025",
    "lifecycle": "approved",
    "content": "De vergadering wordt geopend door de voorzitter om 19:30 uur...",
    "approvedAt": "2025-05-08T19:45:00Z",
    "signedBy": ["Roos de Vries", "Jan Bakker"],
    "version": 2
  },
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-commissie-ruimte-2025-03-04" },
    "title": "Notulen Commissievergadering Ruimte & Wonen 4 maart 2025",
    "lifecycle": "signed",
    "content": "De commissievergadering Ruimte & Wonen wordt geopend...",
    "approvedAt": "2025-04-01T10:00:00Z",
    "signedBy": ["Henk Bakker"],
    "version": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-ab-waterschap-2025-02-13" },
    "title": "Notulen AB Waterschap Aa en Maas 13 februari 2025",
    "lifecycle": "draft",
    "version": 1
  }
]
```

### ActionItem (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-plan-zonnepanelen" },
    "title": "Plan van aanpak zonnepanelen gemeentelijke gebouwen opstellen",
    "description": "College presenteert plan voor 1 januari 2026 conform aangenomen motie.",
    "assignee": "Wethouder Duurzaamheid",
    "dueDate": "2026-01-01T00:00:00Z",
    "taskStatus": "open"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-communicatie-begroting-2026" },
    "title": "Begroting 2026 communiceren naar inwoners",
    "assignee": "Communicatieafdeling",
    "dueDate": "2025-05-01T00:00:00Z",
    "taskStatus": "completed",
    "completedAt": "2025-04-25T14:30:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-bestemmingsplan-publiceren" },
    "title": "Bestemmingsplan Groningen-Noord publiceren op ruimtelijkeplannen.nl",
    "assignee": "Ruimtelijke Ordening",
    "dueDate": "2025-04-10T00:00:00Z",
    "taskStatus": "overdue"
  }
]
```

### Amendment (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-cultuursubsidie" },
    "title": "Amendement A: Extra cultuursubsidie €50.000",
    "text": "Voeg in de begroting 2026 een post van €50.000 toe voor cultuursubsidies.",
    "proposer": "Fatima El-Amrani",
    "lifecycle": "rejected",
    "submittedAt": "2025-04-10T19:45:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-groentarieven" },
    "title": "Amendement B: Verlaging groentarieven 5%",
    "text": "De groentarieven worden met 5% verlaagd ten opzichte van het college-voorstel.",
    "proposer": "Jan Smit",
    "lifecycle": "debating",
    "submittedAt": "2025-04-10T20:30:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-participatiebudget" },
    "title": "Amendement C: Participatiebudget dorpskernen",
    "text": "Stel €25.000 beschikbaar als participatiebudget voor dorpskernenoverleg.",
    "proposer": "Roos de Vries",
    "lifecycle": "adopted",
    "submittedAt": "2025-04-09T14:00:00Z"
  }
]
```

### Vote (4 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-devries-motie-duurzaamheid" },
    "value": "for",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-10T21:01:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-bakker-motie-duurzaamheid" },
    "value": "against",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-10T21:02:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-elamrani-begroting-2026" },
    "value": "for",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-10T21:31:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-proxy-vandam-begroting" },
    "value": "for",
    "weight": 1,
    "isProxy": true,
    "castAt": "2025-04-10T21:32:00Z"
  }
]
```
