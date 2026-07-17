# Design: inspreekrecht-plenair

## Context

Three disjoint pieces of the inspraak story already exist in decidesk specs: the `commissievergaderingen` change defines the full registration loop (`InspraakAanmelding` with contactgegevens/onderwerp field split, griffier moderation, spreektijd-toewijzing, 24h auto-close, verslag publication — REQ-CVG-009/011) but scoped to commissievergaderingen only; `digital-meetings-and-recurrence` REQ-STM owns the live SpeakingTimePanel with a speaker queue that knows nothing about registrations; and `raadsvergadering-livestream-transcript` models `Spreker.rol=inspreker` for transcript attribution with no link back to who registered. This change generalizes the first and wires all three together. Decidesk is a thin client (no own tables); all data lives in OpenRegister, behaviour is declarative-first (ADR-031), register changes ship as additive fragments (ADR-037), and the citizen-facing form surface belongs to portaliq/`portal-contribution` (which ships no create surfaces this wave, REQ-DKPORT-006 — decidesk exposes the API it will consume later).

## Goals / Non-Goals

**Goals:** one canonical meeting-generic `inspraak-aanmelding` schema; per-body opt-in policy; policy-enforcing registration API + griffier moderation; agenda speaking slots (internal) with policy-driven anonymised public projection; REQ-STM queue preload + outcome write-back; post-meeting bijdrage/transcript linkage; griffie cross-meeting overview.

**Non-Goals:** the public portal form (portaliq), video/transcription mechanics (livestream change), spreektijd clock/queue mechanics (REQ-STM), changing commissie-specific inspraak behaviour (REQ-CVG-009/010/011 stay authoritative for that flow), citizen consultations/panels (`p3-citizen-participation`).

## Decisions

### D1: One canonical schema in fragment 64; the commissie change adopts it (extend, don't fork)

ADR-037 register fragments merge at **whole-schema** granularity: a fragment cannot partially overlay another definition, and two sources defining the same schema is a silent load-order conflict. So "extend the commissie change's InspraakAanmelding" can only mean one canonical superset definition. That definition lives in `lib/Settings/register.d/64-inspreekrecht-plenair.json` (decidesk register, slug `inspraak-aanmelding`): the REQ-CVG-009 field split and status enum unchanged, `vergadering` widened to a generic `meeting` reference (sound because commissie D2 makes `CommissieVergadering` inherit from `Meeting`), plus `agendaItem`, `governanceBody`, `volgorde`, `bijdrageTekst`, `transcriptSegment`. The coordination edit to the (also unimplemented) `commissievergaderingen` change removes InspraakAanmelding from its 8-schema register file and points its REQ-CVG-009/011 flows at the shared schema; `depends_on: [commissievergaderingen]` orders the landings, and the fragment import asserts loudly if a same-slug schema is already defined elsewhere.

**Alternatives considered:** (a) extend the schema in place inside `commissievergaderingen_register.json` — rejected: plenary/ALV registrations would live in the commissie register, and every non-commissie consumer would cross registers for its core object; (b) a second, parallel schema for plenary inspraak — rejected: that is the fork the brief forbids, splits the griffie overview, and duplicates the privacy model.

### D2: Per-body policy as an `inspraak-beleid` schema object, not properties on GovernanceBody

Mirrors `vragenuur-interpellatie` D2: a small per-body settings object (`inspraakMogelijk`, `aanmeldDeadlineUren`, `standaardSpreektijdMinuten`, `niveau`, `publiekeWeergave`) keeps the change ADDED-only — no MODIFIED requirement on `governance-bodies`, no schema edit outside fragment 64, no race with sibling changes touching GovernanceBody. Absence of the object means inspraak disabled: the safe default for the three governance domains where public speaking rights are the exception, and it makes enablement an explicit act (Reglement van Orde / statuten decision).

**Alternatives considered:** properties on GovernanceBody (rejected per above); instance-wide admin setting (rejected — inspraak is a per-body legal arrangement, not an installation toggle).

### D3: Declarative-vs-imperative decision (ADR-031)

Default declarative; imperative only where a dialect cannot express the behaviour:

| Behaviour | Mechanism | Why |
|---|---|---|
| Status lifecycle (`aangemeld → goedgekeurd\|afgewezen`, `goedgekeurd → gesproken\|niet-verschenen`) | `x-openregister-lifecycle` (canonical `initial` keyword — never `initialState`/`states`-only/`default`, the silently-ignored drift dialect) | Pure guarded state machine; zero app code |
| Post-approval immutability of `contactgegevens`/`onderwerp`; griffie/chair write authority on status + attachment fields | OR RBAC `authorization` on the schema | Declarative authorization; no app-side role checks |
| Registration confirmations, moderation-decision and referral notifications (nl/en, griffie + registrant) | `x-openregister-notifications` `created`/`updated` triggers | ADR-031 default; gate-18 hard-fails imperative dispatch |
| Deadline **warnings** on the griffie overview | Client-side computed badge from `meeting.start − aanmeldDeadlineUren` | Informative display, never a gate; the notifications dialect has no time-based trigger (open question) |
| Speaking-slot rendering, pending markers, overview filters | Manifest pages/widgets over register queries | Pure projection of object state |
| Deadline auto-close + policy validation (enabled, niveau, per-item ref) + griffier override | **Imperative** — `InspraakService` registration action, server-side | Cross-object datetime comparison against the meeting; no dialect evaluates a related object's field (same justification as vragenuur REQ-VRI-003) |
| Queue preload of approved insprekers when an item opens | **Imperative** — service feeding REQ-STM's queue | Cross-capability side effect into the live session, not object state |
| `gesproken`/`niet-verschenen` write-back from the SpeakingTimePanel | **Imperative** — PUT-semantic `saveObject()` carrying **all** fields forward | Cross-object side effect triggered by a UI action in another capability's component context |
| Anonymised public projection (`aantal`/`voornaam`/`spreker-naam`) | **Imperative** — allow-list payload builder | Allow-list payload construction is by design imperative in decidesk (existing publication pattern); a filter-out approach risks leaking contactgegevens |

### D4: Live wiring is a preload + write-back bridge, never a merged model

REQ-STM keeps sole ownership of the SpeakingTimePanel, queue mechanics, and clock. This change contributes only (a) initial queue entries derived from `goedgekeurd` aanmeldingen (display name, inspreker label, time limit, order) when the agenda item becomes current in an opened meeting, and (b) an outcome action on those entries that transitions the linked aanmelding. Removing a preloaded entry from the queue does not touch the aanmelding (the chair may re-add; the record of approval stands until explicitly marked). No queue state is persisted on the aanmelding beyond the terminal outcome.

**Alternative considered:** modelling the queue itself as aanmelding objects — rejected: ad-hoc speakers (raadsleden) are not registrations, and it would force REQ-STM to depend on this change instead of the reverse.

### D5: Contribution stored once, on the aanmelding

`bijdrageTekst` and `transcriptSegment` live on the aanmelding; the agenda item surfaces them via relation. One record per inspreker from registration through contribution — no duplication onto AgendaItem, mirroring the toezeggingen/vragenuur "one accountability record, cross-referenced" discipline. Both fields are nullable references so the change degrades gracefully when the livestream/transcript change is absent.

### D6: Public surface stays with the portal; decidesk ships API + moderation

Mirrors the commissie change and respects `portal-contribution` REQ-DKPORT-006 (no citizen create surfaces this wave, parent-relation problem): decidesk exposes the governed registration endpoint and all moderation/overview UI; portaliq renders the citizen form against that endpoint in its own change. Until then the endpoint is exercised by the griffier-assisted registration dialog and tests.

## Nextcloud Integration

- Controllers: a thin `InspraakController` for the registration + moderation + queue-outcome actions with explicit auth posture per method (`#[PublicPage]` only on the registration endpoint if portal-unauthenticated access is required — final posture decided with the portal team; everything else `#[NoAdminRequired]` + per-object guards; no-admin-idor/semantic-auth gates).
- Services: `InspraakService` (policy validation, deadline + override, referral re-targeting, queue preload feed, PUT-semantic status write-back); publication payload builder extended with the inspreker projections.
- Mappers/Entities: none — no app tables (thin client).
- Events/Hooks: none new — notifications and lifecycle are OR-side declarative.
- Frontend: manifest pages (`src/manifest.d/inspreekrecht-plenair.json`) for the griffie overview and aanmelding list/detail; agenda-item detail gains the insprekers block; dialogs in `src/dialogs`/`src/modals` (modal-isolation gate); SpeakingTimePanel integration via its existing extension surface only.

## Security Considerations

- `contactgegevens` never leave the internal scope: RBAC restricts reads to griffie; public payloads are allow-list built and PHPUnit-asserted to structurally lack the group (AVG; same discipline as vote publication's "totals, never voters").
- The public registration endpoint is rate-limit-friendly (brute-force protection annotation) and validates against policy server-side; no client-supplied spreektijd or status is trusted.
- Post-approval immutability of citizen field groups prevents after-the-fact identity swaps on an approved slot.
- Referral and override actions are audit-trailed on the object.

## File Structure

```
lib/Settings/register.d/64-inspreekrecht-plenair.json   # inspraak-aanmelding (canonical) + inspraak-beleid + seed
lib/Service/InspraakService.php                         # policy/deadline/override, moderation, preload, write-back
lib/Controller/InspraakController.php                   # registration + moderation + outcome endpoints
appinfo/routes.php                                      # new routes
src/manifest.d/inspreekrecht-plenair.json               # overview, list/detail pages, menu
src/…                                                   # agenda insprekers block, dialogs, SpeakingTimePanel bridge
tests/Unit/Service/…, tests/e2e/…                       # per ADR-009 / gate-19
openspec/changes/commissievergaderingen/…               # coordination amendment (D1)
```

## Seed Data

Realistic Dutch municipal + association examples (fictional "Gemeente Voorbeeldingen" and "VvE De Linde"), planted via the fragment's `x-openregister.seedData` path (ADR-016). References use existing decidesk seed objects (seeded gemeenteraad governance body, seeded raadsvergadering and an agenda item) or the nil UUID `00000000-0000-0000-0000-000000000000` as an obvious placeholder resolved at import. Envelope per object: register `decidesk`, schema slug as listed, slug as listed.

### Schema: `inspraak-beleid`

| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | inspraak-beleid-gemeenteraad | inspraak-beleid-vve-alv |
| governanceBody | (seed gemeenteraad body ref) | (VvE body, nil-UUID placeholder) |
| inspraakMogelijk | true | true |
| aanmeldDeadlineUren | 24 | 48 |
| standaardSpreektijdMinuten | 5 | 3 |
| niveau | per-agendapunt | vergadering |
| publiekeWeergave | voornaam | aantal |

### Schema: `inspraak-aanmelding`

| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | inspraak-herinrichting-dorpsplein | inspraak-kap-eikenlaan | inspraak-verkeersbesluit-centrum | inspraak-alv-servicekosten |
| onderwerp.sprekerNaam | "Mw. Jansen" | "Dhr. De Boer (namens Bewonerscomité)" | "A. Visser" | "Mw. Pietersen" |
| onderwerp.organisatie | — | "Bewonerscomité Eikenlaan" | — | — |
| onderwerp.onderwerpTekst | "Herinrichting dorpsplein" | "Voorgenomen kap 12 eiken" | "Verkeersbesluit centrum" | "Verhoging servicekosten" |
| onderwerp.spreektijdAanvraagMinuten | 5 | 5 | 5 | 3 |
| contactgegevens | (naam/email/telefoon/adres, fictional) | idem | idem | idem |
| meeting / agendaItem | (seed raadsvergadering + item refs) | (seed raadsvergadering + item refs) | (seed raadsvergadering + item refs) | (VvE ALV meeting, nil-UUID; no item) |
| governanceBody | (seed gemeenteraad ref) | (seed gemeenteraad ref) | (seed gemeenteraad ref) | (VvE body, nil-UUID) |
| status | gesproken | goedgekeurd | aangemeld | afgewezen |
| spreektijdToegewezenMinuten / volgorde | 5 / 1 | 4 / 2 | — / — | — / — |
| afwijzingsReden | — | — | — | "Onderwerp staat niet op de agenda van deze vergadering" |
| bijdrageTekst | "Namens de buurt vraag ik aandacht voor…" (rich text) | — | — | — |
| transcriptSegment | (TranscriptSegment, nil-UUID placeholder) | — | — | — |

Object 1 exercises the full loop (approved → queue → gesproken → bijdrage + transcript link); object 2 renders as an upcoming speaking slot and makes queue preload demoable; object 3 keeps the moderation actions demoable on the overview; object 4 shows a stored rejection reason. The two beleid objects demo both niveau and both anonymisation modes on install (ADR-016 testability).

**Related items per object:** Files: the written bijdrage PDF on object 1 via the Files leaf. Notes/Tasks/Contacts: none (contact data lives in the contactgegevens group by design, not as NC Contacts).

## Migration Plan

1. `commissievergaderingen` lands first or its artifacts are amended concurrently (hard `depends_on` — D1 coordination removes its embedded InspraakAanmelding before either register imports).
2. Land fragment 64 + `InspraakService`/controller + manifest fragment + agenda/queue wiring + seed + tests + docs in one decidesk PR; `ConfigurationService::importFromApp()` via the existing repair step picks the schemas up on upgrade (fragments are additive).
3. `raadsvergadering-livestream-transcript` ordering is soft: `transcriptSegment` is nullable and the linkage degrades to absent.
4. Rollback: revert the PR; fragment and manifest files are additive, service/routes have no external callers, existing objects remain inert (proposal's rollback strategy).

## Risks / Trade-offs

- [Duplicate schema across two unarchived changes] → coordination task ordered by depends_on + loud import-time assertion on a same-slug schema (D1).
- [Post-approval writes vs REQ-CVG-009 immutability] → immutability scoped to citizen field groups; explicitly coordinated in the commissie amendment (D1/REQ-INS-002).
- [Queue preload racing manual queue edits] → preload only on item-becoming-current; chair edits always win; removal never mutates the aanmelding (D4).
- [Deadline warning is client-computed, not pushed] → accepted for this wave; time-based declarative triggers tracked as an open question.
- [Public endpoint abuse] → rate limiting + server-side policy validation; no object created outside policy windows.

## Open Questions

- Commissie ↔ GovernanceBody mapping: does one `inspraak-beleid` object govern commissie inspraak too, or does the commissie-level `inspraak-deadline-uren` stay authoritative for commissies? (Assumed: per-body policy, commissie field overrides where present — to be settled in the D1 coordination.)
- Does `x-openregister-notifications` support time-based triggers (deadline approaching)? Assumed no; warnings stay a computed overview badge.
- Final auth posture of the registration endpoint (`#[PublicPage]` now vs portal-authenticated later) — decided with the portaliq team when the form ships.
