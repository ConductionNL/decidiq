# Design: vve-alv-pack

## Architecture Overview

Pure thin-client extension (ADR-022/ADR-037). Four new OpenRegister schemas — `VveConfiguration`, `VveDecisionTemplate`, `ModelreglementPreset`, `KascommissieVerklaring` — ship as one `lib/Settings/register.d/57-vve-alv-pack.json` fragment (fragment number 57 is assigned to this change; 40–56 and 58–65 belong to sibling changes — never renumber). All statutory content is **seed data**; all voting behaviour is **reuse**: the weighted tally, threshold evaluation, and quorum calculation stay in voting-system/process-configuration and are only *parameterised* (majority resolution) and *re-rendered* (breukdelen fractions) here.

```
ModelreglementPreset (builtIn seeds: 1992 / 2006 / 2017)
        ▲ preset ref                    VveDecisionTemplate (6 builtIn seeds)
        │                                       │ instantiate
VveConfiguration ──ref──▶ GovernanceBody        ▼
  ├─ breukdelenDenominator (10.000)         Decision (existing model, unchanged)
  ├─ splitsingsakteDocument ──ref──▶ governing-documents-register (sibling)
  └─ majorityOverrides[] ──────────────▶ round-open voting-rule defaults
                                          (existing process-configuration chain)
KascommissieVerklaring ──ref──▶ AgendaItem (jaarrekening) · referenced by decharge Decision
  └─ FileService verslag attachment
Membership.votingWeight (existing) ──rendered as──▶ breukdeel/denominator (presentation only)
```

Cross-references, not duplication:
- `VveConfiguration.splitsingsakteDocument` → the governing document registered by the `governing-documents-register` sibling; this change stores the reference and the per-akte majority overrides, never the document registration itself.
- Decisions from templates are ordinary `Decision` objects; decision lifecycle, routes, voting, and minutes need zero changes.
- The annual rhythm (when the jaarrekening/decharge happen) is `pc-cyclus`'s; this change owns the statutory decision *content* — hard boundary stated in both proposals.

## Decisions

### D1: Four schemas — configuration, decision template, preset, verklaring

A single "VvE settings blob" was rejected: presets and templates are shared read-only built-ins (many bodies → one preset), while the configuration and verklaringen are per-body objects with their own relations and file attachments — different lifecycles, different RBAC surfaces. Splitting template from preset keeps the template reusable across reglementen (the template names its *category*; the preset maps category → majority per reglement).

**Alternative considered:** putting `breukdelenDenominator`/`modelreglement` directly on `GovernanceBody` — rejected: it would edit `decidesk_register.json` (forbidden by ADR-037 fragment discipline) and pollute the universal body schema with vertical-specific fields for the four non-VvE domains.

### D2: VveDecisionTemplate is a new schema, not a ProcessTemplate extension

`ProcessTemplate` (43-process-config-v1.json) models a decision *state machine* consulted by the transition guard. A VvE decision template is statutory *content* (name, proposed besluittekst, category, default majority) — different shape, different consumers, and irrelevant data in the guard's hot path invites accidents (same reasoning as pc-cyclus D2). The *pattern* is reused wholesale: builtIn seeds via the fragment's seed-data path, read-only-but-duplicable with the same server-side refusal mechanism as `ProcessTemplateService`.

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Statutory templates + reglement presets | **Seed data** in the register fragment (x-openregister seedData path, `builtIn: true`) | Pure content; zero app code; process-configuration precedent |
| Schema relations (config→body/preset, verklaring→agenda item/body) | `x-openregister-relations` | Standard declarative relation dialect |
| Verslag upload | OpenRegister FileService attachment on the verklaring object | Existing capability; no app-local file storage |
| Breukdelen fraction rendering, quorum/totals/results in breukdelen | **Frontend presentation** — pure formatter (`breukdelen.js`) over already-fetched `votingWeight` + denominator | Display concern; no dialect and no backend involved; tally engine untouched |
| Sum-of-breukdelen = denominator warning | **Frontend validation helper** (pure function, vitest-covered, agendaRules.js style) | Non-blocking advisory over loaded memberships; a lifecycle/notification dialect is for state and messages, not inline UI warnings |
| Majority resolution (caller > akte override > preset > template default) | **Imperative** — small resolver feeding the existing round-open defaults | Cross-object precedence lookup is not expressible as a dialect; mirrors `ProcessTemplatePolicyResolver`'s additive pattern; fail-soft to existing defaults |
| Built-in read-only guard (presets + templates) | **Imperative** — refuse edit/delete when `builtIn: true`, allow duplicate | Same rule and mechanism as ProcessTemplateService built-ins |
| Decision instantiation from template | **Imperative** — thin create pre-filling an ordinary Decision | Multi-object copy step; the created object then lives entirely in existing machinery |
| Missing/afkeurend verklaring warning on decharge | **Frontend presentation** over the decision's verklaring reference | Advisory, never a block (ALV sovereignty, BW 2:48) |
| VvE statutory agenda items | Additive `STATUTORY_VVE_ITEMS` in `src/services/agendaRules.js`, active when the body has a `VveConfiguration` | Extends the existing pure-function warning concept; agenda-management spec untouched (ADDED requirement here, never a MODIFIED delta) |

No `x-openregister-lifecycle` and no `x-openregister-notifications` in this change: none of the four schemas has a state machine (verklaring verdict is a value, not a lifecycle), and no scheduled rappels are in scope — gate-18 trivially clean.

### D4: Majority resolution rides the round-open default chain

process-configuration already established the precedence discipline "explicit caller value always wins; template defaults fill the gaps; missing config fails soft". The VvE resolver slots between: caller > `majorityOverrides[]` (splitsingsakte) > preset category mapping > `VveDecisionTemplate` default > existing behaviour. It resolves to the *existing* `voteThreshold` enum and quorum machinery — never a parallel evaluator. The applied value and its source tier are recorded with the round configuration so the minutes can show *why* 2/3 applied (the voting-system spec already records applied threshold/base).

**Alternative considered:** encoding VvE majorities as extra built-in ProcessTemplates (e.g. "VvE machtiging 2/3") — rejected: it would explode the template list (6 categories × 3 reglementen × akte deviations), and a body can hold only one assigned ProcessTemplate while VvE majorities vary *per decision category* within one body.

### D5: Breukdelen are presentation + validation, never tally

`Membership.votingWeight` (REQ-MAT-006) already carries the breukdeel numerator and the voting-system weighted tally already computes weighted results. The only missing pieces are rendering (`150/10.000` instead of `150`) and the integrity check (sum = denominator). Making the denominator a display divisor keeps every stored number an integer breukdeel and requires zero migration of existing weights. The sum check is a **warning**: blocking would strand a VvE mid-mutation (appartementsrecht split/merge) and the meeting-attendees auto-population must keep working with a temporarily inconsistent register.

### D6: Kascommissie verklaring is an object, not an agenda-item field

The verklaring outlives the agenda item (it is referenced by the decharge decision, belongs to a boekjaar, and carries its own file), and a VvE may record it before the ALV agenda exists. A dedicated small schema linked to the agenda item and referenced from the decharge decision keeps agenda-management and decision-management schemas untouched. The decharge feed is advisory presentation (missing/afkeurend → warning), never a transition guard: the ALV can lawfully grant decharge against the kascommissie's advice.

## Nextcloud Integration

- Controllers: one thin `VveController` for `instantiateTemplate` and `duplicate` actions (`#[NoAdminRequired]` + per-object governance guard — no-admin-idor/semantic-auth gates); no CRUD controllers (redundant-controller gate — configuration/verklaring CRUD goes through the OR object API from the frontend stores).
- Services: `VveMajorityResolver` (pure precedence resolution, consumed at round-open next to the existing template defaults) and the built-in read-only guard riding the existing settings/service pattern; saves via container-lazy `OCA\OpenRegister\Service\ObjectService::saveObject()` carrying **all** fields forward (PUT-semantic, nulls omit schema props).
- Mappers/Entities: none — no app tables (thin client). Verslag files via OR `FileService`, container-resolved as in `MeetingFolderService`.
- Events/Hooks: none new.
- Frontend: `src/services/breukdelen.js` (formatter + sum check, pure, vitest), `STATUTORY_VVE_ITEMS` addition in `src/services/agendaRules.js`, fraction wiring in attendee/quorum/tally/results surfaces, kascommissie dialog in `src/dialogs`/`src/modals` (modal-isolation gate), manifest pages for templates/presets/configuration/verklaringen in `src/manifest.d/vve-alv-pack.json` (schema refs by slug, never PascalCase).

## Security Considerations

- **Built-in integrity:** edit/delete refusal for `builtIn: true` presets and templates is enforced server-side (mirroring ProcessTemplateService); duplication clears the flag. Statutory majorities cannot be silently mutated on the canonical seeds.
- **Majority resolution fail-soft, never fail-open in the wrong direction:** a missing/malformed VvE configuration falls back to the existing round-open defaults — it never *lowers* an explicitly configured threshold; the applied source tier is recorded for auditability.
- **Instantiate/duplicate authority:** governance-scoped actions guarded per-object (body scope), not merely by route annotation (semantic-auth gate).
- **No public surface:** all four schemas are internal governance objects behind OR RBAC; no anonymous routes; no change to public-publication (its eligibility-gates requirement is explicitly untouched). Verslag files are ordinary FileService attachments under existing file ACLs.
- **No writeOnly/secret fields** on any schema; no financial data by construction.
- **CSRF/auth posture:** standard NC attributes on the two controller methods; routes registered with matching methods (route-reachability gate).

## File Structure

```
lib/Settings/register.d/57-vve-alv-pack.json     (new — 4 schemas + seeds)
src/manifest.d/vve-alv-pack.json                 (new — templates/presets/config/verklaringen pages + menu)
lib/Service/VveMajorityResolver.php              (new — precedence resolution into round-open defaults)
lib/Controller/VveController.php                 (new — instantiateTemplate / duplicate)
appinfo/routes.php                               (edit — 2 routes)
src/services/breukdelen.js                       (new — fraction formatter + sum check, pure)
src/services/agendaRules.js                      (edit — additive STATUTORY_VVE_ITEMS + VvE-aware helper)
src/dialogs|modals/KascommissieVerklaring*.vue   (new — record verklaring + verslag upload)
tests/Unit/Service/VveMajorityResolverTest.php   (new)
tests/e2e/vve-alv-pack.spec.ts                   (new — per gate-19)
docs/features/vve-alv-pack.md                    (new)
```

## Seed Data

Realistic data per ADR-016: the fictional **VvE Zeewaarts** (Boulevard 1–47, Zandvoort) — 24 appartementsrechten, breukdelen out of **10.000**, splitsingsakte of 2019 on **modelreglement 2017**. References use existing decidiq seed objects (association governance body, seeded Persons/Memberships) or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder resolved at import. Envelope for every object: `@self = { register: decidesk, schema: <slug>, slug: <below> }`.

### Schema: `modelreglement-preset` (built-ins, `builtIn: true`)

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | modelreglement-1992 | modelreglement-2006 | modelreglement-2017 |
| name | Modelreglement 1992 | Modelreglement 2006 | Modelreglement 2017 |
| builtIn | true | true | true |
| categoryRules | machtiging-boven-drempel: 3/4 + quorum 2/3 (art. 38 lid 5) · wijziging-huishoudelijk-reglement: 2/3 (art. 44) · overige: simple-majority | machtiging-boven-drempel: 2/3 + quorum 2/3 (art. 52 lid 5) · wijziging-huishoudelijk-reglement: 2/3 (art. 59) · overige: simple-majority | machtiging-boven-drempel: 2/3 + quorum 2/3 (art. 56 lid 5) · wijziging-huishoudelijk-reglement: 2/3 (art. 60) · overige: simple-majority |

Every rule carries its `article` source string; exact article numbers flagged for juridical review (proposal Risk 1).

### Schema: `vve-decision-template` (built-ins, `builtIn: true`)

| Field | decharge-bestuur | vaststelling-jaarrekening | dotatie-reservefonds | vaststelling-mjop | machtiging-boven-drempel | wijziging-huishoudelijk-reglement |
|-------|------------------|---------------------------|----------------------|-------------------|--------------------------|-----------------------------------|
| decisionCategory | decharge | jaarrekening | reservefonds-dotatie | mjop-vaststelling | machtiging-boven-drempel | wijziging-huishoudelijk-reglement |
| defaultVoteThreshold | simple-majority | simple-majority | simple-majority | simple-majority | qualified-majority-two-thirds | qualified-majority-two-thirds |
| defaultQuorumFraction | — | — | — | — | 2/3 | — |
| reglementSource | BW 2:48/2:49 | MR 2017 art. 14/15 | Woningwet reservefonds (BW 5:126) | MR 2017 art. 14 (MJOP-grondslag) | MR 2017 art. 56 lid 5 | MR 2017 art. 60 |
| proposedText | standard Dutch besluittekst per template (e.g. "De vergadering verleent het bestuur decharge over het boekjaar {boekjaar}…") | idem | idem | idem | idem | idem |

### Schema: `vve-configuration`

| Field | Object 1 |
|-------|----------|
| slug | vve-zeewaarts-configuratie |
| governanceBody | (association body seed `vve-zeewaarts`, bodyType `association` — nil-UUID placeholder if the body seed resolves at import) |
| modelreglement | modelreglement-2017 |
| breukdelenDenominator | 10000 |
| splitsingsakteDocument | governing-documents-register reference (nil-UUID placeholder until the sibling lands) |
| majorityOverrides | one entry: wijziging-huishoudelijk-reglement → qualified-majority-three-quarters (akteArtikel "splitsingsakte 2019 art. 62") — demonstrates the akte-beats-preset tier |

Supporting seeds ride the same fragment: the `vve-zeewaarts` governance body plus 24 Person+Membership records whose `votingWeight` values are the breukdelen — 8× penthouse 620, 8× hoekappartement 450, 7× tussenappartement 300, 1× berging-cluster 250 (sum 10.000; one seeded variant meeting keeps a 150-breukdeel membership expired so the sum-warning path is demoable at 9.850).

### Schema: `kascommissie-verklaring`

| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | kascommissie-verklaring-2025 | kascommissie-verklaring-2024 |
| boekjaar | 2025 | 2024 |
| verdict | goedkeurend | met-voorbehoud |
| toelichting | "Administratie ordelijk; reservefondsdotatie conform MJOP." | "Voorbehoud: onderhoudsfacturen Q3 ontbraken bij controle." |
| agendaItem | jaarrekening-item of the seeded VvE ALV (nil-UUID placeholder) | — (pre-agenda recording demonstrated) |
| governanceBody | vve-zeewaarts | vve-zeewaarts |

**Related items per object:**
- Files: "Kascommissieverslag 2025 VvE Zeewaarts.pdf" attached via FileService on `kascommissie-verklaring-2025`; "MJOP 2026-2041 (concept).pdf" attached on the seeded MJOP-vaststelling decision.
- Decisions: two seeded Decisions instantiated from templates — `decharge-bestuur-2025` (references verklaring-2025) and `vaststelling-mjop-2026` — living in the ordinary Decision model.
- A seeded `general_assembly` meeting "ALV VvE Zeewaarts 2026" whose agenda includes jaarrekening + kascommissieverslag + begroting but **no MJOP item**, so the VvE statutory warning is demoable on install.

## Migration Plan

1. Land register.d + manifest.d fragments, resolver + controller, breukdelen/agendaRules frontend work, dialogs, seeds, tests, docs in one decidiq PR (fragments are additive; `ConfigurationService::importFromApp()` / repair step picks up new schemas on upgrade).
2. No coordination needed with `pc-cyclus` or `governing-documents-register` (boundary only; the splitsingsakte reference is a plain relation that resolves once the sibling lands — nil-UUID placeholder until then). Fragment 57 is this change's, exclusively.
3. Rollback: revert the PR — fragment disappears, pages unregister, routes vanish. Existing VvE objects remain soft-retained in OR; Decisions created from templates are ordinary decisions and survive untouched. No data migration.

## Risks / Trade-offs

- [Wrong statutory majorities shipped] → every seeded rule names its article; presets read-only + duplicable; akte override tier absorbs deviations; juridical review flagged before release (proposal Risk 1).
- [Breukdelen scope creep into the tally] → hard rule: presentation + validation only (D5); any tally/quorum change is a voting-system spec change, out of scope here.
- [Sum warning misread as a blocker] → warning copy names sum and expected denominator and states saving proceeds; never wired into save/transition guards.
- [Boundary erosion with siblings] → no cycle steps created, no documents registered — references only; boundaries stated in all three proposals.
- [Resolver silently lowering a threshold] → resolver only *fills unset* values below the caller tier and records the applied source; PHPUnit covers every precedence pair.

## Open Questions

- Exact article-level majority/quorum mapping per modelreglement (seeded best-effort; juridical review before release — proposal Open Questions).
- Whether the quorum display needs the "second meeting" rule (MR: a new meeting after quorum failure decides regardless of quorum) — deferred; trackable as a future requirement on voting-system, not this pack.
