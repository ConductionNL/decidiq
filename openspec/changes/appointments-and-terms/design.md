# Design: appointments-and-terms

## Architecture Overview

Everything data lives in OpenRegister via fragment `61-appointments-and-terms.json`: `Voordracht` (the nomination workflow object), `TermijnRegeling` (per body-role term rules), `RoosterVanAftreden` (one live projection object per body), and `RoosterRegel` (one first-class object per active Membership under a rule). The change **reads** but never redefines Person/Membership/Post (`person-and-membership`), GovernanceBody (`governance-body-crud`), VotingRound (`voting-system` + ballot specs), Decision, and AgendaItem — voordrachten reference them through `x-openregister-relations`.

Declarative surface: voordracht lifecycle, herbenoemingsrappels, publication predicate, pages, filters, and the expiring-terms KPI. Imperative surface (thin, decidesk backend): `BenoemingService` (assistive Membership creation from a `benoemd` voordracht), `RoosterService` (term derivation + rooster (re)generation + CSV export), and the vacancy-suggestion computation. Frontend consumes the OR API directly per the thin-client pattern; pages come from a `src/manifest.d/` fragment.

```
Voordracht ──besluit──> Decision          TermijnRegeling ─┐ (effective rule)
    │  └─votingRound──> VotingRound       Membership hist ─┴─> derive term nr + einde-termijn
    │  └─agendapunt───> AgendaItem                              │
    └──membership────> Membership  <── assistive create        ▼
                                          RoosterVanAftreden ─< RoosterRegel (rappels, KPI, CSV, publish)
```

## Decisions

### D1: Candidates as a structured array on the Voordracht, not separate objects

A voordracht carries 1–5 candidates that live and die with it (a candidacy has no meaning outside its voordracht); the winner is promoted to a Membership reference on the voordracht. Separate candidacy objects would add N+1 reads for every index row with zero independent lifecycle. Candidates support `persoon` (Person ref) **or** `externeNaam` because corporate RvC/RvT nominations routinely propose externals who get a Person record only after appointment. **Alternative considered:** a `Kandidaatstelling` schema — rejected: no independent lifecycle, volume trivial, and secret ballots already keep per-candidate vote data in the VotingRound, not here.

### D2: Term data is derived, never written onto Membership

`person-and-membership` owns the Membership schema; this change is ADDED-only and must not modify it (and duplicating term fields onto Membership would create two writers for one truth — the same reason `authorization-via-or-rbac` forbids direct scope writes). Term number and end-of-term date are pure functions of (Membership history, effective TermijnRegeling): computed by `RoosterService::deriveTerm()` and **materialized on RoosterRegel objects at generation time**, where they are visible, auditable, and regeneratable. A wrong derivation is fixed by fixing the function and regenerating — never by hand-editing member data. **Alternative considered:** MODIFIED requirement adding `termijnNummer`/`eindeTermijn` to Membership — rejected (ownership boundary, ADDED-only wave discipline, write-amplification on every membership save).

### D3: RoosterRegel objects are first-class, not an array on the rooster

Deliberate contrast with member-onboarding's D2 (steps array): here the per-entry `eindeTermijnDatum` must drive **declarative scheduled rappels** (`x-openregister-notifications` filters on object properties, not on array items) and the **expiring-terms KPI** (widget aggregation over objects). Volume is bounded (one regel per active Membership, ~5–45 per body) and regels have no cross-object completion guard, so the array's atomicity argument doesn't apply. The parent `RoosterVanAftreden` object exists to carry generation metadata and the publication predicate for the rooster as a whole. **Alternative considered:** `regels` array on the rooster — rejected: rappels and KPI would need imperative jobs, exactly what ADR-031 and gate-18 forbid.

### Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where no dialect can express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Voordracht status workflow (`ingediend → behandeld → benoemd \| niet-benoemd \| ingetrokken`) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`default`); `benoemd` transition requires `besluit` present | Pure guarded state machine; zero app code |
| Herbenoemingsrappels (6/3 months, configurable) | `x-openregister-notifications` scheduled triggers on `RoosterRegel.eindeTermijnDatum`, recipients = secretary/griffie group, nl/en subjects | ADR-031 default for reminders; gate-18 hard-fails imperative dispatch; same dialect as the toezeggingen deadline rappels — no bespoke `RappelJob` |
| Public rooster publication | `publicatiedatum` predicate + `authorization.read` for the `public` group while `publicatiedatum <= $now` (OR RBAC published-predicate surface) | Same surface as `public-publication`; no app-local anonymous endpoints |
| Expiring-terms KPI + voordrachten-per-status widgets | Manifest stat-widget `source` aggregations (`metric: count`) | Declarative counts like every existing KPI widget; documented fallback if the filter DSL lacks a relative-date token |
| Index/detail/rooster pages, filters, menu | Manifest.d fragment (slug refs) | Standard manifest v2 |
| Assistive Membership creation | **Imperative** — `BenoemingService` | Cross-schema write with prefill + Person-existence guard + explicit human confirm; not expressible as a dialect |
| Term derivation + rooster (re)generation + CSV export | **Imperative** — `RoosterService` | Cross-object date arithmetic over membership history and rule resolution; CSV is a file side effect |
| Vacancy detection + voordracht suggestion | **Imperative** — computation in `RoosterService`, surfaced as a reviewed suggestion list | Cross-source diff producing griffie-confirmed suggestions; never runs unattended (same never-automatic contract as member-onboarding's raadswisseling diff) |

### D4: Publication is predicate-on-live-object — justified against public-publication's derived-payload rule

`public-publication` forbids setting the predicate on live `Decision`/`Meeting`/`Minutes` objects because those carry confidential fields; it publishes derived allow-list payloads instead. The rooster needs no second derivation: it **is already** a generated, allow-list projection (name, role, term number, term dates, herbenoembaar — never contact details, NC UIDs, or vote data, REQ-APT-007/009), regenerated from source data rather than hand-edited. Setting `publicatiedatum` on the live rooster object therefore satisfies the *rationale* of the derived-payload rule while avoiding a payload-of-a-projection indirection. Withdrawal = clear the predicate. **Alternative considered:** a `PublicationPayload` per rooster via the public-publication pipeline — rejected as double derivation with no confidentiality gain; revisit only if roosters ever grow non-public fields.

### D5: Max consecutive terms is advisory, not blocking

Statutes and verordeningen commonly allow deviation by explicit decision (herbenoeming "in afwijking van het rooster"). A hard block would force data workarounds; instead the derived term number vs `maxAansluitendeTermijnen` yields a visible warning on the rooster and on any voordracht for that person/body-role, and the deviation lives in the voordracht's motivering (REQ-APT-006). Recorded as proposal open question; flip to blocking is a one-line lifecycle/validation change later.

### D6: Ballot and onboarding boundaries are reference-only

Appointment votes (often secret, sometimes ranked with multiple candidates) reuse `secret-ballot`/`preferential-ballot`/`voting-system` untouched — the voordracht stores only the `votingRound` reference (REQ-APT-003). After appointment, the `member-onboarding` sibling owns the traject; this change ends at the created Membership plus a degrade-gracefully handoff suggestion (REQ-APT-004), mirroring member-onboarding's D6 reference-only pattern in the opposite direction.

## API Design

### `POST /api/voordracht/{id}/benoeming`
Assistive Membership creation (griffie-confirmed). **Request:** `{ "kandidaatIndex": 0, "startDate": "2026-09-10" }` **Response:** `{ "membership": "<uuid>", "onboardingSuggestion": true|false }` — refuses (422) when the candidate has no Person record or the voordracht is not `benoemd`.

### `POST /api/rooster/{bodyId}/regenerate`
Regenerates the body's rooster (replaces regels, updates `gegenereerdOp`). **Response:** the rooster with ordered regels.

### `GET /api/rooster/{bodyId}/export.csv`
CSV export (UTF-8 BOM, end-of-term order).

### `GET /api/vacatures`
Vacancy overview: vacant Posts + prefilled voordracht suggestions. Creation of a voordracht from a suggestion goes through the normal OR object API.

All endpoints `#[NoAdminRequired]` with per-body secretary/griffie guards in the method body (no-admin-idor / semantic-auth gates).

## Nextcloud Integration

- Controllers: `AppointmentController` (benoeming, regenerate, export, vacatures) registered in `appinfo/routes.php` with explicit auth attributes.
- Services: `BenoemingService`, `RoosterService` — both consume OR's ObjectService abstractions (ADR-022; no pass-through CRUD wrappers for what the frontend already does via the OR API).
- Mappers/Entities: none — thin client, no own tables.
- Events/Hooks: none imperative; lifecycle/notifications/publication are OR dialects.

## Security Considerations

- Voordrachten may reference secret VotingRounds: the voordracht stores only the round reference; masking of individual votes stays entirely in `secret-ballot` (REQ-SBL-001) — no vote data is copied onto the voordracht.
- Published roosters expose only name/role/term data via the OR published-predicate (allow-list by construction, D4); publication is opt-in and withdrawable; unpublished roosters and all voordrachten stay behind normal RBAC.
- Assistive Membership creation writes access-relevant data (memberships feed the `authorization-via-or-rbac` projection) — hence explicit griffie confirmation, per-body authorization guard on the endpoint, and fail-closed refusal on missing Person.
- CSV export endpoint enforces the same per-body read guard as the rooster page; input validation on `kandidaatIndex`/dates server-side.

## NL Design System

Standard NC components via manifest v2 pages; CSS variables only (nldesign-compatible); term warnings rendered as text + icon (never color alone, WCAG 2.1 AA); rooster table keyboard-navigable with proper caption/headers.

## File Structure

```
lib/
  Settings/register.d/61-appointments-and-terms.json   # 4 schemas + dialects + seeds
  Controller/AppointmentController.php
  Service/BenoemingService.php
  Service/RoosterService.php
appinfo/routes.php                                     # new routes
src/
  manifest.d/appointments-and-terms.json               # pages + menu
  manifest.json                                        # KPI widgets
tests/
  Unit/Service/BenoemingServiceTest.php
  Unit/Service/RoosterServiceTest.php
  e2e/appointments-and-terms.spec.ts
docs/features/appointments-and-terms.md
```

## Seed Data

Per ADR-016, fragment 61 seeds realistic objects for **each** new schema, spanning the municipal committee and corporate RvC domains so both flagship uses are demoable on install. Linked to already-seeded Persons/GovernanceBodies/Memberships (nil-UUID placeholders only where a ref cannot resolve at seed time).

### Schema: `termijn-regeling` (2 objects)

| Field | Object 1 (municipal committee) | Object 2 (corporate RvC) |
|---|---|---|
| body | Auditcommissie (seeded municipal committee body) | Raad van Commissarissen (seeded corporate body) |
| role | *(empty — body-wide)* | *(empty — body-wide)* |
| termijnDuurMaanden | 48 | 48 |
| maxAansluitendeTermijnen | *(empty — unlimited)* | 2 |
| toelichting | "Benoeming voor de duur van de raadsperiode" | "Statutair: eenmaal herbenoembaar (art. 12 statuten)" |

### Schema: `voordracht` (3 objects)

| Field | Object 1 | Object 2 | Object 3 |
|---|---|---|---|
| body / targetRole | Auditcommissie / member | RvC / member | Auditcommissie / chair |
| kandidaten | Person "S. Jansen" | externeNaam "Mw. J. van Duin" → Person after benoeming | Person "K. Bakker" |
| voordragendePartij | fractie (seeded fractie) | orgaan: RvC (coöptatie-voordracht) | fractie |
| lifecycle | `ingediend` | `benoemd` | `ingetrokken` |
| links | *(none yet)* | agendapunt + secret votingRound + besluit (2026-09-10) + created membership | *(withdrawn before treatment)* |
| motivering | "Financiële expertise gewenst" | "Herbenoeming niet mogelijk (rooster); externe werving" | "Kandidaat trekt zich terug" |

### Schema: `rooster-van-aftreden` (2 objects)

| Field | Object 1 (RvC — **published**) | Object 2 (Auditcommissie — unpublished) |
|---|---|---|
| body | Raad van Commissarissen | Auditcommissie |
| gegenereerdOp | 2026-07-01 | 2026-07-01 |
| publicatiedatum | 2026-07-02 (**drives the anonymous-read demo**) | *(empty)* |

### Schema: `rooster-regel` (5 objects)

| Field | R1 (RvC) | R2 (RvC) | R3 (RvC) | R4 (Auditcie) | R5 (Auditcie) |
|---|---|---|---|---|---|
| persoonNaam / role | J. van Duin / member | P. de Wit / chair | A. Smit / member | S. Jansen / member | K. Bakker / member |
| termijnNummer | 1 | **2 (at max — drives the advisory warning)** | 1 | 1 | 1 |
| eindeTermijnDatum | 2030-09-10 | **2026-11-01 (inside 6-month window — drives KPI + rappel)** | 2028-03-15 | 2030-09-10 | 2027-05-01 |
| herbenoembaar | true | **false** | true | true | true |

**Related items per object:** voordracht Object 2 links the seeded raadsvergadering agenda item, a secret VotingRound, a seeded Decision, and the Membership it created; RoosterRegels reference seeded Memberships. No files/notes/tasks/contacts beyond these relations.

## Migration Plan

Additive only: new fragment 61, new manifest fragment, new services/routes. Deploy = register re-import picks up fragment 61. Rollback = revert the PR; existing voordracht/rooster objects stay inert and can be pruned via register admin. No data migration.

## Risks / Trade-offs

- [Regels go stale between regenerations (new appointment, early resignation)] → rooster page shows `gegenereerdOp` prominently and offers one-click regenerate; rappels fire from materialized dates, so a regenerate after membership changes is part of the secretary flow (documented); a stale rooster is visibly stale, never silently wrong.
- [Scheduled-notification dialect may not support per-object configurable windows] → default 6/3-month windows declared in the fragment; if per-body configurability is not expressible, config lives in the TermijnRegeling and regeneration stamps the window onto the regel (documented fallback, never an imperative job).
- [Term derivation across pre-decidesk history] → derivation uses whatever Membership history exists; imported partial history yields term number 1 — acceptable, correctable by importing older memberships and regenerating.
- [Sibling vocabulary drift (parallel wave: member-onboarding, fractievoorzitter)] → enums pinned to person-and-membership's role enum; voordragende-partij vocabulary pinned to the fractievoorzitter change; deferred review question raised.

## Open Questions

- Can the widget filter DSL express "eindeTermijnDatum within now+N months"? If not, the specced fallback applies (REQ-APT-012): count non-ended regels, cut the window on the pre-filtered index.
- Can `x-openregister-notifications` scheduled triggers read the rappel window from object data, or only from the schema declaration? Determines whether per-body windows land in v1 or stay at the 6/3 default (see Risks).
