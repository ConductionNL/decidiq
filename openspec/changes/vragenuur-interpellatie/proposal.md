---
kind: code
depends_on: [fractievoorzitter-fractie-koppeling]
---

# Proposal: vragenuur-interpellatie

## Summary

Add oral questions (mondelinge vragen for the vragenuur) and interpellation requests (interpellatieverzoeken) as first-class registers in decidiq. A `MondelingeVraag` gets an `MV-{jaar}-{volgnummer}` number (mirroring the existing `SV-{jaar}-{volgnummer}` written-question pattern), a chair/griffier admission decision, scheduling into the vragenuur agenda item of a target meeting, spoken-answer recording with follow-up links to the toezeggingen register, and bidirectional escalation linkage with `SchriftelijkeVraag` (a written question answered orally moves to `vervallen-door-mondelinge-beantwoording`). An `Interpellatieverzoek` records subject and questions, support signatures against a per-body configurable threshold (Reglement van Orde, e.g. 1/5 of members), the council's admission decision, scheduling as its own agenda item, and treatment recording. Lifecycles and notifications are declarative (ADR-031); questions and answers publish via the existing public-publication machinery.

## Motivation

The vragenrecht and interpellatierecht (Gemeentewet art. 155, elaborated per municipality in the Reglement van Orde) are core democratic control instruments, and they are among the highest-demand capabilities in the market-gap analysis (q-a-management 928, question-management 740). Decidiq currently covers only half of the questions domain: `SchriftelijkeVraag` (written questions, planned in `fractievoorzitter-fractie-koppeling`) and per-fractie question-time minutes (`vrageUrentijdMinuten` on Fractie). The `motie-amendement-administratie` change explicitly defers interpellatie to its own spec (proposal.md line 90: "Interpellatie, motie van wantrouwen, raadsvoorstel-indiening door raadslid — andere specs"), and no change models mondelinge vragen at all — novelty verified 2026-07-17. Without this change, griffies track vragenuur submissions and interpellation support in e-mail and spreadsheets, the SV status `vervallen-door-mondelinge-beantwoording` has no object that can trigger it, and the public has no register of oral questions and their answers.

## Affected Projects

- [ ] Project: `decidiq` — new `MondelingeVraag`, `Interpellatieverzoek`, and `VragenuurConfiguratie` schemas via register fragment `lib/Settings/register.d/49-vragenuur-interpellatie.json`; manifest fragment with list/detail pages; thin submission/escalation service; publication eligibility + payload extension; seed data.

## Scope

### In Scope

Capabilities (one delta spec each):

- **mondelinge-vragen-register** (new): `MondelingeVraag` schema (`MV-{jaar}-{volgnummer}`, indiener raadslid + fractie, onderwerp, portefeuillehouder, motivering), per-body `VragenuurConfiguratie` (submission deadline in hours before the vragenuur, interpellation support threshold), declarative lifecycle with chair/griffier admission (`toegelaten`/`afgewezen` with mandatory reason), scheduling into the vragenuur agenda item of the target meeting, spoken-answer recording (summary + optional follow-up toezegging / follow-up written question), escalation linkage from/to `SchriftelijkeVraag`, declarative notifications, list/detail pages.
- **interpellatie-register** (new): `Interpellatieverzoek` schema (onderwerp + questions, verzoeker, support recording against the per-body threshold), council admission decision (`toegelaten` → scheduled as its own agenda item; `afgewezen`), treatment recording, declarative notifications, list/detail pages.
- **public-publication** (delta, ADDED-only): publication of admitted/answered oral questions and admitted/treated interpellation requests via the existing derived-payload machinery.

### Out of Scope

- **Motie van wantrouwen** — separate instrument, also explicitly deferred by `motie-amendement-administratie`.
- **The vragenuur speaking-time clock** — live speaking time is owned by `digital-meetings-and-recurrence` REQ-STM; this change only references the existing per-fractie `vrageUrentijdMinuten` allocation.
- **Answering workflow for written questions** — stays in `fractievoorzitter-fractie-koppeling`; this change only sets the already-defined SV status `vervallen-door-mondelinge-beantwoording` on oral answering.
- Creation/management of the vragenuur agenda item itself (ordinary `agenda-item-crud`; this change only references it).

## Approach

Pure thin-client extension (ADR-022/ADR-037): three schemas in one additive `register.d` fragment (number 49, assigned), lifecycles via the canonical `x-openregister-lifecycle` dialect, notifications via `x-openregister-notifications`, authorization via OR RBAC (raadsleden create, griffie/chair transition), UI as manifest-v2 pages in a `src/manifest.d/` fragment. Imperative code is limited to what dialects cannot express: MV/INT number generation, deadline validation relative to the target meeting, the cross-object SV side effect on oral answering, and the publication eligibility/payload extension. Details in design.md.

## New Dependencies

None. No new packages, libraries, or external services.

## Impact

- New: `lib/Settings/register.d/49-vragenuur-interpellatie.json`, `src/manifest.d/vragenuur-interpellatie.json`, `lib/Service/OralQuestionService.php`, e2e tests, docs.
- Edited: `lib/Service/PublicationEligibilityService.php`, `lib/Service/PublicationPayloadService.php`, `lib/Controller/PublicationController.php` (payload type routing), `appinfo/routes.php` (submission/answer action routes).
- No existing schema in `decidesk_register.json` is modified; the SV status value this change sets already exists in the `SchriftelijkeVraag` enum defined by `fractievoorzitter-fractie-koppeling`.

## Cross-Project Dependencies

- `fractievoorzitter-fractie-koppeling` (decidiq change, declared in `depends_on`): provides `SchriftelijkeVraag` (including the `vervallen-door-mondelinge-beantwoording` status and `SV-{jaar}-{volgnummer}` numbering this change mirrors), `Fractie` (indiener's fractie reference, `vrageUrentijdMinuten`), and `Raadslid`. The escalation linkage reads and writes SchriftelijkeVraag objects, so that change must land first or concurrently.
- `toezeggingen-ingekomen-stukken` (decidiq change, soft): `vervolgToezegging` references the `Toezegging` schema; the field is nullable and degrades to a plain link if that change is delayed.
- `motie-amendement-administratie` (decidiq change, soft): style alignment only (numbering, lifecycle conventions); no schema reference.
- OpenRegister: consumed via existing dialects and ObjectService only; no OR changes required.

## Risks

### Risk 1: Concurrent unarchived changes touching the same publication eligibility text

**Severity:** Medium — **Mitigation:** `toezeggingen-ingekomen-stukken` MODIFIES the "Publication eligibility gates" requirement; this change deliberately uses an ADDED-only requirement in its public-publication delta so archiving order cannot drop either change's edits (union-merge of two MODIFIEDs on the same block is a known data-loss mode).

### Risk 2: Deadline enforcement depends on a cross-object comparison

**Severity:** Medium — **Mitigation:** the submission deadline is `meeting.start − VragenuurConfiguratie.indieningstermijnUren`, which no declarative dialect can evaluate. It is enforced server-side in the thin submission action (with an explicit griffier override flag) and mirrored in the UI; the ADR-031 decision table in design.md records this as a justified imperative spot.

### Risk 3: SchriftelijkeVraag side effect writes to a sibling change's schema

**Severity:** Medium — **Mitigation:** the side effect only sets an enum value that schema already declares; the write carries all fields forward (PUT-semantic saveObject) and is covered by a unit test asserting an unrelated SV field survives the transition.

### Risk 4: Lifecycle dialect drift

**Severity:** Low — **Mitigation:** both lifecycles use the canonical `initial` keyword verbatim (never `initialState`/`states`-only/`default`); gates 28/30/51/52 run on register+manifest changes; manifest refs use slugs only.

## Rollback Strategy

Revert the PR: the register fragment and manifest fragment are additive, so schemas unregister and pages disappear; publication payload types refuse again. Existing MV/INT objects remain soft-retained in OpenRegister. Any SchriftelijkeVraag already set to `vervallen-door-mondelinge-beantwoording` keeps that status (it is a valid value of that schema independent of this change); no data migration to undo.

## Open Questions

- Default interpellation support threshold when a body has no `VragenuurConfiguratie` yet: proposal is "no threshold displayed, admission decision still recordable" — confirm with griffie stakeholders during apply.
- Whether the vragenuur agenda item should be auto-tagged `vragenuur` by convention or left free-form; provisional: free-form reference picked by the griffier when scheduling.
