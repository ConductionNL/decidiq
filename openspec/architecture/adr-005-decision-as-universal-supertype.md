# ADR-005: Decision as the Universal Supertype

**Status:** accepted
**Date:** 2026-06-14
**Supersedes:** ADR-001 design choice #1 ("No separate Decision entity")

## Context

Decidiq is a universal decision-making platform (ADR-004, FEATURES.md). Its
whole wedge is that *every* kind of governance outcome — a council motion, a
corporate resolution, a procurement contract award, an appointment, a management
team go/no-go, a meeting outcome — is the same kind of thing: **something that
had to be decided, by someone, somehow.**

The data model did not reflect this. It grew three *sibling* entities for what
are really one concept:

- `motion` (parliamentary proposal)
- `resolution` (corporate board output — added by the board-portal overlay)
- `decision` (generic outcome)

…plus generic schema.org types (`offer`, `order`, `product`, `report`) for the
procurement/contract world that were never woven into the decision model at all.

This produced the vocabulary confusion ADR-004 was meant to prevent: a user sees
"Motions" *and* "Resolutions" *and* "Decisions" in the same app and cannot tell
which to use. Worse, ADR-001 design choice #1 explicitly states *"No separate
Decision entity. A decision is the outcome of a Motion"* — which directly
contradicts both the shipped `decision` schema and the product's universal
framing. The contradiction has sat latent; it must be resolved.

## Decision

**`Decision` is the single universal supertype for everything that gets decided.**
Motion, resolution, contract, appointment, management point, policy and
meeting-outcome are not separate entities — they are **values of a `decisionType`
discriminator** on the one `Decision` entity.

### The discriminator

`Decision.decisionType` (enum, required):

| Value | Domain | Replaces |
|---|---|---|
| `motion` | legislative / parliamentary | `motion` schema |
| `amendment` | legislative (amends a motion) | `amendment` schema |
| `resolution` | corporate governance / association | `resolution` schema |
| `contract` | corporate operations / procurement | (new — uses `offer`/`order` as attachments) |
| `appointment` | all (person → post) | (new) |
| `management-point` | corporate operations | (new) |
| `policy` | all | (new) |
| `meeting-outcome` | all (generic) | generic `decision` |

Type-specific fields render only when the relevant `decisionType` is selected
(progressive disclosure per ADR-004 Rule 2). The register list, search and
detail page are one surface filtered by type; "Moties" in the nav is the
Decisions register pre-filtered to `decisionType=motion`, not a separate store.

### What a Decision carries

- **Identity & substance:** title, subject/dossier, text, legalBasis,
  decisionType.
- **Lifecycle status:** the decision's own workflow state (draft → submitted →
  in-progress → decided → published / withdrawn).
- **Effective status (derived):** layered on top of lifecycle via typed
  relations (`supersedes` / `amends` / `repeals` / `implements` / `refersTo`)
  so the register can answer "is this still in force?" — owned by the
  `decision-relations` capability.
- **Route & stages (future, ADR-006 + Cycle 2):** the ordered path the decision
  travels across decision-makers, each stage resolved by a decision *method*.
- **Attachments & follow-up:** DigitalDocument relations, CalDAV VTODO action
  items.

### Popolo / ORI compatibility is preserved

ADR-001 (Popolo) and ADR-003 (ORI endpoint) are **not** weakened. Popolo's
`Motion` class is an *output serialization*, not a storage requirement. The ORI
endpoint `/api/ori/v1/motions` serializes `Decision` objects where
`decisionType=motion` as Popolo/ORI Motions; `/api/ori/v1/...` for other types
maps accordingly. Storage is unified; standards mapping stays a thin projection
exactly as ADR-001 §"Consequences" intended.

### Why this reverses ADR-001 design choice #1

ADR-001 reasoned "Popolo has no Decision class, so don't add one." That logic
optimised for Popolo fidelity at the cost of the product's own universal model.
We now optimise the other way: the **storage model follows the product concept
(universal Decision)**, and Popolo fidelity is recovered at the serialization
boundary (where ADR-001 already puts ORI). The narrower "decision = motion
outcome" framing cannot represent a corporate resolution, a signed contract, or
an ambtelijk→politiek hand-off, all of which are first-class product scope.

## Consequences

- **`motion`, `amendment`, `resolution` schemas are retired**; their objects
  migrate to `decision` with the matching `decisionType`. (Cycle 1, change
  `unify-decision-supertype`.)
- **The nav stops showing parallel decision vocabularies.** "Besluiten" is the
  register; "Moties" (if kept) is a typed filter, not a sibling store
  (ADR-004 Rule 3).
- **Procurement/contract decisions get a home.** `offer`/`order`/`product`
  become *attachments* to a `decisionType=contract` Decision rather than
  orphaned schema.org types.
- **ORI/Popolo output is unaffected** for consumers — same endpoints, same
  shapes, now sourced from the unified entity.
- **A migration is required** for existing motion/amendment/resolution objects;
  the shipped data is seeded demo data, so migration is re-seed-friendly.
- **Cycle 2 builds on this:** decision route/stages and pluggable decision
  methods attach to the unified Decision (changes `decision-route-and-stages`,
  `decision-methods`).
