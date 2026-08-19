# decision-management Specification

## ADDED Requirements

### Requirement: Appointment adoption materializes Membership records

When a `decisionType = appointment` decision transitions into `lifecycle =
enacted` with `outcome = adopted`, `DecisionLifecycleService` MUST create one
`Membership` object per entry in `candidates`, each carrying
`role = targetRole`, `governanceBody = targetBody`, the paired `post` (per the
pairing rule below), `startDate = enactedAt`, and either `person` (when the
candidate carries one) or `label = externalName` (for a not-yet-registered
candidate). The created Membership ids MUST be written back onto the
decision's `appointedMemberships` field. This activates the field
`appointment-decision-type-schema` declared but left inert.

**Post pairing rule**: when `targetPosts` is empty, every Membership is
created with no `post` (role-only appointment). When `targetPosts` has
exactly one entry, every Membership is created with that Post. When
`targetPosts` has more than one entry, its length MUST equal `candidates`'
length and posts are paired to candidates by array index — see the transition
guard requirement below for the enforcement point.

#### Scenario: A single-candidate, role-only appointment materializes one Membership

- GIVEN a `decisionType = appointment` decision with one candidate carrying a
  `person` reference, `targetRole = member`, `targetBody` set, and no
  `targetPosts`
- WHEN the decision transitions from `decided` (`outcome = adopted`) to
  `enacted`
- THEN exactly one `Membership` is created with `person` set to the
  candidate's person, `role = member`, `governanceBody = targetBody`, no
  `post`, and `startDate` equal to the decision's `enactedAt`
- AND the decision's `appointedMemberships` contains the new Membership's id

#### Scenario: An external (not-yet-registered) candidate is materialized by name

- GIVEN a `decisionType = appointment` decision with one candidate carrying
  only `externalName` (no `person`)
- WHEN the decision is enacted with `outcome = adopted`
- THEN the created `Membership` has `label` set to the candidate's
  `externalName` and no `person` reference

#### Scenario: Multiple candidates for multiple posts pair by index

- GIVEN a `decisionType = appointment` decision with 2 candidates and
  `targetPosts` containing 2 Post references
- WHEN the decision is enacted with `outcome = adopted`
- THEN 2 Memberships are created, each pairing `candidates[i]` with
  `targetPosts[i]`

#### Scenario: A rejected appointment materializes no Memberships

- GIVEN a `decisionType = appointment` decision reaching `lifecycle = decided`
  with `outcome = rejected`
- WHEN the decision is later transitioned (e.g. to `archived` per the shared
  lifecycle)
- THEN no `Membership` is created and `appointedMemberships` stays empty

#### Scenario: Materialization does not run twice

- GIVEN a `decisionType = appointment` decision that has already been enacted
  and has a non-empty `appointedMemberships`
- WHEN `applyPostTransitionEffects` runs again for any reason
- THEN no additional `Membership` objects are created (idempotency guard)

### Requirement: The enact transition rejects an unpairable candidates/posts mismatch

`DecisionLifecycleService::resolveRejection()` MUST reject the `enact` action
for a `decisionType = appointment` decision when `targetPosts` has more than
one entry and its length does not equal `candidates`' length — before the
lifecycle write happens, following the same fail-closed pattern as the
existing quorum-before-`voting` and outcome-before-`enact` gates.

#### Scenario: A mismatched posts/candidates count blocks enactment

- GIVEN a `decisionType = appointment` decision with 3 candidates and
  `targetPosts` containing 2 Post references
- WHEN the `enact` transition is attempted
- THEN it is rejected with a message identifying the posts/candidates count
  mismatch, and the decision's `lifecycle` is unchanged

#### Scenario: Zero or one target post never blocks enactment

- GIVEN a `decisionType = appointment` decision with any number of candidates
  and either zero or exactly one `targetPosts` entry
- WHEN the `enact` transition is attempted (all other gates satisfied)
- THEN the pairing guard does not reject it

### Requirement: Appointment fields render on the Decision detail page

The `DecisionDetail` page's `decision-content` widget MUST include
`targetBody`, `targetPosts`, `targetRole`, `candidates`, `nominatingParty`,
and `appointedMemberships` in its `content.include` scope, so an appointment
decision's nomination data is visible without a bespoke Vue component — the
same generic manifest-driven rendering that already shows `motionType`/
`proposer` for `decisionType = motion` on this widget.

#### Scenario: An appointment decision's candidates are visible on its detail page

- GIVEN a `decisionType = appointment` decision with `candidates` and
  `targetBody` set
- WHEN its `DecisionDetail` page is opened
- THEN the `Content` widget displays `candidates`, `targetBody`, `targetRole`,
  `targetPosts`, `nominatingParty`, and `appointedMemberships` alongside the
  existing generic fields
