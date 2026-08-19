# delegatie-mandaatregister Specification

## Purpose
A `bevoegdheidstoedeling` (delegation/mandate) can sit inside an ondermandaat chain — a mandate re-delegated one or more levels down via `parentAllocation` — and the reader needs to see that chain, not just the single record, to answer "who ultimately authorised this, and who else was authorised under the same original grant." This spec adds a chain widget to `BevoegdheidstoedelingDetail` on top of the page skeleton the `delegatie-mandaatregister` change already shipped.

## Requirements

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
