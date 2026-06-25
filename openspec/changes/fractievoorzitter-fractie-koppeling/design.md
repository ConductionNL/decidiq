# Design — Decidesk Fractievoorzitter en Fractie Koppeling

## Context

DecideDesk models council lifecycle (raadsperiode), participants (raadsleden), governance bodies (raad, commissies), and decisions (moties, stemmingen). However, it lacks the **fractie** (political faction) as a first-class entity. Without it:

- Raadsleden are orphaned from their group identity; history of faction membership is unmapped.
- Votes cannot be snapshot-ed with "which faction was this member in at vote time".
- Written questions (`schriftelijke vragen`) cannot route through a faction.
- Public reporting of faction composition is manual or non-existent.
- Griffiers maintain parallel spreadsheets to track splits, mergers, and succession.

**Proposed solution:** A production-grade fraction register spanning 6 new OpenRegister schemas (PolitiekePartij, Kandidatenlijst, Fractie, FractieLidmaatschap, SchriftelijkeVraag, FractieOndersteuning) with computed fields, audit trails, and deep integration with existing Raadslid and Stemgedrag entities.

**Stakeholders:** Griffiers input fraction changes; researchers, journalists, and public portals consume aggregated data. Raadsleden see their own faction history. Fractievoorzitters manage internal fraction coordination (later change).

## Goals / Non-Goals

**Goals:**

- Implement all 6 new OpenRegister schemas with full REST CRUD, audit trails, and filtering.
- Provide computed `fractieAtDatum(raadslidUuid, date)` so historical votes show the correct faction.
- Support fraction lifecycle: creation (post-election), splits, mergers, succession on interim departure, termination (end of term).
- Public portal `/raad/fracties` with faction composition, chair, members, and visible change history.
- Schriftelijke vragen routed through fractions with deadline tracking and published archive.
- Funding accountability (FractieOndersteuning) per Wfpp and open-data standards.
- Migration: attach all active raadsleden to fractions in the current raadsperiode.

**Non-Goals:**

- Griffier admin UI (frontend, separate change).
- Kiesraad election-result import (separate integration change).
- Fraction-portaal internal workspace (later phase).
- Schriftelijke vraag college-response workflow (docudesk owns responses).
- Commissie seat reallocation UI (mydash owns commission workflow).

## Decisions

### D1: Separate PolitiekePartij and Kandidatenlijst from Fractie

**Decision:** PolitiekePartij (juridical party entity), Kandidatenlijst (per-election candidate list), and Fractie (elected group per term) are three distinct schemas.

**Why:** This separates concerns:
- PolitiekePartij is **stable** (nacional or local party registration per Wfpp).
- Kandidatenlijst is **temporary** (one per election cycle; includes seat count and ordering).
- Fractie is **dynamic** (emerges after election, can split, merge, or end mid-term).

A raadslid is nominated on a Kandidatenlijst, elected via that list, and assigned to a Fractie. A Fractie links back to both (origin-list and represented-party). This enables:
- "Which raadsleden left their original list-partner fractie?" (compare raadslid's candidate-list to current fractie)
- "How many seats did faction X lose to a splinter?" (compare fractie.aantaalZetels at two points in time)
- "Which candidates from list Y were never elected?" (candidates in list but no raadslid on file)

**Alternatives:** Inline party and list data on Fractie. **Why not:** loses traceability and makes it impossible to reconstruct the original political alliance.

### D2: FractieLidmaatschap as an immutable history log

**Decision:** FractieLidmaatschap records are never updated; they are created on membership start and closed (end-date set) on membership end. Searching `where raadslidUuid=X and startDate <= date <= endDate` yields the faction as of that date.

**Why:**
- Audit trail is automatic (createdAt, updatedAt on every record).
- "What faction was this raadslid in on 2024-03-15?" is a simple date-range query.
- "How many times did this raadslid switch?" is a count of FractieLidmaatschap records.
- Rollback or correction is adding a new record with correct dates, not mutating old ones.

**Alternatives:** Inline faction array on Raadslid with start/end dates. **Why not:** requires complex array-field queries (slow in most DB backends); loses immutability for audit.

### D3: Stemgedrag (Vote) captures fractieSnapshot at vote time

**Decision:** When a Stemgedrag (vote record) is created, it stores a snapshot of which Fractie the raadslid was in at that moment (via computed field or explicit trigger).

**Why:**
- If a raadslid switches factions mid-term, we must show historical votes with the **original** faction, not the current one.
- Public reporting of "faction X voted for Y" must use the faction list as of that vote date.
- A computed field `fractieAtDatum()` on Raadslid makes this a single join at vote-creation time.

**Alternatives:** Look up current fractie at query time. **Why not:** retroactively changes historical reporting if a raadslid switches factions.

### D4: SchriftelijkeVraag routed via Fractie, not just Raadslid

**Decision:** SchriftelijkeVraag has an `indienendeFractie` field (required) and `indienendRaadslid` (optional). A question always belongs to a fraction for speaking time and quorum tracking.

**Why:**
- Fracties have reserved question time (e.g., 10 min for a 10-seat faction).
- Speaking time is allocated per fraction, not per raadslid.
- Publishing and deadline tracking is per fraction (the chair follows up).
- Wfpp requires fraction-level disclosure of spending on research.

**Alternatives:** Only track `indienendRaadslid`. **Why not:** loses the group identity and makes it impossible to allocate question time fairly.

### D5: Public portal respects visibility rules per raadsperiode

**Decision:** The public `/raad/fracties` portal only shows fractions from the **current** raadsperiode. Archived fraction data is available via CSV/JSON export.

**Why:**
- Simplifies the frontend (no date-range picker for citizens).
- Prevents confusion (citizens see "current council composition").
- Researchers get historical data via export (not real-time query).
- Reduces real-time query load.

**Alternatives:** Show all raadsperiodes on the portal. **Why not:** complex UX; most citizens care about "who is in the council now?"

### D6: No separate Decision entity for FractieOndersteuning reconciliation

**Decision:** FractieOndersteuning records are created annually (per fractie, per year) with allocated amount, spent amount, and accountant sign-off status. No separate "approval" decision entity.

**Why:**
- One FractieOndersteuning record per fractie per year (simple relation).
- Spending reconciliation is tracked on that single record.
- Audit trail (createdAt, updatedAt, accountant-signed-at) is sufficient.

**Alternatives:** Create a Besluit (decision) entity for each allocation. **Why not:** overkill for administrative data; Fractie itself is the subject of council decisions (allocations are council decisions, but we don't re-model them).

## Reuse Analysis

### Existing Services & Schemas

| Concept | Source | Reuse |
|---------|--------|-------|
| Raadslid, Raadsperiode, Gemeente | decidesk-base | Fractie and FractieLidmaatschap reference these; no schema changes. |
| Stemgedrag (Vote) | decidesk moties-en-amendementen | Add fractieSnapshot field via computed relation; existing votes unchanged. |
| Motie / Amendement | decidesk moties-en-amendementen | Add optional `proposingFractie` field (in later change). |
| Commissie, Commissiezetels | decidesk commissies | No schema changes; D'Hondt reallocation triggered by fractie change (in later change). |
| Person (identity) | OpenRegister common | Raadsleden are Persons; Fractie chair links to Raadslid (which links to Person). |

### REST API Pattern

All 6 new schemas follow the standard OpenRegister CRUD pattern:
- `GET /api/objects?register=fractie&filter[...]` — list with filtering, pagination, sort
- `POST /api/objects` with `register=fractie`, `schema=fractie` — create
- `GET /api/objects/{uuid}?register=fractie` — fetch
- `PUT /api/objects/{uuid}?register=fractie` — update (or patch for specific fields)
- `DELETE /api/objects/{uuid}?register=fractie` — soft-delete (lifecycle field)
- Full audit trail (createdAt, updatedAt, owner, auditTrail array)
- CSV/JSON export with `?format=csv` or `?format=json`

No custom endpoints needed; all operations flow through the generic OpenRegister API.

## Seed Data

### 6 Example Objects (Dutch values, 2024 raadsperiode)

**Gemeente: Edam-Volendam**

**PolitiekePartij**
1. `pp-pvda`: "PvdA", landelijke-partij, kvk-nummer, website
2. `pp-vvd`: "VVD", landelijke-partij, kvk-nummer, website
3. `pp-lokaal`: "Edam-Volendam Lokaal", lokale-partij, no kvk

**Kandidatenlijst (2024 verkiezing)**
1. `kl-pvda-2024`: PvdA, 5 zetels, lijsttrekker=Jan Jansen, candidates=[Jan Jansen, Petra Smith, Dirk Kool, ...]
2. `kl-vvd-2024`: VVD, 4 zetels, lijsttrekker=Rob Pieterse, candidates=[Rob Pieterse, Ans Berg, ...]
3. `kl-lokaal-2024`: Lokaal, 3 zetels, lijsttrekker=Maria Groot, candidates=[Maria Groot, ...]

**Fractie (2024-2028 raadsperiode)**
1. `frac-pvda`: "Fractie PvdA", afkorting="PvdA", aantaalZetels=5, voorzitter=Jan Jansen (raadslid-uuid), kandidatenlijst=kl-pvda-2024, party=pp-pvda
2. `frac-vvd`: "Fractie VVD", afkorting="VVD", aantaalZetels=4, voorzitter=Rob Pieterse, kandidatenlijst=kl-vvd-2024, party=pp-vvd
3. `frac-lokaal`: "Fractie Edam-Volendam Lokaal", afkorting="EVL", aantaalZetels=3, voorzitter=Maria Groot, kandidatenlijst=kl-lokaal-2024, party=pp-lokaal
4. `frac-kool-solo`: "Fractie Dirk Kool", afkorting="DK", aantaalZetels=1, voorzitter=Dirk Kool, originatingFractie=frac-pvda, oprichtingsReden=afsplitsing, startDate=2024-07-15 (example split after 2 months)

**FractieLidmaatschap (2024-2028 raadsperiode)**
1. Jan Jansen + frac-pvda, startDate=2024-06-01, endDate=null, rol=voorzitter, reasonStart=installatie
2. Petra Smith + frac-pvda, startDate=2024-06-01, endDate=null, rol=lid, reasonStart=installatie
3. Dirk Kool + frac-pvda, startDate=2024-06-01, endDate=2024-07-14, rol=lid, reasonStart=installatie, reasonEnd=afsplitsing
4. Dirk Kool + frac-kool-solo, startDate=2024-07-15, endDate=null, rol=voorzitter, reasonStart=afsplitsing-eigen
5. Rob Pieterse + frac-vvd, startDate=2024-06-01, endDate=null, rol=voorzitter, reasonStart=installatie
6. (... more members)

**SchriftelijkeVraag (2024)**
1. `sv-2024-001`: "Ondersteuning ondernemers na energiecrisis", indienendeFractie=frac-vvd, indienendRaadslid=Rob Pieterse, datumIngediend=2024-06-15, portefeuillehouder="Wethouder Economie", status=ingediend, antwoordTermijn=2024-07-15
2. `sv-2024-002`: "Fietsinfra in de buurt Volendam-Noord", indienendeFractie=frac-pvda, indienendRaadslid=Jan Jansen, datumIngediend=2024-06-20, status=beantwoord, antwoordDatum=2024-07-10

**FractieOndersteuning (2024)**
1. frac-pvda, 2024, toegekende-vergoeding=€8500 (5 seats × €1700/seat), besteed=€6200, accountantsVerklaring=null (under threshold)
2. frac-vvd, 2024, toegekende-vergoeding=€6800, besteed=€6750, accountantsVerklaring=signed (close to threshold or required by gemeente)
3. frac-lokaal, 2024, toegekende-vergoeding=€5100, besteed=€2100, accountantsVerklaring=null

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **R1 — Fractie composition mid-term changes invalidate pre-computed analyses.** If a raadslid switches faction after a vote, a pre-computed "faction X voted for Y" total becomes incorrect if re-run today. | FractieLidmaatschap is immutable; Stemgedrag snapshots fractie at vote time. Re-running the analysis with the snapshot field always yields historical accuracy. API exposes `fractieAtDatum()` computed field. |
| **R2 — User error on fraction splits (wrong origin-fractie link).** A griffier accidentally creates a split as a brand-new fractie instead of marking it as a child of the source fractie. | Data validation: `oprichtingsReden=afsplitsing` REQUIRES `bron-fractie` field. API returns 400 if missing. UI will have a dropdown to select source. Training docs emphasize the distinction. |
| **R3 — Historical question time allocations not tracked.** If question-time allocation changes mid-year, old records show the wrong time. | FractieOndersteuning is per-year; if mid-year changes are rare, a note field is sufficient. If frequent, a separate QuestionTimeAllocation log (later enhancement). Current implementation assumes stable allocation per term. |
| **R4 — Performance: fractieAtDatum queries on millions of votes.** Joining FractieLidmaatschap at query time could be slow. | Computed field stored at vote-creation time (see D3). Votes are immutable; query is a simple index lookup, not a join. Historical re-export still uses the snapshot. |
| **R5 — No audit trail for "why" a fractie split.** oprichtingsReden says "it was a split" but not why (ideological, personal, etc.). | A notes field on Fractie can store context; detailed narrative belongs in minutes (Notulen) or media, not the schema. Keep schema focused on structural change, not narrative. |

## Migration Plan

**Phase 1: Schema creation**
1. Create 6 new OpenRegister schemas (PolitiekePartij, Kandidatenlijst, Fractie, FractieLidmaatschap, SchriftelijkeVraag, FractieOndersteuning).
2. Deploy to development, test CRUD operations.
3. Write sample data (seed data section above).

**Phase 2: Integration with existing data**
1. Migrate all active Raadsleden in the current raadsperiode to FractieLidmaatschap records (initial bulk insert).
2. Add computed field `fractieAtDatum()` to Raadslid (no schema change; new query helper).
3. Extend Stemgedrag to include `fractieSnapshot` field (default to null for old votes; backfill on demand).

**Phase 3: API + Public Portal**
1. Expose REST CRUD for all 6 schemas via standard OpenRegister API.
2. Build public `/raad/fracties` portal (Vue component, separate change).
3. Implement schriftelijke vragen archive export (CSV/JSON per Wfpp).

**Rollback:** Delete the 6 new schemas; Raadsleden return to being standalone (lose faction tracking, but votes and council operations continue unaffected).

**Compatibility:** All changes are additive. Existing decidesk functionality (Raadslid, Raadsperiode, Motie, Stemgedrag) is unaffected by schema creation. Stemgedrag `fractieSnapshot` is optional (null for pre-migration votes).

## Data Privacy & Compliance

- **AVG:** Raadslid personal data (phone, email) is public per Gemeentewet artikel 12 and handled via existing Raadslid schema.
- **Wfpp:** FractieOndersteuning records are public accountability ledgers (spending transparency). PolitiekePartij includes kvk-nummer for party registration verification.
- **OWMS v4:** Public `/raad/fracties` portal includes OWMS metadata for search-engine indexing.
- **Gemeentewet artikel 7:** Fractie composition reflects zetelverdeling (seat allocation) per election.
- **NORA:** Schriftelijke vragen integrate with college workflow (cross-sector data sharing via NORA principles).
