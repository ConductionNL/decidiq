# Design: interests-and-integrity

## Context

Decidiq covers per-agenda-item conflict-of-interest declarations (`conflict-of-interest`, done) but has no standing interest registers: nevenfuncties exist only as `Membership.otherPositions` free-text strings (corp mode) and as a council-scoped proposal inside `fractievoorzitter-fractie-koppeling` (REQ-012); a geschenkenregister does not exist at all. Gemeentewet obliges public disclosure of raadsleden's and wethouders' nevenfuncties; every gemeentelijke gedragscode requires gift registration above ~EUR 50; MCCG expects RvC interest transparency (internally). Stakeholders: members (self-service declarations), griffie/bestuurssecretaris (verification, register upkeep), burgemeester/voorzitter/compliance officer (integrity notifications), the public (statutory register). Constraints: thin client (ADR-022), declarative-first (ADR-031), fragments only (ADR-037), no modification of the `public-publication` eligibility gates.

## Goals / Non-Goals

**Goals:** structured Nevenfunctie and Geschenk objects with per-body disclosure/threshold policy; a live public nevenfunctiesregister per body; declarative annual review and integrity notifications; self-service + register pages + compliance view + KPI; assistive nevenfuncties context on the COI surfaces; stable names for `member-onboarding`.

**Non-Goals:** integrity investigations/case management; gedragscode authoring (governing-documents-register); Wfpp party finance; automatic conflict detection; automated migration of `otherPositions` free text.

## Architecture Overview

A fully declarative thin-client extension — the first wave-2 change expected to ship **zero new PHP**. Three schemas (`Nevenfunctie`, `Geschenk`, `Integriteitsbeleid`) in one fragment `lib/Settings/register.d/62-interests-and-integrity.json` (OpenAPI `components.schemas`, merged at load; base file never edited). Lifecycle, rappels, integrity notifications, and public readability are all OpenRegister dialects/RBAC rules declared in the fragment. UI is a `src/manifest.d/interests-and-integrity.json` fragment (5 pages + menu) rendered by `CnPageRenderer`; publish/withdraw, end-position, and annual-confirm are field writes through the shared object stores against `/apps/openregister/api/objects` (redundant-controller gate: no decidiq CRUD wrappers).

Cross-references, not duplication:
- `Nevenfunctie.person` / `Geschenk.recipient` → `Person`; the compliance panel joins against `Membership` (person-and-membership) client-side.
- COI stays `conflict-of-interest`'s notes mechanism; this change only injects an assistive block into the REQ-COI-001 dialog and a link into the REQ-COI-002 panel.
- The gedragscode document lives in `governing-documents-register`; the policy object carries only the numeric threshold and defaults.

## Decisions

### D1: Three schemas, one change, fragment 62

Nevenfunctie and Geschenk are distinct registers (different lifecycle vs. decision shape, different publics) but share the policy object, the notification recipient model, one stakeholder set, and one market gap. Splitting would race on fragment numbering and split the policy schema's ownership. `Integriteitsbeleid` is a schema (one object per body), not GovernanceBody fields, because ADR-037 forbids editing the base register where GovernanceBody lives.

**Alternative considered:** policy fields on GovernanceBody — rejected: requires editing `decidesk_register.json` (forbidden) or a MODIFIED delta on a done canonical spec for what is plainly satellite configuration.

### D2: Supersede fractievoorzitter REQ-012's mechanics, compose with its portal

REQ-012 (sibling change, planned) wants a council nevenfuncties register published at an app-local `/raad/nevenfuncties` page, a burgemeester notification on paid positions, and an annual rappel. This change delivers all three generalized: register for every body type, publication via the OR predicate surface (app-local anonymous pages violate the fleet convention REQ-012 predates), notification recipient configurable per body (burgemeester is the council configuration), rappel declarative. The fractie-portaal (REQ-014 there) composes: it deep-links members to `MyDeclarations`. When `fractievoorzitter-fractie-koppeling` reaches apply, its REQ-012 should be thinned to a reference to this capability.

**Alternative considered:** letting REQ-012 land as specced and layering the general register on top — rejected: two nevenfuncties stores for the same raadslid is the exact duplication this wave exists to prevent.

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative; this change ends with **no imperative backend surface at all**:

| Behaviour | Mechanism | Why |
|---|---|---|
| Nevenfunctie disclosure workflow (`gemeld → openbaar \| intern → beëindigd`) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| Public nevenfunctiesregister / optional public geschenkenregister | Schema `authorization.read` rule for the `public` group while `publicatiedatum <= $now`; publish/withdraw = staff field writes | Existing OR RBAC predicate surface; no endpoint |
| Annual review rappel | `x-openregister-notifications` scheduled trigger (non-terminal + reviewedAt/declaredAt > 12 months), recipient = the object's person, nl/en subjects | ADR-031 default for reminders; gate-18 hard-fails imperative dispatch; no bespoke ReminderJob |
| Integrity notification (new/changed nevenfunctie, boven-drempel geschenk) | `x-openregister-notifications` `created`/`updated` triggers, recipient = per-body integrity group | Same |
| Dashboard KPI "Nevenfuncties zonder actuele review" | Manifest stat-widget `source` aggregation (`metric: count`) | Declarative count like every existing KPI |
| Boven-drempel badge | Frontend render rule comparing `geschatteWaarde` to the body's policy threshold | Display logic, not workflow |
| Compliance panel (members without reviewed declarations) | **Client-side join** of two standard OR list queries (memberships × nevenfuncties) in the index page | Cross-schema view no dialect or widget aggregation expresses; assistive, so client-side is acceptable — no backend endpoint |
| Annual confirm / end position / publish | Frontend actions doing plain `saveObject` field writes (full object carried forward — PUT-semantic) | No transition side effects beyond the declared dialects |

### D4: Predicate on the live object, never the derived-payload machinery

Same carve-out as `toezeggingen-register` D4, same rationale: the statutory register must be *live* (an ended nevenfunctie must show its endDate without a rectify cycle) and both schemas are publishable by construction — no internal-remarks property, no remuneration amounts, giver as a plain string. The `public-publication` eligibility gates, deny-list, and payload builder are untouched and never invoked for these schemas. Publication stays an explicit staff action (griffie verifies `gemeld` → `openbaar`, then sets `publicatiedatum`), satisfying the "never publish without explicit staff action" convention while meeting the Gemeentewet duty.

**Alternative considered:** extending public-publication with two new payload types — rejected: derived payloads guarantee a stale statutory register, and the eligibility-gates requirement is explicitly not to be modified by this wave.

### D5: Declaration is per person *and* per body

`governanceBody` is required on both schemas even though a nevenfunctie is intrinsically personal: the disclosure regime, threshold, notification recipient, and public register are all body-scoped, and a person holding roles in two bodies (raadslid + RvC-lid) faces two different regimes. The rare dual-role person registers the same outside position once per regime — accepted duplication, far simpler than a per-object policy resolution across multiple memberships.

### D6: COI integration is assistive frontend context only

The REQ-COI-001 dialog and REQ-COI-002 panel gain a nevenfuncties block fed by a standard OR list query (person + non-terminal lifecycle); "matching the item's subject" is a plain case-insensitive word overlap between the agenda item title and `organisatie`/`functie` — highlight only. No auto-declaration, no blocking, no scoring (out of scope by proposal). This keeps `conflict-of-interest` untouched as a spec: its notes mechanism, panel, and audit trail behave exactly as before, with one extra read-only block rendered inside.

### D7: Dashboard KPI lives in the base manifest; compliance panel lives on the index page

`mergePages()` replaces same-id pages wholesale, so the one-widget Dashboard addition is a direct `src/manifest.json` edit (same rationale as sibling changes). The compliance panel sits on the `Nevenfuncties` index (per-body via the body quick filter), not the dashboard: it needs the client-side join (D3) and body context. If the panel needs a custom widget, that trips the custom-widget ratchet — preferred implementation is composition of existing list/summary components; a ratchet exception is the documented fallback, not the default.

## Nextcloud Integration

- Controllers/Services/Mappers: none new, none edited (thin client; zero-PHP change).
- Register import: fragment 62 picked up by the existing repair step / `ConfigurationService::importFromApp()` merge.
- Notifications: OR-side declarative; recipients are NC groups (per-body integrity group from the policy object; see Open Questions for dialect recipient resolution).
- Frontend: manifest pages via `CnPageRenderer`; export via `ExportService` + `CnMassExportDialog`; any new dialogs live in `src/dialogs`/`src/modals` (modal-isolation gate); COI dialog/panel blocks extend the existing conflict-of-interest components.

## Security Considerations

- **Public predicate scope (high):** only `Nevenfunctie` (and `Geschenk` where policy opts in) carries the public read rule, gated on `publicatiedatum <= $now`; the schemas contain no non-publishable fields by construction — adding any internal-only property later requires revisiting D4 (constraint recorded in the schema `description`). No writeOnly fields exist, so no render-boundary exposure.
- **AVG/WOO:** `gever` is a plain string, never a Person object — no external-party PII in the people register; no addresses or remuneration amounts anywhere; publication of geschenken is off by default and per-body opt-in.
- **Self-service authorization:** members create/edit only their own declarations via OR per-object RBAC (owner + governance scope); griffie/staff scopes govern lifecycle processing and publication. No new endpoints → no route-auth/IDOR surface.
- **Integrity notifications** reveal declaration existence to the configured group only; the group is body-scoped configuration set by admins.

## File Structure

```
lib/Settings/register.d/62-interests-and-integrity.json   (new — 3 schemas + dialects + RBAC rules + seed)
src/manifest.d/interests-and-integrity.json               (new — 5 pages + menu)
src/manifest.json                                         (edit — 1 Dashboard stat widget)
src/(components|dialogs)/...                              (new/edit — COI assistive block, compliance panel, declare/confirm dialogs)
tests/e2e/...                                             (new — scenario coverage per gate-19)
docs/features/interests-and-integrity.md                  (new)
```

## Seed Data

Realistic Dutch examples continuing the existing seed cast (gemeenteraad + RvC Waterschap Amstel from `decidesk_register.json`); references use existing seed objects or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder resolved at import.

### Schema: `integriteitsbeleid` (2 objects)

| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | beleid-gemeenteraad | beleid-rvc-waterschap |
| governanceBody | (seed gemeenteraad ref) | (seed RvC ref) |
| nevenfunctieDisclosureDefault | openbaar | intern |
| geschenkDrempelbedrag | 50 | 100 |
| geschenkenOpenbaar | true | false |
| integriteitsNotificatieGroep | decidesk-burgemeester | decidesk-compliance |

### Schema: `nevenfunctie` (5 objects)

| Field | 1 | 2 | 3 | 4 | 5 |
|-------|---|---|---|---|---|
| slug | nf-bestuurslid-welzijn | nf-gr-afvalschap-qq | nf-docent-hogeschool | nf-rvc-stedin | nf-adviseur-beeindigd |
| person / governanceBody | raadslid / gemeenteraad | wethouder / gemeenteraad | raadslid / gemeenteraad | Janneke de Bruin / RvC | raadslid / gemeenteraad |
| organisatie + functie | Stichting Welzijn Noord, bestuurslid | GR Afvalschap, AB-lid | Hogeschool, docent bestuurskunde | Stedin, RvC vice-voorzitter | Adviesbureau, adviseur |
| bezoldigd / urenIndicatie / qualitateQua | false / 4 u-wk / false | false / — / **true** | true / 8 u-wk / false | true / — / false | true / — / false |
| lifecycle | openbaar | openbaar | **gemeld** | intern | beëindigd (endDate set) |
| publicatiedatum | past datetime | past datetime | — | — | past datetime |
| reviewedAt | **14 months ago** | current | — | current | — |

Object 3 demonstrates the pending-verification state; object 4 mirrors the existing `Membership.otherPositions` seed string "RvC Stedin (vice-voorzitter)" as a structured object (the supersession story made visible); object 1's stale `reviewedAt` makes the dashboard KPI and the compliance panel non-zero on a fresh install (ADR-016 testability). One seeded council member deliberately has **no** nevenfunctie object, so the compliance panel's "geen opgave geregistreerd" row is demoable.

### Schema: `geschenk` (3 objects)

| Field | 1 | 2 | 3 |
|-------|---|---|---|
| slug | gs-boek-delegatie | gs-diner-projectontwikkelaar | gs-congres-uitnodiging |
| recipient / governanceBody | raadslid / gemeenteraad | raadslid / gemeenteraad | RvC-lid / RvC |
| type / gever | geschenk / "Delegatie gemeente Aken" | uitnodiging / "Projectontwikkelaar BV" | uitnodiging / "Brancheorganisatie Water" |
| omschrijving / geschatteWaarde | Fotoboek stedenband / 20 | Diner nieuwjaarsreceptie / 95 | Congres met overnachting / 400 |
| besluit | aanvaard | **geweigerd** (boven-drempel) | overgedragen |
| publicatiedatum | past datetime (body policy public) | past datetime | — (policy: not public) |

**Related items per object:** none beyond the references above (no Files/Notes seeded; the registers are self-contained records).

## Migration Plan

1. Land register.d fragment 62 + manifest.d fragment + Dashboard widget edit + seed + tests + docs in one decidiq PR (fragments additive; repair step imports the schemas on upgrade).
2. No dependency ordering: `conflict-of-interest` is done; `fractievoorzitter-fractie-koppeling` and `member-onboarding` are planned siblings that reference this change's stable names (`interests-and-integrity`, slug `nevenfunctie`, page `MyDeclarations`) — they align at their own apply time (D2).
3. Existing `Membership.otherPositions` values stay untouched; griffie re-enters structured declarations at the next annual review cycle (communicated in docs).
4. Rollback: revert the PR — schemas/pages de-register; objects remain soft-retained in OR; published declarations withdrawable by setting `depublicatiedatum` via the normal staff flow.

## Risks / Trade-offs

- [Future field addition breaks publishability-by-construction] → D4 constraint recorded in both schema `description`s; adding a non-public property requires revisiting the predicate decision (mirrors toezeggingen precedent).
- [Notification dialect cannot read a per-body recipient group from the policy object] → fallback: one literal integrity group per trigger (e.g. `decidesk-integrity`) with per-body routing handled by group membership; documented, never silent (proposal Risk 3).
- [Compliance panel client-side join misleads on large bodies] → panel is labeled assistive, paginates memberships, and computes per selected body only (two queries, D3); never presented as an authoritative audit.
- [Lifecycle dialect drift (`initial` vs `initialState`)] → fragment uses the canonical dialect verbatim from existing wave fragments; gates 28/30/51/52 run on register+manifest changes; manifest refs use slugs (`nevenfunctie`, `geschenk`, `integriteitsbeleid`), never PascalCase.
- [Dual-role person must declare twice (D5)] → accepted; MyDeclarations shows both with their body context, and the annual rappel covers each object independently.

## Open Questions

- Recipient resolution in `x-openregister-notifications`: literal group per trigger vs. token/lookup from a related policy object — verify against OR during apply (fallback stands).
- Relative-date condition ("older than 12 months") in the scheduled-trigger filter and the KPI widget filter DSL — verify the supported token during apply; fallback: KPI counts `reviewedAt: null` + index quick-filter carries the cutoff (documented, not silent).
- Nil-declarations ("geen nevenfuncties") — deferred per proposal; would need conditional required-field validation.
