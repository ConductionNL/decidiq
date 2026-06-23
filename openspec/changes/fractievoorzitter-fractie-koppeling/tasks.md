# Tasks — Decidesk Fractievoorzitter en Fractie Koppeling

> Scope reminder: this change implements 6 new OpenRegister schemas (PolitiekePartij, Kandidatenlijst, Fractie, FractieLidmaatschap, SchriftelijkeVraag, FractieOndersteuning) with computed fields and migrations to integrate faction tracking into DecideDesk. See `proposal.md`, `design.md`, and `specs.md` for context.
>
> Acceptance gates: every task's checkbox flips only when its acceptance criteria pass.
> Do not mark tasks done by inspection — run the listed commands.

## 1. OpenRegister Schema Definitions

### 1.1 PolitiekePartij schema

- [ ] Create `openregister/schemas/politieke-partij.json` with fields: naam, afkorting, type (enum), oprichtingsDatum, ophefdingsDatum, kvkNummer, website, fractieOverstijgend.
  **Acceptance:** Schema validates against OpenRegister JSON Schema spec; `php -l` is clean; all required fields are marked `required: true` in the schema.

### 1.2 Kandidatenlijst schema

- [ ] Create `openregister/schemas/kandidatenlijst.json` with fields: verkiezingsDatum, politiekePartijKoppeling (relation to PolitiekePartij), lijstNummer, lijsttrekker, kandidaten (array), behaaldeZetels, restZetels.
  **Acceptance:** Schema validates; politiekePartijKoppeling is a proper OpenRegister relation field; kandidaten is immutable after creation (stored as array, not mutable reference).

### 1.3 Fractie schema

- [ ] Create `openregister/schemas/fractie.json` with fields: naam, afkorting, gemeenteKoppeling, raadsperiodeKoppeling, politiekePartijKoppeling (optional), kandidatenlijstKoppeling (optional), oprichtingsDatum, oprichtingsReden (enum), bronFractie (optional relation), ophefdingsDatum (optional), ophefdingsReden (optional), aantaalZetels, voorzitterKoppeling, plaatsvervangendeVoorzitterKoppeling (optional), secretarisKoppeling (optional), fractieVergoedingPerJaar (optional), vraagTijdMinuten (optional).
  **Acceptance:** Schema validates; enum values match spec exactly; role-koppeling fields are relations to Raadslid; bronFractie is self-referential relation (Fractie → Fractie).

### 1.4 FractieLidmaatschap schema

- [ ] Create `openregister/schemas/fractie-lidmaatschap.json` with fields: raadslidKoppeling, fractieKoppeling, beginDatum, eindDatum (optional), rol (enum), redenBegin (enum), redenEind (optional enum). Mark as **immutable on creation** — only eindDatum is patchable after creation.
  **Acceptance:** Schema validates; enum values match spec; both koppeling fields are required relations; beginDatum is required; validation prevents redenBegin without a beginning (required) and redenEind without an ending (conditional on endDate).

### 1.5 SchriftelijkeVraag schema

- [ ] Create `openregister/schemas/schriftelijke-vraag.json` with fields: vraagNummer (auto-generated per spec SV-YYYY-NNN), indienendeFractieKoppeling, indienendRaadslidKoppeling, datumIngediend, onderwerp, vraagTekst (richtext), portefeuillehouderKoppeling (optional), status (enum), antwoordTermijn, antwoordTekst (optional, richtext), antwoordDatum (optional), vervolgVragen (array of uuids, optional).
  **Acceptance:** Schema validates; vraagNummer is auto-generated (read-only field with generation logic in OpenRegister); status enum includes all 4 states; antwoordTermijn defaults to 30 days from datumIngediend if not provided.

### 1.6 FractieOndersteuning schema

- [ ] Create `openregister/schemas/fractie-ondersteuning.json` with fields: fractieKoppeling, jaar, vergoedingToegestemd, vergoedingBesteed (optional), verantwoordingsDocument (optional, file UUID), accountantsVerklaringVereist (boolean, default false), accountantsVerklaard (optional, date), opmerkingen (optional, text).
  **Acceptance:** Schema validates; jaar is integer (YYYY); both vergoeding fields are integers (€ cents or whole euros, depending on gemeente system); unique constraint on (fractieUuid, jaar) to prevent duplicate allocations.

---

## 2. Register & Fixtures Setup

### 2.1 Create registers for all 6 schemas

- [ ] In the OpenRegister admin or configuration, create registers named: `politieke-partijen`, `kandidatenlijsten`, `fracties`, `fractie-lidmaatschappen`, `schriftelijke-vragen`, `fractie-ondersteuning`.
  **Acceptance:** All 6 registers appear in `GET /api/registers`; each register has correct schema linked; CRUD endpoints respond at `GET /api/objects?register=<name>`.

### 2.2 Seed data: Edam-Volendam example municipality (2024–2028 term)

- [ ] Insert seed data (from design.md § Seed Data):
  - 3 PolitiekePartij objects (PvdA, VVD, Edam-Volendam Lokaal)
  - 3 Kandidatenlijst objects (one per party, 2024 election)
  - 4 Fractie objects (3 post-election + 1 split)
  - 10+ FractieLidmaatschap objects (all members across all fractions)
  - 2 SchriftelijkeVraag objects
  - 3 FractieOndersteuning objects (one per main faction for 2024)
  
  **Acceptance:** All seed data inserts without error; verify via REST:
  ```bash
  curl /api/objects?register=politieke-partijen → returns 3 objects
  curl /api/objects?register=fracties&filter[raadsperiodeUuid]=<uuid> → returns 4 objects
  ```

---

## 3. Computed Fields & Integrations

### 3.1 fractieAtDatum() helper on Raadslid

- [ ] Add a computed property or helper method to Raadslid that looks up the active FractieLidmaatschap at a given date:
  ```
  Raadslid::fractieAtDatum(date $at): ?Fractie
    → queries FractieLidmaatschap where raadslidUuid=this AND beginDatum <= $at AND (endDate IS NULL OR endDate >= $at)
    → returns Fractie object or null
  ```
  **Acceptance:** Helper exists (either in PHP service or as computed OpenRegister field); test query works for a known raadslid on a known date; returns correct Fractie.

### 3.2 Stemgedrag fractieSnapshot on vote creation

- [ ] Modify vote creation flow (Stemgedrag REST create endpoint or service) to:
  1. Accept the vote data (raadslidUuid, motionUuid, vote value, etc.)
  2. Call `fractieAtDatum(voteDatum)` to compute active faction
  3. Store the result in a `fractieSnapshot` field on the Stemgedrag object
  4. If no faction found (edge case: raadslid between fractions), return 400 with clear error
  
  **Acceptance:** Creating a vote stores both the raadslid and the faction snapshot; querying the vote returns both; historical votes from before this change have null/empty snapshot (backfill in 3.4).

### 3.3 Vraag-tijd herberekening on fractie changes

- [ ] When a Fractie's aantaalZetels changes (split/merge/succession), recalculate vraagTijdMinuten:
  ```
  fractie.vraagTijdMinuten = max(5, fractie.aantaalZetels * 2)
  ```
  Wire this into the Fractie PATCH/PUT endpoint so it triggers on seat-count change.
  
  **Acceptance:** Test: split a 5-seat fraction, verify new single-seat fraction has vraagTijdMinuten=5 (min), verify original has vraagTijdMinuten recalculated; test: merge two fractions, verify combined vraagTijdMinuten is correct.

### 3.4 Fractie-vergoeding herberekening on fractie changes

- [ ] When Fractie.aantaalZetels changes, trigger FractieOndersteuning recalculation for the current year:
  ```
  FractieOndersteuning(fractieUuid, currentYear).vergoedingToegestemd
    = fractie.aantaalZetels * (baseAllocationPerSeat per gemeente, configurable)
  ```
  Create (if not exists) or update (if exists) the FractieOndersteuning record.
  
  **Acceptance:** Test: split a 5-seat fraction into 1-seat + 4-seat, verify both get new FractieOndersteuning records for current year; verify totals remain sane.

### 3.5 Backfill Stemgedrag fractieSnapshot for pre-migration votes

- [ ] Write a data migration script that iterates all Stemgedrag objects with null/empty fractieSnapshot:
  ```sql
  FOR each vote:
    SET vote.fractieSnapshot = FractieLidmaatschap lookup for (raadslidUuid, voteDatum)
    IF no faction found: log warning (edge case: raadslid between fractions at vote time)
  ```
  Run script in test environment; verify all old votes get snapshot.
  
  **Acceptance:** Script runs without error; queries before/after confirm all votes have fractieSnapshot (or null with logged reason); manual spot-check 10 random votes against known faction changes.

---

## 4. REST API & Validation

### 4.1 CRUD endpoints (standard OpenRegister pattern)

- [ ] Verify that OpenRegister provides standard CRUD for all 6 registers:
  ```
  GET /api/objects?register=fractie&filter[raadsperiodeUuid]=X → list fractions
  POST /api/objects {register: fractie, schema: fractie, ...} → create
  GET /api/objects/<uuid>?register=fractie → fetch
  PUT /api/objects/<uuid>?register=fractie {fields} → update
  DELETE /api/objects/<uuid>?register=fractie → soft-delete
  ```
  **Acceptance:** All 6 CRUD endpoints respond with 200/201/400 as appropriate; test one create→read→update→delete cycle per schema.

### 4.2 Field validation: enum and relation constraints

- [ ] Test that OpenRegister enforces:
  - Enum fields (oprichtingsReden, rol, status, etc.) reject invalid values → 400 error
  - Relation fields (fractieKoppeling, raadslidKoppeling, etc.) reject non-existent UUIDs → 400 or 404 error
  - Required fields (naam, fractieKoppeling, etc.) cannot be omitted → 400 error
  - Conditional requirements (redenEind only valid if eindDatum is set) are enforced → 400 error
  
  **Acceptance:** Write 10 unit tests, each testing one validation rule; all pass.

### 4.3 Schriftelijke vraag auto-numbering

- [ ] Test that creating a SchriftelijkeVraag auto-generates vraagNummer:
  ```
  POST /api/objects {register: schriftelijke-vragen, schema: schriftelijke-vraag, ...}
  → Response includes vraagNummer: "SV-2024-001" (auto-generated, not provided in request)
  ```
  Test: create 5 questions in same year → numbers are SV-2024-001, 002, 003, 004, 005 (correct sequence, no gaps).
  Test: create questions in different years → each year has its own sequence (SV-2024-001, SV-2025-001).
  
  **Acceptance:** All 3 test cases pass; verify with direct API calls.

### 4.4 FractieLidmaatschap immutability (post-creation)

- [ ] Protect FractieLidmaatschap: after creation, only the `eindDatum` and `redenEind` fields are patchable.
  Attempts to PATCH other fields (raadslidKoppeling, fractieKoppeling, rol, beginDatum) → 403 Forbidden.
  
  **Acceptance:** Unit test: create a FractieLidmaatschap, attempt PATCH on rol → receives 403; attempt PATCH on eindDatum → succeeds.

### 4.5 Unique constraint on FractieOndersteuning

- [ ] Enforce that only one FractieOndersteuning record exists per (fractieUuid, jaar) pair.
  Attempt to create a duplicate → 409 Conflict.
  
  **Acceptance:** Create FractieOndersteuning(frac1, 2024); attempt create FractieOndersteuning(frac1, 2024) → receives 409; create FractieOndersteuning(frac1, 2025) → succeeds.

---

## 5. Migration & Backfill

### 5.1 Bulk insert initial FractieLidmaatschappen from current raadsleden

- [ ] Write a migration script: for each active Raadslid in the current raadsperiode, determine their initial Fractie assignment (from existing fractie register or seed data), and create a FractieLidmaatschap entry:
  ```
  FOR each Raadslid in current raadsperiode:
    fractieUuid = determine from candidate-list origin or manual assignment
    CREATE FractieLidmaatschap {
      raadslidUuid: raadslid.uuid,
      fractieUuid: fractie.uuid,
      beginDatum: raadsperiode.beginDatum,
      rol: (chair if in Fractie.voorzitterKoppeling, else "lid"),
      redenBegin: "installatie"
    }
  ```
  **Acceptance:** Script runs without error; all active raadsleden now have at least one FractieLidmaatschap; verify counts: total fractions × expected members = total FractieLidmaatschap records.

### 5.2 Verify migration data integrity

- [ ] Run consistency checks post-migration:
  - Every active Raadslid has a FractieLidmaatschap with active date range
  - Every Fractie has at least one member (FractieLidmaatschap)
  - Voorzitter of a Fractie has a FractieLidmaatschap with rol="voorzitter"
  - No overlapping FractieLidmaatschap for the same raadslid (one active per date)
  
  **Acceptance:** Write 4 SQL/query checks; all return zero errors (or log exceptions with clear reasoning).

---

## 6. Unit Tests

### 6.1 Schema validation tests

- [ ] Test all 6 schemas load without errors:
  ```
  foreach schema in [politieke-partij, kandidatenlijst, fractie, fractie-lidmaatschap, schriftelijke-vraag, fractie-ondersteuning]:
    GET /openregister/schemas/{schema} → 200 OK
    Schema JSON is valid OpenRegister schema
  ```
  **Acceptance:** All 6 schemas load; PHPUnit passes.

### 6.2 CRUD happy path tests

- [ ] Create-Read-Update-Delete test for each schema:
  - POST create → 201, returns uuid
  - GET read → 200, returns created object
  - PUT/PATCH update → 200, updated field visible
  - DELETE soft-delete → 200, object marked deleted
  
  **Acceptance:** All 6 CRUD cycles pass (6 tests).

### 6.3 Relation and enum validation tests

- [ ] Test that invalid enum values and broken relations are rejected:
  - POST Fractie with oprichtingsReden="invalid" → 400
  - POST Fractie with voorzitterKoppeling=<invalid-uuid> → 400 or 404
  - POST FractieLidmaatschap with rol="invalid" → 400
  - POST SchriftelijkeVraag with status="invalid" → 400
  
  **Acceptance:** 8 negative tests, all return expected error status.

### 6.4 FractieLidmaatschap immutability tests

- [ ] Test that immutable fields cannot be patched:
  - Create FractieLidmaatschap(raadslid=A, fractie=B, rol="lid")
  - PATCH with rol="voorzitter" → 403
  - PATCH with raadslidKoppeling=<other> → 403
  - PATCH with eindDatum="2025-06-01" → 200 OK
  
  **Acceptance:** 3 tests pass (2 forbidden, 1 allowed).

### 6.5 Schriftelijke vraag numbering tests

- [ ] Test auto-numbering:
  - Create 3 questions in 2024 → vraagNummers are SV-2024-001, 002, 003
  - Create 2 questions in 2025 → vraagNummers are SV-2025-001, 002
  - Create 1 question, then delete it, then create another → sequence continues (SV-2025-003), no gap
  
  **Acceptance:** All 3 tests pass.

### 6.6 Computed field: fractieAtDatum

- [ ] Test the fractieAtDatum() helper:
  - Raadslid in Fractie A from 2024-06-01 to 2025-01-31
  - fractieAtDatum(2024-07-01) → returns Fractie A
  - fractieAtDatum(2025-02-01) → returns null (no longer in any faction)
  - Raadslid switches to Fractie B on 2025-02-01
  - fractieAtDatum(2025-03-01) → returns Fractie B
  
  **Acceptance:** 4 date queries return expected results.

### 6.7 Vote snapshot on creation

- [ ] Test that votes store fractieSnapshot:
  - Create a vote for Raadslid X on 2024-07-15 (X is in Fractie A)
  - Verify vote.fractieSnapshot = Fractie A
  - X switches to Fractie B on 2024-08-01
  - Create a second vote for X on 2024-08-15
  - Verify vote.fractieSnapshot = Fractie B
  - Query first vote again → still shows Fractie A (immutable)
  
  **Acceptance:** Both votes have correct snapshots; historical vote is not affected by later switch.

### 6.8 Fractie reallocation: vraagTijd & vergoeding

- [ ] Test that fractie seat-count changes trigger reallocation:
  - Create Fractie A with 5 seats → vraagTijdMinuten ≈ 10, vergoeding ≈ €8500
  - Split: Raadslid X leaves → Fractie A now 4 seats
  - Verify Fractie A.vraagTijdMinuten recalculated to ≈ 8
  - Verify Fractie A.vergoeding recalculated to ≈ €6800
  - Verify new Fractie X (1 seat) has vraagTijdMinuten = 5 (minimum), vergoeding = €1700
  
  **Acceptance:** All 4 allocations match expected formulas.

---

## 7. Integration Tests

### 7.1 Election → Fractie → FractieLidmaatschap workflow

- [ ] End-to-end scenario:
  1. Create Kandidatenlijst for 2024 election (3 candidates)
  2. Create Fractie as post-election
  3. Create 3 Raadsleden (candidate winners)
  4. Create 3 FractieLidmaatschap records binding them
  5. Query `/api/objects?register=fracties&filter[raadsperiodeUuid]=X` → returns 1 fractie
  6. Query `/api/objects?register=fractie-lidmaatschappen&filter[fractieUuid]=X` → returns 3 members
  
  **Acceptance:** All 5 creation steps succeed; final queries return expected counts.

### 7.2 Fractie split scenario

- [ ] Scenario: Raadslid X splits from Fractie A:
  1. Fractie A has 5 members (including X), voorzitter=Y
  2. Register X's departure: create FractieLidmaatschap end-event (eindDatum=today, redenEind="afsplitsing")
  3. Create new Fractie X (1-member splinter) with voorzitterKoppeling=X
  4. Create FractieLidmaatschap for X → Fractie X (beginDatum=today, reden="afsplitsing-eigen")
  5. Query Fractie A: aantaalZetels = 4 (automatically updated)
  6. Query Fractie X: aantaalZetels = 1
  7. Query X's history: FractieLidmaatschap shows A (ended) → X (started)
  
  **Acceptance:** Split is atomic across all 3 operations; seat counts auto-update; history is preserved.

### 7.3 Fractie merger scenario

- [ ] Scenario: Fractie A (3 members) merges with Fractie B (2 members):
  1. Create new Fractie AB (merged, 5 members)
  2. End all FractieLidmaatschap records for A and B (redenEind="fusie")
  3. Create new FractieLidmaatschap records for all 5 members → Fractie AB (redenBegin="fusie")
  4. Mark Fractie A and B as ophefdingsDatum=today, ophefdingsReden="fusie"
  5. Query Fractie AB: aantaalZetels = 5, members list shows all 5
  6. Query history of any member: shows A or B (ended) → AB (started)
  
  **Acceptance:** Merger is atomic; old fractions are soft-deleted but visible in history; no member is lost.

### 7.4 Written question lifecycle

- [ ] Scenario: Schriftelijke vraag from Fractie A:
  1. Create SchriftelijkeVraag: onderwerp="Fietspad Volendam-Noord", indienendeFractie=A, indienendRaadslid=X, status="ingediend"
  2. Verify vraagNummer auto-generated (SV-2024-001)
  3. PATCH: status="in-behandeling"
  4. PATCH: antwoordTekst="...", antwoordDatum=today, status="beantwoord"
  5. Query: `/api/objects?register=schriftelijke-vragen&filter[status]=beantwoord` → returns this question
  
  **Acceptance:** Lifecycle progresses; vraagNummer is auto-set; final status query finds it.

### 7.5 Data export (CSV/JSON)

- [ ] Test export functionality:
  ```
  GET /api/objects?register=fractie,fractie-lidmaatschap&format=csv&period=2024-2028&excludePrivate=true
  → Returns CSV with all fracties and memberships, no private contact fields
  
  GET /api/objects?register=schriftelijke-vragen&format=json&period=2024
  → Returns JSON array with all questions from 2024
  ```
  **Acceptance:** Both formats are valid (CSV parseable, JSON valid); no private fields in output.

---

## 8. Documentation

### 8.1 OpenRegister schema documentation

- [ ] Create `docs/schemas/politieke-partij.md`, `docs/schemas/kandidatenlijst.md`, `docs/schemas/fractie.md`, `docs/schemas/fractie-lidmaatschap.md`, `docs/schemas/schriftelijke-vraag.md`, `docs/schemas/fractie-ondersteuning.md`.
  Each doc includes: field list with types and constraints, example objects, related schemas, API endpoints.
  
  **Acceptance:** All 6 docs exist; each has ≥3 example API calls (create, list, query by filter); no typos or dead links.

### 8.2 Griffier workflow guide

- [ ] Create `docs/guides/fractie-beheer.md` (Griffier guide):
  - How to create a fractie after elections
  - How to register a member split (3 steps: end old membership, create new fractie, create new membership)
  - How to merge two fracties
  - How to handle succession (member departs, new member arrives)
  - How to publish written questions and track deadlines
  - Screenshots or UI wireframes
  
  **Acceptance:** Doc is comprehensive (covers all 5 operations); each operation has step-by-step instructions; doc is readable by non-developer griffiers.

### 8.3 Data analyst export guide

- [ ] Create `docs/guides/fractie-data-export.md`:
  - How to download faction history (CSV/JSON)
  - Field explanations (what does redenBegin mean, etc.)
  - Privacy notice (which fields are excluded)
  - Open data license (CC0 or CC-BY)
  - Example queries (list all splits in a term, track member movement)
  
  **Acceptance:** Doc has ≥3 worked examples; export format is clearly documented; license is stated.

### 8.4 API documentation

- [ ] Add to main API docs or Postman collection:
  - List all 6 new registers with example requests/responses
  - Document computed field `fractieAtDatum(raadslidUuid, date)`
  - Document the `fractieSnapshot` field on Stemgedrag
  - Link to schema docs (8.1)
  
  **Acceptance:** All 6 registers are documented; at least one worked example per register; computed fields are explained.

### 8.5 Standards & legal reference guide

- [ ] Create `docs/references/fractie-wettelijk-kader.md`:
  - Gemeentewet articles (7, 12, 33, 36b) with Dutch full text links
  - Kieswet zetelverdeling rules
  - Wfpp transparency requirements
  - Gemeente-specific regulations (verordening fractieondersteuning)
  - Link to Kiesraad standards (sZNL2, election data format)
  
  **Acceptance:** All standards are cited with link/article number; at least one paragraph per standard explaining relevance to this change.

---

## 9. Quality Gates

### 9.1 OpenRegister schema validation

- [ ] Run OpenRegister schema validator on all 6 schema JSON files:
  ```bash
  openregister validate openregister/schemas/fractie.json (etc.)
  ```
  All 6 schemas pass validation.
  
  **Acceptance:** Validator exits 0 for all 6.

### 9.2 Unit & integration tests pass

- [ ] Run full test suite:
  ```bash
  composer test:unit
  composer test:integration
  ```
  All tests in sections 6 and 7 pass.
  
  **Acceptance:** Test output shows ≥30 tests passing (rough estimate: 6 schema tests + 6 CRUD tests + 8 validation tests + 6 computed field tests + 5 integration tests); zero failures.

### 9.3 API smoke tests

- [ ] Quick validation of all 6 endpoints:
  ```bash
  curl /api/objects?register=politieke-partijen → 200, returns array
  curl /api/objects?register=kandidatenlijsten → 200
  curl /api/objects?register=fracties → 200
  curl /api/objects?register=fractie-lidmaatschappen → 200
  curl /api/objects?register=schriftelijke-vragen → 200
  curl /api/objects?register=fractie-ondersteuning → 200
  ```
  All 6 return 200 with valid JSON.
  
  **Acceptance:** All 6 endpoints respond as expected.

### 9.4 Data integrity checks (post-migration)

- [ ] Run migration integrity checks (from 5.2):
  - No orphaned FractieLidmaatschappen (all reference valid Raadslid + Fractie)
  - No overlapping FractieLidmaatschap per raadslid (max 1 active per date)
  - All Fracties have ≥1 member
  - All Stemgedrag votes have fractieSnapshot (or null with logged reason)
  
  **Acceptance:** All 4 checks pass (zero errors, or logged exceptions with justification).

### 9.5 Seed data verification

- [ ] Verify seed data (Edam-Volendam example) loads and is queryable:
  ```bash
  curl /api/objects?register=politieke-partijen&limit=100
  → returns 3 parties (PvdA, VVD, Edam Lokaal)
  
  curl /api/objects?register=fracties&limit=100
  → returns 4 fracties (3 post-election + 1 split)
  
  curl /api/objects?register=fractie-lidmaatschappen&limit=100
  → returns 10+ memberships
  ```
  All seed data is present and correct.
  
  **Acceptance:** All 3 queries return expected counts.

### 9.6 Documentation completeness

- [ ] Verify all docs from section 8 exist and are readable:
  ```bash
  ls -la docs/schemas/fractie*.md → 6 files
  ls -la docs/guides/fractie*.md → 2 files
  ls -la docs/references/fractie*.md → 1 file
  
  # Spot-check: each doc has ≥3 examples or clear explanations
  grep -c "example\|Example" docs/guides/fractie-beheer.md → ≥3 hits
  ```
  
  **Acceptance:** All 9 docs exist; spot-check confirms they have substantive content.

---

## 10. Handoff & Next Steps

### 10.1 Known non-goals (separate changes)

- [ ] Document what is deliberately **not** in this change:
  - Griffier admin UI (frontend, `decidesk-fractie-admin-ui` change)
  - Kiesraad election import (integration change, `decidesk-kiesraad-sync`)
  - Commission seat reallocation UI (handled by commission change, `decidesk-commissie-realloc`)
  - Fractie-portaal (internal workspace, `decidesk-fractie-portaal` change)
  - Question deadline automation (depends on this, `decidesk-vraag-termijn` change)
  
  Create `NEXT_STEPS.md` in the change directory listing these dependencies and approximate effort/timeline.
  
  **Acceptance:** File exists and is linked from README or docs.

### 10.2 Version & release notes

- [ ] Add entry to CHANGELOG for this release (e.g., v3.0.0):
  ```
  ## [3.0.0] — 2026-05-22
  ### Added
  - Fractie (political faction) register with complete membership history
  - PolitiekePartij, Kandidatenlijst, FractieLidmaatschap, SchriftelijkeVraag, FractieOndersteuning schemas
  - Computed field fractieAtDatum() for historical faction queries
  - Vote snapshot tracking (Stemgedrag.fractieSnapshot)
  - Public `/raad/fracties` portal support
  - Data export (CSV/JSON) for researchers
  ```
  
  **Acceptance:** CHANGELOG is updated; version number is consistent with project semver.

### 10.3 PR checklist

- [ ] Final PR preparation:
  - [ ] All tasks in this file are marked done
  - [ ] All tests pass (`composer test:all`)
  - [ ] All docs are written and spell-checked
  - [ ] Seed data loads cleanly
  - [ ] No console warnings or errors in logs
  - [ ] PR title and description are clear (reference this spec)
  
  **Acceptance:** PR is ready for review.

