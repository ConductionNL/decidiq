# Design: vragenuur-interpellatie

## Architecture Overview

Pure thin-client extension (ADR-022/ADR-037). Three new OpenRegister schemas — `MondelingeVraag`, `Interpellatieverzoek`, and `VragenuurConfiguratie` — ship as one assigned `lib/Settings/register.d/49-vragenuur-interpellatie.json` fragment (OpenAPI `components.schemas`, merged onto `decidesk_register.json` at load; the base file is never edited; numbers 40–48 and 50–65 belong to sibling changes). Workflow behaviour is declared in OpenRegister dialects (`x-openregister-lifecycle`, `x-openregister-notifications`, RBAC authorization); all UI is manifest-v2 pages in a `src/manifest.d/vragenuur-interpellatie.json` fragment rendered by `CnPageRenderer` (frontend talks to `/apps/openregister/api/objects` via the shared object stores — no decidiq CRUD controllers, per the redundant-controller gate).

Imperative code is limited to the places declarative dialects genuinely cannot reach:

1. `OralQuestionService` — number generation (`MV-`/`INT-{jaar}-{volgnummer}`, sequence per body per year), submission-deadline validation against the target meeting's start (with griffier override), and the cross-object SchriftelijkeVraag side effect on oral answering.
2. `PublicationEligibilityService` / `PublicationPayloadService` — the two new payload types (extends the existing eligibility matrix and allow-list payload builders).

Cross-references, not duplication:
- `MondelingeVraag.bronSchriftelijkeVraag` / `.vervolgSchriftelijkeVraag` → `SchriftelijkeVraag` (`fractievoorzitter-fractie-koppeling`, hard dependency — that change also owns the SV answering workflow and already declares the `vervallen-door-mondelinge-beantwoording` status this change sets).
- `MondelingeVraag.vervolgToezegging` → `Toezegging` (`toezeggingen-ingekomen-stukken`, nullable/soft); afdoening stays there.
- `vragenuurAgendaItem` / `Interpellatieverzoek.agendaItem` → ordinary `AgendaItem` objects (agenda-item-crud); live meeting handling and the speaking-time clock (REQ-STM) need zero changes.

## Decisions

### D1: Three schemas, one change, one fragment

MondelingeVraag and Interpellatieverzoek are distinct instruments (different lifecycle, different admission authority — chair/griffier vs. the council) but share the vragenrecht domain, the `VragenuurConfiguratie` per-body settings object, the griffie stakeholder, and one market gap; the motie change deferred interpellatie exactly here. One change keeps fragment 49 and the seed data coherent; the capabilities stay separate specs so they archive independently.

**Alternative considered:** folding interpellatie into a future moties follow-up — rejected: an interpellation is a question instrument (Gemeentewet art. 155), not a decision instrument; its natural linkage is the questions domain.

### D2: Per-body settings as a schema object, not properties on GovernanceBody

`VragenuurConfiguratie` (one object per body: submission deadline hours, interpellation support threshold) is a new schema instead of new properties on `GovernanceBody`. This keeps the change ADDED-only (no MODIFIED requirement on an existing capability, no schema edit outside fragment 49), avoids racing sibling changes that touch GovernanceBody, and matches how bodies with no vragenuur simply have no configuratie object (threshold display degrades gracefully per REQ-VRI-011).

**Alternative considered:** properties on GovernanceBody — rejected per above; also admin-settings-style app config was rejected because the threshold is per body, not per instance.

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| MondelingeVraag lifecycle (`ingediend → toegelaten\|afgewezen`, `toegelaten → ingepland → beantwoord\|niet-behandeld`, re-schedule loop, `ingetrokken`) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`states`-only/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| Interpellatieverzoek lifecycle (`ingediend → toegelaten\|afgewezen`, `toegelaten → geagendeerd → behandeld`, `ingetrokken`) | `x-openregister-lifecycle` | Same |
| Submission confirmations, admission-decision and scheduling notifications (nl/en) | `x-openregister-notifications` `created`/`updated` triggers | ADR-031 default; gate-18 hard-fails imperative dispatch |
| Transition authority (raadsleden create; griffie/chair update) | OR RBAC `authorization` on the schemas | Declarative authorization; no app-side role checks |
| Support-threshold status ("N of M required") | Client-side computed display from VragenuurConfiguratie + body member count | Informative, never a gate (REQ-VRI-011); no server code needed |
| Scheduling (set agenda-item ref + volgorde + transition) | Plain object edits via the shared stores | No side effects beyond the object itself |
| MV/INT number generation (per body per year) | **Imperative** — `OralQuestionService` submission action | Sequence allocation is not expressible as a dialect (same reason the SV numbering in the fractie change is imperative) |
| Submission deadline (`targetMeeting.start − indieningstermijnUren`, griffier override) | **Imperative** — same service, server-side validation | Cross-object datetime comparison; no dialect evaluates a related object's field |
| SV → `vervallen-door-mondelinge-beantwoording` on oral answering | **Imperative** — same service, PUT-semantic saveObject carrying all SV fields forward | Cross-object side effect on another schema's lifecycle |
| Publication eligibility + payloads for the two new types | **Imperative** — existing eligibility/payload services | Allow-list payload construction is by design imperative in decidiq (existing pattern) |

### D4: Publication via derived payloads; delta is ADDED-only

Both types follow the full existing public-publication machinery (derived allow-list payload, server-side eligibility, predicate + OpenCatalogi routing, withdraw/rectify) — no live-predicate carve-out like the toezeggingenlijst, because MV/INT objects carry fields that must never publish raw (rejection reasons pre-decision context, individual supporter identities). The interpellation payload carries the support **count** only; supporter references are structurally absent (same "totals, never voters" discipline as vote publication). The public-publication delta spec adds one new requirement (REQ-VRI-015) instead of MODIFYING the "Publication eligibility gates" requirement, because the unarchived `toezeggingen-ingekomen-stukken` change already MODIFIES that block — two concurrent MODIFIEDs of the same requirement is a known archive-time data-loss mode.

**Alternative considered:** MODIFIED on the eligibility-gates requirement — rejected per the archive collision above; the ADDED requirement is self-contained and composes.

### D5: Answer recording creates the toezegging, never an execution log

A commitment made during the answer is a real `Toezegging` in the sibling register (pre-filled meeting/agendaItem/madeBy), linked back via `vervolgToezegging`. The question object stores only the spoken-answer summary. This mirrors the toezeggingen change's D3/REQ-003 discipline: one accountability record per instrument, cross-referenced, never duplicated.

### D6: Escalation is a linked pair, not a status mirror

Written→oral escalation creates a MondelingeVraag with `bronSchriftelijkeVraag` set; the SV keeps living in its own register and only its status flips (to a value its schema already declares) when the oral answer is recorded. No field mirroring, no shared lifecycle: if the oral question is rejected or withdrawn, the SV simply continues its written workflow untouched.

## Nextcloud Integration

- Controllers: `PublicationController` (existing — gains the two payload-type routings); a thin governance-scoped controller endpoint for the `OralQuestionService` submission/answer actions with `#[NoAdminRequired]` + per-object guard (no-admin-idor/semantic-auth gates).
- Services: `OralQuestionService` (new — numbering, deadline validation + override, SV side effect; transitions via `ObjectService::saveObject()` carrying **all** fields forward, PUT-semantic); `PublicationEligibilityService`, `PublicationPayloadService` (extended).
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: none new — notifications and lifecycles are OR-side declarative.
- Frontend: manifest pages via `CnPageRenderer`; dialogs in `src/dialogs`/`src/modals` (modal-isolation gate); Files leaf + audit trail sidebar on detail pages.

## Security Considerations

- **Transition authority:** raadsleden can create questions/requests and record support; only griffie/chair (OR RBAC groups on the schemas) can transition admission, scheduling, answering, and treatment. Enforced by OR RBAC declaratively, plus per-object guards on the imperative action endpoints (never route-annotation-only — semantic-auth gate).
- **Supporter privacy on publication:** individual `steunbetuigingen` never enter a payload; PHPUnit asserts structural absence and the assertion is mutation-guarded (adding a supporter changes only the count) so a no-op fake green is caught.
- **Deadline override:** explicit flag, griffie-only, visible in the audit trail — no silent bypass.
- **Cross-schema write (SV side effect):** goes through ObjectService with the caller's RBAC context; PUT-semantic save is unit-tested to prove an unrelated SV field survives.
- **CSRF/auth posture:** standard NC attributes on all touched controller methods; no public app routes — the only anonymous surface is OR/OpenCatalogi.

## File Structure

```
lib/Settings/register.d/49-vragenuur-interpellatie.json   (new — 3 schemas + dialects + seed)
src/manifest.d/vragenuur-interpellatie.json               (new — 4 pages + menu)
lib/Service/OralQuestionService.php                       (new — numbering, deadline, SV side effect)
lib/Service/PublicationEligibilityService.php             (edit — MV/INT gates)
lib/Service/PublicationPayloadService.php                 (edit — MV/INT payloads, support-count-only)
lib/Controller/PublicationController.php                  (edit — payload type routing)
appinfo/routes.php                                        (edit — submission/answer action routes)
tests/Unit/Service/...                                    (new/edit — numbering, deadline, side effect, payloads)
tests/e2e/...                                             (new — scenario coverage per gate-19)
docs/features/vragenuur.md, docs/features/interpellaties.md (new)
```

## Seed Data

Realistic Dutch municipal examples (fictional "Gemeente Voorbeeldingen"), planted via the fragment's `x-openregister.seedData` path (ADR-016). References use existing decidiq seed objects (gemeenteraad governance body, seeded raadsvergadering and its vragenuur agenda item — added by this seed) or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder resolved at import. Envelope per object: register `decidiq`, schema slug as listed, slug as listed.

### Schema: `vragenuur-configuratie`

| Field | Object 1 |
|-------|----------|
| slug | vragenuur-configuratie-gemeenteraad |
| governanceBody | (seed gemeenteraad body ref) |
| indieningstermijnUren | 24 |
| interpellatieSteunDrempelType | breukdeel |
| interpellatieSteunDrempelWaarde | 0.2 |

### Schema: `mondelinge-vraag`

| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | mv-wachtlijsten-jeugdzorg | mv-verkeersveiligheid-lindelaan | mv-sluiting-zwembad | mv-opvang-statushouders |
| vraagNummer | MV-2026-001 | MV-2026-002 | MV-2026-003 | MV-2026-004 |
| onderwerp | "Wachtlijsten jeugdzorg na aanbesteding" | "Verkeersveiligheid schoolzone Lindelaan" | "Voorgenomen sluiting zwembad De Plons" | "Opvangcapaciteit statushouders Q3" |
| indiener / fractie | (Raadslid + Fractie, nil-UUID placeholders) | idem | idem | idem |
| portefeuillehouder | (Person: wethouder, nil-UUID placeholder) | idem | idem | idem |
| targetMeeting | (seed raadsvergadering ref) | (seed raadsvergadering ref) | (seed raadsvergadering ref) | (seed raadsvergadering ref) |
| vragenuurAgendaItem | (seed vragenuur item ref) | (seed vragenuur item ref) | — | — |
| volgorde | 1 | 2 | — | — |
| lifecycle | beantwoord | ingepland | ingediend | afgewezen |
| afwijzingsReden | — | — | — | "Betreft een individuele casus, geen algemeen belang" |
| antwoordSamenvatting | "Wethouder erkent oplopende wachttijden; raadsbrief met plan van aanpak toegezegd vóór 1 maart." | — | — | — |
| beantwoordDoor | (wethouder ref) | — | — | — |
| vervolgToezegging | (Toezegging, nil-UUID placeholder) | — | — | — |
| bronSchriftelijkeVraag | (SchriftelijkeVraag, nil-UUID placeholder) | — | — | — |

Object 1 exercises the full path (escalated from a written question, answered, with a follow-up toezegging) and is seeded as published so the payload path is visible on install; object 3 keeps the admission action demoable; object 4 shows a stored rejection reason.

### Schema: `interpellatieverzoek`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | int-veiligheid-stationsgebied | int-jaarrekening-grondbedrijf | int-subsidie-cultuurhuis |
| verzoekNummer | INT-2026-001 | INT-2026-002 | INT-2026-003 |
| onderwerp | "Veiligheid stationsgebied" | "Tekorten jaarrekening grondbedrijf" | "Subsidieverlening cultuurhuis" |
| vragen | "1. Sinds wanneer was het college bekend met…" | "1. Waarom is de raad niet geïnformeerd over…" | "1. Op welke grond is de subsidie verhoogd…" |
| verzoeker / fractie / portefeuillehouder | (nil-UUID placeholders) | idem | idem |
| governanceBody | (seed gemeenteraad body ref) | idem | idem |
| steunbetuigingen | 4 Raadslid refs (threshold met) | 2 Raadslid refs (below threshold) | 5 Raadslid refs |
| lifecycle | behandeld | ingediend | geagendeerd |
| raadsbesluitDatum | 2026-05-12 | — | 2026-06-30 |
| behandeldIn / agendaItem | (seed meeting + own agenda-item refs) | — | (seed meeting + own agenda-item refs) |
| behandelingVerslag | "Interpellatiedebat gevoerd; wethouder zegt extern onderzoek toe…" | — | — |

**Related items per object:** Files: the written interpellation request PDF on interpellatieverzoek object 1 and the original schriftelijke vraag PDF on mondelinge-vraag object 1 via the Files leaf. Notes/Tasks/Contacts: none (internal follow-up belongs in a VTODO, deliberately not seeded). The seed also adds one "Vragenuur" AgendaItem to the seeded upcoming raadsvergadering so scheduling is demoable on install (ADR-016 testability).

## Migration Plan

1. `fractievoorzitter-fractie-koppeling` lands first or concurrently (hard `depends_on` — SchriftelijkeVraag/Fractie references and the SV status value).
2. Land fragment 49 + manifest fragment + `OralQuestionService` + publication extensions + seed + tests + docs in one decidiq PR (fragments are additive; `ConfigurationService::importFromApp()` / repair step picks up the schemas on upgrade).
3. `toezeggingen-ingekomen-stukken` ordering is soft: `vervolgToezegging` is nullable and degrades to a plain link if delayed.
4. Rollback: revert the PR — fragments disappear, pages unregister, payload types refuse again. MV/INT objects stay soft-retained in OR; an SV already set to `vervallen-door-mondelinge-beantwoording` keeps a status its own schema declares.

No data migration — all three registers start empty apart from seed data.

## Risks / Trade-offs

- [Two concurrent changes editing the publication eligibility text] → ADDED-only delta requirement (D4); never a second MODIFIED on the same block.
- [Lifecycle dialect drift (`initial` vs `initialState`)] → canonical dialect verbatim; gates 28/30/51/52 run on register+manifest changes; manifest refs use slugs (`mondelinge-vraag`, `interpellatieverzoek`, `vragenuur-configuratie`), never PascalCase.
- [SV side effect nulls unrelated SV fields] → PUT-semantic saveObject carries all fields forward; unit test asserts a non-changed SV field survives the transition.
- [Supporter identities leak via payload] → structural allow-list with count-only; mutation-guarded PHPUnit assertion (adding a supporter changes only the count).
- [Number sequence race on concurrent submissions] → sequence computed server-side in the submission action per body+year; collision retried once; uniqueness asserted in tests.
- [Threshold rounding disputes (1/5 of 19 members)] → round up (ceil), documented in the schema property description so the rule travels with the schema.

## Open Questions

- Default when no `VragenuurConfiguratie` exists for a body (proposal: no threshold display, decision still recordable) — confirm with griffie stakeholders during apply.
- Whether the vragenuur agenda item should carry a conventional tag (`vragenuur`) for auto-discovery in the scheduling dialog, or stay a free-form picker — provisional: free-form picker.
