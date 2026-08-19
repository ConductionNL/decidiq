# app-navigation Specification (delta for nav-ceiling-gate)

## ADDED Requirements

### Requirement: REQ-NAV-007 The primary top-level navigation is mechanically capped at the ADR-004 ceiling

A CI check SHALL rebuild the effective top-level menu the same way
`src/main.js`'s `buildManifest` pipeline does — merging `src/manifest.json`
with every `src/manifest.d/*.json` fragment, then applying
`src/menu-layout.json`'s relocations, removals, and settings-section lift —
and SHALL fail when the number of primary top-level entries (entries with no
`section`, i.e. neither `"footer"` nor `"settings"`) exceeds 6.

#### Scenario: A merged menu at or under the ceiling passes

- GIVEN the effective merged menu has 6 or fewer primary top-level entries
- WHEN the nav-ceiling check runs
- THEN it reports pass and prints the primary, footer, and settings entry counts

#### Scenario: A merged menu over the ceiling fails

- GIVEN the effective merged menu has 7 or more primary top-level entries
- WHEN the nav-ceiling check runs
- THEN it exits non-zero
- AND the failure output names the ceiling (6), the actual primary count, and the offending entry ids

#### Scenario: Footer and settings entries do not count against the ceiling

- GIVEN the merged menu includes entries carrying `section: "footer"` or `section: "settings"`
- WHEN the nav-ceiling check computes the primary count
- THEN those entries are excluded from the primary count and reported separately

### Requirement: REQ-NAV-008 Every fragment top-level menu entry must be explicitly placed

For every `src/manifest.d/*.json` fragment, each entry in that fragment's
top-level `menu` array SHALL be considered "placed" only if at least one of
the following holds: (a) the entry itself declares `section: "footer"` or
`section: "settings"`; (b) the entry's `id` is a key in
`src/menu-layout.json#relocations`; (c) the entry's `id` is listed in
`src/menu-layout.json#removals`; (d) the entry's `id` is listed in
`src/menu-layout.json#settingsSection`. The nav-ceiling check SHALL fail when
any fragment declares a top-level menu entry that is not placed, independent
of whether the ceiling in REQ-NAV-007 is currently exceeded — an unplaced
entry is a defect even while the count happens to stay at or under 6.

#### Scenario: An unplaced fragment entry fails even under the ceiling

- GIVEN a fragment declares a new top-level menu entry with an id that appears in none of `relocations`, `removals`, or `settingsSection`, and is not self-scoped to `section: "footer"`/`"settings"`
- AND the resulting primary count is still 6 or fewer
- WHEN the nav-ceiling check runs
- THEN it exits non-zero and names the fragment file and the unplaced entry id

#### Scenario: A relocated fragment entry is placed

- GIVEN a fragment declares a top-level menu entry whose id is a key in `menu-layout.json#relocations`
- WHEN the nav-ceiling check runs
- THEN that entry is treated as placed and does not trigger an unplaced-entry failure

#### Scenario: A removed fragment entry is placed

- GIVEN a fragment declares a top-level menu entry whose id is listed in `menu-layout.json#removals`
- WHEN the nav-ceiling check runs
- THEN that entry is treated as placed and does not trigger an unplaced-entry failure

#### Scenario: A settings-lifted fragment entry is placed

- GIVEN a fragment declares a top-level menu entry whose id is listed in `menu-layout.json#settingsSection`, OR the entry itself declares `section: "settings"`
- WHEN the nav-ceiling check runs
- THEN that entry is treated as placed and does not trigger an unplaced-entry failure

### Requirement: REQ-NAV-009 The nav-ceiling check carries a positive control

The nav-ceiling check's test suite SHALL include at least one fixture that
proves the check can fail: an in-memory fragment fixture with an unplaced
top-level menu entry, asserted to produce a non-zero result naming that
entry. A check whose test suite only ever exercises passing fixtures is
unproven — a check that never ran would look identical.

#### Scenario: The positive-control fixture fails the check

- GIVEN the positive-control fixture (a base menu, one fragment with one unplaced top-level entry, and an empty `menu-layout.json`)
- WHEN the check's evaluation function runs against that fixture
- THEN it reports at least one failure naming the fixture's unplaced entry id

#### Scenario: Placing the same entry clears the positive-control failure

- GIVEN the positive-control fixture from the previous scenario
- WHEN the fixture's unplaced entry id is added to `relocations`, `removals`, or `settingsSection`
- AND the check's evaluation function runs again
- THEN it reports no failure for that entry
