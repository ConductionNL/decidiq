---
kind: config
---

# Proposal: consultation-discriminator

## Summary

Decidesk carries three sibling "consultation" OpenRegister schemas — `PublicConsultation` (already a discriminated supertype covering citizen-participation, market-consultation, tender, idea-box, participatory-budget via `consultationType`), `MemberConsultation` + `MemberConsultationResponse` (internal, non-binding achterbanraadpleging), and `ConsultationRequest` (the formal WOR art. 25/27 traject). ADR-006 requires one schema per concept, with parallel schemas allowed only when an ADR amendment demonstrates the concept is genuinely distinct. This change performs that evaluation with measured evidence (field-name overlap, authorization model, schema.org type, structural cross-references, lifecycle shape) and records the outcome as an ADR-006 addendum: **`PublicConsultation` remains the sole discriminated concept for the public/market-consultation family (already correct, no change needed there); `MemberConsultation` and `ConsultationRequest` are each independently exempted as genuinely distinct concepts, not relabellings.** The three schemas' descriptions are cross-referenced to the addendum and the `citizen-participation` capability spec gets an ADDED requirement recording the boundary, so a future spec-review does not need to re-litigate this without new evidence.

## Motivation

"Consultation" is used informally for all three schemas across the app's UI (three separate `DecisionDetail` widgets: `decision-public-consultations`, `decision-member-consultations`, `decision-wor-consultations`; three separate nav-nested index pages under the Decisions cluster). That naming proximity, plus ADR-006's "one schema per concept" mandate (written after the board-portal parallel-entity incident), creates a standing risk that a future change proposes folding these into one schema on vocabulary similarity alone — which would be the exact anti-pattern ADR-006 exists to prevent, just approached from the opposite direction (merging genuinely distinct concepts instead of duplicating one concept). This change closes that risk once, with the measurement done, rather than leaving it as an implicit assumption three different engineers might re-derive (or get wrong) independently.

## Affected Projects

- [ ] Project: `decidesk` — ADR-006 addendum (architecture doc), description/version-only edits to three existing OpenRegister schema definitions, one ADDED requirement on the `citizen-participation` capability spec. No PHP, Vue, or route changes.

## Scope

### In Scope

1. **Measure field-name overlap** between `PublicConsultation` (28 properties), `MemberConsultation` (15 properties), and `ConsultationRequest` (20 properties) — pairwise exact-name intersection, expressed as a share of each schema's own field count.
2. **Evaluate qualitative distinctness** beyond field names: `authorization` block presence (public-group anonymous read vs. none), `x-schema-org` type, lifecycle shape (state count, terminal states, derived fields), and the live structural cross-reference `ConsultationRequest.constituencyConsultation → MemberConsultation`.
3. **Record the decision as an ADR-006 addendum** in `openspec/architecture/adr-006-mode-adaptation-over-parallel-entities.md` — the mechanism ADR-006 itself names as the only valid path to a distinctness exemption.
4. **Cross-reference the addendum** from each of the three schemas' `description` fields (patch version bump only — no field, lifecycle, or authorization changes).
5. **Add one ADDED requirement** to `openspec/specs/citizen-participation/spec.md` stating the discriminator boundary and pointing at the addendum, so the canonical spec carries the decision alongside the ADR.
6. **Verify** the existing three widgets / three index pages need no change: confirm none reference a schema slug that would be retired, and that all three already nest correctly under the Decisions nav cluster (`src/menu-layout.json`) — a verification task, not a UI change.

### Out of Scope

- Actually folding any schema, adding new `consultationType` enum values, or any field/lifecycle/authorization changes to `PublicConsultation`, `MemberConsultation`, `MemberConsultationResponse`, or `ConsultationRequest`.
- Modifying the `works-council-consultation` or `constituency-consultation` OpenSpec changes themselves (still open/unarchived — owned by their own change lifecycle; this change only reads their schemas and adds a one-sentence description cross-reference plus a patch version bump to the two files they authored).
- Any UI, route, or manifest change — the existing three-widget / three-page separation is confirmed correct, not altered.
- Data migration — nothing is folded, no slug is retired, no field changes shape.

## Approach

Pure documentation + schema-description change (`kind: config`). No PHP, Vue, migration class, or route work. Details in design.md.

## New Dependencies

None.

## Impact

- `openspec/architecture/adr-006-mode-adaptation-over-parallel-entities.md` — new addendum section.
- `lib/Settings/decidesk_register.json` (`PublicConsultation`) — description + patch version bump only.
- `lib/Settings/register.d/47-works-council-consultation.json` (`ConsultationRequest`) — description + patch version bump only.
- `lib/Settings/register.d/48-constituency-consultation.json` (`MemberConsultation`) — description + patch version bump only.
- `openspec/specs/citizen-participation/spec.md` — one ADDED requirement.

## Cross-Project Dependencies

None. Self-contained within decidesk.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `citizen-participation`: ADDED requirement recording the ADR-006 discriminator-boundary decision for the consultation family (no change to existing requirements or PublicConsultation behavior).

## Risks

### Risk 1: Shared-file edits collide with two open sibling changes

**Severity:** Medium — **Mitigation:** `register.d/47-works-council-consultation.json` and `register.d/48-constituency-consultation.json` are the schema files the still-open `works-council-consultation` and `constituency-consultation` OpenSpec changes authored. This change touches only the `description` string and the semver `version` field on each — a two-line, non-overlapping diff against any field/lifecycle/notification work those changes might still land. Apply this change's edits last, after checking `git diff` against those two files immediately before committing, and re-apply the description edit if either sibling change has since bumped the same version field.

### Risk 2: Evidence-based conclusion diverges from the framing that invited this evaluation

**Severity:** Low — **Mitigation:** The evaluation was invited as "fold `member-consultation`, keep `consultation-request` distinct" (option b) but the measured evidence does not support an asymmetric split — both schemas show comparably low field overlap with `PublicConsultation` (13% and 10% of their own fields respectively) and an identical qualitative gap (no public-authorization block, distinct schema.org type, distinct lifecycle shape). design.md documents the full evidence table; this is flagged as a deferred question for explicit human confirmation rather than silently substituting the evaluator's own conclusion for the invited framing.

## Rollback Strategy

Revert the four-file diff (`git revert`). No data migration, no schema shape change, no deployed behavior change — description and semver-patch edits only, safe to revert at any time with no user-visible effect.

## Open Questions

- Should the ADR-006 addendum also be cross-referenced from the still-open `works-council-consultation` and `constituency-consultation` change design docs, or is the architecture-level addendum sufficient on its own once those changes archive? Deferred — see DEFERRED_QUESTIONS.
