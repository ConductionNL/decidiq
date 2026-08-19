# delegatie-mandaatregister Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- delegatie-mandaatregister
- register-detail-optimisation

## Purpose

A `bevoegdheidstoedeling` (delegation/mandate) can sit inside an ondermandaat chain — a mandate re-delegated one or more levels down via `parentAllocation` — and the reader needs to see that chain, not just the single record, to answer "who ultimately authorised this, and who else was authorised under the same original grant." This spec adds a chain widget to `BevoegdheidstoedelingDetail` on top of the page skeleton the `delegatie-mandaatregister` change already shipped.

## ADDED Requirements

### Requirement: REQ-DMR-008 Ondermandaat chain widget on BevoegdheidstoedelingDetail

The `BevoegdheidstoedelingDetail` page MUST render a `delegation-chain` widget showing: the ancestor chain walked up via `parentAllocation` (the current toedeling's parent, grandparent, and so on to the root grant) as a breadcrumb, and the direct-child ondermandaten walked down (toedelingen whose `parentAllocation` points at the current object) as a list — plus the source `decision` link and resolved delegans/delegataris display. The chain walk MUST terminate safely on a cycle (defensive — the underlying data model forbids cycles, but the widget MUST NOT infinite-loop if one is ever present) and MUST NOT re-fetch the same object twice within one render.

#### Scenario: Third-level ondermandaat shows its full ancestor breadcrumb
- GIVEN a root `bevoegdheidstoedeling` A, a level-2 toedeling B with `parentAllocation` = A, and a level-3 toedeling C with `parentAllocation` = B
- WHEN the user opens `BevoegdheidstoedelingDetail` for C
- THEN the chain widget's breadcrumb shows A → B → C in that order

#### Scenario: A toedeling with sub-mandates lists them
- GIVEN a `bevoegdheidstoedeling` with two other toedelingen whose `parentAllocation` points at it
- WHEN the user opens its detail page
- THEN the chain widget lists both child ondermandaten with links to their own detail pages

#### Scenario: A root toedeling with no parent and no children renders a minimal chain
- GIVEN a `bevoegdheidstoedeling` with no `parentAllocation` and no toedelingen referencing it as parent
- WHEN the user opens its detail page
- THEN the chain widget renders just that toedeling with no ancestor breadcrumb and no child list, not an error or an empty grid gap

#### Scenario: The chain widget never infinite-loops on malformed data
- GIVEN a defensive-only scenario where `parentAllocation` references form a cycle (never producible via normal application flows)
- WHEN the chain widget resolves the ancestor walk
- THEN it MUST terminate after at most as many steps as there are ondermandaat objects in the register, rendering the partial chain rather than hanging

## Non-Functional Requirements

- **Performance:** The chain widget MUST NOT re-fetch an already-resolved ancestor/child object within the same render pass.
- **Accessibility:** The breadcrumb and child-list links MUST be keyboard-navigable with correct accessible names (WCAG 2.2 AA, ADR-010).
- **Internationalization:** Dutch and English MUST be supported (ADR-005/025).

## Acceptance Criteria

- [ ] `BevoegdheidstoedelingDetail` renders the ondermandaat ancestor breadcrumb and child list via the `delegation-chain` widget
- [ ] The source `decision` and resolved delegans/delegataris render alongside the chain
- [ ] The chain walk terminates safely on a defensive cycle check

## Notes

Builds on `lib/Settings/register.d/54-delegatie-mandaatregister.json` and `src/manifest.d/delegatie-mandaatregister.json` already shipped by the `delegatie-mandaatregister` change. The "geldig op" in-force-on-date filter and CSV export remain that change's own follow-up work (its Task 4). The index's "no table, no empty state on zero rows" defect is explicitly OUT of this spec's scope — see `register-detail-optimisation`'s proposal.md Open Questions; it was investigated and traced to shared `CnIndexPage` / object-store loading-state behaviour, not to a page-declaration defect this spec could fix.
