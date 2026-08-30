---
kind: code
depends_on: [commissievergaderingen]
---

# Proposal: inspreekrecht-plenair

## Summary

Generalize the citizen speaking-right (inspreekrecht) registration loop — today specced only for commissievergaderingen (REQ-CVG-009/010/011) — to any meeting type whose governance body enables it: plenary raadsvergaderingen, ALVs, and board meetings with public sessions. The change promotes the `InspraakAanmelding` schema to a single canonical, meeting-generic definition in the decidesk register (fragment 64), adds a per-body `InspraakBeleid` policy object (enabled, deadline hours, default spreektijd, per-item vs meeting-level, public display mode), renders approved insprekers as speaking slots on the agenda (anonymised in the public view per policy), preloads the REQ-STM speaker queue with approved insprekers so the chair can mark gesproken/niet-verschenen with status flow-back, attaches the written bijdrage and/or transcript segment (Spreker.rol=inspreker) afterwards, and gives the griffie a cross-meeting moderation overview with deadline warnings.

## Motivation

Inspreken is a statutory participation right (Gemeentewet / Reglement van Orde for councils; statuten for verenigingen), yet the market treats it as an afterthought: GO ships "GO Inspreken" as a standalone bolt-on module disconnected from the meeting itself. Decidiq already specs the full registration loop — public POST, privacy field split, griffier moderation, spreektijd-toewijzing, 24h auto-close, publication of public parts into the verslag — but ONLY for commissievergaderingen, and it is wired to nothing live: `digital-meetings-and-recurrence` REQ-STM has the SpeakingTimePanel and speaker queue but no connection to registrations, and `raadsvergadering-livestream-transcript` has `Spreker.rol=inspreker` for transcripts but no link back to who registered. Novelty verification (2026-07-17) rated this gap PARTIAL: the loop exists but is scoped and unwired. Generalizing one schema and wiring the three existing ends together closes the gap for all five governance domains at marginal cost, and turns inspraak from a form into a traceable end-to-end record: aanmelding → agenda slot → speaker queue → gesproken → bijdrage/transcript.

## Affected Projects

- [ ] Project: `decidiq` — register fragment `lib/Settings/register.d/64-inspreekrecht-plenair.json` (canonical `inspraak-aanmelding` superset + new `inspraak-beleid`), `InspraakService` (registration policy enforcement, moderation, queue preload/write-back), manifest fragment (griffie overview, list/detail pages, agenda slot rendering), coordination amendment to the `commissievergaderingen` change so it adopts the shared schema instead of defining its own copy.

## Scope

### In Scope

Two capabilities (one delta spec each):

- **inspraak-register**: the canonical, meeting-generic `inspraak-aanmelding` schema on the decidesk register (fragment 64) preserving the commissie change's field split (`contactgegevens` internal / `onderwerp` public) and status enum (`aangemeld`/`goedgekeurd`/`afgewezen`/`gesproken`/`niet-verschenen`); declarative lifecycle + post-approval immutability of the citizen-entered field groups; per-body `inspraak-beleid` policy schema (enabled, `aanmeldDeadlineUren`, `standaardSpreektijdMinuten`, `niveau` per-agendapunt|vergadering, `publiekeWeergave`); server-side registration API enforcing policy and deadline auto-close; griffier moderation (approve with spreektijd-toewijzing, reject with reason, refer to another meeting/body); written bijdrage-tekst and transcript-segment linkage afterwards.
- **inspraak-agenda-live**: approved insprekers rendered as speaking slots with time limits on the relevant agenda item (internal view); anonymised public agenda view per policy (count-only or first-name-only); preloading the REQ-STM speaker queue with approved insprekers and flowing the chair's gesproken/niet-verschenen marking back to the aanmelding; griffie cross-meeting overview with pending approvals, deadline warnings, and declarative notifications.

### Out of Scope

- The public portal FORM rendering — `portaliq` / the `portal-contribution` change own the citizen-facing surface (and that change explicitly ships no create surfaces this wave, REQ-DKPORT-006); decidiq exposes the API + moderation, mirroring the commissie change's approach.
- Video capture, livestreaming, and transcription mechanics (`raadsvergadering-livestream-transcript` owns them; this change only references `TranscriptSegment`/`Spreker.rol=inspreker`).
- Spreektijd clock mechanics, queue reordering, and the SpeakingTimePanel itself (REQ-STM owns them; this change only preloads entries and consumes the outcome).
- Commissie-specific inspraak behaviour already specced (REQ-CVG-009/010/011 stay authoritative for the commissie flow; this change generalizes the schema they use).
- Citizen accounts, consultations, panels, and participatory budgets (`p3-citizen-participation` owns the citizen side).

## Approach

Reuse, don't fork: because ADR-037 register fragments merge at whole-schema granularity (a fragment cannot partially overlay a schema, and two fragments defining the same schema is a load-order conflict), the generalized `inspraak-aanmelding` gets exactly one canonical definition — fragment 64 in the decidesk register — and the `commissievergaderingen` change (our declared dependency) is amended to adopt it instead of defining `InspraakAanmelding` inside `commissievergaderingen_register.json`. `CommissieVergadering` inherits from `Meeting` (commissie change D2), so a generic `meeting` reference covers commissies unchanged. Per-body policy follows the sibling `vragenuur-interpellatie` D2 pattern: a small `inspraak-beleid` schema object per governance body, absence meaning inspraak disabled. Behaviour is declarative-first per ADR-031 (lifecycle, RBAC, notifications as OR dialects); imperative code is limited to the deadline comparison, queue preload/write-back, and anonymised public projection. Details in design.md.

## New Dependencies

None. No new packages, libraries, or external services — only OpenRegister dialects and existing decidiq services.

## Impact

- `lib/Settings/register.d/64-inspreekrecht-plenair.json` — new (canonical `inspraak-aanmelding`, new `inspraak-beleid`, seed data).
- `openspec/changes/commissievergaderingen/` — coordination amendment: its register file no longer defines `InspraakAanmelding`; REQ-CVG-009/011 flows target the shared decidesk-register schema.
- `lib/Service/InspraakService.php` (+ controller/routes) — new: registration validation (policy, deadline, override), moderation actions, queue preload, status write-back.
- `src/manifest.d/inspreekrecht-plenair.json` + agenda-item rendering — new pages (griffie overview, aanmelding list/detail) and speaking-slot display on agenda items.
- Public agenda/verslag payloads — anonymised inspreker projection per policy.
- No changes to the REQ-STM SpeakingTimePanel contract, the transcript schemas, or `decidesk_register.json`.

## Cross-Project Dependencies

None outside decidiq. Within decidiq: hard dependency on the `commissievergaderingen` change (we generalize its `InspraakAanmelding`; coordination amendment required); soft references to `digital-meetings-and-recurrence` REQ-STM (queue preload target), `raadsvergadering-livestream-transcript` (`TranscriptSegment`/`Spreker` references, nullable — degrade to absent links if it lands later), `governance-bodies` (per-body policy anchors on `GovernanceBody`), and `portal-contribution`/`portaliq` (consume the registration API later; no contract change here).

## Risks

### Risk 1: Duplicate schema definitions across two unarchived changes
**Severity:** High — **Mitigation:** ADR-037 fragments merge whole schemas, so if both this fragment and `commissievergaderingen_register.json` define the aanmelding schema, load order silently decides. The coordination task amends the commissie change's artifacts before either implements; the depends_on ordering plus an import-time assertion (fragment fails loudly if a same-slug schema already exists in another decidiq-managed register) guard the race.

### Risk 2: Post-approval writes vs the commissie immutability constraint
**Severity:** Medium — **Mitigation:** The commissie change declares `InspraakAanmelding` immutable after `goedgekeurd`, but live wiring must write `gesproken`/`niet-verschenen` and attach bijdrage/transcript afterwards. The generalized schema scopes immutability to the citizen-entered field groups (`contactgegevens`, `onderwerp`) and keeps status transitions + griffie-owned attachment fields writable via RBAC; recorded as an explicit coordination point with the commissie change.

### Risk 3: Deadline enforcement depends on a cross-object comparison
**Severity:** Medium — **Mitigation:** `meeting.start − aanmeldDeadlineUren` cannot be expressed declaratively; it is a justified imperative spot in `InspraakService` (same justification as vragenuur REQ-VRI-003), unit-tested including the griffier override path.

### Risk 4: Privacy leak of contactgegevens in public projections
**Severity:** Medium — **Mitigation:** Public agenda/verslag payloads are allow-list built (never filtered-out), carrying only the policy-selected public projection (`aantal` or `voornaam`); PHPUnit asserts contactgegevens are structurally absent from every public payload.

## Rollback Strategy

Revert the decidiq PR: fragment 64 and the manifest fragment are additive files (delete them and re-run the repair step; existing registers are untouched since no existing schema is modified), `InspraakService` and its routes are new code with no callers outside this change, and the commissie-change coordination amendment is a spec-file edit that reverts with the same commit. Aanmelding objects already created remain in OpenRegister as inert data and can be exported before removal if needed.

## Open Questions

- Should `Commissie` map onto `GovernanceBody` so one `inspraak-beleid` object also governs commissie inspraak, or does the commissie-level `inspraak-deadline-uren` setting stay authoritative for commissies? (Default assumed here: policy per governance body; the commissie change's per-commissie deadline overrides it where present.)
- Does the `x-openregister-notifications` dialect support time-based triggers for deadline warnings, or do warnings stay a computed UI badge on the griffie overview (assumed here) with only created/status-change notifications declarative?
